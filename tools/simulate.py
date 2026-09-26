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
# The rules -- one sealed round per election
# =====================================================================

DEFAULTS = dict(
    total_spaces=14,
    start_hand=5,
    draw_per_round=2,
    hand_limit=10,
    start_money=12,
    patron_bonus=2,          # per card the Patron cashes, the round after
    payout_num=3,
    payout_den=2,
    track_min=-5,
    track_max=5,
    min_commit=1,
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
        self.committed = 0
        self.prints = 0
        self.cashes = 0


class Game:
    """One round per election. Every seat commits blind, then all reveal."""

    def __init__(self, strategies, config=None, rng=None):
        self.cfg = dict(DEFAULTS)
        if config:
            self.cfg.update(config)
        self.rng = rng or random.Random()
        self.space = 1
        self.history = []
        self.deck = list(OPENING)
        self.rng.shuffle(self.deck)
        self.discard = []
        self.players = [Player(i, s) for i, s in enumerate(strategies)]
        for p in self.players:
            p.money = self.cfg["start_money"]
        for p in self.players:
            self.draw(p, self.cfg["start_hand"])
        self.ended = None

    def draw_one(self):
        if not self.deck:
            if not self.discard:
                return None
            self.deck, self.discard = self.discard, []
            self.rng.shuffle(self.deck)
        return self.deck.pop(0)

    def draw(self, p, n):
        for _ in range(n):
            if len(p.hand) >= self.cfg["hand_limit"]:
                return
            c = self.draw_one()
            if c is None:
                return
            p.hand.append(c)

    def election(self):
        return ELECTIONS[self.space - 1]

    def payout(self, stake):
        return stake * self.cfg["payout_num"] // self.cfg["payout_den"]

    def validate(self, p, plays, reserve):
        keys = [pl[0] for pl in plays]
        assert len(keys) >= min(self.cfg["min_commit"], len(p.hand)), (p.strategy, "commit too few")
        assert len(set(keys)) == len(keys) and all(k in p.hand for k in keys), (p.strategy, keys)
        for key, mode, side in plays:
            assert mode in ("cash", "print") and (mode == "cash" or side in SIDES)
        assert reserve is None or reserve in keys

    def play_round(self):
        # 1. Everyone commits blind -- a strategy sees the table, never the
        #    other commitments.
        commits = {}
        for p in self.players:
            plays, reserve = STRATEGIES[p.strategy](self, p)
            self.validate(p, plays, reserve)
            if reserve is None and plays:
                reserve = max((pl[0] for pl in plays), key=lambda k: CARDS[k]["value"])
            commits[p.seat] = (plays, reserve)

        # 2. Reveal. Cash pays now; prints push and stake.
        track = 0
        stakes = {"nation": {}, "states": {}}
        for p in self.players:
            plays, _ = commits[p.seat]
            for key, mode, side in plays:
                card = CARDS[key]
                p.hand.remove(key)
                p.committed += 1
                if mode == "cash":
                    p.money += card["value"] + (self.cfg["patron_bonus"] if p.patron else 0)
                    p.cashes += 1
                else:
                    track += card["push"]
                    stakes[side][p.seat] = stakes[side].get(p.seat, 0) + card["value"]
                    p.prints += 1
        track = max(self.cfg["track_min"], min(self.cfg["track_max"], track))

        # 3. The election.
        e = self.election()
        if track != 0:
            winner, decided_by = ("nation" if track > 0 else "states"), "track"
        else:
            n, s = sum(stakes["nation"].values()), sum(stakes["states"].values())
            if n != s:
                winner, decided_by = ("nation" if n > s else "states"), "stakes"
            else:
                winner, decided_by = e["historical_winner"], "history"
        loser = "states" if winner == "nation" else "nation"
        for seat, stake in stakes[winner].items():
            self.players[seat].money += self.payout(stake)

        patron, best, tied = None, 0, False
        for seat, stake in stakes[winner].items():
            if stake > best:
                patron, best, tied = seat, stake, False
            elif stake == best:
                tied = True
        if tied:
            patron = None
        prev = next((p.seat for p in self.players if p.patron), None)
        for p in self.players:
            p.patron = (p.seat == patron)
            p.patronages += p.patron

        # 4. Everyone but the Patron takes one committed card back; the
        #    rest are spent. Then everyone draws two.
        for p in self.players:
            plays, reserve = commits[p.seat]
            for key, _, _ in plays:
                if key == reserve and p.seat != patron:
                    p.hand.append(key)
                else:
                    self.discard.append(key)
        for p in self.players:
            self.draw(p, self.cfg["draw_per_round"])

        self.history.append(dict(
            space=self.space, winner=winner, decided_by=decided_by, track=track,
            patron=patron, repeat_patron=(patron is not None and patron == prev),
            contested_patron=len(stakes[winner]) > 1,
            both_sides_backed=bool(stakes[winner]) and bool(stakes[loser]),
            anyone_backed=bool(stakes[winner]) or bool(stakes[loser]),
            matched=(winner == e["historical_winner"]),
            committed=sum(len(commits[s][0]) for s in commits),
        ))

        self.space += 1
        if self.space > self.cfg["total_spaces"]:
            self.ended = "board_completed"
            return
        fresh = released(YEARS[self.space - 2], YEARS[self.space - 1])
        if fresh:
            self.deck.extend(fresh)
            self.rng.shuffle(self.deck)

    def run(self):
        while self.ended is None:
            self.play_round()
        return self


# =====================================================================
# Strategies: f(game, player) -> ([(card, 'cash'|'print', side)], reserve)
# =====================================================================

def by_value(keys):
    return sorted(keys, key=lambda k: -CARDS[k]["value"])


def hand_side(game, hand):
    """The side this hand can push hardest; history breaks a tie."""
    net = sum(CARDS[k]["push"] for k in hand)
    if net > 0:
        return "nation"
    if net < 0:
        return "states"
    return game.election()["historical_winner"]


def spare(game, p):
    """Cards to commit while keeping four in hand after the draw."""
    return max(1, len(p.hand) - 4 + game.cfg["draw_per_round"])


def strat_hoarder(game, p):
    """Cashes one card a round and never prints: the line to beat."""
    return [(by_value(p.hand)[0], "cash", None)], None


def strat_casher(game, p):
    """Commits as many cards as the bot does, but only ever cashes. Tests
    whether printing earns its risk at equal card throughput."""
    return [(k, "cash", None) for k in by_value(p.hand)[:spare(game, p)]], None


def strat_bot(game, p):
    """The server bot -- keep in step with engine_bot_commit.

      1. Keep four cards in hand; commit the rest (at least one).
      2. Patron? Cash them all: the bonus pays on every card cashed.
      3. Otherwise print the cards that push the way the hand leans (history
         breaks a tie), and cash the ones that push nobody. Cards pushing
         the other way stay in hand.
      4. Reserve the most valuable card printed.

    A first draft that printed up to three and cashed one lost 89% heads-up
    to this line; banking as Patron alone was worth 96% against an otherwise
    identical player.
    """
    n = spare(game, p)
    if p.patron:
        return [(k, "cash", None) for k in by_value(p.hand)[:n]], None
    side = hand_side(game, p.hand)
    want = 1 if side == "nation" else -1
    helpers = by_value([k for k in p.hand if sign(CARDS[k]["push"]) == want])
    zeros = by_value([k for k in p.hand if CARDS[k]["push"] == 0])
    plays = [(k, "print", side) for k in helpers[:n]]
    for k in zeros[:max(0, n - len(plays))]:
        plays.append((k, "cash", None))
    if not plays:
        plays = [(by_value(p.hand)[0], "cash", None)]
    printed = [k for k, m, _ in plays if m == "print"]
    return plays, (by_value(printed)[0] if printed else None)


def strat_all_in(game, p):
    """Commits the whole hand every round, printing all of it."""
    side = hand_side(game, p.hand)
    return [(k, "print", side) for k in p.hand], None


def strat_blind(game, p):
    """Prints as many cards as the bot commits, all for its hand's side,
    whatever each card pushes. Tests that the push on each card matters."""
    side = hand_side(game, p.hand)
    return [(k, "print", side) for k in by_value(p.hand)[:spare(game, p)]], None


def strat_contrarian(game, p):
    """Backs the side its hand pushes AGAINST. Tests whether stake and push
    should ever be split."""
    side = hand_side(game, p.hand)
    other = "states" if side == "nation" else "nation"
    plays = [(k, "print", other) for k in by_value(p.hand)[:2]]
    return plays, plays[0][0]


STRATEGIES = {
    "hoarder": strat_hoarder,
    "casher": strat_casher,
    "bot": strat_bot,
    "all_in": strat_all_in,
    "blind": strat_blind,
    "contrarian": strat_contrarian,
}


# =====================================================================
# Experiments
# =====================================================================

def run_matchup(strategies, games, config=None, seed=0):
    rng = random.Random(seed)
    wins = [0.0] * len(strategies)
    money = [[] for _ in strategies]
    history = []
    for _ in range(games):
        g = Game(list(strategies), config, random.Random(rng.randrange(1 << 30))).run()
        top = max(p.money for p in g.players)
        leaders = [p for p in g.players if p.money == top]
        for p in g.players:
            money[p.seat].append(p.money)
            if p in leaders:
                wins[p.seat] += 1 / len(leaders)
        history.extend(g.history)
    return dict(strategies=strategies, games=games, wins=wins, money=money, history=history)


def pct(x):
    return "%5.1f%%" % (100 * x)


def report_matchup(r):
    print("  " + "  vs  ".join(r["strategies"]))
    for i, s in enumerate(r["strategies"]):
        print("    %-10s wins %s   mean money %6.1f"
              % (s, pct(r["wins"][i] / r["games"]), statistics.mean(r["money"][i])))


def report_elections(history):
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
    print("    track at +-5                           %s" % share(history, lambda h: abs(h["track"]) >= 5))
    print("    both candidates backed                 %s" % share(history, lambda h: h["both_sides_backed"]))
    print("    two+ seats bid for the Patron          %s" % share(history, lambda h: h["contested_patron"]))
    print("    same Patron as last round              %s" % share(history, lambda h: h["repeat_patron"]))
    print("    cards committed per round (table)      %.1f"
          % statistics.mean(h["committed"] for h in history))


def standard(games, seed, config=None):
    field = ["hoarder", "casher", "bot", "all_in", "blind", "contrarian"]
    print("=" * 76)
    print("HEADS-UP ROUND ROBIN: row strategy's win rate against column (%d games)" % games)
    print("=" * 76)
    print("  %-11s" % "" + "".join("%11s" % c for c in field))
    for a in field:
        row = "  %-11s" % a
        for b in field:
            if a == b:
                row += "%11s" % "-"
                continue
            r = run_matchup([a, b], games, config, seed)
            row += "%11s" % pct(r["wins"][0] / games)
        print(row)
    print()
    print("=" * 76)
    print("TABLES OF BOTS, and one casher among bots (fair = 1/n)")
    print("=" * 76)
    for n in (2, 3, 4, 5):
        r = run_matchup(["bot"] * n, games, config, seed)
        print("  %d bots" % n)
        report_elections(r["history"])
        c = run_matchup(["casher"] + ["bot"] * (n - 1), games, config, seed)
        print("    one casher among them wins            %s (fair %s)"
              % (pct(c["wins"][0] / games), pct(1 / n)))
        print()
    print("=" * 76)
    print("MIRROR: bot vs bot, heads-up")
    print("=" * 76)
    r = run_matchup(["bot", "bot"], games, config, seed)
    report_matchup(r)
    report_elections(r["history"])
    print()


def sweep(games, seed):
    print("=" * 76)
    print("SWEEP: bot win rate vs casher / vs all_in, heads-up")
    print("=" * 76)
    for knob, values in (("patron_bonus", (0, 1, 2, 3)),
                         ("draw_per_round", (1, 2, 3)),
                         ("start_hand", (4, 5, 7))):
        for v in values:
            cfg = {knob: v}
            a = run_matchup(["bot", "casher"], games, cfg, seed)
            b = run_matchup(["bot", "all_in"], games, cfg, seed)
            m = run_matchup(["bot", "bot"], games, cfg, seed)
            print("  %-15s %d   vs casher %s   vs all_in %s   repeat Patron %s"
                  % (knob, v, pct(a["wins"][0] / games), pct(b["wins"][0] / games),
                     pct(sum(h["repeat_patron"] for h in m["history"]) / len(m["history"]))))
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
