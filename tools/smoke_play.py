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
        cfg = st["config"]
        self.check(st["space"] >= 1 and st["space"] <= cfg["total_spaces"] + 1,
                   "space in range", "got %s" % st["space"])
        self.check(0 <= st["stability"] <= st["stability_max"],
                   "stability in range", "%s/%s" % (st["stability"], st["stability_max"]))
        self.check(len(st["tracks"]) == 3, "three tracks", "got %d" % len(st["tracks"]))
        for t in st["tracks"]:
            self.check(cfg["track_min"] <= t["value"] <= cfg["track_max"],
                       "track %s in range" % t["axis"], "got %s" % t["value"])
        for p in st["players"]:
            self.check(p["money"] >= 0, "seat %d money non-negative" % p["seat"],
                       "got %s" % p["money"])
        # Exactly one seat may hold the presidency.
        holders = [p for p in st["players"] if p["controls_president"]]
        self.check(len(holders) <= 1, "at most one controller",
                   "got %d" % len(holders))
        # Our own hand must never leak another seat's cards.
        if st.get("you"):
            self.check(len(st["you"]["hand"]) <= cfg["hand_size"],
                       "hand within limit", "got %d" % len(st["you"]["hand"]))
        for p in st["players"]:
            self.check("private" not in p and "hand" not in p,
                       "seat %d exposes no private state" % p["seat"])
        return st


def choose(st):
    """Pick a legal action from what the server says is legal.

    Deliberately uses ONLY the server advisory flags (can_sway,
    can_transition) rather than reimplementing any rule, so that a
    disagreement between the flags and the engine shows up as a rejected
    action instead of being silently papered over.
    """
    hand = st["you"]["hand"]
    if not hand:
        return None, None

    for c in hand:
        if c.get("can_transition"):
            return ("transition", {"card": c["key"]})

    race = st.get("race")
    if race:
        swayable = [c for c in hand if c.get("can_sway")]
        if swayable:
            cheapest = min(swayable, key=lambda c: c["sway_cost"])
            # Back whoever the issues currently favour.
            best = max(race["candidates"], key=lambda c: c["alignment"])
            return ("sway", {"card": cheapest["key"], "candidate": best["key"]})

    richest = max(hand, key=lambda c: c["finance"])
    return ("finance", {"card": richest["key"]})


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
                say("  %2d. %s  %s vs %s   stability %d/%d"
                    % (race["space"], race["year"],
                       race["candidates"][0]["name"], race["candidates"][1]["name"],
                       st["stability"], st["stability_max"]))

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
    print("  elections     %d of %d" % (elections_seen, final["config"]["total_spaces"]))
    print("  stability     %d/%d" % (final["stability"], final["stability_max"]))
    print("  transitions   %d of 3"
          % sum(1 for t in final["tracks"] if t["transitioned"]))
    for p in final["players"]:
        print("  seat %d %-28s wealth %4s  presidencies %s"
              % (p["seat"], p["player_name"],
                 p["final_score"] if p["final_score"] is not None else p["money"],
                 p["presidencies"]))

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
