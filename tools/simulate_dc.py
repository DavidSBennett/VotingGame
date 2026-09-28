"""Playout harness for the VARIANT as a DC Deck-Building (Heroes Unite) game.

The game in one paragraph: papers take TURNS. Each paper starts with 7
Letters to the Editor (+1 influence) and 3 Local Notices (nothing), draws
5, plays its whole hand, and spends the influence it made. Influence comes
in four kinds: plain influence spends on anything; POLITICAL, ECONOMIC and
SOCIAL influence spend only on stories of that theme or on electing a
candidate of that theme; Campaign spends only on elections. With it the
paper may ELECT the current election (once a turn): reach either man's
threshold and take the election card as his Patron -- it goes into the
deck and pays its bonus whenever played. Then it buys stories off the
exchange (5 face up, refilled from the main deck) or an Editorial (always
available). Negative stories attack rivals (discard, or gain a Scandal:
-1 prestige). Media events stay in play for good: a bonus to the owner,
a smaller one to everyone else. Stories enter the main deck when the year
of the election in progress reaches them. The game ends when 1860 is
decided; most prestige on the cards you own wins.

Content: docs/deck-dc.csv and docs/elections-dc.csv (edit either; the next
run picks it up).

    py -X utf8 tools/simulate_dc.py                  # the standard report
    py -X utf8 tools/simulate_dc.py --games 1000
"""

import argparse
import csv
import os
import random
import statistics

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")
THEMES = ("Political", "Economic", "Social")
INT = ("cost", "vp", "gen", "themed", "campaign", "draw", "trash", "gain_upto", "chain", "per_same",
       "per_office", "defense", "ongoing_gen", "ongoing_draw", "others_bonus", "copies")


def load():
    cards = {}
    with open(os.path.join(DOCS, "deck-dc.csv"), encoding="utf-8-sig") as fh:
        for r in csv.DictReader(fh):
            c = dict(r)
            for f in INT:
                c[f] = int(r[f] or 0)
            c["theme"] = r["theme"] or None
            c["released"] = int(r["released"]) if r["released"] else None
            cards[c["key"]] = c
    elections = []
    with open(os.path.join(DOCS, "elections-dc.csv"), encoding="utf-8-sig") as fh:
        for r in csv.DictReader(fh):
            e = dict(r)
            for f in ("space", "year", "vp", "nation_threshold", "states_threshold", "patron_gen", "patron_themed"):
                e[f] = int(r[f])
            elections.append(e)
    return cards, elections


CARDS, ELECTIONS = load()
assert len(ELECTIONS) == 17
STORIES = [k for k, c in CARDS.items() if c["released"]]
for k in STORIES:
    assert CARDS[k]["released"] in [e["year"] for e in ELECTIONS], k


def card(cid):
    """'letter#0.3' -> the letter; 'elec#4:nation' -> a synthetic election card."""
    if cid.startswith("elec#"):
        i, side = cid[5:].split(":")
        e = ELECTIONS[int(i)]
        return dict(key=cid, name="%s %s" % (e["year"], e[side]), type="Election", theme=e[side + "_theme"],
                    vp=e["vp"], gen=e["patron_gen"], themed=e["patron_themed"], cost=0,
                    attack="", others_theme="", released=None,
                    **{f: 0 for f in INT if f not in ("vp", "gen", "themed", "cost")})
    return CARDS[cid.split("#")[0]]


DEFAULTS = dict(hand=5, exchange_size=5, max_rounds=60, unhistorical_extra=None,
                threshold_add=0,
                draw_cap=None,              # cap every story's draw
                political_any=False,        # Political influence counts toward ANY candidate
                catchup=0)                  # later seats: +1 influence per seat on their first turn


class Player:
    def __init__(self, seat, strategy):
        self.seat, self.strategy = seat, strategy
        self.deck = ["letter#%d.%d" % (seat, i) for i in range(7)] + ["notice#%d.%d" % (seat, i) for i in range(3)]
        self.hand, self.discard, self.locations = [], [], []
        self.stats = dict(turns=0, elected=0, bought=0, attacks=0, scandals=0, trashed=0,
                          gen=0, themed=0, draws=0, unhistorical=0)

    def owned(self):
        return self.deck + self.hand + self.discard + self.locations

    def offices(self):
        return sum(1 for c in self.owned() if c.startswith("elec#"))

    def prestige(self):
        return sum(card(c)["vp"] for c in self.owned())


class Game:
    def __init__(self, strategies, config=None, rng=None):
        self.cfg = dict(DEFAULTS)
        if config:
            self.cfg.update(config)
        self.rng = rng or random.Random()
        self.players = [Player(i, s) for i, s in enumerate(strategies)]
        for p in self.players:
            self.rng.shuffle(p.deck)
            self.draw(p, self.cfg["hand"])
        self.e = 0                         # the election in progress
        self.main = []
        self.release(ELECTIONS[0]["year"])
        self.exchange = []
        self.refill()
        self.editorials = CARDS["editorial"]["copies"]
        self.scandals = CARDS["scandal"]["copies"]
        self.ended, self.rounds = None, 0
        self.log = []                       # (year, seat, side, turn)

    def release(self, year):
        fresh = [k for k in STORIES if CARDS[k]["released"] == year]
        self.main += fresh
        self.rng.shuffle(self.main)

    def refill(self):
        while len(self.exchange) < self.cfg["exchange_size"] and self.main:
            self.exchange.append(self.main.pop())

    def draw(self, p, n):
        got = []
        for _ in range(n):
            if not p.deck:
                if not p.discard:
                    break
                p.deck, p.discard = p.discard, []
                self.rng.shuffle(p.deck)
            c = p.deck.pop()
            p.hand.append(c)
            got.append(c)
        return got

    def threshold(self, side):
        e = ELECTIONS[self.e]
        t = e[side + "_threshold"] + self.cfg["threshold_add"]
        if self.cfg["unhistorical_extra"] is not None and side != e["historical_winner"]:
            t = e[side + "_threshold"] - 2 + self.cfg["unhistorical_extra"] + self.cfg["threshold_add"]
        return t

    # ---- one turn ---------------------------------------------------------
    def turn(self, p):
        bot = BOTS[p.strategy]
        p.stats["turns"] += 1
        gen, campaign = 0, 0
        if p.stats["turns"] == 1:
            gen += self.cfg["catchup"] * p.seat
        themed = {t: 0 for t in THEMES}
        # Media events in play: the owner's bonus, and everyone else's.
        for loc in p.locations:
            c = card(loc)
            gen += c["ongoing_gen"]
            p.stats["draws"] += len(self.draw(p, c["ongoing_draw"]))
        for q in self.players:
            if q is p:
                continue
            for loc in q.locations:
                c = card(loc)
                if c["others_theme"]:
                    themed[c["others_theme"]] += c["others_bonus"]
                else:
                    gen += c["others_bonus"]

        played = []
        while p.hand:
            cid = p.hand.pop(0)
            c = card(cid)
            played.append(cid)
            gen += c["gen"]
            if c["theme"]:
                themed[c["theme"]] += c["themed"]
            campaign += c["campaign"]
            n_draw = c["draw"] if self.cfg["draw_cap"] is None else min(c["draw"], self.cfg["draw_cap"])
            if n_draw:
                p.stats["draws"] += len(self.draw(p, n_draw))
            if c["trash"]:
                self.trash(p, bot)
            if c["gain_upto"]:
                pick = bot.choose(self, p, [x for x in self.exchange if card(x)["cost"] <= c["gain_upto"]])
                if pick:
                    self.exchange.remove(pick)
                    p.discard.append(pick)
                    self.refill()
            if c["attack"]:
                p.stats["attacks"] += 1
                for q in self.players:
                    if q is not p:
                        self.attack(q, c["attack"])
        by_theme = {t: sum(1 for x in played if card(x)["theme"] == t and card(x)["type"] != "Election") for t in THEMES}
        for cid in played:
            c = card(cid)
            if c["per_same"]:
                gen += c["per_same"] * max(0, by_theme[c["theme"]] - 1)
            if c["chain"] and by_theme[c["theme"]] >= 2:
                gen += c["chain"]
            if c["per_office"]:
                themed[c["theme"]] += c["per_office"] * p.offices()
        p.stats["gen"] += gen
        p.stats["themed"] += sum(themed.values())

        # Elect (once a turn): the cheaper man to reach, history breaking a tie.
        e = ELECTIONS[self.e]
        best = None
        for side in ("nation", "states"):
            th = e[side + "_theme"]
            need = self.threshold(side)
            extra = themed["Political"] if self.cfg["political_any"] and th != "Political" else 0
            if themed[th] + campaign + extra + gen >= need:
                spend_gen = max(0, need - themed[th] - campaign - extra)
                rank = (spend_gen, side != e["historical_winner"])
                if best is None or rank < best[0]:
                    best = (rank, side, th, need)
        if best and bot.will_elect(self, p, best[1]):
            _, side, th, need = best
            use = min(themed[th], need)
            themed[th] -= use
            need -= use
            use = min(campaign, need)
            need -= use
            if self.cfg["political_any"] and th != "Political":
                use = min(themed["Political"], need)
                themed["Political"] -= use
                need -= use
            gen -= need
            p.discard.append("elec#%d:%s" % (self.e, side))
            p.stats["elected"] += 1
            p.stats["unhistorical"] += side != e["historical_winner"]
            self.log.append((e["year"], p.seat, side, self.rounds))
            self.e += 1
            if self.e >= len(ELECTIONS):
                self.ended = "1860 decided"
            else:
                self.release(ELECTIONS[self.e]["year"])
                self.refill()

        # Buy.
        while True:
            options = []
            for x in self.exchange:
                c = card(x)
                pay = gen + (themed[c["theme"]] if c["theme"] else 0)
                if pay >= c["cost"]:
                    options.append(x)
            if self.editorials and gen >= CARDS["editorial"]["cost"]:
                options.append("editorial")
            pick = bot.choose(self, p, options, buying=True)
            if not pick:
                break
            c = card(pick)
            cost = c["cost"]
            if c["theme"]:
                use = min(themed[c["theme"]], cost)
                themed[c["theme"]] -= use
                cost -= use
            gen -= cost
            if pick == "editorial":
                self.editorials -= 1
                pick = "editorial#%d" % self.editorials
            else:
                self.exchange.remove(pick)
            p.discard.append(pick)
            p.stats["bought"] += 1
        self.refill()

        for cid in played:
            if card(cid)["type"] == "Media event":
                p.locations.append(cid)
            else:
                p.discard.append(cid)
        self.draw(p, self.cfg["hand"])

    def trash(self, p, bot):
        order = [c for c in p.hand + p.discard if card(c)["type"] == "Scandal"]
        order += [c for c in p.hand + p.discard if c.startswith("notice#")]
        if bot.trash_letters(self, p):
            order += [c for c in p.discard if c.startswith("letter#")]
        if order:
            c = order[0]
            (p.hand if c in p.hand else p.discard).remove(c)
            p.stats["trashed"] += 1

    def attack(self, q, kind):
        shield = next((c for c in q.hand if card(c)["defense"]), None)
        if shield:
            q.hand.remove(shield)
            q.discard.append(shield)
            self.draw(q, 1)
            return
        if kind == "discard" and q.hand:
            c = self.rng.choice(q.hand)
            q.hand.remove(c)
            q.discard.append(c)
        elif kind == "scandal" and self.scandals:
            self.scandals -= 1
            q.discard.append("scandal#%d" % self.scandals)
            q.stats["scandals"] += 1

    def run(self):
        while not self.ended and self.rounds < self.cfg["max_rounds"]:
            self.rounds += 1
            for p in self.players:
                self.turn(p)
                if self.ended:
                    break
        if not self.ended:
            self.ended = "stalled"
        return self


# =====================================================================
# Bots
# =====================================================================

def value(c):
    t = c["type"]
    if t == "Media event":
        return 1.2 * c["vp"] + 3 * (c["ongoing_gen"] + 1.3 * c["ongoing_draw"]) - 0.5 * c["others_bonus"]
    v = (1.2 * c["vp"] + c["gen"] + 0.8 * c["themed"] + 0.6 * c["campaign"] + 1.3 * c["draw"]
         + 0.8 * c["trash"] + 0.4 * c["gain_upto"] + 0.6 * c["chain"] + 0.8 * c["per_same"]
         + 1.0 * c["per_office"] + 0.3 * c["defense"])
    if c["attack"] == "scandal":
        v += 1.5
    elif c["attack"] == "discard":
        v += 1.0
    return v


class Bot:
    def __init__(self, focus=None, attack=1.0, big=False, min_value=1.5):
        self.focus, self.attack_w, self.big, self.min_value = focus, attack, big, min_value

    def score(self, game, p, cid):
        c = card(cid)
        v = value(c)
        if self.focus:
            if c["theme"] == self.focus:
                v *= 1.5
            elif c["theme"] or c["type"] == "Editorial":
                v *= 0.85
        if c["attack"]:
            v *= self.attack_w
        if self.big and c["type"] != "Editorial" and c["cost"] < 5:
            v = 0
        # Late in the game only prestige counts.
        if ELECTIONS and game.e >= len(ELECTIONS) - 2:
            v = c["vp"] * 2 + 0.2 * v
        return v

    def choose(self, game, p, options, buying=False):
        if not options:
            return None
        best = max(options, key=lambda x: (self.score(game, p, x), card(x)["cost"]))
        if buying and self.score(game, p, best) < self.min_value:
            return None
        return best

    def will_elect(self, game, p, side):
        return True

    def trash_letters(self, game, p):
        bought = sum(1 for c in p.owned() if not c.startswith(("letter#", "notice#", "scandal#")))
        return bought >= 8


BOTS = {
    "balanced": Bot(),
    "political": Bot(focus="Political"),
    "economic": Bot(focus="Economic"),
    "social": Bot(focus="Social"),
    "attacker": Bot(attack=2.5),
    "pacifist": Bot(attack=0.0),
    "bigmoney": Bot(big=True),
}


# =====================================================================
# Experiments
# =====================================================================

def run_matchup(strategies, games, config=None, seed=0):
    rng = random.Random(seed)
    wins = [0.0] * len(strategies)
    vp = [[] for _ in strategies]
    stats = [[] for _ in strategies]
    ended, rounds, unhist, log = {}, [], 0, 0
    for _ in range(games):
        g = Game(list(strategies), config, random.Random(rng.randrange(1 << 30))).run()
        ended[g.ended] = ended.get(g.ended, 0) + 1
        rounds.append(g.rounds)
        scores = [p.prestige() for p in g.players]
        top = max(scores)
        leaders = [i for i, s in enumerate(scores) if s == top]
        for i in leaders:
            wins[i] += 1 / len(leaders)
        for p in g.players:
            vp[p.seat].append(scores[p.seat])
            stats[p.seat].append(dict(p.stats))
        unhist += sum(1 for y, s, side, t in g.log if side != ELECTIONS[[e["year"] for e in ELECTIONS].index(y)]["historical_winner"])
        log += len(g.log)
    return dict(strategies=strategies, games=games, wins=wins, vp=vp, stats=stats, ended=ended,
                rounds=rounds, unhistorical=unhist / max(1, log))


def pct(x):
    return "%5.1f%%" % (100 * x)


def mean(xs):
    return statistics.mean(xs) if xs else 0


def rotated(field, games, config=None, seed=0):
    """Every seat order: a strategy's win share with seat advantage averaged out."""
    n = len(field)
    won = {}
    count = {}
    per = max(1, games // n)
    for rot in range(n):
        seats = field[rot:] + field[:rot]
        r = run_matchup(seats, per, config, seed + rot)
        for s, w in zip(seats, r["wins"]):
            won[s] = won.get(s, 0) + w
            count[s] = count.get(s, 0) + per
    return {s: won[s] / count[s] for s in won}


def report(games, seed, config=None):
    print("=" * 78)
    print("MIRRORS: seat advantage and game shape (balanced bots)")
    print("=" * 78)
    for n in (2, 3, 4):
        r = run_matchup(["balanced"] * n, games, config, seed)
        st = [x for s in r["stats"] for x in s]
        print("  %d papers: seat wins %s   ended %s" % (n, " / ".join(pct(w / games) for w in r["wins"]), r["ended"]))
        print("    rounds %.1f (turns per paper), prestige %.1f, elections per paper %.1f, rewrote history %s"
              % (mean(r["rounds"]), mean([x for v in r["vp"] for x in v]), mean([x["elected"] for x in st]),
                 pct(r["unhistorical"])))
        print("    per paper: bought %.1f, trashed %.1f, attacks %.1f, scandals taken %.1f"
              % (mean([x["bought"] for x in st]), mean([x["trashed"] for x in st]),
                 mean([x["attacks"] for x in st]), mean([x["scandals"] for x in st])))
    print()
    print("=" * 78)
    print("THEME DECKS: one focused paper among balanced ones (fair = 1/n)")
    print("=" * 78)
    for n in (2, 3, 4):
        row = []
        for s in ("political", "economic", "social", "attacker", "pacifist", "bigmoney"):
            share = rotated([s] + ["balanced"] * (n - 1), games, config, seed)
            row.append("%s %s" % (s, pct(share[s])))
        print("  %d papers (fair %s): %s" % (n, pct(1 / n), "  ".join(row)))
    print()
    print("  all three themes at one table (fair 33.3%):",
          "  ".join("%s %s" % (k, pct(v)) for k, v in rotated(["political", "economic", "social"], games, config, seed).items()))
    four = rotated(["political", "economic", "social", "attacker"], games, config, seed)
    print("  themes + attacker (fair 25%):", "  ".join("%s %s" % (k, pct(v)) for k, v in four.items()))
    print()
    print("=" * 78)
    print("HOW EACH DECK PLAYS (3 papers: political / economic / social)")
    print("=" * 78)
    r = run_matchup(["political", "economic", "social"], games, config, seed)
    print("  %-10s %8s %9s %9s %8s %8s %8s %8s" % ("", "prestige", "elected", "infl/turn", "themed%", "draws", "bought", "trashed"))
    for i, s in enumerate(r["strategies"]):
        st = r["stats"][i]
        turns = sum(x["turns"] for x in st)
        g_, t_ = sum(x["gen"] for x in st), sum(x["themed"] for x in st)
        print("  %-10s %8.1f %9.1f %9.1f %7.0f%% %8.1f %8.1f %8.1f"
              % (s, mean(r["vp"][i]), mean([x["elected"] for x in st]), (g_ + t_) / max(1, turns),
                 100 * t_ / max(1, g_ + t_), mean([x["draws"] for x in st]), mean([x["bought"] for x in st]),
                 mean([x["trashed"] for x in st])))
    print()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--games", type=int, default=400)
    ap.add_argument("--seed", type=int, default=20260928)
    args = ap.parse_args()
    kinds = {}
    for k in STORIES:
        kinds[CARDS[k]["type"]] = kinds.get(CARDS[k]["type"], 0) + 1
    print()
    print("Read %d stories %s and %d elections" % (len(STORIES), kinds, len(ELECTIONS)))
    print()
    report(args.games, args.seed)


if __name__ == "__main__":
    main()
