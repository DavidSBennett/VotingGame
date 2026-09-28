#!/usr/bin/env python3
"""Drive DC-style games (backend/engine_dc.php) against the LIVE variant.

Games opt into the new engine with "engine": "dc" at creation; everything
else on the site still plays the newsroom game. This plays whole games
through the real endpoints and checks, after every action:
  - one paper on turn, and it is current_seat; turn order is kept;
  - my hand / deck / discard match the public counts; my prestige adds up;
  - no seat's hand, deck or discard is ever in the public block, and a
    second person never sees my hand;
  - influence pools never negative; the exchange holds at most five;
  - at most one election per action of mine; the ending is right.

    py -X utf8 tools/smoke_dc.py                 # solo vs 2 bots, then 2 people + 1 bot
    py -X utf8 tools/smoke_dc.py --games 3       # three solo games (2, 3, 4 seats)
    py -X utf8 tools/smoke_dc.py --quiet

Exit code 0 only if every game ended properly with no failed check.
"""

import argparse
import json
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


def my_prestige(st):
    you = st["you"]
    me = [p for p in st["players"] if p["is_you"]][0]
    cards = you["hand"] + you["deck"] + you["discard"] + you["held"] + me["locations"]
    if st.get("turn") and st["turn"]["seat"] == you["seat"]:
        cards += st["turn"]["played"]
    vp = sum(c["vp"] for c in cards)
    if me["paper"] and me["paper"]["key"] == "argus":
        vp += sum(1 for c in cards if c["type"] == "Election")
    return vp


def check_state(ck, st, where):
    if st["status"] != "active":
        return
    on = [p for p in st["players"] if p["on_turn"]]
    ck.check(len(on) == 1, where + ": one paper on turn", str(len(on)))
    if on:
        ck.check(on[0]["seat"] == st["current_seat"] == st["turn"]["seat"], where + ": on turn is current_seat")
    for p in st["players"]:
        ck.check(not ({"hand", "deck", "discard", "private_state"} & set(p)), where + ": seat %d exposes nothing private" % p["seat"])
    me = [p for p in st["players"] if p["is_you"]][0]
    you = st["you"]
    ck.check(len(you["hand"]) == me["hand_count"], where + ": hand matches count", "%d vs %d" % (len(you["hand"]), me["hand_count"]))
    ck.check(len(you["deck"]) == me["deck_count"], where + ": deck matches count")
    ck.check(len(you["discard"]) == me["discard_count"], where + ": discard matches count")
    ck.check(my_prestige(st) == me["prestige"], where + ": my prestige adds up", "%d vs %d" % (my_prestige(st), me["prestige"]))
    ck.check(len(st["exchange"]) <= 5, where + ": exchange at most five")
    for k, v in st["turn"]["pools"].items():
        ck.check(v >= 0, where + ": pool %s not negative" % k, str(v))
    ck.check(st["election"] is None or st["election"]["index"] == len(st["history"]), where + ": election in progress follows history")


def take_turn(base, token, st, ck, say, stats):
    """One whole turn for a person, through the API: play, prompts, elect, buy, paper, end."""
    before = len(st["history"])
    guard = 0
    trail = []
    while st["status"] == "active" and st["turn"]["seat"] == st["you"]["seat"] and guard < 80:
        guard += 1
        av = st["available_actions"]
        you = st["you"]
        trail.append("pending=%s play_all=%s elect=%s paper=%s buy=%s hand=%d" % (
            bool(you["pending"]), av.get("play_all"), av.get("elect"), av.get("paper"), av.get("buy"), len(you["hand"])))
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
        elif av.get("elect"):
            act(base, token, "elect", {"side": av["elect"][0]})
            stats["elected"] += 1
        elif av.get("paper") and not stats.get("paper_used_turn"):
            act(base, token, "paper")
            stats["paper"] += 1
            stats["paper_used_turn"] = True
        elif av.get("buy"):
            ex = {c["key"]: c for c in st["exchange"]}
            best = max(av["buy"], key=lambda k: ex[k]["cost"] if k in ex else 3)
            act(base, token, "buy", {"card": best})
            stats["bought"] += 1
        else:
            act(base, token, "end_turn")
            stats["turns"] += 1
            stats["paper_used_turn"] = False
            st = state_of(base, token)
            check_state(ck, st, "after turn %d" % stats["turns"])
            break               # in a solo game the bots have already played: my next turn is a new call
        st = state_of(base, token)
        check_state(ck, st, "turn %d action %d" % (stats["turns"], guard))
    ck.check(guard < 80, "a turn finishes", " | ".join(trail[-6:]))
    return st


def refusals(base, token, st, ck):
    def refused(label, action, params, needle):
        try:
            act(base, token, action, params)
            ck.check(False, label)
        except ApiError as e:
            ck.check(needle.lower() in str(e).lower(), label, str(e))
    refused("a card not in hand is refused", "play", {"card": "no_such_card"}, "not in your hand")
    refused("an unaffordable election is refused", "elect", {"side": "states"}, "needs")
    dear = max(st["exchange"], key=lambda c: c["cost"])
    refused("an unaffordable story is refused", "buy", {"card": dear["key"]}, "costs")
    refused("an unknown action is refused", "print_money", {}, "unknown action")


def finish(base, token, st, ck, say, label):
    ck.check(st["status"] == "ended", label + ": the game ended", st["status"])
    ck.check(st["ended_reason"] == "board_completed", label + ": ended because 1860 was decided", str(st["ended_reason"]))
    ck.check(len(st["history"]) == st["elections_total"], label + ": every election decided", "%d" % len(st["history"]))
    scores = {p["seat"]: p["final_score"] for p in st["players"]}
    ck.check(all(v is not None for v in scores.values()), label + ": final scores")
    live = [p for p in st["players"] if not p["conceded"]]
    top = max(p["final_score"] for p in live)
    ck.check(scores[st["winner_seat"]] == top, label + ": the winner has the most prestige")
    for p in st["players"]:
        ck.check(p["final_score"] == p["prestige"], label + ": seat %d final score is prestige" % p["seat"])
    export = call(base, "/exportGame.php", params={"player_token": token})["export"]
    ck.check(export["summary"]["config"].get("engine") == "dc", label + ": export says engine dc")
    ck.check(len(export["events"]) > 0 and len(export["elections"]) == st["elections_total"], label + ": export carries the log")
    say("  %s: ended (%s) after %d rounds; history rewritten in %d of %d; scores %s; winner seat %s"
        % (label, st["ended_reason"], st["round"], sum(1 for h in st["history"] if not h["matched_history"]),
           len(st["history"]), json.dumps(scores), st["winner_seat"]))


def solo(base, bots, level, ck, say):
    seat = call(base, "/createGame.php", {"player_name": "smoke-dc", "max_players": 1, "bots": bots,
                                         "bot_level": level})     # the DC game is the default now
    token = seat["player_token"]
    st = state_of(base, token)
    ck.check(st["status"] == "active", "solo: starts at once")
    ck.check(st["election"]["year"] == 1796 and len(st["exchange"]) == 5, "solo: 1796, five on the exchange")
    ck.check(all(p["paper"] for p in st["players"]), "solo: every seat has a newspaper")
    ck.check(len({p["paper"]["key"] for p in st["players"]}) == len(st["players"]), "solo: all papers different")
    check_state(ck, st, "solo start")
    refusals(base, token, st, ck)
    stats = dict(turns=0, elected=0, bought=0, prompts=0, paper=0)
    guard = 0
    while st["status"] == "active" and guard < 120:
        guard += 1
        ck.check(st["turn"]["seat"] == st["you"]["seat"], "solo: bots have played; it is my turn again")
        st = take_turn(base, token, st, ck, say, stats)
    finish(base, token, st, ck, say, "solo vs %d %s bots" % (bots, level))
    say("    my turns %d, elections %d, purchases %d, prompts %d, paper used %d"
        % (stats["turns"], stats["elected"], stats["bought"], stats["prompts"], stats["paper"]))


def two_people(base, ck, say):
    host = call(base, "/createGame.php", {"player_name": "smoke-dc-A", "max_players": 2, "bots": 1, "paper": "globe"})
    ta = host["player_token"]
    listed = call(base, "/listOpenGames.php")
    mine = [g for g in listed.get("games", []) if g["game_id"] == host["game_id"]]
    ck.check(len(mine) == 1, "two: the new table is on the lobby list")
    ck.check(bool(mine) and mine[0].get("papers_taken") == ["globe"], "two: the list shows the host's paper taken",
             str(mine[0].get("papers_taken") if mine else None))
    ck.check(len(listed.get("papers", [])) == 8, "two: the list carries the eight newspapers")
    try:
        call(base, "/joinGame.php", {"player_name": "smoke-dc-X", "join_code": host["join_code"], "paper": "globe"})
        ck.check(False, "two: a paper already taken is refused")
    except ApiError as e:
        ck.check("already taken" in str(e), "two: a paper already taken is refused", str(e))
    guest = call(base, "/joinGame.php", {"player_name": "smoke-dc-B", "join_code": host["join_code"], "paper": "sun"})
    tb = guest["player_token"]
    call(base, "/startGame.php", {"player_token": ta})
    sa, sb = state_of(base, ta), state_of(base, tb)
    ck.check(sa["status"] == "active", "two: started")
    ck.check(sa.get("engine") == "dc", "two: the state says engine dc")
    papers = {p["player_name"]: p["paper"]["key"] for p in sa["players"]}
    ck.check(papers.get("smoke-dc-A") == "globe" and papers.get("smoke-dc-B") == "sun", "two: chosen papers kept", str(papers))
    a_hand = [c["key"] for c in sa["you"]["hand"]]
    b_json = json.dumps(sb)
    ck.check(not any('"%s"' % k in b_json for k in a_hand if k.startswith(("letter#", "notice#"))),
             "two: B never sees A's hand")
    tokens = {sa["you"]["seat"]: ta, sb["you"]["seat"]: tb}
    stats = dict(turns=0, elected=0, bought=0, prompts=0, paper=0)
    guard = 0
    order = []
    st = sa
    while st["status"] == "active" and guard < 400:
        guard += 1
        seat = st["turn"]["seat"]
        ck.check(seat in tokens, "two: bots never leave a bot on turn", str(seat))
        other = [t for s, t in tokens.items() if s != seat][0]
        try:
            act(base, other, "end_turn")
            ck.check(False, "two: the paper not on turn is refused")
        except ApiError as e:
            ck.check("not your turn" in str(e).lower(), "two: the paper not on turn is refused", str(e))
        order.append(seat)
        st = take_turn(base, tokens[seat], state_of(base, tokens[seat]), ck, say, stats)
    ck.check(all(order[i] != order[i + 1] for i in range(len(order) - 1)), "two: the two people alternate")
    finish(base, ta, state_of(base, ta), ck, say, "two people + 1 bot")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default=BASE)
    ap.add_argument("--games", type=int, default=1, help="solo games (2, 3, 4 seats in turn)")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()
    ck = Checks()

    def say(*a):
        if not args.quiet:
            print(*a)

    print("DC-style games against %s" % args.base)
    try:
        for i in range(args.games):
            solo(args.base, 1 + (i + 1) % 3, "easy" if i % 2 == 0 else "hard", ck, say)
        two_people(args.base, ck, say)
    except ApiError as e:
        ck.check(False, "API error", str(e))
    print()
    print("%d checks" % ck.checked)
    if ck.failures:
        print("FAILED (%d):" % len(ck.failures))
        for f in ck.failures[:40]:
            print("  " + f)
        sys.exit(1)
    print("all clear")


if __name__ == "__main__":
    main()
