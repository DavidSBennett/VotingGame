#!/usr/bin/env python3
"""Playout harness for VotingGame.

WHY THIS EXISTS
---------------
Money is both the resource and the score. Swaying spends victory points to
buy an income stream, which is an elegant tension and exactly the kind that
collapses if mistuned: if control_bonus is too small, the solved line is to
never sway at all, let rivals fight over the presidency, and bank every card
as Finance.

That question cannot be answered by feel, so this harness answers it by
playing the game a few thousand times.

    py -X utf8 tools/simulate.py                 # the control_bonus sweep
    py -X utf8 tools/simulate.py --games 2000    # more samples
    py -X utf8 tools/simulate.py --bonus 5       # one value, verbose

NO SECOND SOURCE OF TRUTH
-------------------------
The cards and the board are PARSED OUT OF THE PHP, not retyped here. A
simulation that drifts from the engine is worse than no simulation, so the
data comes from the same files the server reads and the parser asserts on
the counts it expects. The RULES are still a hand-port of engine.php and
have to be kept in step by hand — see check_parity() for what is asserted.
"""

import argparse
import os
import random
import re
import statistics
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BACKEND = os.path.join(ROOT, "backend")


# =====================================================================
# A very small parser for the PHP array literals in backend/*_data.php
# =====================================================================

class PhpArrayParser:
    """Parses the subset of PHP literal syntax the data files use:
    strings, ints, true/false/null, and nested [] arrays with optional
    => keys. Skips // and /* */ comments between tokens.
    """

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
        if self.s.startswith("true", self.i):
            self.i += 4
            return True
        if self.s.startswith("false", self.i):
            self.i += 5
            return False
        if self.s.startswith("null", self.i):
            self.i += 4
            return None
        m = re.match(r"-?\d+", self.s[self.i:])
        if not m:
            raise ValueError("unparseable at %d: %r" % (self.i, self.s[self.i:self.i + 40]))
        self.i += m.end()
        return int(m.group(0))

    def parse_string(self):
        assert self.s[self.i] == "'"
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
        assert self.s[self.i] == "["
        self.i += 1
        items = []       # (key_or_None, value)
        while True:
            self.skip()
            if self.s[self.i] == "]":
                self.i += 1
                break
            value = self.parse_value()
            self.skip()
            if self.s.startswith("=>", self.i):
                self.i += 2
                key = value
                value = self.parse_value()
                items.append((key, value))
            else:
                items.append((None, value))
            self.skip()
            if self.s[self.i] == ",":
                self.i += 1

        if items and all(k is not None for k, _ in items):
            return {k: v for k, v in items}
        return [v for _, v in items]


def php_function_array(filename, function_name):
    """The array literal a `function name() { return [...]; }` returns."""
    path = os.path.join(BACKEND, filename)
    with open(path, encoding="utf-8") as fh:
        text = fh.read()
    marker = "function %s(" % function_name
    at = text.find(marker)
    assert at >= 0, "%s not found in %s" % (function_name, filename)
    ret = text.find("return", at)
    assert ret >= 0, "no return in %s" % function_name
    start = text.find("[", ret)
    return PhpArrayParser(text, start).parse_array()


CARDS = php_function_array("cards_data.php", "vg_cards")
ELECTIONS = php_function_array("history_data.php", "vg_elections")
TRACKS = php_function_array("history_data.php", "vg_issue_tracks")

# PHP does not distinguish an empty list from an empty map, so a card with
# no track movement (`'deltas' => []`) parses as a list. Normalise, or every
# such card blows up the first time it is swayed.
for _card in CARDS.values():
    if not _card["deltas"]:
        _card["deltas"] = {}


def check_parity():
    """Loud assertions that the parsed data is what the engine expects.

    These are the guardrail against the sim quietly drifting from the
    server. They do NOT verify the ported rules — that is still on me.
    """
    assert len(ELECTIONS) == 14, "expected 14 spaces, parsed %d" % len(ELECTIONS)
    candidates = [c for e in ELECTIONS for c in e["candidates"]]
    assert len(candidates) == 28, "expected 28 candidates, parsed %d" % len(candidates)
    for c in candidates:
        assert set(c["stance_early"]) == {"market", "tariff", "federal"}, c["key"]
        assert set(c["stance_late"]) == {"expansion", "slavery", "states"}, c["key"]

    keys = [k for k, v in CARDS.items() if v.get("key")]
    assert len(keys) == 3, "expected 3 key cards, parsed %d" % len(keys)
    transitions = sorted(CARDS[k]["transitions"] for k in keys)
    assert transitions == ["federal", "market", "tariff"], transitions
    for k in keys:
        assert CARDS[k].get("earliest_space"), "%s has no earliest_space gate" % k
    for key, card in CARDS.items():
        for axis in card["deltas"]:
            assert axis in ("market", "tariff", "federal",
                            "expansion", "slavery", "states"), (key, axis)
    assert len(CARDS) >= 50, "deck looks too small: %d" % len(CARDS)


check_parity()

BASE_PACK = [k for k, v in CARDS.items() if v["pack"] == "base" and not v.get("key")]
KEY_CARDS = [k for k, v in CARDS.items() if v.get("key")]


def pack(name):
    return [k for k, v in CARDS.items() if v["pack"] == name and not v.get("key")]


# =====================================================================
# The rules — a hand-port of backend/engine.php
# =====================================================================

DEFAULTS = dict(
    # Mirrored from engine_default_config() in backend/engine.php.
    # Every one of these was set by a run of this harness; see
    # docs/DESIGN.md section 8 for which run found what.
    total_spaces=14,
    turns_per_space=3,
    hand_size=5,
    start_money=12,
    control_bonus=4,
    stability_start=28,
    stability_recovery=3,
    losing_cp_payout=1,
    track_min=-5,
    track_max=5,
)


class Player:
    def __init__(self, seat, strategy, name):
        self.seat = seat
        self.strategy = strategy
        self.name = name
        self.money = 0
        self.hand = []
        self.controls_president = False
        self.presidencies = 0
        self.sways = 0
        self.finances = 0


class Game:
    def __init__(self, strategies, config=None, rng=None):
        self.cfg = dict(DEFAULTS)
        if config:
            self.cfg.update(config)
        self.rng = rng or random.Random()

        self.slots = [
            {"axis": "market", "value": 0, "transitioned": False},
            {"axis": "tariff", "value": 0, "transitioned": False},
            {"axis": "federal", "value": 0, "transitioned": False},
        ]
        # Per two seats, scaled to the table — see engine_setup().
        self.stability_max = (self.cfg["stability_start"]
                              * len(strategies) // 2)
        self.stability = self.stability_max
        self.space = 1
        self.control = {}
        self.turns_this_space = 0
        self.start_seat = 0
        self.ended = None
        self.history = []

        self.deck = self.build_deck()
        self.discard = []

        self.players = [Player(i, s, "P%d-%s" % (i, s)) for i, s in enumerate(strategies)]
        for p in self.players:
            p.money = self.cfg["start_money"]
        for p in self.players:
            self.draw_up(p)
        self.current = 0

    # ---- deck ----

    def build_deck(self):
        base = list(BASE_PACK)
        self.rng.shuffle(base)
        keys = list(KEY_CARDS)
        self.rng.shuffle(keys)
        n = len(base)
        third = max(1, n // 3)
        positions = [
            self.rng.randint(third // 2, third),
            self.rng.randint(third + 1, third * 2),
            self.rng.randint(third * 2 + 1, max(third * 2 + 2, n)),
        ]
        for key_card, at in zip(keys, positions):
            base.insert(min(len(base), at), key_card)
        return base

    def draw_one(self):
        if not self.deck:
            if not self.discard:
                return None
            self.deck = self.discard
            self.discard = []
            self.rng.shuffle(self.deck)
        return self.deck.pop(0)

    def draw_up(self, p):
        while len(p.hand) < self.cfg["hand_size"]:
            card = self.draw_one()
            if card is None:
                break
            p.hand.append(card)

    # ---- tracks ----

    def slot_for(self, axis):
        for i, s in enumerate(self.slots):
            if s["axis"] == axis:
                return i
        return None

    def swayable(self, key):
        card = CARDS[key]
        if card.get("key"):
            return False
        return all(self.slot_for(a) is not None for a in card["deltas"])

    def apply_deltas(self, deltas):
        moved = {}
        for axis, delta in deltas.items():
            i = self.slot_for(axis)
            if i is None:
                continue
            before = self.slots[i]["value"]
            after = max(self.cfg["track_min"], min(self.cfg["track_max"], before + delta))
            self.slots[i]["value"] = after
            if after != before:
                moved[axis] = after
        return moved

    def adjust_stability(self, delta):
        self.stability = max(0, min(self.stability_max, self.stability + delta))

    # ---- alignment ----

    def alignment(self, candidate):
        total = 0
        for slot in self.slots:
            axis = slot["axis"]
            stance = (candidate["stance_late"] if slot["transitioned"]
                      else candidate["stance_early"]).get(axis, 0)
            total += stance * slot["value"]
        return total

    def election(self):
        return ELECTIONS[self.space - 1]

    # ---- play ----

    def play(self, p, action, card_key, candidate_key=None):
        card = CARDS[card_key]
        if action == "finance":
            bonus = self.cfg["control_bonus"] if p.controls_president else 0
            p.money += card["finance"] + bonus
            self.adjust_stability(card["stability"])
            p.finances += 1
        elif action == "sway":
            p.money -= card["sway_cost"]
            self.control.setdefault(candidate_key, {})
            self.control[candidate_key][p.seat] = (
                self.control[candidate_key].get(p.seat, 0) + card["sway_cp"])
            moved = self.apply_deltas(card["deltas"])
            stab = card["stability"]
            for _axis, value in moved.items():
                if abs(value) >= 4:
                    stab -= 1
            self.adjust_stability(stab)
            p.sways += 1
        elif action == "transition":
            # Naming the new national question is itself the story you
            # sell. Without this the card is strictly worse than playing
            # it for money and no one ever fires one.
            p.money += card["finance"]
            i = self.slot_for(card["transitions"])
            new_axis = card["unlocks"]
            self.slots[i] = {"axis": new_axis, "value": 0, "transitioned": True}
            self.deck.extend(pack(new_axis))
            self.rng.shuffle(self.deck)
            self.adjust_stability(card["stability"])
        else:
            raise ValueError(action)

        p.hand.remove(card_key)
        if action != "transition":
            self.discard.append(card_key)
        self.draw_up(p)

    def end_turn(self):
        if self.stability <= 0:
            self.ended = "the_union_breaks"
            return
        self.turns_this_space += 1
        if self.turns_this_space >= len(self.players) * self.cfg["turns_per_space"]:
            self.resolve_election()
            return
        self.current = (self.current + 1) % len(self.players)

    def resolve_election(self):
        e = self.election()
        a, b = e["candidates"]
        align_a, align_b = self.alignment(a), self.alignment(b)
        cp_a = sum(self.control.get(a["key"], {}).values())
        cp_b = sum(self.control.get(b["key"], {}).values())

        if align_a != align_b:
            winner = a if align_a > align_b else b
        elif cp_a != cp_b:
            winner = a if cp_a > cp_b else b
        else:
            winner = a if e["historical_winner"] == a["key"] else b

        control = self.control.get(winner["key"], {})
        controller, best, tied = None, 0, False
        for seat, points in control.items():
            if points > best:
                controller, best, tied = seat, points, False
            elif points == best and points > 0:
                tied = True
        if tied or best <= 0:
            controller = None

        for p in self.players:
            p.controls_president = (controller is not None and p.seat == controller)
            if p.controls_president:
                p.presidencies += 1

        # Support for the losing candidate is not wasted: you sold papers
        # to a losing campaign. Without this, contesting is negative-sum
        # and control silently goes uncontested to whoever bids first.
        loser = b if winner is a else a
        rate = self.cfg["losing_cp_payout"]
        for seat, points in self.control.get(loser["key"], {}).items():
            self.players[seat].money += points * rate

        self.history.append(dict(
            space=self.space, year=e["year"], winner=winner["key"],
            controller=controller,
            matched_history=(winner["key"] == e["historical_winner"]),
            stability=self.stability))

        # Per two seats: drain scales with the table, so recovery must too.
        self.adjust_stability(
            self.cfg["stability_recovery"] * len(self.players) // 2)
        self.control = {}
        self.turns_this_space = 0
        self.space += 1
        if self.space > self.cfg["total_spaces"]:
            self.ended = "board_completed"
            return
        # Rotate who opens the campaign, or the same seat buys control
        # first and cheapest every single era.
        self.start_seat = (self.start_seat + 1) % len(self.players)
        self.current = self.start_seat

    def run(self):
        guard = 0
        while self.ended is None and guard < 5000:
            guard += 1
            p = self.players[self.current]
            action, card, candidate = STRATEGIES[p.strategy](self, p)
            self.play(p, action, card, candidate)
            self.end_turn()
        return self


# =====================================================================
# Strategies
# =====================================================================

def best_finance_card(game, p):
    return max(p.hand, key=lambda k: CARDS[k]["finance"])


def strat_hoarder(game, p):
    """Never sways. The degenerate line the tuning has to beat.

    Plays a transition only when it cannot avoid it, so it is not
    accidentally advantaged or penalised by the endgame arc.
    """
    return finance_or_transition(game, p)


def strat_zealot(game, p):
    """Sways whenever it can afford to, at whoever the issues favour.
    The opposite extreme: spends everything on control.
    """
    e = game.election()
    a, b = e["candidates"]
    favoured = a if game.alignment(a) >= game.alignment(b) else b
    options = [k for k in p.hand
               if game.swayable(k) and CARDS[k]["sway_cost"] <= p.money]
    if options:
        card = max(options, key=lambda k: CARDS[k]["sway_cp"])
        return ("sway", card, favoured["key"])
    return ("finance", best_finance_card(game, p), None)


def strat_investor(game, p):
    """The port of engine_bot_choice — the heuristic the server plays."""
    if p.money < 4:
        return ("finance", best_finance_card(game, p), None)

    e = game.election()
    a, b = e["candidates"]
    favoured = a if game.alignment(a) >= game.alignment(b) else b

    control = game.control.get(favoured["key"], {})
    mine = control.get(p.seat, 0)
    rival = max([v for s, v in control.items() if s != p.seat] or [0])

    best_sway, best_value = None, -1
    for k in p.hand:
        card = CARDS[k]
        if not game.swayable(k) or card["sway_cost"] > p.money:
            continue
        takes_lead = (mine + card["sway_cp"]) > rival
        value = card["sway_cp"] * 2 - card["sway_cost"] + (4 if takes_lead else 0)
        if value > best_value:
            best_sway, best_value = k, value

    for k in p.hand:
        if CARDS[k].get("key") and game.space >= 7 and best_value < 6:
            return ("transition", k, None)

    if best_sway is not None and best_value >= 2:
        return ("sway", best_sway, favoured["key"])
    return ("finance", best_finance_card(game, p), None)


def strat_tycoon(game, p):
    """Buy control as cheaply as it can be had, then collect on it.

    This is the line a thinking player takes, and the one the economy has
    to reward if the design is going to work:

      - Already holding the presidency? Bank. The income is the point.
      - Already leading the candidate the issues favour? Bank; do not
        bid against yourself.
      - Otherwise take the lead with the cheapest sway that does it, but
        only while enough turns remain in the era to earn it back.
      - Never sway for a candidate the issues are carrying away from.
    """
    turns_left = (len(game.players) * game.cfg["turns_per_space"]
                  - game.turns_this_space) // len(game.players)

    if p.controls_president:
        return finance_or_transition(game, p)

    e = game.election()
    a, b = e["candidates"]
    favoured = a if game.alignment(a) >= game.alignment(b) else b

    control = game.control.get(favoured["key"], {})
    mine = control.get(p.seat, 0)
    rival = max([v for s2, v in control.items() if s2 != p.seat] or [0])

    if mine > rival:
        return finance_or_transition(game, p)

    # With losing support paying out, a bid that fails is a refund rather
    # than a loss, so contesting an established lead is worth doing.

    # Once losing support pays out, a late bid is no longer a sunk cost:
    # worst case it refunds. Guarding against it was what stopped the
    # non-opening seat from ever making the last bid, which handed every
    # presidency to whoever opened the campaign.
    if turns_left < 1:
        return finance_or_transition(game, p)

    takers = [k for k in p.hand
              if game.swayable(k)
              and CARDS[k]["sway_cost"] <= p.money
              and mine + CARDS[k]["sway_cp"] > rival]
    if takers:
        cheapest = min(takers, key=lambda k: CARDS[k]["sway_cost"])
        return ("sway", cheapest, favoured["key"])

    return finance_or_transition(game, p)


def finance_or_transition(game, p):
    """Bank a card -- and prefer a key card, since a transition pays the
    same money AND moves the board. Held back only while the track it
    would reset is one this player is currently winning on.
    """
    for k in p.hand:
        card = CARDS[k]
        if not card.get("key"):
            continue
        if game.space < card.get("earliest_space", 1):
            continue
        i = game.slot_for(card["transitions"])
        if i is None:
            continue
        e = game.election()
        a, b = e["candidates"]
        favoured = a if game.alignment(a) >= game.alignment(b) else b
        slot = game.slots[i]
        stance = favoured["stance_early"].get(slot["axis"], 0)
        # Resetting a track that is currently carrying my candidate would
        # throw away the position I paid for.
        helping_me = (stance * slot["value"]) > 2 and \
            game.control.get(favoured["key"], {}).get(p.seat, 0) > 0
        if not helping_me:
            return ("transition", k, None)
    return ("finance", best_finance_card(game, p), None)


STRATEGIES = {
    "hoarder": strat_hoarder,
    "tycoon": strat_tycoon,
    "zealot": strat_zealot,
    "investor": strat_investor,
}


# =====================================================================
# Experiments
# =====================================================================

def run_matchup(strategies, games, config, seed=0):
    rng = random.Random(seed)
    wins = [0] * len(strategies)
    scores = [[] for _ in strategies]
    endings = {}
    spaces = []
    matched = []
    for _ in range(games):
        g = Game(strategies, config, random.Random(rng.randrange(1 << 30))).run()
        endings[g.ended] = endings.get(g.ended, 0) + 1
        spaces.append(len(g.history))
        if g.history:
            matched.append(sum(1 for h in g.history if h["matched_history"]) / len(g.history))
        best = max(g.players, key=lambda p: p.money)
        top = [p for p in g.players if p.money == best.money]
        if len(top) == 1:
            wins[best.seat] += 1
        for p in g.players:
            scores[p.seat].append(p.money)
    return dict(wins=wins, scores=scores, endings=endings, spaces=spaces, matched=matched,
                games=games)


def sweep_window(games, seed):
    """control_bonus against turns_per_space, hoarder vs tycoon.

    Reports the tycoon mean-wealth ADVANTAGE. Positive means investing in
    control beats hoarding; that is the cell the design needs.
    """
    print("=" * 76)
    print("PAYBACK WINDOW SWEEP: tycoon mean wealth minus hoarder mean wealth")
    print("Positive = investing in control pays. %d games per cell." % games)
    print("=" * 76)
    print()
    windows = [2, 3, 4]
    header = "  bonus |" + "".join("  %d turns/space" % w for w in windows)
    print(header)
    print("  ------+" + "-" * (len(header) - 9))
    grid = {}
    for bonus in range(2, 13, 2):
        row = "  %5d |" % bonus
        for w in windows:
            r = run_matchup(["hoarder", "tycoon"], games,
                            {"control_bonus": bonus, "turns_per_space": w}, seed)
            edge = statistics.mean(r["scores"][1]) - statistics.mean(r["scores"][0])
            grid[(bonus, w)] = (edge, r)
            row += "  %+13.1f" % edge
        print(row)
    print()
    best = max(grid.items(), key=lambda kv: kv[1][0])
    (bonus, w), (edge, r) = best
    print("  Best cell: control_bonus=%d, turns_per_space=%d, edge %+.1f" % (bonus, w, edge))
    print("  spaces played there: %.1f of 14, endings %s"
          % (statistics.mean(r["spaces"]), r["endings"]))
    print()
    return grid


def sweep(games, seed):
    print("=" * 72)
    print("THE CENTRAL QUESTION: does never swaying beat investing in control?")
    print("Heads-up, hoarder (seat 0) against investor (seat 1), %d games each." % games)
    print("=" * 72)
    print()
    print("  bonus | hoarder wins | investor wins |  hoarder $ | investor $ | verdict")
    print("  ------+--------------+---------------+------------+------------+---------")
    results = []
    for bonus in range(0, 11):
        r = run_matchup(["hoarder", "investor"], games, {"control_bonus": bonus}, seed)
        h_win = r["wins"][0] / games
        i_win = r["wins"][1] / games
        h_mean = statistics.mean(r["scores"][0])
        i_mean = statistics.mean(r["scores"][1])
        verdict = "hoard" if h_mean > i_mean else "invest"
        print("  %5d | %11.1f%% | %12.1f%% | %10.1f | %10.1f | %s"
              % (bonus, h_win * 100, i_win * 100, h_mean, i_mean, verdict))
        results.append((bonus, h_mean, i_mean))
    print()

    crossover = None
    for bonus, h, i in results:
        if i > h and crossover is None:
            crossover = bonus
    if crossover is None:
        print("  NO CROSSOVER. Hoarding wins at every bonus tested — the control")
        print("  bonus alone cannot make investment pay. The economy needs a")
        print("  structural change, not a bigger number.")
    else:
        print("  Crossover at control_bonus = %d: below it, hoarding is the solved" % crossover)
        print("  line. A healthy setting sits ABOVE the crossover, far enough that")
        print("  investing is clearly right but not so far that the leader runs away.")
    return crossover


def detail(games, seed, config):
    print("=" * 72)
    print("THREE-WAY, control_bonus = %d, %d games" % (config["control_bonus"], games))
    print("=" * 72)
    strategies = ["hoarder", "tycoon", "investor", "zealot"]
    r = run_matchup(strategies, games, config, seed)
    for i, s in enumerate(strategies):
        print("  %-9s wins %5.1f%%   mean wealth %6.1f   median %6.1f"
              % (s, r["wins"][i] / games * 100,
                 statistics.mean(r["scores"][i]),
                 statistics.median(r["scores"][i])))
    print()
    print("  endings:            %s" % r["endings"])
    print("  mean spaces played: %.1f of 14" % statistics.mean(r["spaces"]))
    if r["matched"]:
        print("  elections matching history: %.0f%%" % (statistics.mean(r["matched"]) * 100))
    print()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--games", type=int, default=800)
    ap.add_argument("--seed", type=int, default=20260824)
    ap.add_argument("--bonus", type=int, default=None,
                    help="skip the sweep, run one three-way at this bonus")
    ap.add_argument("--window", action="store_true",
                    help="sweep control_bonus against turns_per_space")
    ap.add_argument("--turns", type=int, default=None,
                    help="turns per space for the --bonus run")
    args = ap.parse_args()

    print()
    print("Parsed %d cards and %d spaces from backend/*.php" % (len(CARDS), len(ELECTIONS)))
    print()

    if args.window:
        sweep_window(args.games, args.seed)
        return

    if args.bonus is None:
        crossover = sweep(args.games, args.seed)
        print()
        chosen = (crossover + 2) if crossover is not None else DEFAULTS["control_bonus"]
        detail(args.games, args.seed, {"control_bonus": chosen})
    else:
        cfg = {"control_bonus": args.bonus}
        if args.turns:
            cfg["turns_per_space"] = args.turns
        detail(args.games, args.seed, cfg)


if __name__ == "__main__":
    main()
