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

    def state(self, st, prev_money):
        tr = st["track"]
        self.check(1 <= st["space"] <= st["total_spaces"] + 1,
                   "space in range", "got %s" % st["space"])
        self.check(tr["min"] <= tr["value"] <= tr["max"],
                   "track in range", "got %s" % tr["value"])
        for p in st["players"]:
            self.check(p["money"] >= 0, "seat %d money non-negative" % p["seat"],
                       "got %s" % p["money"])
        patrons = [p for p in st["players"] if p["is_patron"]]
        self.check(len(patrons) <= 1, "at most one Patron", "got %d" % len(patrons))
        self.check(st["patron_seat"] == (patrons[0]["seat"] if patrons else None),
                   "patron_seat matches the seat flagged Patron")
        race = st.get("race")
        if race:
            for side in ("nation", "states"):
                total = sum(x["amount"] for x in race[side]["stakes"])
                self.check(total == race[side]["total"], "%s stake total adds up" % side)
            self.check(race["turns_taken"] < race["turns_needed"],
                       "election resolves on time",
                       "%s of %s" % (race["turns_taken"], race["turns_needed"]))
        if st.get("you"):
            self.check(len(st["you"]["hand"]) <= 5,
                       "hand within limit", "got %d" % len(st["you"]["hand"]))
        for p in st["players"]:
            self.check("private_state" not in p and "hand" not in p,
                       "seat %d exposes no private state" % p["seat"])
        return st


def choose(st):
    """Pick a move from what the server reports, never from a re-derived rule.

    Prints the most valuable card that leaves a side ahead, staking on the
    side the server says would then lead; otherwise cashes the best card.
    Printing most turns exercises the stake, payout and Patron paths.
    """
    hand = st["you"]["hand"]
    if not hand:
        return None, None
    printable = [c for c in hand if c["track_after"] != 0]
    if printable and st.get("race"):
        c = max(printable, key=lambda c: c["value"])
        return ("print", {"card": c["key"], "side": c["leads_after"]})
    c = max(hand, key=lambda c: c["cash_value"])
    return ("cash", {"card": c["key"]})


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

    turns = 0
    elections_seen = 0
    last_space = 0
    prev_money = None

    while turns < args.max_turns:
        data = call(args.base, "/getState.php", params={"player_token": token})
        st = data["state"]
        checks.state(st, prev_money)

        if st["status"] == "ended":
            break

        if st["space"] != last_space:
            last_space = st["space"]
            race = st.get("race")
            if race:
                say("  %2d. %s  %s (Nation) vs %s (States)%s"
                    % (race["space"], race["year"],
                       race["nation"]["name"], race["states"]["name"],
                       "   [crisis]" if st["crisis"] else ""))

        if st["current_seat"] != st["you"]["seat"]:
            raise ApiError("stuck: current_seat=%s but we are seat %s and no bot ran"
                           % (st["current_seat"], st["you"]["seat"]))

        action, params = choose(st)
        if action is None:
            raise ApiError("no legal action and the game has not ended")

        me = [p for p in st["players"] if p["is_you"]][0]
        prev_money = me["money"]

        res = call(args.base, "/playAction.php",
                   {"player_token": token, "action": action, "params": params})
        checks.check(res.get("ok") is True, "action accepted", str(res)[:120])
        turns += 1

    final = call(args.base, "/getState.php", params={"player_token": token})["state"]
    elections_seen = len(final.get("history", []))

    print()
    print("finished after %d of my turns" % turns)
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
    print("%d invariants checked across %d turns" % (checks.checked, turns))
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
