#!/usr/bin/env python3
"""Playout harness for the SIMPLIFIED ruleset (v2).

v2 in one paragraph: one track, Nation (+) against States (-), -5..+5.
Each card has a value and a push. On your turn you CASH a card (take its
value, +patron_bonus if you are the Patron) or PRINT it (move the track by
its push, and stake its value on either candidate). After every seat has
had turns_per_space turns the election resolves: the side the track leans
wins; winning stakes pay payout_num/payout_den times back; the largest
stake on the winner makes that seat the Patron for the next era; the track
returns to 0. Cards are dated: each campaign shuffles in the cards from the
years since the last one, so the deck opens on the Revolution and reaches
Kansas in the 1850s. After the fourteenth election the richest paper wins.

Three rules here overturned the first draft of the simplified game, each on a run of this file:
  - The track RESETS every election. Carried over, it drifted to an end and
    stayed: 67% of races were decided at +-5 before anyone voted.
  - Pushes are halved to +-1..2 (see backend/game_data.php). With the reset
    alone, 43% of races still ended pinned; with both, ~12%.
  - The crisis does NOT double pushes. Doubling changed no win rate at all,
    only re-pinned the track (4% -> 14%). The States-leaning late cards
    arriving are what make the late era different on their own. (Dated
    release has since replaced the crisis rule outright.)

    py -X utf8 tools/simulate.py                 # the standard report
    py -X utf8 tools/simulate.py --games 4000
    py -X utf8 tools/simulate.py --sweep         # payout x patron bonus

The content is PARSED OUT OF backend/game_data.php, the same file the
server plays. The rules are a hand-port of backend/engine.php and are
kept in step by hand: change one, change the other in the same commit.
"""

import argparse
import os
import random
import re
import statistics

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BACKEND = os.path.join(ROOT, "backend")


# =====================================================================
# A very small parser for the PHP array literals in backend/game_data.php
# # =====================================================================

class PhpArrayParser:
    def __init__(self, text, pos):
        self.s = text
        self.i = pos

    def skip(self):
        while self.i < len(self.s):
            ch = self.s[self.i]
            if ch in " \t\r\n":
                self.i += 1
            elif self.s.startswith("//", self.i):
                nl = self.s.find("\n", self.i)
                self.i = len(self.s) if nl < 0 else nl + 1
            elif self.s.startswith("/*", self.i):
                end = self.s.find("*/", self.i)
                self.i = len(self.s) if end < 0 else end + 2
            else:
                return

    def parse_value(self):
        self.skip()
        ch = self.s[self.i]
        if ch == "[":
            return self.parse_array()
        if ch == "'":
            return self.parse_string()
        for word, val in (("true", True), ("false", False), ("null", None)):
            if self.s.startswith(word, self.i):
                self.i += len(word)
                return val
        m = re.match(r"-?\d+", self.s[self.i:])
        if not m:
            raise ValueError("unparseable at %d: %r" % (self.i, self.s[self.i:self.i + 40]))
        self.i += m.end()
        return int(m.group(0))

    def parse_string(self):
        self.i += 1
        out = []
        while True:
            ch = self.s[self.i]
            if ch == "\\":
                out.append(self.s[self.i + 1])
                self.i += 2
            elif ch == "'":
                self.i += 1
                return "".join(out)
            else:
                out.append(ch)
                self.i += 1

    def parse_array(self):
        self.i += 1
        items = []
        while True:
            self.skip()
            if self.s[self.i] == "]":
                self.i += 1
                break
            value = self.parse_value()
            self.skip()
            if self.s.startswith("=>", self.i):
                self.i += 2
                items.append((value, self.parse_value()))
            else:
                items.append((None, value))
            self.skip()
            if self.s[self.i] == ",":
                self.i += 1
        if items and all(k is not None for k, _ in items):
            return {k: v for k, v in items}
        return [v for _, v in items]


def php_function_array(filename, function_name):
    with open(os.path.join(BACKEND, filename), encoding="utf-8") as fh:
        text = fh.read()
    at = text.find("function %s(" % function_name)
    assert at >= 0, "%s not found in %s" % (function_name, filename)
    start = text.find("[", text.find("return", at))
    return PhpArrayParser(text, start).parse_array()


CARDS = php_function_array("game_data.php", "vg_cards")
ELECTIONS = php_function_array("game_data.php", "vg_elections")


def check_parity():
    assert len(ELECTIONS) == 14, "expected 14 spaces, parsed %d" % len(ELECTIONS)
    for i, e in enumerate(ELECTIONS, 1):
        assert e["space"] == i, e["year"]
        assert e["historical_winner"] in ("nation", "states"), e["year"]
        assert e["nation"]["key"] != e["states"]["key"], e["year"]
    for key, c in CARDS.items():
        assert c["kind"] in ("event", "profit"), key
        assert c["kind"] != "profit" or c["push"] == 0, "%s: profit cards do not push" % key
        assert -3 <= c["push"] <= 3, key
        assert c["value"] > 0, key
    assert len(CARDS) >= 40, "deck looks too small: %d" % len(CARDS)


check_parity()

YEARS = [e["year"] for e in ELECTIONS]


def released(after_year, through_year):
    """Cards a campaign releases -- mirror of vg_cards_released()."""
    return [k for k, c in CARDS.items()
            if (after_year is None or c["year"] > after_year) and c["year"] <= through_year]


OPENING = released(None, YEARS[0])
assert len(OPENING) >= 26, "opening deck of %d cannot deal five hands" % len(OPENING)
LATE_SPACE = 11      # 1848: only a reporting boundary now, not a rule
SIDES = ("nation", "states")


# =====================================================================
# The rules
# =====================================================================

DEFAULTS = dict(
    total_spaces=14,
    turns_per_space=2,
    hand_size=5,
    start_money=12,
    patron_bonus=2,
    # Winning stakes pay back stake * payout_num / payout_den, rounded
    # down per seat. Kept as a fraction so the engine can stay integer.
    payout_num=3,
    payout_den=2,
    track_min=-5,
    track_max=5,
)


def sign(x):
    return (x > 0) - (x < 0)


class Player:
    def __init__(self, seat, strategy):
        self.seat = seat
        self.strategy = strategy
        self.money = 0
        self.hand = []
        self.patron = False
        self.patronages = 0
        self.prints = 0
        self.cashes = 0
        self.staked = 0
        self.returned = 0


class Game:
    def __init__(self, strategies, config=None, rng=None):
        self.cfg = dict(DEFAULTS)
        if config:
            self.cfg.update(config)
        self.rng = rng or random.Random()
        self.track = 0
        self.space = 1
        self.stakes = {"nation": {}, "states": {}}
        self.turns_this_space = 0
        self.start_seat = 0
        self.ended = None
        self.history = []

        self.deck = list(OPENING)
        self.rng.shuffle(self.deck)
        self.discard = []

        self.players = [Player(i, s) for i, s in enumerate(strategies)]
        for p in self.players:
            p.money = self.cfg["start_money"]
        for p in self.players:
            self.draw_up(p)
        self.current = 0

    # ---- deck ----

    def draw_one(self):
        if not self.deck:
            if not self.discard:
                return None
            self.deck, self.discard = self.discard, []
            self.rng.shuffle(self.deck)
        return self.deck.pop(0)

    def draw_up(self, p):
        while len(p.hand) < self.cfg["hand_size"]:
            card = self.draw_one()
            if card is None:
                break
            p.hand.append(card)

    # ---- queries a strategy may use ----

    def election(self):
        return ELECTIONS[self.space - 1]

    def track_after(self, key):
        return max(self.cfg["track_min"],
                   min(self.cfg["track_max"], self.track + CARDS[key]["push"]))

    def leading_side(self, track=None):
        """Who would win if the election were held now."""
        t = self.track if track is None else track
        if t > 0:
            return "nation"
        if t < 0:
            return "states"
        n = sum(self.stakes["nation"].values())
        s = sum(self.stakes["states"].values())
        if n != s:
            return "nation" if n > s else "states"
        return self.election()["historical_winner"]

    def payout(self, stake):
        return stake * self.cfg["payout_num"] // self.cfg["payout_den"]

    # ---- play ----

    def play(self, p, action, key, side=None):
        card = CARDS[key]
        if action == "cash":
            p.money += card["value"] + (self.cfg["patron_bonus"] if p.patron else 0)
            p.cashes += 1
        elif action == "print":
            assert side in SIDES
            self.track = self.track_after(key)
            self.stakes[side][p.seat] = self.stakes[side].get(p.seat, 0) + card["value"]
            p.prints += 1
            p.staked += card["value"]
        else:
            raise ValueError(action)
        p.hand.remove(key)
        self.discard.append(key)
        self.draw_up(p)

    def end_turn(self):
        self.turns_this_space += 1
        if self.turns_this_space >= len(self.players) * self.cfg["turns_per_space"]:
            self.resolve_election()
            return
        self.current = (self.current + 1) % len(self.players)

    def resolve_election(self):
        e = self.election()
        if self.track != 0:
            decided_by = "track"
        elif sum(self.stakes["nation"].values()) != sum(self.stakes["states"].values()):
            decided_by = "stakes"
        else:
            decided_by = "history"
        winner = self.leading_side()
        loser = "states" if winner == "nation" else "nation"

        for seat, stake in self.stakes[winner].items():
            paid = self.payout(stake)
            self.players[seat].money += paid
            self.players[seat].returned += paid

        # The Patron: the single largest stake on the winner. A tie, or
        # nobody backing him, leaves the office unowned.
        patron, best, tied = None, 0, False
        for seat, stake in self.stakes[winner].items():
            if stake > best:
                patron, best, tied = seat, stake, False
            elif stake == best:
                tied = True
        if tied:
            patron = None
        prev_patron = next((p.seat for p in self.players if p.patron), None)
        for p in self.players:
            p.patron = (p.seat == patron)
            if p.patron:
                p.patronages += 1

        backers = set(self.stakes[winner]) | set(self.stakes[loser])
        self.history.append(dict(
            space=self.space, winner=winner, decided_by=decided_by,
            patron=patron, repeat_patron=(patron is not None and patron == prev_patron),
            track=self.track,
            contested_patron=len(self.stakes[winner]) > 1,
            both_sides_backed=bool(self.stakes[winner]) and bool(self.stakes[loser]),
            anyone_backed=bool(backers),
            matched=(winner == e["historical_winner"]),
        ))

        self.stakes = {"nation": {}, "states": {}}
        self.track = 0
        self.turns_this_space = 0
        self.space += 1
        if self.space > self.cfg["total_spaces"]:
            self.ended = "board_completed"
            return
        fresh = released(YEARS[self.space - 2], YEARS[self.space - 1])
        if fresh:
            self.deck.extend(fresh)
            self.rng.shuffle(self.deck)
        self.start_seat = (self.start_seat + 1) % len(self.players)
        self.current = self.start_seat

    def run(self):
        guard = 0
        while self.ended is None and guard < 5000:
            guard += 1
            p = self.players[self.current]
            action, key, side = STRATEGIES[p.strategy](self, p)
            self.play(p, action, key, side)
            self.end_turn()
        return self


# =====================================================================
# Strategies
# =====================================================================

def best_cash(game, p):
    return ("cash", max(p.hand, key=lambda k: CARDS[k]["value"]), None)


def strat_hoarder(game, p):
    """Never prints. The degenerate line the economy has to beat."""
    return best_cash(game, p)


def strat_bot(game, p):
    """The server bot -- keep in step with engine_bot_choice.

      1. Patron? Cash the best card; the bonus is the point of the office.
      2. Otherwise print the most valuable card that leaves the track off
         zero, staking it on whichever side then leads.
      3. No such card? Cash the best card.

    An earlier draft printed only cards already pushing toward the leader.
    A shark beat that bot 91% heads-up and 78% at a three-seat table (fair
    is 33%); this one holds it to ~61% and ~39%.
    """
    if p.patron:
        return best_cash(game, p)
    best = None
    for k in p.hand:
        track = game.track_after(k)
        if track == 0:
            continue
        if best is None or CARDS[k]["value"] > CARDS[best[0]]["value"]:
            best = (k, "nation" if track > 0 else "states")
    if best:
        return ("print", best[0], best[1])
    return best_cash(game, p)


def strat_zealot(game, p):
    """Always prints, backing whichever side its best card pushes toward."""
    key = max(p.hand, key=lambda k: CARDS[k]["value"])
    push = CARDS[key]["push"]
    side = "nation" if push > 0 else "states" if push < 0 else game.leading_side()
    return ("print", key, side)


def win_chance(game, side, track, turns_after):
    """Crude read of how safe a lead is: the margin on the track, discounted
    by how many turns rivals still have to move it."""
    margin = track if side == "nation" else -track
    if margin == 0:
        return 0.5
    swing = 1.5 * max(1, turns_after) ** 0.5
    return max(0.05, min(0.95, 0.5 + 0.5 * margin / (abs(margin) + swing)))


def strat_shark(game, p):
    """Expected-value player: print when the stake's expected return, plus
    the chance of taking the Patron, beats cashing the card."""
    n = len(game.players)
    turns_after = n * game.cfg["turns_per_space"] - game.turns_this_space - 1
    bonus_now = game.cfg["patron_bonus"] if p.patron else 0
    patron_worth = game.cfg["patron_bonus"] * game.cfg["turns_per_space"]
    best = best_cash(game, p)
    best_ev = CARDS[best[1]]["value"] + bonus_now

    for key in p.hand:
        value = CARDS[key]["value"]
        track = game.track_after(key)
        for side in SIDES:
            chance = win_chance(game, side, track, turns_after)
            mine = game.stakes[side].get(p.seat, 0) + value
            rival = max([v for s, v in game.stakes[side].items() if s != p.seat] or [0])
            ev = chance * game.payout(value)
            if mine > rival and game.space < game.cfg["total_spaces"]:
                ev += chance * patron_worth
            if ev > best_ev:
                best, best_ev = ("print", key, side), ev
    return best


STRATEGIES = {
    "hoarder": strat_hoarder,
    "bot": strat_bot,
    "zealot": strat_zealot,
    "shark": strat_shark,
}


# =====================================================================
# Experiments
# =====================================================================

def run_matchup(strategies, games, config=None, seed=0):
    rng = random.Random(seed)
    wins = [0.0] * len(strategies)
    money = [[] for _ in strategies]
    prints = [[] for _ in strategies]
    history = []
    for _ in range(games):
        # Rotate seats so no strategy is always first to act.
        shift = rng.randrange(len(strategies))
        order = strategies[shift:] + strategies[:shift]
        g = Game(order, config, random.Random(rng.randrange(1 << 30))).run()
        top = max(p.money for p in g.players)
        leaders = [p for p in g.players if p.money == top]
        for p in g.players:
            i = (p.seat + shift) % len(strategies)
            money[i].append(p.money)
            prints[i].append(p.prints / max(1, p.prints + p.cashes))
            if p in leaders:
                wins[i] += 1 / len(leaders)
        history.extend(g.history)
    return dict(strategies=strategies, games=games, wins=wins, money=money,
                prints=prints, history=history)


def seat_bias(strategy, seats, games, config=None, seed=0):
    """Identical strategies at every seat, seats NOT rotated: seat 0 edge."""
    rng = random.Random(seed)
    wins = [0.0] * seats
    for _ in range(games):
        g = Game([strategy] * seats, config, random.Random(rng.randrange(1 << 30))).run()
        top = max(p.money for p in g.players)
        leaders = [p for p in g.players if p.money == top]
        for p in leaders:
            wins[p.seat] += 1 / len(leaders)
    return [w / games for w in wins]


def pct(x):
    return "%5.1f%%" % (100 * x)


def report_matchup(r):
    print("  " + "  vs  ".join(r["strategies"]))
    for i, s in enumerate(r["strategies"]):
        print("    %-8s wins %s   mean wealth %6.1f   prints %s of turns"
              % (s, pct(r["wins"][i] / r["games"]), statistics.mean(r["money"][i]),
                 pct(statistics.mean(r["prints"][i]))))


def report_elections(history):
    n = len(history)
    early = [h for h in history if h["space"] < LATE_SPACE]
    late = [h for h in history if h["space"] >= LATE_SPACE]

    def share(rows, f):
        return pct(sum(1 for h in rows if f(h)) / max(1, len(rows)))

    print("    decided by track / stakes / history:  %s / %s / %s"
          % (share(history, lambda h: h["decided_by"] == "track"),
             share(history, lambda h: h["decided_by"] == "stakes"),
             share(history, lambda h: h["decided_by"] == "history")))
    print("    matched history                        %s" % share(history, lambda h: h["matched"]))
    print("    nation won     before 1848 %s   after %s"
          % (share(early, lambda h: h["winner"] == "nation"),
             share(late, lambda h: h["winner"] == "nation")))
    print("    track at +-5   before 1848 %s   after %s"
          % (share(early, lambda h: abs(h["track"]) >= 5),
             share(late, lambda h: abs(h["track"]) >= 5)))
    print("    nobody backed anyone                   %s" % share(history, lambda h: not h["anyone_backed"]))
    print("    both candidates backed                 %s" % share(history, lambda h: h["both_sides_backed"]))
    print("    two+ seats bid for the Patron          %s" % share(history, lambda h: h["contested_patron"]))
    print("    same Patron as last era                %s" % share(history, lambda h: h["repeat_patron"]))


def standard(games, seed, config=None):
    print("=" * 76)
    print("v2 HEADS-UP, %d games each, seats rotated" % games)
    print("=" * 76)
    for pair in (["hoarder", "bot"], ["hoarder", "shark"], ["hoarder", "zealot"],
                 ["bot", "shark"], ["shark", "shark"]):
        r = run_matchup(pair, games, config, seed)
        report_matchup(r)
        if pair == ["shark", "shark"]:
            report_elections(r["history"])
        print()

    print("=" * 76)
    print("TABLE SIZE: one shark against bots / hoarders")
    print("=" * 76)
    for n in (3, 4, 5):
        report_matchup(run_matchup(["shark"] + ["bot"] * (n - 1), games, config, seed))
        report_matchup(run_matchup(["shark"] + ["hoarder"] * (n - 1), games, config, seed))
        print()

    print("=" * 76)
    print("SEAT BIAS: identical sharks, seats fixed (fair = 1/n each)")
    print("=" * 76)
    for n in (2, 3, 4):
        print("  %d seats: %s" % (n, "  ".join(pct(w) for w in seat_bias("shark", n, games, config, seed))))
    print()

    print("=" * 76)
    print("SOLO AS SHIPPED: shark (standing in for a human) vs the server bot")
    print("=" * 76)
    for n in (2, 3):
        r = run_matchup(["shark"] + ["bot"] * (n - 1), games, config, seed)
        report_matchup(r)
        report_elections(r["history"])
        print()


def sweep(games, seed):
    print("=" * 76)
    print("SWEEP: shark wealth minus hoarder wealth, heads-up (+ = printing pays)")
    print("=" * 76)
    payouts = [(1, 1), (5, 4), (3, 2), (2, 1)]
    print("  patron |" + "".join("  payout %-5s" % ("%g" % (a / b)) for a, b in payouts))
    for bonus in (0, 1, 2, 3, 4):
        row = "  %6d |" % bonus
        for num, den in payouts:
            r = run_matchup(["hoarder", "shark"], games,
                            dict(patron_bonus=bonus, payout_num=num, payout_den=den), seed)
            row += "  %+12.1f" % (statistics.mean(r["money"][1]) - statistics.mean(r["money"][0]))
        print(row)
    print()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--games", type=int, default=1000)
    ap.add_argument("--seed", type=int, default=20260926)
    ap.add_argument("--sweep", action="store_true")
    args = ap.parse_args()

    print()
    print("Parsed %d cards (%d in the opening deck, %d profit) and %d races from backend/game_data.php"
          % (len(CARDS), len(OPENING), sum(1 for c in CARDS.values() if c["kind"] == "profit"),
             len(ELECTIONS)))
    print()
    if args.sweep:
        sweep(args.games, args.seed)
    else:
        standard(args.games, args.seed)


if __name__ == "__main__":
    main()
