"""Playout harness for The Fourth Estate.

The game in one paragraph: fourteen elections, one sealed round each. Every
paper commits cards blind, each played for PROFIT (money, the only score),
POSITIVE coverage or NEGATIVE coverage. Coverage pushes the Nation/States
track and counts as influence on a candidate you name; negative coverage
also costs the Union stability; each paper may play only one card
negatively a round. All reveal: the side the track leans toward wins, the
most influence on the winner is Patron (every card it plays for profit next
round pays double), everyone else keeps one reserved card, and
everyone draws two. If stability reaches zero the Union breaks and EVERYONE
loses. Cards are dated and enter the deck when their events happened.

    py -X utf8 tools/simulate.py                 # the standard report
    py -X utf8 tools/simulate.py --sweep         # patron bonus, stability

The content is PARSED OUT OF backend/game_data.php, the same file the
server plays, and the per-batch push balance is asserted here. The rules
are a hand-port of backend/engine.php and are kept in step by hand.

What earlier runs established still holds where the rules carried over:
printing (now covering) a card against its own push loses; the Patron who
banks never repeats; turn order is gone, so seat bias is gone.
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
        pos, neg, stab = c["positive"], c["negative"], c["stability"]
        assert c["profit"] >= 0 and stab >= 0, key
        assert -3 <= pos <= 3 and -3 <= neg <= 3, key
        if c["kind"] == "profit":
            assert pos == 0 and neg == 0 and stab == 0, "%s: profit cards only profit" % key
        if pos and neg:
            assert (pos > 0) != (neg > 0), "%s: positive and negative push the same way" % key
        assert (neg != 0) == (stab > 0), "%s: stability is paid by, and only by, negative coverage" % key
    assert len(CARDS) >= 40, "deck looks too small: %d" % len(CARDS)


def check_balance():
    """Every release batch offers as much push toward Nation as toward
    States, counting both coverage options of every card."""
    prev = None
    for y in YEARS:
        nation = states = 0
        for k in released(prev, y):
            for push in (CARDS[k]["positive"], CARDS[k]["negative"]):
                nation += max(0, push)
                states += max(0, -push)
        assert nation == states, "batch %d unbalanced: Nation %d, States %d" % (y, nation, states)
        prev = y


check_parity()

YEARS = [e["year"] for e in ELECTIONS]


def released(after_year, through_year):
    """Cards a campaign releases -- mirror of vg_cards_released()."""
    return [k for k, c in CARDS.items()
            if (after_year is None or c["year"] > after_year) and c["year"] <= through_year]


check_balance()
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
    patron_multiplier=2,     # the Patron's profit plays pay double, the round after
    max_negative=1,          # negative-coverage cards per paper per round
    track_min=-5,
    track_max=5,
    min_commit=1,
    stability_start=10,      # per two seats, scaled to the table
    stability_recovery=2,    # per two seats, after each election
)


def sign(x):
    return (x > 0) - (x < 0)


def push_of(key, mode):
    return CARDS[key]["positive"] if mode == "positive" else CARDS[key]["negative"]


class Player:
    def __init__(self, seat, strategy):
        self.seat = seat
        self.strategy = strategy
        self.money = 0
        self.hand = []
        self.patron = False
        self.patronages = 0
        self.profits = 0
        self.positives = 0
        self.negatives = 0


class Game:
    """One round per election. Every seat commits blind, then all reveal."""

    def __init__(self, strategies, config=None, rng=None):
        self.cfg = dict(DEFAULTS)
        if config:
            self.cfg.update(config)
        self.rng = rng or random.Random()
        n = len(strategies)
        self.stability_max = self.cfg["stability_start"] * n // 2
        self.stability = self.stability_max
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
        self.min_stability = self.stability

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

    def validate(self, p, plays, reserve):
        keys = [pl[0] for pl in plays]
        assert len(keys) >= min(self.cfg["min_commit"], len(p.hand)), (p.strategy, "commit too few")
        assert len(set(keys)) == len(keys) and all(k in p.hand for k in keys), (p.strategy, keys)
        for key, mode, side in plays:
            assert mode in ("profit", "positive", "negative"), mode
            if mode != "profit":
                assert side in SIDES and push_of(key, mode) != 0, (p.strategy, key, mode)
        negs = sum(1 for _, mode, _ in plays if mode == "negative")
        assert negs <= self.cfg["max_negative"], (p.strategy, "too many negative plays")
        assert reserve is None or reserve in [pl[0] for pl in plays if pl[1] != "profit"],             (p.strategy, "only a card played for coverage can be reserved")

    def play_round(self):
        # 1. Everyone commits blind.
        commits = {}
        for p in self.players:
            plays, reserve = STRATEGIES[p.strategy](self, p)
            self.validate(p, plays, reserve)
            covered = [pl[0] for pl in plays if pl[1] != "profit"]
            if reserve is None and covered:
                reserve = max(covered, key=lambda k: CARDS[k]["profit"])
            commits[p.seat] = (plays, reserve)

        # 2. Reveal. Profit pays; coverage pushes, counts influence on the
        #    named candidate, and negative coverage costs stability.
        track = 0
        influence = {"nation": {}, "states": {}}
        spent = 0
        for p in self.players:
            plays, _ = commits[p.seat]
            for key, mode, side in plays:
                card = CARDS[key]
                p.hand.remove(key)
                if mode == "profit":
                    p.money += card["profit"] * (self.cfg["patron_multiplier"] if p.patron else 1)
                    p.profits += 1
                    continue
                push = push_of(key, mode)
                track += push
                influence[side][p.seat] = influence[side].get(p.seat, 0) + abs(push)
                if mode == "negative":
                    spent += card["stability"]
                    p.negatives += 1
                else:
                    p.positives += 1
        track = max(self.cfg["track_min"], min(self.cfg["track_max"], track))
        self.stability -= spent
        self.min_stability = min(self.min_stability, self.stability)
        if self.stability <= 0:
            self.ended = "the_union_breaks"      # everyone loses
            self.history.append(dict(space=self.space, broke=True, spent=spent))
            return

        # 3. The election.
        e = self.election()
        if track != 0:
            winner, decided_by = ("nation" if track > 0 else "states"), "track"
        else:
            n, s = sum(influence["nation"].values()), sum(influence["states"].values())
            if n != s:
                winner, decided_by = ("nation" if n > s else "states"), "influence"
            else:
                winner, decided_by = e["historical_winner"], "history"

        patron, best, tied = None, 0, False
        for seat, inf in influence[winner].items():
            if inf > best:
                patron, best, tied = seat, inf, False
            elif inf == best:
                tied = True
        if tied:
            patron = None
        prev = next((p.seat for p in self.players if p.patron), None)
        for p in self.players:
            p.patron = (p.seat == patron)
            p.patronages += p.patron

        # 4. Everyone but the Patron takes back its reserved card -- which is
        #    always one played for COVERAGE. (When any committed card could be
        #    reserved, a pure casher replayed its best profit card every
        #    round and avoiding the Patronage became the winning line.)
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
            space=self.space, broke=False, winner=winner, decided_by=decided_by, track=track,
            patron=patron, repeat_patron=(patron is not None and patron == prev),
            contested_patron=len(influence[winner]) > 1, spent=spent,
            matched=(winner == e["historical_winner"]),
        ))

        # 5. The country settles a little; the next round's cards arrive.
        self.stability = min(self.stability_max,
                             self.stability + self.cfg["stability_recovery"] * len(self.players) // 2)
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
# Strategies: f(game, player) -> ([(card, mode, side)], reserve)
# =====================================================================

def by_profit(keys):
    return sorted(keys, key=lambda k: -CARDS[k]["profit"])


def spare(game, p):
    """Cards to commit while keeping four in hand after the draw."""
    return max(1, len(p.hand) - 4 + game.cfg["draw_per_round"])


def best_push(game, key, want, allow_negative):
    """The mode that pushes toward `want` (+1/-1) hardest, or None."""
    options = []
    c = CARDS[key]
    if c["positive"] * want > 0:
        options.append((abs(c["positive"]), "positive"))
    if allow_negative and c["negative"] * want > 0:
        options.append((abs(c["negative"]), "negative"))
    return max(options)[1] if options else None


def lean(game, hand, allow_negative):
    """The side this hand can push hardest (history breaks a tie)."""
    reach = {"nation": 0, "states": 0}
    for k in hand:
        for side, want in (("nation", 1), ("states", -1)):
            mode = best_push(game, k, want, allow_negative)
            if mode:
                reach[side] += abs(push_of(k, mode))
    if reach["nation"] != reach["states"]:
        return "nation" if reach["nation"] > reach["states"] else "states"
    return game.election()["historical_winner"]


def safe_to_destabilise(game, cost, margin):
    return game.stability - cost > margin


def strat_hoarder(game, p):
    """Plays one card a round for profit. The line to beat."""
    return [(by_profit(p.hand)[0], "profit", None)], None


def strat_casher(game, p):
    """Commits as many cards as the bot, all for profit, never covers."""
    return [(k, "profit", None) for k in by_profit(p.hand)[:spare(game, p)]], None


def make_bot(weight=1, margin=4):
    """The server bot -- keep in step with engine_bot_commit.

      1. Keep four cards in hand; commit the rest (at least one).
      2. Patron? Play them all for profit: each pays double.
      3. Otherwise pick the side the hand can push hardest. Cover a card
         for that side when its push, times `weight`, is worth at least
         its profit -- positively if that pushes the right way; negatively
         (one card at most) only if it does and stability stays above
         `margin` after the cost. Name that side's candidate.
      4. Play the rest of the commitment for profit, best first.
      5. Reserve the most profitable card it covered.
    """
    def strat(game, p):
        n = spare(game, p)
        if p.patron:
            return [(k, "profit", None) for k in by_profit(p.hand)[:n]], None
        side = lean(game, p.hand, allow_negative=True)
        want = 1 if side == "nation" else -1
        plays, budget, negs = [], game.stability, 0
        ranked = sorted(p.hand, key=lambda k: CARDS[k]["profit"])      # cheapest first
        for k in ranked:
            if len(plays) >= n:
                break
            c = CARDS[k]
            ok_neg = negs < game.cfg["max_negative"] and budget - c["stability"] > margin
            mode = best_push(game, k, want, allow_negative=ok_neg)
            if mode and abs(push_of(k, mode)) * weight >= c["profit"]:
                plays.append((k, mode, side))
                if mode == "negative":
                    budget -= c["stability"]
                    negs += 1
        used = {k for k, _, _ in plays}
        for k in by_profit([k for k in p.hand if k not in used]):
            if len(plays) >= n:
                break
            plays.append((k, "profit", None))
        return plays, None
    return strat


def strat_positive_only(game, p):
    """Like the bot, but never plays negative coverage."""
    return make_bot(margin=10 ** 6)(game, p)


def strat_spoiler(game, p):
    """Every round, plays its costliest card negatively (the most the rules
    allow). The griefer: tests whether the Union survives one."""
    n = spare(game, p)
    plays = []
    worst = sorted([k for k in p.hand if CARDS[k]["negative"]], key=lambda k: -CARDS[k]["stability"])
    for k in worst[:game.cfg["max_negative"]]:
        neg = CARDS[k]["negative"]
        plays.append((k, "negative", "nation" if neg > 0 else "states"))
    if not plays:
        plays = [(by_profit(p.hand)[0], "profit", None)]
    return plays, None


def strat_all_cover(game, p):
    """Covers everything it commits for its side, positive where it can."""
    n = spare(game, p)
    side = lean(game, p.hand, allow_negative=False)
    want = 1 if side == "nation" else -1
    plays = []
    for k in p.hand:
        if len(plays) >= n:
            break
        ok_neg = (not any(m == "negative" for _, m, _ in plays)
                  and safe_to_destabilise(game, CARDS[k]["stability"], 4))
        mode = best_push(game, k, want, allow_negative=ok_neg)
        if mode:
            plays.append((k, mode, side))
    if not plays:
        plays = [(by_profit(p.hand)[0], "profit", None)]
    return plays, None


STRATEGIES = {
    "hoarder": strat_hoarder,
    "casher": strat_casher,
    "bot": make_bot(),
    "positive": strat_positive_only,
    "all_cover": strat_all_cover,
    "spoiler": strat_spoiler,
}


# =====================================================================
# Experiments
# =====================================================================

def run_matchup(strategies, games, config=None, seed=0):
    rng = random.Random(seed)
    wins = [0.0] * len(strategies)
    money = [[] for _ in strategies]
    history, broke, low = [], 0, []
    plays = [[0, 0, 0] for _ in strategies]
    for _ in range(games):
        g = Game(list(strategies), config, random.Random(rng.randrange(1 << 30))).run()
        low.append(g.min_stability)
        for p in g.players:
            money[p.seat].append(p.money)
            plays[p.seat][0] += p.profits
            plays[p.seat][1] += p.positives
            plays[p.seat][2] += p.negatives
        history.extend(h for h in g.history if not h["broke"])
        if g.ended == "the_union_breaks":
            broke += 1                             # everyone loses: nobody scores a win
            continue
        top = max(p.money for p in g.players)
        leaders = [p for p in g.players if p.money == top]
        for p in leaders:
            wins[p.seat] += 1 / len(leaders)
    return dict(strategies=strategies, games=games, wins=wins, money=money, history=history,
                broke=broke, low=low, plays=plays)


def pct(x):
    return "%5.1f%%" % (100 * x)


def report_matchup(r):
    print("  " + "  vs  ".join(r["strategies"]) + "   (Union broke in %s)" % pct(r["broke"] / r["games"]))
    for i, s in enumerate(r["strategies"]):
        pr, po, ne = r["plays"][i]
        tot = max(1, pr + po + ne)
        print("    %-10s wins %s   mean money %6.1f   profit/pos/neg %2.0f/%2.0f/%2.0f%%"
              % (s, pct(r["wins"][i] / r["games"]), statistics.mean(r["money"][i]),
                 100 * pr / tot, 100 * po / tot, 100 * ne / tot))


def report_elections(r):
    history = r["history"]

    def share(rows, f):
        return pct(sum(1 for h in rows if f(h)) / max(1, len(rows)))

    early = [h for h in history if h["space"] < LATE_SPACE]
    late = [h for h in history if h["space"] >= LATE_SPACE]
    print("    decided by track / influence / history:  %s / %s / %s"
          % (share(history, lambda h: h["decided_by"] == "track"),
             share(history, lambda h: h["decided_by"] == "influence"),
             share(history, lambda h: h["decided_by"] == "history")))
    print("    matched history                          %s" % share(history, lambda h: h["matched"]))
    print("    nation won     before 1848 %s   after %s"
          % (share(early, lambda h: h["winner"] == "nation"), share(late, lambda h: h["winner"] == "nation")))
    print("    two+ seats bid for the Patron            %s" % share(history, lambda h: h["contested_patron"]))
    print("    same Patron as last round                %s" % share(history, lambda h: h["repeat_patron"]))
    print("    lowest stability reached (median)        %s" % statistics.median(r["low"]))


def standard(games, seed, config=None):
    field = ["hoarder", "casher", "bot", "positive", "all_cover", "spoiler"]
    print("=" * 76)
    print("HEADS-UP ROUND ROBIN: row's win rate against column (%d games; a broken" % games)
    print("Union is a loss for both, so rows can sum below 100%)")
    print("=" * 76)
    print("  %-10s" % "" + "".join("%11s" % c for c in field))
    for a in field:
        row = "  %-10s" % a
        for b in field:
            if a == b:
                row += "%11s" % "-"
                continue
            rr = run_matchup([a, b], games, config, seed)
            row += "%11s" % pct(rr["wins"][0] / games)
        print(row)
    print()
    print("=" * 76)
    print("TABLES OF BOTS, and one casher / one spoiler among bots (fair = 1/n)")
    print("=" * 76)
    for n in (2, 3, 4, 5):
        rr = run_matchup(["bot"] * n, games, config, seed)
        print("  %d bots   (Union broke in %s)" % (n, pct(rr["broke"] / games)))
        report_elections(rr)
        c = run_matchup(["casher"] + ["bot"] * (n - 1), games, config, seed)
        sp = run_matchup(["spoiler"] + ["bot"] * (n - 1), games, config, seed)
        print("    one casher among them wins              %s (fair %s)" % (pct(c["wins"][0] / games), pct(1 / n)))
        print("    one spoiler among them: Union broke     %s" % pct(sp["broke"] / games))
        print()


def sweep(games, seed):
    print("=" * 76)
    print("SWEEP: heads-up bot vs casher, and bot mirror stability")
    print("=" * 76)
    for knob, values in (("patron_multiplier", (1, 2, 3)),
                         ("stability_start", (6, 8, 10, 14)),
                         ("stability_recovery", (0, 1, 2, 3))):
        for v in values:
            cfg = {knob: v}
            a = run_matchup(["bot", "casher"], games, cfg, seed)
            m = run_matchup(["bot", "bot"], games, cfg, seed)
            sp = run_matchup(["spoiler", "bot"], games, cfg, seed)
            print("  %-19s %2d   bot beats casher %s   mirror broke %s   vs spoiler broke %s"
                  % (knob, v, pct(a["wins"][0] / games), pct(m["broke"] / games), pct(sp["broke"] / games)))
    print()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--games", type=int, default=800)
    ap.add_argument("--seed", type=int, default=20260927)
    ap.add_argument("--sweep", action="store_true")
    args = ap.parse_args()
    print()
    print("Parsed %d cards (%d in the opening deck, %d profit-only) and %d races from backend/game_data.php"
          % (len(CARDS), len(OPENING), sum(1 for c in CARDS.values() if c["kind"] == "profit"), len(ELECTIONS)))
    print()
    if args.sweep:
        sweep(args.games, args.seed)
    else:
        standard(args.games, args.seed)


if __name__ == "__main__":
    main()
