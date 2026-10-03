#!/usr/bin/env python3
"""Drive the 2024 game (backend/engine_2024.php) against the LIVE 2024 site.

Plays whole solo games against bots through the real endpoints and checks,
after every action:
  - one outlet on turn, and it is current_seat;
  - my hand / deck / discard match the public counts; my stake count too;
  - no seat's hand, deck, discard or stakes are ever in the public block;
  - currency pools never negative; the exchange holds at most five;
  - the race tally is the votes of the claimed states on the map;
  - at most one big state called per action of mine;
  - the ending: a side has 270, the stakes are revealed, and my score is the
    prestige I staked on the winner.

    py -X utf8 tools/smoke_2024.py                 # three solo games (2, 3, 4 seats)
    py -X utf8 tools/smoke_2024.py --games 5

Exit code 0 only if every game ended properly with no failed check.
"""

import argparse
import random
import sys

from smoke_play import BASE, ApiError, call


class Checks:
    def __init__(self):
        self.failures, self.checked = [], 0

    def check(self, ok, label, detail=""):
        self.checked += 1
        if not ok:
            self.failures.append("%s %s" % (label, detail))
        return ok


def state_of(base, token):
    return call(base, "/getState.php", params={"player_token": token})["state"]


def act(base, token, action, params=None):
    return call(base, "/playAction.php", {"player_token": token, "action": action, "params": params or {}})


def check_state(ck, st, where):
    ck.check(st.get("engine") == "2024", where + ": the 2024 engine")
    for p in st["players"]:
        ck.check(not ({"hand", "deck", "discard", "private_state", "staked"} & set(p)),
                 where + ": seat %d exposes nothing private" % p["seat"])
    ev = {"trump": 0, "harris": 0}
    for s in st["map"]:
        if s["side"]:
            ev[s["side"]] += s["ev"]
    ck.check(ev["trump"] == st["race"]["trump"] and ev["harris"] == st["race"]["harris"], where + ": tally is the claimed map",
             "%s vs %s" % (ev, st["race"]))
    if st["status"] != "active":
        return
    on = [p for p in st["players"] if p["on_turn"]]
    ck.check(len(on) == 1, where + ": one outlet on turn", str(len(on)))
    if on:
        ck.check(on[0]["seat"] == st["current_seat"] == st["turn"]["seat"], where + ": on turn is current_seat")
    me = [p for p in st["players"] if p["is_you"]][0]
    you = st["you"]
    ck.check(len(you["hand"]) == me["hand_count"], where + ": hand matches count")
    ck.check(len(you["deck"]) == me["deck_count"], where + ": deck matches count")
    ck.check(len(you["discard"]) == me["discard_count"], where + ": discard matches count")
    ck.check(len(you["staked"]) == me["stakes"], where + ": stake count matches")
    ck.check(len(st["exchange"]) <= 5, where + ": exchange at most five")
    for k, v in st["turn"]["pools"].items():
        ck.check(v >= 0, where + ": pool %s not negative" % k, str(v))


def take_turn(base, token, st, ck, stats, rng):
    """One whole turn for a person: sometimes stake; else play all, answer
    prompts, call the big state, buy, end."""
    guard = 0
    while st["status"] == "active" and st["turn"]["seat"] == st["you"]["seat"] and guard < 80:
        guard += 1
        av = st["available_actions"]
        you = st["you"]
        called_before = len(st["big_called"])
        if av.get("stake") and (rng.random() < 0.18 or st["race"]["final"]):
            card = max(you["hand"], key=lambda c: c["vp"])
            side = st["race"]["winner_side"] or rng.choice(["trump", "harris"])
            act(base, token, "stake", {"card": card["key"], "side": side})
            stats["stakes"] += 1
            stats["turns"] += 1
            st = state_of(base, token)
            check_state(ck, st, "after stake %d" % stats["stakes"])
            break
        if you["pending"]:
            opts = [c["key"] for c in you["pending"]["options"]]
            pick = None
            if you["pending"]["type"] == "trash":
                pick = next((k for k in opts if k.startswith("scandal#")), None) or \
                       next((k for k in opts if k.startswith("notice#")), None)
            else:
                pick = max(you["pending"]["options"], key=lambda c: c["cost"])["key"]
            act(base, token, "choose", {"card": pick})
            stats["prompts"] += 1
        elif av.get("play_all"):
            act(base, token, "play_all")
        elif av.get("call"):
            act(base, token, "call", {"side": rng.choice(av["call"])})
            stats["called"] += 1
        elif av.get("buy"):
            pick = rng.choice(av["buy"])
            if ":" in pick:
                card, side = pick.split(":")
                act(base, token, "buy", {"card": card, "side": side})
                stats["states"] += 1
            else:
                act(base, token, "buy", {"card": pick})
                stats["bought"] += 1
        else:
            act(base, token, "end_turn")
            stats["turns"] += 1
            st = state_of(base, token)
            check_state(ck, st, "after turn %d" % stats["turns"])
            break
        st = state_of(base, token)
        check_state(ck, st, "turn %d action %d" % (stats["turns"], guard))
        if st["status"] == "active" and st["turn"]["seat"] == st["you"]["seat"]:
            ck.check(len(st["big_called"]) - called_before <= 1, "one big state per action")
    ck.check(guard < 80, "a turn finishes")
    return st


def refusals(base, token, st, ck):
    def refused(label, action, params, needle):
        try:
            act(base, token, action, params)
            ck.check(False, label)
        except ApiError as e:
            ck.check(needle.lower() in str(e).lower(), label, str(e))
    refused("a card not in hand is refused", "play", {"card": "no_such_card"}, "not in your hand")
    refused("a state needs a side", "buy", {"card": next((c["key"] for c in st["exchange"] if c["type"] == "State"), "st#wy")},
            "")
    refused("an unaffordable call is refused", "call", {"side": "trump"}, "needs")


def play_game(base, seats, ck, seed, quiet):
    rng = random.Random(seed)
    made = call(base, "/createGame.php", {"player_name": "smoke 2024", "max_players": 1, "bots": seats - 1})
    token = made["player_token"]
    st = state_of(base, token)
    check_state(ck, st, "start")
    stats = dict(turns=0, stakes=0, called=0, states=0, bought=0, prompts=0)
    if st["status"] == "active" and st["turn"]["seat"] == st["you"]["seat"]:
        refusals(base, token, st, ck)
        st = state_of(base, token)
    guard = 0
    while st["status"] == "active" and guard < 200:
        guard += 1
        st = take_turn(base, token, st, ck, stats, rng)
    ck.check(st["status"] == "ended", "game %d ends" % seed, st["status"])
    if st["status"] == "ended":
        w = st["race"]["winner_side"]
        if st["ended_reason"] == "race_called":
            ck.check(w is not None and st["race"][w] >= 270, "the winner side has 270", str(st["race"]))
        me = [p for p in st["players"] if p["is_you"]][0]
        b = me["score_breakdown"] or {}
        ck.check(len(b.get("stakes", [])) == stats["stakes"], "my stakes are revealed", "%s vs %d" % (len(b.get("stakes", [])), stats["stakes"]))
        want = sum(s["vp"] for s in b.get("stakes", []) if s["side"] == w) + (b.get("argus") or 0)
        ck.check(me["final_score"] == want, "my score is my stakes on the winner", "%s vs %s" % (me["final_score"], want))
    if not quiet:
        print("  %d seats: %s, round %d, Trump %d / Harris %d, winner %s; me %s" % (
            seats, st["ended_reason"], st["round"], st["race"]["trump"], st["race"]["harris"], st["race"]["winner_side"],
            stats))
    return st


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default=BASE)
    ap.add_argument("--games", type=int, default=3)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()
    ck = Checks()
    for i in range(args.games):
        try:
            play_game(args.base, 2 + i % 3, ck, 1000 + i, args.quiet)
        except ApiError as e:
            ck.check(False, "game %d: API error" % i, str(e))
    print("%d checks, %d failed" % (ck.checked, len(ck.failures)))
    for f in ck.failures[:30]:
        print("  FAIL:", f)
    sys.exit(1 if ck.failures else 0)


if __name__ == "__main__":
    main()
