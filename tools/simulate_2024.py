"""Playout harness for the 2024 game: the DC-style Fourth Estate played on
the 2024 electoral college.

The game in one paragraph: outlets take TURNS. Each starts with 7 Letters
to the Editor (+1 influence) and 3 Local Notices (nothing), draws 5, plays
its whole hand, and spends the influence it made. Influence comes in four
kinds: plain influence spends on anything; POLITICS, ECONOMY and CULTURE
influence (the beats; Political / Economic / Social in the data) spend only
on stories of that beat or on calling a state of that beat; Campaign spends
only on calls. THE MAP: 4 states lie face up, dealt from the state deck,
which runs from the safest states to the closest (shuffled within each
tier). An outlet may CALL any face-up state it can afford, for Trump or
for Harris: each side has its own threshold (history's winner is cheaper,
by how far history must be rewritten) and its own card, which goes into
the outlet's deck and pays its power whenever played. Then it buys stories
off the exchange (5 face up) or an Editorial. The calendar moves on a step
for every few states called, releasing that step's stories. The game ends
when the last state is called. The score is in electoral votes: a state is
worth its EVs, and a story's prestige is counted as STORY_EV votes per star.
Catch-up: each later seat starts with one more Editorial in its deck (the
second outlet 1, the third 2, the fourth 3). When the last state is
called, the round is played out.

Content: docs/states-2024.csv (tools/build_states_2024.py), and for now
the variant's stories, docs/deck-dc.csv, released on 17 calendar steps in
place of its 17 election years.

    py -X utf8 tools/simulate_2024.py                  # the standard report
    py -X utf8 tools/simulate_2024.py --games 1000
"""

import argparse
import csv
import os
import random
import statistics

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")
THEMES = ("Political", "Economic", "Social")
SIDES = ("trump", "harris")
INT = ("cost", "vp", "gen", "themed", "campaign", "draw", "trash", "gain_upto", "chain", "per_same",
       "per_office", "defense", "ongoing_gen", "ongoing_draw", "others_bonus", "copies", "retract")
TIERS = ("Safe", "Likely", "Lean", "Toss-up")


def load():
    cards = {}
    with open(os.path.join(DOCS, "deck-dc.csv"), encoding="utf-8-sig") as fh:
        for r in csv.DictReader(fh):
            c = dict(r)
            for f in INT:
                c[f] = int(r.get(f) or 0)
            c["theme"] = r["theme"] or None
            c["released"] = int(r["released"]) if r["released"] else None
            cards[c["key"]] = c
    states = {}
    with open(os.path.join(DOCS, "states-2024.csv"), encoding="utf-8-sig") as fh:
        for r in csv.DictReader(fh):
            s = dict(r)
            for f in ("ev", "order"):
                s[f] = int(r[f])
            s["margin"] = float(r["margin"])
            for side in SIDES:
                for f in ("threshold", "gen", "themed", "draw", "trash"):
                    s["%s_%s" % (side, f)] = int(r["%s_%s" % (side, f)])
            states[s["key"]] = s
    return cards, states


CARDS, STATES = load()
with open(os.path.join(DOCS, "papers-dc.csv"), encoding="utf-8-sig") as fh:
    PAPERS = {r["key"]: r for r in csv.DictReader(fh)}      # the Super Heroes: one per player
assert len(STATES) == 51 and sum(s["ev"] for s in STATES.values()) == 538
STORIES = [k for k, c in CARDS.items() if c["released"]]
# The calendar: the variant's 17 release years become 17 steps.
STEPS = sorted({CARDS[k]["released"] for k in STORIES})
STEP_OF = {k: STEPS.index(CARDS[k]["released"]) for k in STORIES}
STORY_EV = 6        # electoral votes per star of a story's prestige


def card(cid):
    """'letter#0.3' -> the letter; 'st#pa:harris' -> Pennsylvania called for Harris."""
    if cid.startswith("st#"):
        key, side = cid[3:].split(":")
        s = STATES[key]
        c = dict(key=cid, name="%s for %s" % (s["state"], side.title()), type="State", theme=s[side + "_theme"],
                 vp=s["ev"], cost=0, others_theme="", released=None, attack="",
                 **{f: 0 for f in INT if f not in ("vp", "cost")})
        c.update(gen=s[side + "_gen"], themed=s[side + "_themed"], draw=s[side + "_draw"],
                 trash=s[side + "_trash"], trash_draw=0)
        return c
    return CARDS[cid.split("#")[0]]


DEFAULTS = dict(hand=5, exchange_size=5, max_rounds=80,
                map_size=4,                 # face-up states
                max_calls=None,             # states an outlet may call a turn (None: any it can afford)
                order="tiers",              # state deck: 'tiers' (safe -> toss-up, shuffled within) / 'fixed' / 'random'
                calls_per_step=3,           # states called per calendar step
                state_to_deck=True,         # a called state's card goes into the deck (else it only scores)
                story_ev=STORY_EV,
                office_div=3,               # per-office stories count one office per this many states
                threshold_add=0,
                threshold_mult=1.0,
                papers=True,
                power_choice=0.5,
                intelligencer="all",
                sun_junk="scandal", sun_draw=3,
                journal_second="gen",
                north_star_at=2, north_star_draw=1,
                argus_ev=2,                 # the Argus: electoral votes per state called
                herald_cost=2, herald_to_hand=True,
                globe_per=0,
                aurora_per=1,
                retract_draw="always",
                political_any=False,
                catchup=0,                  # first-turn influence per seat (replaced by extra_editorials)
                attack_reward=1,
                finish_round=True,
                first_call_round=1,
                rewrite_mult=1.0,
                extra_letters=(0, 0, 0, 0),     # catch-up: Letters added to each seat's starting deck
                first_hand_extra=(0, 0, 0, 0),  # catch-up: extra cards in each seat's first hand
                extra_editorials=(0, 1, 2, 3),  # catch-up: Editorials added to each seat's starting deck
                editorial_in_hand=False)        # ... dealt into the first hand rather than shuffled in         # no state may be called before this round (the primaries)          # when the last state is called, the round is played out


class Player:
    def __init__(self, seat, strategy):
        self.seat = seat
        self.strategy, _, self.paper = strategy.partition("@")    # "political@globe"
        self.paper = self.paper or None
        self.deck = ["letter#%d.%d" % (seat, i) for i in range(7)] + ["notice#%d.%d" % (seat, i) for i in range(3)]
        self.hand, self.discard, self.locations, self.map = [], [], [], []
        self.stats = dict(turns=0, called=0, bought=0, attacks=0, scandals=0, trashed=0, retracted=0,
                          gen=0, themed=0, draws=0, unhistorical=0, ev=0)

    def owned(self):
        return self.deck + self.hand + self.discard + self.locations + self.map

    def states(self):
        return [c for c in self.owned() if c.startswith("st#")]

    def score(self, cfg):
        ev = sum(STATES[c[3:].split(":")[0]]["ev"] for c in self.states())
        if self.paper == "argus":
            ev += cfg["argus_ev"] * len(self.states())
        stars = sum(card(c)["vp"] for c in self.owned() if not c.startswith("st#"))
        return ev + cfg["story_ev"] * stars


class Game:
    def __init__(self, strategies, config=None, rng=None):
        self.cfg = dict(DEFAULTS)
        if config:
            self.cfg.update(config)
        self.rng = rng or random.Random()
        self.players = [Player(i, s) for i, s in enumerate(strategies)]
        if self.cfg["papers"]:
            free = [k for k in PAPERS if k not in {p.paper for p in self.players}]
            self.rng.shuffle(free)
            for p in self.players:
                if p.paper is None:
                    p.paper = free.pop()
        else:
            for p in self.players:
                p.paper = None
        for p in self.players:
            p.deck += ["letter#%d.x%d" % (p.seat, i) for i in range(self.cfg["extra_letters"][p.seat])]
            eds = ["editorial#s%d.%d" % (p.seat, i) for i in range(self.cfg["extra_editorials"][p.seat])]
            self.rng.shuffle(p.deck)
            if self.cfg["editorial_in_hand"]:
                p.hand += eds
            else:
                p.deck += eds
                self.rng.shuffle(p.deck)
            self.draw(p, self.cfg["hand"] + self.cfg["first_hand_extra"][p.seat])
        # The state deck, dealt from the end: the safest states first.
        order = sorted(STATES, key=lambda k: STATES[k]["order"])
        if self.cfg["order"] == "tiers":
            order = []
            for t in TIERS:
                group = [k for k in STATES if STATES[k]["tier"] == t]
                self.rng.shuffle(group)
                order += group
        elif self.cfg["order"] == "random":
            self.rng.shuffle(order)
        self.state_deck = order[::-1]
        self.map = []
        self.deal_map()
        self.called = 0
        self.step = 0
        self.main = []
        self.release(0)
        self.exchange = []
        self.refill()
        self.editorials = CARDS["editorial"]["copies"]
        self.scandals = CARDS["scandal"]["copies"]
        self.ended, self.rounds = None, 0
        self.log = []                       # (state, seat, side, round)

    def deal_map(self):
        while len(self.map) < self.cfg["map_size"] and self.state_deck:
            self.map.append(self.state_deck.pop())

    def release(self, step):
        self.main += [k for k in STORIES if STEP_OF[k] == step]
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

    def threshold(self, key, side):
        s = STATES[key]
        t = s[side + "_threshold"]
        if side != s["winner"]:             # rewriting history: scale the premium over history's side
            won = s[s["winner"] + "_threshold"]
            t = won + round((t - won) * self.cfg["rewrite_mult"])
        return max(1, round(t * self.cfg["threshold_mult"]) + self.cfg["threshold_add"])

    def remaining(self):
        return len(self.map) + len(self.state_deck)

    # ---- one turn ---------------------------------------------------------
    def turn(self, p):
        bot = BOTS[p.strategy]
        p.stats["turns"] += 1
        gen, campaign = 0, 0
        if p.stats["turns"] == 1:
            cu = self.cfg["catchup"]
            gen += cu[p.seat] if isinstance(cu, (list, tuple)) else cu * p.seat
        themed = {t: 0 for t in THEMES}
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

        if p.paper == "sun":
            junk = [c for c in p.hand if card(c)["type"] == "Scandal"]
            if junk:
                p.hand.remove(junk[0])
                p.discard.append(junk[0])
                p.stats["draws"] += len(self.draw(p, self.cfg["sun_draw"]))

        played = []
        count = {t: 0 for t in THEMES}
        star_done = False
        while p.hand:
            cid = p.hand.pop(0)
            c = card(cid)
            played.append(cid)
            if c["theme"] and c["type"] != "State":
                count[c["theme"]] += 1
                if p.paper == "journal" and c["theme"] == "Economic" and count["Economic"] <= 2:
                    gen += 1
                if (p.paper == "north_star" and c["theme"] == "Social"
                        and count["Social"] == self.cfg["north_star_at"] and not star_done):
                    star_done = True
                    p.stats["draws"] += len(self.draw(p, self.cfg["north_star_draw"]))
            gen += c["gen"]
            if c["theme"]:
                themed[c["theme"]] += c["themed"]
            campaign += c["campaign"]
            if c["draw"]:
                p.stats["draws"] += len(self.draw(p, c["draw"]))
            for _ in range(c["trash"]):
                self.trash(p, bot)
            for _ in range(c["retract"]):
                self.retract(p)
            if c["gain_upto"]:
                pick = bot.choose(self, p, [x for x in self.exchange if card(x)["cost"] <= c["gain_upto"]])
                if pick:
                    self.exchange.remove(pick)
                    p.discard.append(pick)
                    self.refill()
            if c["attack"]:
                p.stats["attacks"] += 1
                hits = sum(self.attack(q, c["attack"]) for q in self.players if q is not p)
                gen += self.cfg["attack_reward"] * min(1, hits)
        by_theme = {t: sum(1 for x in played if card(x)["theme"] == t and card(x)["type"] != "State") for t in THEMES}
        offices = len(p.states()) // self.cfg["office_div"]
        for cid in played:
            c = card(cid)
            if c["per_same"]:
                gen += c["per_same"] * max(0, by_theme[c["theme"]] - 1)
            if c["chain"] and by_theme[c["theme"]] >= 2:
                gen += c["chain"]
            if c["per_office"]:
                themed[c["theme"]] += c["per_office"] * offices
        if p.paper == "globe":
            themed["Political"] += self.cfg["globe_per"] * by_theme["Political"]
        if p.paper == "aurora":
            gen += self.cfg["aurora_per"] * len({card(x)["key"] for x in played if card(x)["type"] == "Negative story"})
        p.stats["gen"] += gen
        p.stats["themed"] += sum(themed.values())

        # Call states off the map, the best first, while the influence lasts.
        calls = 0
        while (self.map and self.rounds >= self.cfg["first_call_round"]
               and (self.cfg["max_calls"] is None or calls < self.cfg["max_calls"])):
            any_ = self.cfg["political_any"] or p.paper == "globe"
            best = None
            for key in self.map:
                s = STATES[key]
                for side in SIDES:
                    th = s[side + "_theme"]
                    extra = themed["Political"] if any_ and th != "Political" else 0
                    need = self.threshold(key, side)
                    if themed[th] + campaign + extra + gen < need:
                        continue
                    spend_gen = max(0, need - themed[th] - campaign - extra)
                    rank = (bot.state_value(self, p, key, side) - self.cfg["power_choice"] * spend_gen,
                            side == s["winner"])
                    if best is None or rank > best[0]:
                        best = (rank, key, side, th, need)
            if not best or not bot.will_call(self, p, best[1], best[2], best[0][0]):
                break
            _, key, side, th, need = best
            use = min(themed[th], need)
            themed[th] -= use
            need -= use
            use = min(campaign, need)
            campaign -= use
            need -= use
            if (self.cfg["political_any"] or p.paper == "globe") and th != "Political":
                use = min(themed["Political"], need)
                themed["Political"] -= use
                need -= use
            gen -= need
            cid = "st#%s:%s" % (key, side)
            (p.discard if self.cfg["state_to_deck"] else p.map).append(cid)
            self.map.remove(key)
            self.deal_map()
            calls += 1
            p.stats["called"] += 1
            p.stats["ev"] += STATES[key]["ev"]
            p.stats["unhistorical"] += side != STATES[key]["winner"]
            self.log.append((key, p.seat, side, self.rounds))
            self.called += 1
            step = min(len(STEPS) - 1, self.called // self.cfg["calls_per_step"])
            while self.step < step:
                self.step += 1
                self.release(self.step)
                self.refill()
            if not self.map:
                self.ended = "map called"

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
        if p.paper == "herald" and gen >= self.cfg["herald_cost"] and self.main and bot.scoop(self, p):
            gen -= self.cfg["herald_cost"]
            (p.hand if self.cfg["herald_to_hand"] else p.discard).append(self.main.pop())
            p.stats["bought"] += 1
        self.refill()

        for cid in played:
            (p.locations if card(cid)["type"] == "Media event" else p.discard).append(cid)
        self.draw(p, self.cfg["hand"])

    def retract(self, p):
        if self.cfg["retract_draw"] == "always":
            p.stats["draws"] += len(self.draw(p, 1))
        for pile in (p.hand, p.discard):
            sc = next((c for c in pile if c.startswith("scandal#")), None)
            if sc:
                pile.remove(sc)
                p.stats["retracted"] += 1
                return

    def trash(self, p, bot):
        order = [c for c in p.hand + p.discard if card(c)["type"] == "Scandal"]
        order += [c for c in p.hand + p.discard if c.startswith("notice#")]
        if bot.trash_letters(self, p):
            order += [c for c in p.discard if c.startswith("letter#")]
        if order:
            c = order[0]
            (p.hand if c in p.hand else p.discard).remove(c)
            p.stats["trashed"] += 1
            return True
        return False

    def attack(self, q, kind):
        shield = next((c for c in q.hand if card(c)["defense"]), None)
        if shield:
            q.hand.remove(shield)
            q.discard.append(shield)
            self.draw(q, 1)
            return False
        if kind == "discard" and q.hand:
            c = self.rng.choice(q.hand)
            q.hand.remove(c)
            q.discard.append(c)
            return True
        elif kind == "scandal" and q.paper == "intelligencer":
            return False
        elif kind == "scandal" and self.scandals:
            self.scandals -= 1
            q.discard.append("scandal#%d" % self.scandals)
            q.stats["scandals"] += 1
            return True
        return False

    def run(self):
        while not self.ended and self.rounds < self.cfg["max_rounds"]:
            self.rounds += 1
            for p in self.players:
                if self.ended and not self.cfg["finish_round"]:
                    break
                self.turn(p)
        if not self.ended:
            self.ended = "stalled"
        return self


# =====================================================================
# Bots
# =====================================================================

def value(c):
    """A card's worth to a bot, in stars (a state's votes / STORY_EV)."""
    t = c["type"]
    vp = c["vp"] / STORY_EV if t == "State" else c["vp"]
    if t == "Media event":
        return 1.2 * vp + 3 * (c["ongoing_gen"] + 1.3 * c["ongoing_draw"]) - 0.5 * c["others_bonus"]
    v = (1.2 * vp + c["gen"] + 0.8 * c["themed"] + 0.6 * c["campaign"] + 1.3 * c["draw"]
         + 0.8 * c["trash"] + 0.4 * c["gain_upto"] + 0.6 * c["chain"] + 0.8 * c["per_same"]
         + 1.0 * c["per_office"] + 0.3 * c["defense"] + 0.8 * c.get("retract", 0))
    if c["attack"] == "scandal":
        v += 1.5
    elif c["attack"] == "discard":
        v += 1.0
    return v


class Bot:
    def __init__(self, focus=None, attack=1.0, big=False, min_value=1.5):
        self.focus, self.attack_w, self.big, self.min_value = focus, attack, big, min_value

    def late(self, game):
        return game.remaining() <= game.cfg["map_size"] + 2

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
        if self.late(game):             # late in the game only prestige counts
            v = c["vp"] * 2 + 0.2 * v
        return v

    def state_value(self, game, p, key, side):
        c = card("st#%s:%s" % (key, side))
        v = value(c)
        if game.cfg["state_to_deck"] is False:
            v = 1.2 * c["vp"] / STORY_EV
        return v

    def choose(self, game, p, options, buying=False):
        if not options:
            return None
        best = max(options, key=lambda x: (self.score(game, p, x), card(x)["cost"]))
        if buying and self.score(game, p, best) < self.min_value:
            return None
        return best

    def will_call(self, game, p, key, side, worth):
        return True

    def scoop(self, game, p):
        return not self.late(game)

    def trash_letters(self, game, p):
        bought = sum(1 for c in p.owned() if not c.startswith(("letter#", "notice#", "scandal#", "st#")))
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
    ended, rounds, unhist, calls = {}, [], 0, 0
    harris_270, early_lead_wins, story_share = 0, 0, []
    for _ in range(games):
        g = Game(list(strategies), config, random.Random(rng.randrange(1 << 30))).run()
        ended[g.ended] = ended.get(g.ended, 0) + 1
        rounds.append(g.rounds)
        scores = [p.score(g.cfg) for p in g.players]
        top = max(scores)
        leaders = [i for i, s in enumerate(scores) if s == top]
        for i in leaders:
            wins[i] += 1 / len(leaders)
        for p in g.players:
            vp[p.seat].append(scores[p.seat])
            stats[p.seat].append(dict(p.stats))
            if scores[p.seat] > 0:
                story_share.append(1 - p.stats["ev"] / scores[p.seat])
        unhist += sum(1 for k, s, side, t in g.log if side != STATES[k]["winner"])
        calls += len(g.log)
        harris_270 += sum(STATES[k]["ev"] for k, s, side, t in g.log if side == "harris") >= 270
        # The outlet with the most votes after the first 17 calls (a third of the map).
        early = {}
        for k, s, side, t in g.log[:17]:
            early[s] = early.get(s, 0) + STATES[k]["ev"]
        if early:
            lead = max(early, key=early.get)
            early_lead_wins += (lead in leaders) / len(leaders)
    return dict(strategies=strategies, games=games, wins=wins, vp=vp, stats=stats, ended=ended,
                rounds=rounds, unhistorical=unhist / max(1, calls), harris_270=harris_270 / games,
                early_lead=early_lead_wins / games, story_share=mean(story_share))


def pct(x):
    return "%5.1f%%" % (100 * x)


def mean(xs):
    return statistics.mean(xs) if xs else 0


def rotated(field, games, config=None, seed=0):
    """Every seat order: a strategy's win share with seat advantage averaged out."""
    n = len(field)
    won, count = {}, {}
    per = max(1, games // n)
    for rot in range(n):
        seats = field[rot:] + field[:rot]
        r = run_matchup(seats, per, config, seed + rot)
        for s, w in zip(seats, r["wins"]):
            won[s] = won.get(s, 0) + w
            count[s] = count.get(s, 0) + per
    return {s: won[s] / count[s] for s in won}


def shape(games, seed, config=None, seats=(2, 3, 4)):
    for n in seats:
        r = run_matchup(["balanced"] * n, games, config, seed)
        st = [x for s in r["stats"] for x in s]
        print("  %d outlets: seat wins %s   ended %s" % (n, " / ".join(pct(w / games) for w in r["wins"]), r["ended"]))
        print("    rounds %.1f, score %.0f EV (stories %s of it), states per outlet %.1f (%.0f EV), "
              "rewrote history %s, Harris reached 270 %s"
              % (mean(r["rounds"]), mean([x for v in r["vp"] for x in v]), pct(r["story_share"]),
                 mean([x["called"] for x in st]), mean([x["ev"] for x in st]), pct(r["unhistorical"]),
                 pct(r["harris_270"])))
        print("    per outlet: bought %.1f, trashed %.1f, attacks %.1f, scandals %.1f; early leader wins %s"
              % (mean([x["bought"] for x in st]), mean([x["trashed"] for x in st]),
                 mean([x["attacks"] for x in st]), mean([x["scandals"] for x in st]), pct(r["early_lead"])))


def report(games, seed, config=None):
    print("=" * 78)
    print("MIRRORS: seat advantage and game shape (balanced bots)")
    print("=" * 78)
    shape(games, seed, config)
    print()
    print("=" * 78)
    print("BEAT DECKS: one focused outlet among balanced ones (fair = 1/n)")
    print("=" * 78)
    for n in (3, 4):
        row = []
        for s in ("political", "economic", "social", "attacker", "pacifist", "bigmoney"):
            share = rotated([s] + ["balanced"] * (n - 1), games, config, seed)
            row.append("%s %s" % (s, pct(share[s])))
        print("  %d outlets (fair %s): %s" % (n, pct(1 / n), "  ".join(row)))
    print("  all three beats at one table (fair 33.3%):",
          "  ".join("%s %s" % (k, pct(v)) for k, v in rotated(["political", "economic", "social"], games, config, seed).items()))
    print()
    print("=" * 78)
    print("OUTLETS: each newspaper among two balanced outlets (fair 33.3%)")
    print("=" * 78)
    row = []
    for k in PAPERS:
        share = rotated(["balanced@" + k, "balanced", "balanced"], games, config, seed)
        row.append("%s %s" % (k, pct(share["balanced@" + k])))
    print("  " + "  ".join(row))
    print()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--games", type=int, default=400)
    ap.add_argument("--seed", type=int, default=20261003)
    ap.add_argument("--shape", action="store_true", help="only the game shape")
    args = ap.parse_args()
    print("Read %d stories on %d calendar steps and %d states (%d EV)"
          % (len(STORIES), len(STEPS), len(STATES), sum(s["ev"] for s in STATES.values())))
    print()
    if args.shape:
        shape(args.games, args.seed)
    else:
        report(args.games, args.seed)


if __name__ == "__main__":
    main()
