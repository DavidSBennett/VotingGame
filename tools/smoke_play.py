#!/usr/bin/env python3
"""Drive a real game against the LIVE install, start to finish.

There is no local PHP runtime, so CI can only prove the backend parses.
This proves it RUNS: it creates a solo game over HTTP, plays every turn
through the real endpoints, and checks the invariants that matter after
each one. Everything it touches is a normal game that the admin
clear-finished endpoint will tidy away.

    py -X utf8 tools/smoke_play.py
    py -X utf8 tools/smoke_play.py --base https://voting.thehistorians.org
    py -X utf8 tools/smoke_play.py --quiet

Exit code is 0 only if a game reached a terminal state with no failed
invariant, so this is usable as a post-deploy gate.
"""

import argparse
import json
import sys
import urllib.error
import urllib.request

BASE = "https://voting.thehistorians.org"


class ApiError(Exception):
    pass


def call(base, path, payload=None, params=None, timeout=45):
    url = base.rstrip("/") + path
    if params:
        url += "?" + "&".join("%s=%s" % (k, v) for k, v in params.items())
    data = None
    headers = {"Accept": "application/json"}
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as fh:
            return json.loads(fh.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")
        try:
            parsed = json.loads(body)
        except ValueError:
            # An empty or non-JSON body is itself a finding: every endpoint
            # is supposed to answer in JSON even when it fails.
            raise ApiError("%s %s -> HTTP %d with non-JSON body: %r"
                           % (path, params or "", e.code, body[:300]))
        raise ApiError("%s -> HTTP %d: %s" % (path, e.code, parsed.get("error", body[:200])))
    except urllib.error.URLError as e:
        raise ApiError("%s -> unreachable: %s" % (path, e))


class Checks:
    """Invariants worth asserting after every single turn."""

    def __init__(self):
        self.failures = []
        self.checked = 0

    def check(self, ok, label, detail=""):
        self.checked += 1
        if not ok:
            self.failures.append("%s %s" % (label, detail))
        return ok

    def state(self, st):
        self.check(1 <= st["space"] <= st["total_spaces"] + 1,
                   "space in range", "got %s" % st["space"])
        for p in st["players"]:
            self.check(p["money"] >= 0, "seat %d money non-negative" % p["seat"],
                       "got %s" % p["money"])
        patrons = [p for p in st["players"] if p["is_patron"]]
        self.check(len(patrons) <= 1, "at most one Patron", "got %d" % len(patrons))
        self.check(st["patron_seat"] == (patrons[0]["seat"] if patrons else None),
                   "patron_seat matches the seat flagged Patron")
        self.check(st["current_seat"] is None, "rounds are simultaneous: nobody is on turn")
        race = st.get("race")
        if st.get("you"):
            hand = st["you"]["hand"]
            self.check(len(hand) <= st["rules"]["hand_limit"],
                       "hand within limit", "got %d" % len(hand))
            year = race["year"] if race else None
            for c in hand:
                if year is not None:
                    self.check(c["year"] <= year, "hand holds no card from the future",
                               "%s (%s) in %s" % (c["name"], c["year"], year))
                if c["kind"] == "profit":
                    self.check(c["positive"] == 0 and c["negative"] == 0 and c["stability"] == 0,
                               "profit card %s has profit alone" % c["key"])
                if c["negative"]:
                    self.check(c["stability"] > 0, "%s: negative coverage costs stability" % c["key"])
                if c["positive"] and c["negative"]:
                    self.check((c["positive"] > 0) != (c["negative"] > 0),
                               "%s: positive and negative push opposite ways" % c["key"])
        # The hidden-information boundary: no seat's hand or commitment leaks.
        for p in st["players"]:
            self.check(not ({"private_state", "hand", "commit", "plays"} & set(p)),
                       "seat %d exposes nothing private" % p["seat"])
        return st

    def reveal(self, st, prev_money, prev_stability):
        """The round just resolved: check the reveal adds up."""
        r = st.get("last_reveal")
        if not self.check(r is not None, "a reveal follows every round"):
            return
        tr = st["track"]
        cover = [pl for sd in r["seats"] for pl in sd["plays"] if pl["action"] != "profit"]
        pushes = sum(pl["push"] for pl in cover)
        self.check(r["track"] == max(tr["min"], min(tr["max"], pushes)),
                   "track is the sum of coverage pushes", "%s vs %s" % (r["track"], pushes))
        spent = sum(pl["stability"] for pl in cover if pl["action"] == "negative")
        self.check(r["stability_spent"] == spent, "stability spent is the negative plays' cost")
        self.check(all(pl["stability"] == 0 for pl in cover if pl["action"] == "positive"),
                   "positive coverage costs no stability")
        self.check(r["stability_before"] == prev_stability, "stability carried over from last round",
                   "%s vs %s" % (r["stability_before"], prev_stability))
        for sd in r["seats"]:
            negs = sum(1 for pl in sd["plays"] if pl["action"] == "negative")
            self.check(negs <= st["rules"]["max_negative"], "seat %d played at most one negative" % sd["seat"])
            for side in ("nation", "states"):
                inf = sum(abs(pl["push"]) for pl in sd["plays"] if pl["action"] != "profit" and pl["side"] == side)
                self.check(sd["influence"][side] == inf, "seat %d %s influence adds up" % (sd["seat"], side))
            if sd["kept"] is not None:
                kept = [pl for pl in sd["plays"] if pl["name"] == sd["kept"]]
                self.check(kept and kept[0]["action"] != "profit", "only a coverage card is kept")
        if r["broke"]:
            self.check(st["status"] == "ended" and st["ended_reason"] == "the_union_breaks",
                       "a broken Union ends the game")
            self.check(st["winner_seat"] is None and all(p["final_score"] == 0 for p in st["players"]),
                       "a broken Union: nobody wins, everyone scores zero")
            return
        self.check(r["stability_after"] == prev_stability - spent, "stability paid for hostile coverage")
        if r["decided_by"] == "track":
            self.check(r["track"] != 0 and (r["track"] > 0) == (r["winner_side"] == "nation"),
                       "the side the track leans toward wins")
        inf = {sd["seat"]: sd["influence"][r["winner_side"]] for sd in r["seats"]}
        top = max(inf.values()) if inf else 0
        leaders = [s for s, v in inf.items() if v == top and v > 0]
        want = leaders[0] if len(leaders) == 1 else None
        self.check(r["patron_seat"] == want, "Patron is the single most influence on the winner",
                   "%s vs %s" % (r["patron_seat"], want))
        for sd in r["seats"]:
            if sd["seat"] == r["patron_seat"]:
                self.check(sd["kept"] is None, "the Patron keeps no reserve")
        me = [p for p in st["players"] if p["is_you"]][0]
        mine = [sd for sd in r["seats"] if sd["seat"] == me["seat"]][0]
        self.check(me["money"] == prev_money + mine["earned"], "my money moved by exactly my profit",
                   "%s -> %s (+%s)" % (prev_money, me["money"], mine["earned"]))


def choose(st):
    """Build a commitment from what the server reports, like the server bot:
    keep four; cover cheap cards whose push beats their profit for the side
    the hand leans (one negative at most, and never below 5 stability);
    profit the rest. Exercises profit, positive, negative, Patron, reserve.
    """
    hand = st["you"]["hand"]
    if not hand:
        return {"plays": []}
    n = max(1, len(hand) - 4 + st["rules"]["draw_per_round"])
    reach = {"nation": 0, "states": 0}
    for c in hand:
        for side, want in (("nation", 1), ("states", -1)):
            reach[side] += max([abs(c[m]) for m in ("positive", "negative") if c[m] * want > 0] or [0])
    side = ("nation" if reach["nation"] > reach["states"] else "states") if reach["nation"] != reach["states"] \
        else st["race"]["historical_winner"]
    want = 1 if side == "nation" else -1
    plays, used, negs, budget = [], set(), 0, st["stability"]
    for c in sorted(hand, key=lambda c: c["profit"]):
        if len(plays) >= n:
            break
        opts = []
        if c["positive"] * want > 0:
            opts.append((abs(c["positive"]), "positive"))
        if negs < st["rules"]["max_negative"] and c["negative"] * want > 0 and budget - c["stability"] > 4:
            opts.append((abs(c["negative"]), "negative"))
        if not opts:
            continue
        push, mode = max(opts)
        if push < c["profit"]:
            continue
        plays.append({"card": c["key"], "action": mode, "side": side})
        used.add(c["key"])
        if mode == "negative":
            negs += 1
            budget -= c["stability"]
    for c in sorted(hand, key=lambda c: -c["profit_value"]):
        if len(plays) >= n:
            break
        if c["key"] not in used:
            plays.append({"card": c["key"], "action": "profit"})
    covered = [pl["card"] for pl in plays if pl["action"] != "profit"]
    return {"plays": plays, "reserve": covered[0] if covered else None}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default=BASE)
    ap.add_argument("--name", default="smoke-test")
    ap.add_argument("--max-turns", type=int, default=200)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    checks = Checks()

    def say(*a):
        if not args.quiet:
            print(*a)

    print("Driving a live game against %s" % args.base)
    print()

    seat = call(args.base, "/createGame.php",
                {"player_name": args.name, "max_players": 1, "bots": 1})
    token = seat["player_token"]
    game_id = seat["game_id"]
    print("created game %s (%s), seat %s, status %s"
          % (game_id, seat["join_code"], seat["seat"], seat["status"]))

    # Refusals the server must make.
    def refused(label, params, needle):
        try:
            call(args.base, "/playAction.php", {"player_token": token, "action": "commit", "params": params})
            checks.check(False, label)
        except ApiError as e:
            checks.check(needle in str(e), label, str(e))

    refused("a card not in hand is refused",
            {"plays": [{"card": "no_such_card", "action": "profit"}]}, "not in your hand")
    first = call(args.base, "/getState.php", params={"player_token": token})["state"]
    hand = first["you"]["hand"]
    negs = [c for c in hand if c["negative"]]
    if len(negs) >= 2:
        refused("a second negative card is refused",
                {"plays": [{"card": c["key"], "action": "negative", "side": "nation"} for c in negs[:2]]},
                "negative coverage")
    refused("reserving a profit card is refused",
            {"plays": [{"card": hand[0]["key"], "action": "profit"}], "reserve": hand[0]["key"]},
            "coverage can be reserved")
    zero = [c for c in hand if c["positive"] == 0]
    if zero:
        refused("coverage a card does not have is refused",
                {"plays": [{"card": zero[0]["key"], "action": "positive", "side": "nation"}]}, "has no positive")

    turns = 0
    while turns < args.max_turns:
        st = checks.state(call(args.base, "/getState.php", params={"player_token": token})["state"])
        if st["status"] == "ended":
            break
        race = st["race"]
        say("  %2d. %s  %s (Nation) vs %s (States)   +%d cards   hand %d   stability %d/%d"
            % (race["space"], race["year"], race["nation"]["name"], race["states"]["name"],
               len(st["news"]), len(st["you"]["hand"]), st["stability"], st["stability_max"]))
        late = [n["name"] for n in st["news"] if n["year"] > race["year"]]
        checks.check(not late, "no card released before its year", str(late))
        bots = [p for p in st["players"] if p["is_bot"]]
        checks.check(all(p["committed"] for p in bots), "bots are sealed before we commit")
        if st["you"]["commit"] is not None:
            raise ApiError("stuck: we already committed but the round did not resolve")

        prev_money = [p for p in st["players"] if p["is_you"]][0]["money"]
        prev_stability = st["stability"]
        space = st["space"]
        res = call(args.base, "/playAction.php",
                   {"player_token": token, "action": "commit", "params": choose(st)})
        checks.check(res.get("ok") is True, "commitment accepted", str(res)[:120])
        turns += 1

        after = checks.state(call(args.base, "/getState.php", params={"player_token": token})["state"])
        broke = (after.get("last_reveal") or {}).get("broke")
        checks.check(broke or len(after["history"]) == space, "the round resolved on our commitment",
                     "%d elections after round %d" % (len(after["history"]), space))
        checks.reveal(after, prev_money, prev_stability)

    final = call(args.base, "/getState.php", params={"player_token": token})["state"]
    elections_seen = len(final.get("history", []))

    print()
    print("finished after %d rounds" % turns)
    print("  status        %s (%s)" % (final["status"], final["ended_reason"]))
    print("  elections     %d of %d" % (elections_seen, final["total_spaces"]))
    if final["ended_reason"] == "the_union_breaks":
        print("  THE UNION BROKE after %d elections" % elections_seen)
    else:
        checks.check(elections_seen == final["total_spaces"], "every election was held",
                     "%d of %d" % (elections_seen, final["total_spaces"]))
        checks.check(final["ended_reason"] == "board_completed", "game ran to 1860", str(final["ended_reason"]))
    sides = [h["winner_side"] for h in final.get("history", [])]
    print("  winners       %d Nation, %d States" % (sides.count("nation"), sides.count("states")))
    print("  stability     %d/%d at the end" % (final["stability"], final["stability_max"]))
    for p in final["players"]:
        print("  seat %d %-28s money %4s  Patron %s times  positive %s  negative %s"
              % (p["seat"], p["player_name"],
                 p["final_score"] if p["final_score"] is not None else p["money"],
                 p["patronages"], p["positives"], p["negatives"]))

    matched = sum(1 for h in final.get("history", []) if h.get("matched_history"))
    if elections_seen:
        print("  matched history %d of %d elections" % (matched, elections_seen))

    # The export is the artefact every playtest review reads; if it cannot
    # be produced the loop is broken even when the game is not.
    export = call(args.base, "/exportGame.php", params={"player_token": token})["export"]
    checks.check(len(export["events"]) > 0, "export carries the event log")
    checks.check(export["summary"]["game_id"] == game_id, "export identifies the game")
    print("  export        %d events, %d elections"
          % (len(export["events"]), len(export["elections"])))

    print()
    print("%d invariants checked across %d rounds" % (checks.checked, turns))
    if checks.failures:
        print("FAILURES:")
        for f in checks.failures[:20]:
            print("  " + f)
        return 1
    if final["status"] != "ended":
        print("FAILED: game never reached a terminal state")
        return 1
    print("all clear")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except ApiError as e:
        print("API ERROR: %s" % e)
        sys.exit(2)
