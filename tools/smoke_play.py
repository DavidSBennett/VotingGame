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
                    self.check(c["push"] == 0, "profit card %s does not push" % c["key"])
        # The hidden-information boundary: no seat's hand or commitment leaks.
        for p in st["players"]:
            self.check(not ({"private_state", "hand", "commit", "plays"} & set(p)),
                       "seat %d exposes nothing private" % p["seat"])
        return st

    def reveal(self, st, prev_money):
        """The round just resolved: check the reveal adds up."""
        r = st.get("last_reveal")
        if not self.check(r is not None, "a reveal follows every round"):
            return
        tr = st["track"]
        self.check(tr["min"] <= r["track"] <= tr["max"], "track in range", str(r["track"]))
        pushes = sum(pl["push"] for s in r["seats"] for pl in s["plays"] if pl["action"] == "print")
        self.check(r["track"] == max(tr["min"], min(tr["max"], pushes)),
                   "track is the sum of printed pushes", "%s vs %s" % (r["track"], pushes))
        if r["decided_by"] == "track":
            self.check((r["track"] > 0) == (r["winner_side"] == "nation") and r["track"] != 0,
                       "the side the track leans toward wins")
        stakes = {}
        for sd in r["seats"]:
            stakes[sd["seat"]] = sum(pl["value"] for pl in sd["plays"]
                                     if pl["action"] == "print" and pl["side"] == r["winner_side"])
            self.check(sd["paid"] == stakes[sd["seat"]] * 3 // 2,
                       "seat %d paid 1.5x its winning stake" % sd["seat"],
                       "%s on %s" % (sd["paid"], stakes[sd["seat"]]))
        top = max(stakes.values()) if stakes else 0
        leaders = [s for s, v in stakes.items() if v == top and v > 0]
        want = leaders[0] if len(leaders) == 1 else None
        self.check(r["patron_seat"] == want, "Patron is the single biggest stake on the winner",
                   "%s vs %s" % (r["patron_seat"], want))
        for sd in r["seats"]:
            if sd["seat"] == r["patron_seat"]:
                self.check(sd["kept"] is None, "the Patron keeps no reserve")
            elif sd["plays"]:
                self.check(sd["kept"] is not None, "seat %d kept its reserve" % sd["seat"])
        me = [p for p in st["players"] if p["is_you"]][0]
        mine = [sd for sd in r["seats"] if sd["seat"] == me["seat"]][0]
        self.check(me["money"] == prev_money + mine["cashed"] + mine["paid"],
                   "my money moved by exactly cash + payout",
                   "%s -> %s (+%s +%s)" % (prev_money, me["money"], mine["cashed"], mine["paid"]))


def choose(st):
    """Build a commitment from what the server reports, never a re-derived rule.

    Like the server bot: keep four cards, print those pushing the way the
    hand leans, cash the ones that push nobody -- which exercises cash,
    print, payout, Patron and reserve every game.
    """
    hand = st["you"]["hand"]
    if not hand:
        return {"plays": []}
    n = max(1, len(hand) - 4 + st["rules"]["draw_per_round"])
    net = sum(c["push"] for c in hand)
    side = "nation" if net > 0 else "states" if net < 0 else st["race"]["historical_winner"]
    want = 1 if side == "nation" else -1
    ranked = sorted(hand, key=lambda c: -c["value"])
    plays = [{"card": c["key"], "action": "print", "side": side}
             for c in ranked if c["push"] * want > 0][:n]
    plays += [{"card": c["key"], "action": "cash"} for c in ranked if c["push"] == 0][:max(0, n - len(plays))]
    if not plays:
        plays = [{"card": ranked[0]["key"], "action": "cash"}]
    return {"plays": plays, "reserve": plays[0]["card"]}


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

    # One refusal the server must make: committing a card we do not hold.
    try:
        call(args.base, "/playAction.php", {"player_token": token, "action": "commit",
             "params": {"plays": [{"card": "no_such_card", "action": "cash"}]}})
        checks.check(False, "a commitment of a card not in hand is refused")
    except ApiError as e:
        checks.check("not in your hand" in str(e), "a commitment of a card not in hand is refused", str(e))

    turns = 0
    while turns < args.max_turns:
        st = checks.state(call(args.base, "/getState.php", params={"player_token": token})["state"])
        if st["status"] == "ended":
            break
        race = st["race"]
        say("  %2d. %s  %s (Nation) vs %s (States)   +%d cards   hand %d"
            % (race["space"], race["year"], race["nation"]["name"], race["states"]["name"],
               len(st["news"]), len(st["you"]["hand"])))
        late = [n["name"] for n in st["news"] if n["year"] > race["year"]]
        checks.check(not late, "no card released before its year", str(late))
        bots = [p for p in st["players"] if p["is_bot"]]
        checks.check(all(p["committed"] for p in bots), "bots are sealed before we commit")
        if st["you"]["commit"] is not None:
            raise ApiError("stuck: we already committed but the round did not resolve")

        prev_money = [p for p in st["players"] if p["is_you"]][0]["money"]
        space = st["space"]
        res = call(args.base, "/playAction.php",
                   {"player_token": token, "action": "commit", "params": choose(st)})
        checks.check(res.get("ok") is True, "commitment accepted", str(res)[:120])
        turns += 1

        after = checks.state(call(args.base, "/getState.php", params={"player_token": token})["state"])
        checks.check(len(after["history"]) == space, "the round resolved on our commitment",
                     "%d elections after round %d" % (len(after["history"]), space))
        checks.reveal(after, prev_money)

    final = call(args.base, "/getState.php", params={"player_token": token})["state"]
    elections_seen = len(final.get("history", []))

    print()
    print("finished after %d rounds" % turns)
    print("  status        %s (%s)" % (final["status"], final["ended_reason"]))
    print("  elections     %d of %d" % (elections_seen, final["total_spaces"]))
    checks.check(elections_seen == final["total_spaces"], "every election was held",
                 "%d of %d" % (elections_seen, final["total_spaces"]))
    checks.check(final["ended_reason"] == "board_completed", "game ran to 1860",
                 str(final["ended_reason"]))
    sides = [h["winner_side"] for h in final.get("history", [])]
    print("  winners       %d Nation, %d States" % (sides.count("nation"), sides.count("states")))
    for p in final["players"]:
        print("  seat %d %-28s money %4s  Patron %s times  printed %s"
              % (p["seat"], p["player_name"],
                 p["final_score"] if p["final_score"] is not None else p["money"],
                 p["patronages"], p["prints"]))

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
