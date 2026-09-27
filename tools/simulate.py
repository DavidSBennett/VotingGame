"""Playout harness for The Fourth Estate.

The game in one paragraph: seventeen elections, one sealed round each. Every
paper commits cards blind, each played for PROFIT (money, the only score),
POSITIVE coverage or NEGATIVE coverage. Coverage pushes the Nation/States
track and counts as influence on a candidate you name; negative coverage
also costs the Union stability; each paper may play only one card
negatively a round. All reveal: the side the track leans toward wins, the
most influence on the winner is Patron (every card it plays for profit next
round pays double), everyone else keeps one reserved card, and
everyone draws two. If stability reaches zero the Union breaks, the game
ends, and the most exposed paper (most negative plays over the game) loses
exposure_penalty. Cards are dated and enter the deck when their events happened.

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
    assert len(ELECTIONS) == 17, "expected 17 spaces, parsed %d" % len(ELECTIONS)
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
            assert abs(neg) > abs(pos), "%s: hostile coverage must push harder than favourable" % key
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
LATE_SPACE = 14      # 1848: only a reporting boundary now, not a rule
SIDES = ("nation", "states")


# =====================================================================
# The rules -- one sealed round per election
# =====================================================================

DEFAULTS = dict(
    total_spaces=17,
    start_hand=5,
    draw_per_round=2,
    hand_limit=10,
    start_money=12,
    patron_multiplier=2,     # the Patron's profit plays pay double, the round after
    max_negative=1,          # negative-coverage cards per paper per round
    track_min=-5,
    track_max=5,
    min_commit=0,            # a paper may pass; no passing line beat a fair share
    stability_start=10,      # per two seats, scaled to the table (the ceiling)
    stability_recovery=1,    # per two seats, after each election
    exposure_penalty=25,     # paid by the most exposed paper if the Union breaks
    history_shock=2,         # per two seats: stability lost when an election goes against history
    # --- VARIANT: the newsroom deck-builder ---------------------------------
    deckbuild=True,          # each paper draws from its own deck and buys stories off the exchange
    start_deck=5,            # opening stories dealt into each paper's own deck
    exchange_size=6,             # face-up stories for sale; fresh news goes on first
    price_rule="profit",     # a story costs its bury value (see Game.price) ...
    price_add=0,             # ... + price_add
    max_buys=1,              # stories a paper may buy a round
)

# main's rules, for comparison: one shared deck, nothing to buy.
SHARED = dict(deckbuild=False)


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
        self.deck = []           # deckbuild: this paper's own draw pile
        self.discard = []        # deckbuild: this paper's own discard pile
        self.bought = 0
        self.spent_buying = 0
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
        self.exchange, self.trash = [], []
        if self.cfg["deckbuild"]:
            # Each paper's own deck is dealt from the opening stories; the
            # rest are the unsold supply, and the exchange shows the first few.
            for p in self.players:
                p.deck, self.deck = self.deck[:self.cfg["start_deck"]], self.deck[self.cfg["start_deck"]:]
            self.refill_exchange()
        for p in self.players:
            self.draw(p, self.cfg["start_hand"])
        self.ended = None
        self.min_stability = self.stability

    def refill_exchange(self):
        while len(self.exchange) < self.cfg["exchange_size"] and self.deck:
            self.exchange.append(self.deck.pop(0))

    def price(self, key):
        c = CARDS[key]
        base = c["profit"]
        rule = self.cfg["price_rule"]
        if rule == "profit_only_double" and c["kind"] == "profit":
            base = 2 * c["profit"]
        elif rule == "patron_value":
            # what it would pay the Patron, less its push
            base = 2 * c["profit"] - reach(key)
        elif rule == "plus_weak":
            base = c["profit"] + (3 - reach(key))
        return max(1, base + self.cfg["price_add"])

    def draw_one(self, p=None):
        if self.cfg["deckbuild"]:
            if not p.deck:
                if not p.discard:
                    return None
                p.deck, p.discard = p.discard, []
                self.rng.shuffle(p.deck)
            return p.deck.pop(0)
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
            c = self.draw_one(p)
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
        wishes = {}
        for p in self.players:
            name, _, buy_policy = p.strategy.partition("/")
            buy_policy = buy_policy or DEFAULT_BUY.get(name, "steady")
            plays, reserve = STRATEGIES[name](self, p) if p.hand else ([], None)
            self.validate(p, plays, reserve)
            covered = [pl[0] for pl in plays if pl[1] != "profit"]
            if reserve is None and covered:
                reserve = max(covered, key=lambda k: CARDS[k]["profit"])
            commits[p.seat] = (plays, reserve)
            if self.cfg["deckbuild"]:
                wishes[p.seat] = BUYERS[buy_policy](self, p, plays)

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
            # The Union breaks: the game ends here, and the most exposed
            # paper (most negative plays over the game; ties all pay) loses
            # exposure_penalty. Everyone else keeps what they have.
            self.break_union()
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
            for key, mode, _ in plays:
                if key == reserve and p.seat != patron:
                    p.hand.append(key)
                elif not self.cfg["deckbuild"]:
                    self.discard.append(key)
                elif mode == "profit":
                    self.trash.append(key)      # a buried story is sold: it leaves the game
                else:
                    p.discard.append(key)       # a story run stays in the paper's deck
        if self.cfg["deckbuild"]:
            self.buy(wishes)
        for p in self.players:
            self.draw(p, self.cfg["draw_per_round"])

        self.history.append(dict(
            space=self.space, broke=False, winner=winner, decided_by=decided_by, track=track,
            patron=patron, repeat_patron=(patron is not None and patron == prev),
            contested_patron=len(influence[winner]) > 1, spent=spent,
            matched=(winner == e["historical_winner"]),
        ))

        # 5. History bends: a winner the country did not historically elect
        #    shakes the Union. A break here ends the game like any other,
        #    and the most exposed paper pays.
        if winner != e["historical_winner"] and self.cfg["history_shock"]:
            self.stability -= self.cfg["history_shock"] * len(self.players) // 2
            self.min_stability = min(self.min_stability, self.stability)
            if self.stability <= 0:
                self.break_union()
                return

        # 6. The country settles a little; the next round's cards arrive.
        self.stability = min(self.stability_max,
                             self.stability + self.cfg["stability_recovery"] * len(self.players) // 2)
        self.space += 1
        if self.space > self.cfg["total_spaces"]:
            self.ended = "board_completed"
            return
        fresh = released(YEARS[self.space - 2], YEARS[self.space - 1])
        if fresh and self.cfg["deckbuild"]:
            # The news goes on the exchange first; unsold stories it pushes off
            # go back to the top of the supply.
            self.rng.shuffle(fresh)
            self.deck = self.exchange + self.deck
            self.exchange = []
            self.deck = fresh + self.deck
            self.refill_exchange()
        elif fresh:
            self.deck.extend(fresh)
            self.rng.shuffle(self.deck)

    def buy(self, wishes):
        """Sealed buys, resolved poorest paper first (seat breaks a tie):
        each takes its wished stories still on the exchange that it can afford,
        up to max_buys. A bought story goes to the buyer's discard pile."""
        order = sorted(self.players, key=lambda p: (p.money, self.rng.random()))
        for p in order:
            got = 0
            for key in wishes.get(p.seat, []):
                if got >= self.cfg["max_buys"]:
                    break
                if key in self.exchange and p.money >= self.price(key):
                    self.exchange.remove(key)
                    p.money -= self.price(key)
                    p.spent_buying += self.price(key)
                    p.discard.append(key)
                    p.bought += 1
                    got += 1
        self.refill_exchange()

    def break_union(self):
        """The Union breaks: the game ends, the most exposed paper (most
        negative plays; ties all pay) loses exposure_penalty."""
        self.ended = "the_union_breaks"
        top = max(p.negatives for p in self.players)
        self.blamed = [p.seat for p in self.players if p.negatives == top and top > 0]
        for seat in self.blamed:
            self.players[seat].money -= self.cfg["exposure_penalty"]

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
         `margin` per two seats after the cost (every rival may be spending
         it the same round). Name that side's candidate.
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
            ok_neg = (negs < game.cfg["max_negative"]
                      and budget - c["stability"] > margin * len(game.players) // 2)
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


def strat_breaker(game, p):
    """Plays like the bot until it is ahead and a rival carries more
    exposure; then plays its costliest card negatively to end the game on
    the rival's head. Tests whether the penalty can be weaponised."""
    rivals = [q for q in game.players if q is not p]
    lead = p.money - max(q.money for q in rivals)
    exposed = max(q.negatives for q in rivals)
    plays, reserve = make_bot()(game, p)
    if lead > 0 and exposed > p.negatives:
        worst = sorted([k for k in p.hand if CARDS[k]["negative"]], key=lambda k: -CARDS[k]["stability"])
        if worst and not any(m == "negative" for _, m, _ in plays):
            k = worst[0]
            plays = [pl for pl in plays if pl[0] != k]
            side = "nation" if CARDS[k]["negative"] > 0 else "states"
            plays.append((k, "negative", side))
    return plays, None


def make_hard(keep_on_dump=0, cushion=2, neg_margin=3):
    """Distilled from the playtests the bots kept losing (games 24, 29, 31).

      1. Patron? Sell the hand at double, keeping `keep_on_dump` cards
         (the cheapest-to-cover ones) for the next Patronage bid.
      2. Otherwise win the Patronage as cheaply as possible: on the side
         the hand pushes hardest, add the lowest-profit coverage cards until
         influence reaches a target -- 1 if every rival is the sitting Patron
         (who will be selling), else `cushion` + 1. One hostile card at most,
         only while stability stays above `neg_margin` per two seats.
      3. Keep everything else, except sell the cheapest cards the draw
         would otherwise waste at the hand limit.
      4. The final election carries nothing forward: sell the whole hand.
    """
    def strat(game, p):
        hand = list(p.hand)
        if game.space >= game.cfg["total_spaces"]:
            return [(k, "profit", None) for k in hand], None
        if p.patron:
            keep = sorted(hand, key=lambda k: (CARDS[k]["profit"], -max(abs(CARDS[k]["positive"]), abs(CARDS[k]["negative"]))))[:keep_on_dump]
            return [(k, "profit", None) for k in hand if k not in keep], None

        side = lean(game, hand, allow_negative=True)
        want = 1 if side == "nation" else -1
        rivals = [q for q in game.players if q is not p]
        target = 1 if all(q.patron for q in rivals) else cushion + 1

        options = []
        for k in hand:
            c = CARDS[k]
            if c["positive"] * want > 0:
                options.append((c["profit"] / abs(c["positive"]), c["profit"], k, "positive", abs(c["positive"])))
            if c["negative"] * want > 0 and game.stability - c["stability"] > neg_margin * len(game.players) // 2:
                options.append((c["profit"] / abs(c["negative"]) + 0.5, c["profit"], k, "negative", abs(c["negative"])))
        options.sort()
        plays, used, inf, negs = [], set(), 0, 0
        for _, _, k, mode, push in options:
            if inf >= target:
                break
            if k in used or (mode == "negative" and negs >= game.cfg["max_negative"]):
                continue
            plays.append((k, mode, side))
            used.add(k)
            inf += push
            negs += mode == "negative"

        # Don't let the draw overflow the hand limit: sell the cheapest extras.
        spare = len(hand) - len(used) + game.cfg["draw_per_round"] - game.cfg["hand_limit"]
        if spare > 0:
            rest = sorted([k for k in hand if k not in used], key=lambda k: CARDS[k]["profit"])
            plays += [(k, "profit", None) for k in rest[:spare]]
        if not plays and hand:
            plays = []   # a pass is fine: nothing worth bidding
        covered = [k for k, m, _ in plays if m != "profit"]
        return plays, (max(covered, key=lambda k: CARDS[k]["profit"]) if covered else None)
    return strat


def sniper(game, p):
    """The human line, as played in games 29 and 31 (the benchmark)."""
    if p.patron:
        return [(k, "profit", None) for k in p.hand], None
    side = lean(game, p.hand, True)
    want = 1 if side == "nation" else -1
    opts = []
    for k in p.hand:
        c = CARDS[k]
        for mode in ("positive", "negative"):
            if c[mode] * want > 0 and (mode == "positive" or game.stability - c["stability"] > 3):
                opts.append((c["profit"], abs(c[mode]), k, mode))
    opts.sort()
    if not opts:
        return [], None
    _, _, k, mode = opts[0]
    return [(k, mode, side)], None


# =====================================================================
# Buy policies (deckbuild): f(game, player, plays) -> wished exchange stories,
# best first. Named after a slash: "hard/steady". Decided at commit time,
# blind, like the plays; affordability is checked when buys resolve.
# =====================================================================

def reach(key):
    return max(abs(CARDS[key]["positive"]), abs(CARDS[key]["negative"]))


def make_buyer(rank, min_left=4, floor=4, deck_cap=None):
    """Wish for exchange stories (ranked by `rank`) while at least `min_left`
    elections remain and the paper would keep `floor` money after buying
    (counting what this round's burials will pay). Stops once the paper
    owns `deck_cap` stories."""
    def buyer(game, p, plays):
        if game.cfg["total_spaces"] - game.space < min_left:
            return []
        owned = len(p.hand) + len(p.deck) + len(p.discard)
        if deck_cap is not None and owned >= deck_cap:
            return []
        income = sum(CARDS[k]["profit"] * (game.cfg["patron_multiplier"] if p.patron else 1)
                     for k, m, _ in plays if m == "profit")
        cash = p.money + income - floor
        return [k for k in sorted(game.exchange, key=rank) if game.price(k) <= cash]
    return buyer


BUYERS = {
    "none": lambda game, p, plays: [],
    # the most push per dollar: a paper buying influence
    "steady": make_buyer(lambda k: (-reach(k) / max(1, CARDS[k]["profit"]), CARDS[k]["profit"])),
    # the dearest stories: a paper buying things to bury as Patron
    "rich": make_buyer(lambda k: -CARDS[k]["profit"]),
    # the dearest story that also pushes
    "rich_ev": make_buyer(lambda k: (reach(k) == 0, -CARDS[k]["profit"])),
    # the cheapest story, whatever it is
    "cheap": make_buyer(lambda k: (CARDS[k]["profit"], -reach(k))),
    # buys push per dollar every round it can, down to 1 money, until 2 are left
    "greedy": make_buyer(lambda k: (-reach(k) / max(1, CARDS[k]["profit"]), CARDS[k]["profit"]),
                         min_left=2, floor=1),
    # buys only while its deck is thin
    "thin": make_buyer(lambda k: (-reach(k) / max(1, CARDS[k]["profit"]), CARDS[k]["profit"]),
                       deck_cap=8),
}


# What each strategy buys when no "/policy" is named. The server's bots:
# easy = bot/steady, hard = hard/rich_ev (engine_bot_buys).
DEFAULT_BUY = {"hard": "rich_ev", "hoarder": "none", "casher": "none", "spoiler": "none"}


STRATEGIES = {
    "hoarder": strat_hoarder,
    "casher": strat_casher,
    "bot": make_bot(),                    # the server's EASY bot
    "hard": make_hard(keep_on_dump=1, cushion=1, neg_margin=5),   # the server's HARD bot
    "sniper": sniper,                     # the human line from games 29 and 31
    "positive": strat_positive_only,
    "all_cover": strat_all_cover,
    "spoiler": strat_spoiler,
    "breaker": strat_breaker,
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
            broke += 1
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
    print("HEADS-UP ROUND ROBIN: row's win rate against column (%d games)" % games)
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


def newsroom(games, seed):
    """The deck-builder: does buying pay, is any one way of buying dominant,
    and how does it compare with main's shared deck?"""
    print("=" * 76)
    print("NEWSROOM (deck-builder), %d games per line" % games)
    print("=" * 76)
    a = run_matchup(["hard/rich_ev", "hard/none"], games, None, seed)
    b = run_matchup(["bot/steady", "bot/none"], games, None, seed)
    print("  a paper that buys beats one that never does:   hard %s   easy %s"
          % (pct(a["wins"][0] / games), pct(b["wins"][0] / games)))
    policies = ["steady", "rich", "rich_ev", "cheap", "greedy"]
    for n in (2, 3, 4, 5):
        row = []
        for pol in policies:
            won = 0
            for rot in range(n):
                seats = ["hard/steady"] * n
                seats[rot] = "hard/" + pol
                won += run_matchup(seats, games // n, None, seed + rot)["wins"][rot]
            row.append("%s %s" % (pol, pct(won / (games // n * n))))
        print("  one buyer among %d hard/steady (fair %s): %s" % (n, pct(1 / n), "  ".join(row)))
    print()
    print("  %-26s %12s %12s" % ("", "shared deck", "newsroom"))
    for label, seats in (("hard vs easy bot", ["hard", "bot"]),
                         ("human line vs hard bot", ["sniper", "hard"]),
                         ("casher vs 2 easy bots", ["casher", "bot", "bot"])):
        s_ = run_matchup(seats, games, SHARED, seed)
        d_ = run_matchup(seats, games, None, seed)
        print("  %-26s %12s %12s" % (label, pct(s_["wins"][0] / games), pct(d_["wins"][0] / games)))
    for n in (2, 3, 4, 5):
        s_ = run_matchup(["hard"] * n, games, SHARED, seed)
        d_ = run_matchup(["hard"] * n, games, None, seed)
        top = lambda r: statistics.mean(max(m) for m in zip(*r["money"]))
        print("  %d hard bots: Union broke   %12s %12s   winning money %5.0f / %5.0f"
              % (n, pct(s_["broke"] / games), pct(d_["broke"] / games), top(s_), top(d_)))
    print()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--games", type=int, default=800)
    ap.add_argument("--seed", type=int, default=20260927)
    ap.add_argument("--sweep", action="store_true")
    ap.add_argument("--newsroom", action="store_true", help="the deck-builder report")
    ap.add_argument("--shared", action="store_true", help="main's rules: one shared deck")
    args = ap.parse_args()
    if args.shared:
        DEFAULTS.update(SHARED)
    print()
    print("Parsed %d cards (%d in the opening deck, %d profit-only) and %d races from backend/game_data.php"
          % (len(CARDS), len(OPENING), sum(1 for c in CARDS.values() if c["kind"] == "profit"), len(ELECTIONS)))
    print()
    if args.newsroom:
        newsroom(args.games, args.seed)
    elif args.sweep:
        sweep(args.games, args.seed)
    else:
        standard(args.games, args.seed)


if __name__ == "__main__":
    main()
