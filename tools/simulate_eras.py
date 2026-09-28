"""Playout harness for the VARIANT's era redesign (VARIANT.md, revision 3).

The game in one paragraph: seventeen elections in three eras (I 1796-1816,
II 1820-1840, III 1844-1860). Each paper draws from its own deck, which
starts with generic Era I starter stories. Every round each paper commits
blind. Each story is either
  PLAYED on a candidate, in his POSITIVE space (adds its influence, plus
    its theme bonus if its theme matches his) or his NEGATIVE space
    (subtracts the same). A played story is spent: it leaves the paper's
    deck, and the paper banks its PRESTIGE -- the score;
  or BURIED: its purchasing power (doubled for the Patron), and it goes
    back to the paper's discard pile to be buried again another day.
With the commitment it names stories to BUY off the exchange at their
cost. The candidate with the higher net influence wins; the most influence
on him (positive on him, and -- by default -- negative on his rival) makes
a paper Patron for the next round. Entering a new era, every card of the
old era is lost. A story's era is set by the election that releases it,
so next-era stories arrive three elections early. Most prestige by 1860
wins.

Content is read from docs/deck-v2.csv and docs/candidates.csv, so an edit
to either spreadsheet is picked up on the next run.

    py -X utf8 tools/simulate_eras.py                 # the standard report
    py -X utf8 tools/simulate_eras.py --games 2000
    py -X utf8 tools/simulate_eras.py --sweep         # the main knobs

This is NOT yet the engine: backend/engine.php still plays the newsroom
(revision 1), which tools/simulate.py models.
"""

import argparse
import csv
import os
import random
import statistics

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")
SIDES = ("nation", "states")


# =====================================================================
# Content
# =====================================================================

def load_cards():
    cards, starters = {}, []
    with open(os.path.join(DOCS, "deck-v2.csv"), encoding="utf-8-sig") as fh:
        for r in csv.DictReader(fh):
            c = dict(
                key=r["key"], name=r["name"], era=r["era"], kind=r["kind"],
                theme=r["theme"] or None,
                influence=int(r["influence"] or 0), theme_bonus=int(r["theme_bonus"] or 0),
                profit=int(r["profit"] or 0), prestige=int(r["prestige"] or 0),
                cost=int(r["cost"]) if r["cost"] else None,
                released=None if r["released"] == "start" else int(r["released"]),
                starter=(r["deck"] == "starter"),
            )
            assert c["era"] in ("I", "II", "III"), c
            assert c["kind"] in ("news", "trade"), c
            if c["kind"] == "trade":
                assert c["influence"] == 0 and c["theme"] is None, "%s: trade stories push nobody" % c["key"]
            else:
                assert c["theme"] in ("Economic", "Political", "Social"), c
                assert c["influence"] > 0, "%s: a news story needs influence" % c["key"]
            cards[c["key"]] = c
            if c["starter"]:
                starters.append((c["key"], int(r["copies"] or 1)))
            else:
                assert c["cost"] is not None and c["cost"] > 0, "%s: needs a cost" % c["key"]
    return cards, starters


def load_elections():
    out = []
    with open(os.path.join(DOCS, "candidates.csv"), encoding="utf-8-sig") as fh:
        for r in csv.DictReader(fh):
            out.append(dict(
                year=int(r["year"]), era=r["era"],
                nation=dict(name=r["nation_candidate"], theme=r["nation_theme"]),
                states=dict(name=r["states_candidate"], theme=r["states_theme"]),
                historical_winner=r["historical_winner"],
            ))
    return out


CARDS, STARTERS = load_cards()
ELECTIONS = load_elections()
YEARS = [e["year"] for e in ELECTIONS]
assert len(ELECTIONS) == 17
ERA_OF_RELEASE = {y: ("I" if y <= 1804 else "II" if y <= 1828 else "III") for y in YEARS}
for c in CARDS.values():
    if not c["starter"]:
        assert c["released"] in YEARS, c["key"]
        # Next-era stories arrive three elections before their era.
        assert c["era"] == ERA_OF_RELEASE[c["released"]], "%s: era %s but released %d" % (c["key"], c["era"], c["released"])
LAST_OF_ERA = {e["era"]: i for i, e in enumerate(ELECTIONS)}      # index of each era's last election
ERA_RANK = {"I": 0, "II": 1, "III": 2}


def base(card_id):
    """Starter copies are 'key#seat.n'; history stories are their key."""
    return CARDS[card_id.split("#")[0]]


# =====================================================================
# Rules
# =====================================================================

DEFAULTS = dict(
    start_hand=5,
    draw_per_round=2,
    hand_limit=10,
    start_money=6,          # purchasing power at the start
    patron_multiplier=2,    # the Patron's burials pay double purchasing power
    exchange_size=6,
    max_buys=1,
    era_topup=True,         # after an era change, draw back up to start_hand
    neg_patron=True,        # a negative play counts toward the Patronage of his rival
    floor_zero=True,        # a candidate's net influence never drops below 0
    income=0,               # purchasing power every paper gets each round (subscriptions)
    starter_copies=1,       # multiply every starter's copies
    reserve=True,           # every paper but the new Patron keeps one story it played (no prestige for it)
    reserve_pick="influence",   # which played story a paper keeps: 'influence' or 'prestige'
    # stat overrides for sweeps (None = the spreadsheet's value)
    bonus=None,
    prestige_by_era=None,   # e.g. {"I": 1, "II": 2, "III": 3}
    cost_add=0,
    profit_add=0,           # add to every story's profit (starters and trade included)
    profit_mult=1,          # ... or multiply it
    cost_follows=False,     # raise each price by the same amount its profit rose
)


class Player:
    def __init__(self, seat, strategy):
        self.seat = seat
        self.strategy = strategy
        self.money = 0
        self.prestige = 0
        self.hand, self.deck, self.discard = [], [], []
        self.patron = False
        self.patronages = 0
        self.bought = {"I": 0, "II": 0, "III": 0}
        self.buried = 0
        self.ran = 0             # played positive
        self.negs = 0            # played negative
        self.kept = 0            # played stories kept by the reserve rule
        self.lost = 0            # cards lost at era changes
        self.empty_rounds = 0

    def owned(self):
        return self.hand + self.deck + self.discard


class Game:
    def __init__(self, strategies, config=None, rng=None):
        self.cfg = dict(DEFAULTS)
        if config:
            self.cfg.update(config)
        self.rng = rng or random.Random()
        self.players = [Player(i, s) for i, s in enumerate(strategies)]
        for p in self.players:
            p.money = self.cfg["start_money"]
            p.deck = ["%s#%d.%d" % (k, p.seat, i) for k, n in STARTERS
                      for i in range(n * self.cfg["starter_copies"])]
            self.rng.shuffle(p.deck)
        self.space = 0
        self.supply, self.exchange = [], []
        self.release(YEARS[0])
        for p in self.players:
            self.draw(p, self.cfg["start_hand"])
        self.history = []
        self.ended = False
        self.exchange_short = 0

    # ---- stats (with sweep overrides) -----------------------------------
    def prestige(self, cid):
        c = base(cid)
        if self.cfg["prestige_by_era"] and c["kind"] == "news" and not c["starter"]:
            return self.cfg["prestige_by_era"][c["era"]]
        return c["prestige"]

    def bonus(self, cid):
        c = base(cid)
        return c["theme_bonus"] if self.cfg["bonus"] is None or c["kind"] == "trade" else self.cfg["bonus"]

    def profit(self, cid):
        return base(cid)["profit"] * self.cfg["profit_mult"] + self.cfg["profit_add"]

    def price(self, cid):
        cost = base(cid)["cost"] + self.cfg["cost_add"]
        if self.cfg["cost_follows"]:
            cost += self.profit(cid) - base(cid)["profit"]
        return max(1, cost)

    def influence(self, cid, side, e=None):
        c = base(cid)
        e = e or self.election()
        if c["kind"] == "trade":
            return 0
        return c["influence"] + (self.bonus(cid) if c["theme"] == e[side]["theme"] else 0)

    # ---- the board -------------------------------------------------------
    def election(self):
        return ELECTIONS[self.space]

    def era(self):
        return self.election()["era"]

    def last_round(self):
        return self.space == len(ELECTIONS) - 1

    def expires_now(self, cid):
        """This is the last election in which the card can still be used."""
        c = base(cid)
        return self.last_round() or (c["era"] == self.era() and LAST_OF_ERA[self.era()] == self.space)

    def usable_later(self, cid):
        """Worth buying now: it will still be in play after this round."""
        c = base(cid)
        if self.last_round():
            return False
        return not (c["era"] == self.era() and LAST_OF_ERA[self.era()] == self.space)

    def release(self, year):
        fresh = [k for k, c in CARDS.items() if c["released"] == year]
        self.rng.shuffle(fresh)
        # The news goes on the exchange first; unsold stories it pushes off
        # go back to the top of the supply.
        self.supply = fresh + self.exchange + self.supply
        self.exchange = []
        self.refill()

    def refill(self):
        while len(self.exchange) < self.cfg["exchange_size"] and self.supply:
            self.exchange.append(self.supply.pop(0))

    def draw(self, p, n):
        for _ in range(n):
            if len(p.hand) >= self.cfg["hand_limit"]:
                return
            if not p.deck:
                if not p.discard:
                    return
                p.deck, p.discard = p.discard, []
                self.rng.shuffle(p.deck)
            p.hand.append(p.deck.pop(0))

    # ---- one election ----------------------------------------------------
    def play_round(self):
        e = self.election()
        commits, wishes = {}, {}
        for p in self.players:
            name, _, buy = p.strategy.partition("/")
            plays = STRATEGIES[name](self, p) if p.hand else []
            self.validate(p, plays)
            commits[p.seat] = plays
            wishes[p.seat] = BUYERS[buy or DEFAULT_BUY.get(name, "mixed")](self, p, plays)
            if not p.hand:
                p.empty_rounds += 1

        pos = {"nation": {}, "states": {}}     # side -> seat -> influence played for him
        neg = {"nation": {}, "states": {}}     # side -> seat -> influence played against him
        for p in self.players:
            for cid, act, side in commits[p.seat]:
                p.hand.remove(cid)
                if act == "bury":
                    p.money += self.profit(cid) * (self.cfg["patron_multiplier"] if p.patron else 1)
                    p.buried += 1
                    continue
                # Played: spent, and known for it (prestige banked below,
                # once we know whether it is the story the paper keeps).
                book = pos if act == "pos" else neg
                book[side][p.seat] = book[side].get(p.seat, 0) + self.influence(cid, side, e)
                if act == "pos":
                    p.ran += 1
                else:
                    p.negs += 1

        totals = {}
        for s in SIDES:
            net = sum(pos[s].values()) - sum(neg[s].values())
            totals[s] = max(0, net) if self.cfg["floor_zero"] else net
        if totals["nation"] != totals["states"]:
            winner, decided = ("nation" if totals["nation"] > totals["states"] else "states"), "influence"
        else:
            winner, decided = e["historical_winner"], "history"
        loser = "states" if winner == "nation" else "nation"
        influence = {winner: dict(pos[winner])}
        if self.cfg["neg_patron"]:
            for seat, v in neg[loser].items():
                influence[winner][seat] = influence[winner].get(seat, 0) + v
        best = max(influence[winner].values(), default=0)
        top = [s for s, v in influence[winner].items() if v == best and best > 0]
        patron = top[0] if len(top) == 1 else None
        prev = next((p.seat for p in self.players if p.patron), None)
        for p in self.players:
            p.patron = (p.seat == patron)
            p.patronages += p.patron

        # Stories buried go home to be buried again. Every paper but the new
        # Patron keeps one story it played: back to hand, no prestige for
        # it. Every other story played is spent, and its prestige banked.
        for p in self.players:
            played = [c for c, a, _ in commits[p.seat] if a != "bury"]
            kept = None
            if self.cfg["reserve"] and played and p.seat != patron:
                if self.cfg["reserve_pick"] == "prestige":
                    kept = max(played, key=lambda c: (self.prestige(c), base(c)["influence"]))
                else:
                    kept = max(played, key=lambda c: (base(c)["influence"], self.prestige(c)))
                p.hand.append(kept)
                p.kept += 1
            for cid, act, _ in commits[p.seat]:
                if act == "bury":
                    p.discard.append(cid)
                elif cid != kept:
                    p.prestige += self.prestige(cid)
        for p in self.players:
            p.money += self.cfg["income"]
        self.buy(wishes)
        self.history.append(dict(space=self.space, year=e["year"], era=e["era"], winner=winner,
                                 decided=decided, patron=patron, repeat=(patron is not None and patron == prev),
                                 contested=len(influence[winner]) > 1, total=totals[winner],
                                 matched=(winner == e["historical_winner"]), exchange=len(self.exchange)))

        # The next election.
        self.space += 1
        if self.space >= len(ELECTIONS):
            self.ended = True
            return
        new_era = self.era() != e["era"]
        if new_era:
            old = e["era"]
            for p in self.players:
                keep = lambda cid: base(cid)["era"] != old
                before = len(p.owned())
                p.hand = [c for c in p.hand if keep(c)]
                p.deck = [c for c in p.deck if keep(c)]
                p.discard = [c for c in p.discard if keep(c)]
                p.lost += before - len(p.owned())
            self.exchange = [c for c in self.exchange if base(c)["era"] != old]
            self.supply = [c for c in self.supply if base(c)["era"] != old]
        self.release(YEARS[self.space])
        if len(self.exchange) < self.cfg["exchange_size"]:
            self.exchange_short += 1
        for p in self.players:
            self.draw(p, self.cfg["draw_per_round"])
            if new_era and self.cfg["era_topup"] and len(p.hand) < self.cfg["start_hand"]:
                self.draw(p, self.cfg["start_hand"] - len(p.hand))

    def validate(self, p, plays):
        ids = [c for c, _, _ in plays]
        assert len(set(ids)) == len(ids) and all(c in p.hand for c in ids), (p.strategy, ids)
        for cid, act, side in plays:
            assert act in ("pos", "neg", "bury"), act
            if act != "bury":
                assert side in SIDES and base(cid)["kind"] == "news", (p.strategy, cid)

    def buy(self, wishes):
        """Sealed buys, poorest paper first (a coin breaks a tie)."""
        order = sorted(self.players, key=lambda p: (p.money, self.rng.random()))
        for p in order:
            got = 0
            for cid in wishes.get(p.seat, []):
                if got >= self.cfg["max_buys"]:
                    break
                if cid in self.exchange and p.money >= self.price(cid):
                    self.exchange.remove(cid)
                    p.money -= self.price(cid)
                    p.discard.append(cid)
                    p.bought[base(cid)["era"]] += 1
                    got += 1
        self.refill()

    def run(self):
        while not self.ended:
            self.play_round()
        return self


# =====================================================================
# Strategies: f(game, player) -> [(card, 'pos'|'neg'|'bury', side|None)]
#   side is the candidate the story is PLAYED ON: 'pos' adds to him,
#   'neg' subtracts from him.
# =====================================================================

def best_play(game, cid, side, allow_neg=True):
    """How this story helps `side` win most: positive on him, or negative on
    his rival. Returns (influence, act, played_on)."""
    rival = "states" if side == "nation" else "nation"
    options = [(game.influence(cid, side), "pos", side)]
    if allow_neg:
        options.append((game.influence(cid, rival), "neg", rival))
    return max(options, key=lambda o: (o[0], o[1] == "pos"))


def choose_side(game, hand, allow_neg=True):
    """The candidate whose theme this hand fits best, played positive (a coin
    breaks a tie). With negative plays every story helps either man about
    equally, so following history -- or the most reach -- herds every paper
    onto the same candidate."""
    reach = {s: sum(game.influence(c, s) for c in hand) for s in SIDES}
    if reach["nation"] != reach["states"]:
        return max(SIDES, key=lambda s: reach[s])
    return game.rng.choice(SIDES)


def news(hand):
    return [c for c in hand if base(c)["kind"] == "news"]


def make_bot(target=4, keep_hand=3, patron_keep=1, allow_neg=True, only_neg=False):
    """
      1. 1860, or the last election a story's era allows: play it (its
         prestige is lost otherwise). Money is worth nothing by then.
      2. Patron? Bury everything else (double purchasing power), keeping
         `patron_keep` stories to bid with next round.
      3. Otherwise back the candidate the hand helps most: play the stories
         worth more played than buried (prestige high, profit low) until the
         influence reaches `target` -- positive on him, or negative on his
         rival, whichever helps more.
      4. Bury the rest (they come back), keeping `keep_hand` stories in hand
         for next round's bid.
    """
    def strat(game, p):
        hand = list(p.hand)
        plays, used = [], set()
        side = choose_side(game, news(hand), allow_neg)
        for c in news(hand):
            if game.expires_now(c):
                inf, act, on = best_play(game, c, side, allow_neg)
                if only_neg and allow_neg:
                    act, on = "neg", ("states" if side == "nation" else "nation")
                plays.append((c, act, on))
                used.add(c)
        if game.last_round():
            return plays
        rest = [c for c in hand if c not in used]
        if p.patron:
            keep = sorted(news(rest), key=lambda c: (-game.prestige(c), base(c)["profit"]))[:patron_keep]
            return plays + [(c, "bury", None) for c in rest if c not in keep]
        options = sorted(news(rest), key=lambda c: (base(c)["profit"] - game.prestige(c),
                                                    -best_play(game, c, side, allow_neg)[0]))
        inf = 0
        for c in options:
            if inf >= target:
                break
            got, act, on = best_play(game, c, side, allow_neg)
            if only_neg and allow_neg:
                rival = "states" if side == "nation" else "nation"
                got, act, on = game.influence(c, rival), "neg", rival
            plays.append((c, act, on))
            used.add(c)
            inf += got
        left = sorted([c for c in hand if c not in used], key=lambda c: (-base(c)["profit"], game.prestige(c)))
        keepers = set(sorted(news(left), key=lambda c: (-game.prestige(c), base(c)["profit"]))[:keep_hand])
        for c in left:
            if c not in keepers:
                plays.append((c, "bury", None))
        return plays
    return strat


def strat_banker(game, p):
    """Buries everything to build money and buys; plays a story only when it
    would otherwise be lost (era end, 1860)."""
    plays = []
    side = choose_side(game, news(p.hand))
    for c in p.hand:
        if base(c)["kind"] == "news" and game.expires_now(c):
            _, act, on = best_play(game, c, side)
            plays.append((c, act, on))
        elif not game.last_round():
            plays.append((c, "bury", None))
    return plays


def strat_spender(game, p):
    """Plays every story it can, every round; buries only trade stories."""
    side = choose_side(game, news(p.hand))
    plays = []
    for c in p.hand:
        if base(c)["kind"] == "news":
            _, act, on = best_play(game, c, side)
            plays.append((c, act, on))
        elif not game.last_round():
            plays.append((c, "bury", None))
    return plays


STRATEGIES = {
    "hunter": make_bot(target=4, keep_hand=3, patron_keep=1),     # bid for the Patronage, positive or negative
    "positive": make_bot(target=4, keep_hand=3, patron_keep=1, allow_neg=False),
    "negative": make_bot(target=4, keep_hand=3, patron_keep=1, only_neg=True),
    "steady": make_bot(target=3, keep_hand=1, patron_keep=1),     # smaller bids, buries more
    "bidder": make_bot(target=7, keep_hand=5, patron_keep=2),     # overbids for the Patronage
    "banker": strat_banker,
    "spender": strat_spender,
}


# =====================================================================
# Buy policies: f(game, player, plays) -> wished exchange stories, best first
# =====================================================================

def make_buyer(rank):
    def buyer(game, p, plays):
        mult = game.cfg["patron_multiplier"] if p.patron else 1
        cash = p.money + sum(game.profit(c) * mult for c, a, _ in plays if a == "bury")
        ok = [c for c in game.exchange if game.price(c) <= cash and game.usable_later(c)]
        return sorted(ok, key=lambda c: rank(game, c))
    return buyer


def infl(c):
    return base(c)["influence"] + base(c)["theme_bonus"]


BUYERS = {
    "none": lambda game, p, plays: [],
    "prestige": make_buyer(lambda g, c: (-g.prestige(c) / g.price(c), -g.prestige(c))),
    "influence": make_buyer(lambda g, c: (-infl(c) / g.price(c), -infl(c))),
    "mixed": make_buyer(lambda g, c: (-(g.prestige(c) + infl(c)) / g.price(c), -g.prestige(c))),
    "cheap": make_buyer(lambda g, c: (g.price(c), -g.prestige(c))),
    "dear": make_buyer(lambda g, c: (-g.price(c), -g.prestige(c))),
    "trade": make_buyer(lambda g, c: (base(c)["kind"] != "trade", -base(c)["profit"] / g.price(c))),
    # next-era stories first: they survive the era change
    "ahead": make_buyer(lambda g, c: (ERA_RANK[base(c)["era"]] <= ERA_RANK[g.era()],
                                      -(g.prestige(c) + infl(c)) / g.price(c))),
}
DEFAULT_BUY = {}


# =====================================================================
# Experiments
# =====================================================================

def run_matchup(strategies, games, config=None, seed=0):
    rng = random.Random(seed)
    wins = [0.0] * len(strategies)
    prestige = [[] for _ in strategies]
    stats = [dict(patronages=[], bought=[], lost=[], empty=[], money=[]) for _ in strategies]
    history, short = [], 0
    for _ in range(games):
        g = Game(list(strategies), config, random.Random(rng.randrange(1 << 30))).run()
        history.extend(g.history)
        short += g.exchange_short
        top = max(p.prestige for p in g.players)
        leaders = [p for p in g.players if p.prestige == top]
        if len(leaders) > 1:                         # tie: most purchasing power left
            m = max(p.money for p in leaders)
            leaders = [p for p in leaders if p.money == m]
        for p in leaders:
            wins[p.seat] += 1 / len(leaders)
        for p in g.players:
            prestige[p.seat].append(p.prestige)
            st = stats[p.seat]
            st["patronages"].append(p.patronages)
            st["bought"].append(dict(p.bought))
            st["lost"].append(p.lost)
            st["empty"].append(p.empty_rounds)
            st["money"].append(p.money)
            st.setdefault("plays", []).append((p.ran, p.negs, p.buried))
    return dict(strategies=strategies, games=games, wins=wins, prestige=prestige, stats=stats,
                history=history, short=short)


def pct(x):
    return "%5.1f%%" % (100 * x)


def mean(xs):
    return statistics.mean(xs) if xs else 0


def rotated(field, games, config, seed):
    """Every strategy in every seat: returns win share per strategy name."""
    n = len(field)
    won = {s: 0.0 for s in field}
    per = max(1, games // n)
    for rot in range(n):
        seats = field[rot:] + field[:rot]
        r = run_matchup(seats, per, config, seed + rot)
        for s, w in zip(seats, r["wins"]):
            won[s] += w
    return {s: won[s] / (per * n) for s in field}, per * n


def report(games, seed, config=None):
    print("=" * 78)
    print("HEADS-UP: row's win rate against column (%d games, seats alternated)" % games)
    print("=" * 78)
    field = ["hunter", "positive", "negative", "steady", "bidder", "banker", "spender"]
    print("  %-10s" % "" + "".join("%9s" % c for c in field))
    for a in field:
        row = "  %-10s" % a
        for b in field:
            if a == b:
                row += "%9s" % "-"
                continue
            share, _ = rotated([a, b], games, config, seed)
            row += "%9s" % pct(share[a])
        print(row)
    print()

    print("=" * 78)
    print("BUY POLICIES: one hunter/<policy> among hunter/mixed (fair = 1/n)")
    print("=" * 78)
    for n in (2, 3, 4):
        row = []
        for pol in ("none", "prestige", "influence", "cheap", "dear", "trade", "ahead"):
            field_ = ["hunter/" + pol] + ["hunter/mixed"] * (n - 1)
            won = 0.0
            per = max(1, games // n)
            for rot in range(n):
                seats = field_[rot:] + field_[:rot]
                r = run_matchup(seats, per, config, seed + rot)
                won += r["wins"][seats.index("hunter/" + pol)]
            row.append("%s %s" % (pol, pct(won / (per * n))))
        print("  %d seats (fair %s): %s" % (n, pct(1 / n), "  ".join(row)))
    print()

    print("=" * 78)
    print("TABLES OF HUNTERS, and one of each other strategy among them")
    print("=" * 78)
    for n in (2, 3, 4, 5):
        r = run_matchup(["hunter"] * n, games, config, seed)
        h = r["history"]
        seat_wins = "  ".join(pct(w / games) for w in r["wins"])
        pr = [x for xs in r["prestige"] for x in xs]
        winners = [max(r["prestige"][s][i] for s in range(n)) for i in range(games)]
        bought = [sum(b.values()) for st in r["stats"] for b in st["bought"]]
        by_era = {e: mean([b[e] for st in r["stats"] for b in st["bought"]]) for e in ("I", "II", "III")}
        print("  %d hunters   seat win rates %s" % (n, seat_wins))
        print("    prestige: mean %.1f, winner %.1f, winner's margin over 2nd %.1f"
              % (mean(pr), mean(winners),
                 mean([sorted([r["prestige"][s][i] for s in range(n)])[-1]
                       - sorted([r["prestige"][s][i] for s in range(n)])[-2] for i in range(games)]) if n > 1 else 0))
        print("    bought per paper %.1f (Era I %.1f, II %.1f, III %.1f); lost at era changes %.1f; empty-hand rounds %.1f"
              % (mean(bought), by_era["I"], by_era["II"], by_era["III"],
                 mean([x for st in r["stats"] for x in st["lost"]]),
                 mean([x for st in r["stats"] for x in st["empty"]])))
        print("    elections: decided by influence %s, matched history %s, contested Patron %s, same Patron twice %s"
              % (pct(mean([x["decided"] == "influence" for x in h])), pct(mean([x["matched"] for x in h])),
                 pct(mean([x["contested"] for x in h])), pct(mean([x["repeat"] for x in h]))))
        pl = [x for st in r["stats"] for x in st["plays"]]
        print("    per paper a game: played positive %.1f, negative %.1f, buried %.1f"
              % (mean([x[0] for x in pl]), mean([x[1] for x in pl]), mean([x[2] for x in pl])))
        print("    Patronages per paper: %s   exchange short after release: %.1f rounds a game"
              % (" / ".join("%.1f" % mean(st["patronages"]) for st in r["stats"]), r["short"] / games))
        others = []
        for s in ("positive", "negative", "steady", "bidder", "banker", "spender"):
            share, _ = rotated([s] + ["hunter"] * (n - 1), games, config, seed)
            others.append("%s %s" % (s, pct(share[s])))
        print("    one of these among hunters (fair %s): %s" % (pct(1 / n), "  ".join(others)))
        print()


def sweep(games, seed):
    print("=" * 78)
    print("SWEEP: one of each line among two hunters (fair 3-seat = 33.3%)")
    print("=" * 78)
    knobs = [
        ("neg_patron", (True, False)),
        ("floor_zero", (True, False)),
        ("patron_multiplier", (1, 2, 3)),
        ("start_money", (0, 6, 12)),
        ("bonus", (0, 1, 2, 3)),
        ("cost_add", (-2, 0, 2)),
        ("max_buys", (1, 2)),
        ("prestige_by_era", ({"I": 1, "II": 1, "III": 1}, {"I": 1, "II": 2, "III": 3}, {"I": 1, "II": 3, "III": 5})),
    ]
    lines = ("positive", "negative", "banker", "spender", "hunter/none")
    for knob, values in knobs:
        for v in values:
            cfg = {knob: v}
            r = run_matchup(["hunter"] * 3, games, cfg, seed)
            got = []
            for s in lines:
                sh, _ = rotated([s, "hunter", "hunter"], games, cfg, seed)
                got.append("%s %s" % (s.replace("hunter/none", "non-buyer"), pct(sh[s])))
            label = v if not isinstance(v, dict) else "/".join(str(v[e]) for e in ("I", "II", "III"))
            print("  %-17s %-6s %s | contested %s, winner prestige %.1f"
                  % (knob, label, "  ".join(got), pct(mean([x["contested"] for x in r["history"]])),
                     mean([max(r["prestige"][s][i] for s in range(3)) for i in range(games)])))
        print()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--games", type=int, default=600)
    ap.add_argument("--seed", type=int, default=20260928)
    ap.add_argument("--sweep", action="store_true")
    args = ap.parse_args()
    news = sum(1 for c in CARDS.values() if not c["starter"])
    by_era = {e: sum(1 for c in CARDS.values() if not c["starter"] and c["era"] == e) for e in ("I", "II", "III")}
    print()
    print("Read %d stories (Era I %d, II %d, III %d) and %d starter kinds from docs/deck-v2.csv; %d elections"
          % (news, by_era["I"], by_era["II"], by_era["III"], len(STARTERS), len(ELECTIONS)))
    print()
    if args.sweep:
        sweep(args.games, args.seed)
    else:
        report(args.games, args.seed)


if __name__ == "__main__":
    main()
