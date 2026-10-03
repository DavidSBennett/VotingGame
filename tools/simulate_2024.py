"""Playout harness for the 2024 game: the DC-style Fourth Estate played on
the 2024 electoral college.

The game in one paragraph: outlets take TURNS. Each starts with 7 Letters
to the Editor (+1) and 3 Local Notices (nothing) -- later seats also an
Editorial each, one more per seat -- and draws 5. THREE CURRENCIES: neutral
spends on anything; Republican only on Trump (calling or buying a state
for him, or a Republican story), Democratic only on Harris (or a
Democratic story); Campaign only on calling a big state. THE ELECTIONS
DECK: the ten biggest states, shuffled, one up at a time; an outlet may
call it once a turn, for Trump or Harris (history's winner is cheaper),
and gains that side's card. Each call moves the calendar on a step,
releasing that step's stories; the game ends when the tenth is called (the
round is played out). THE MAIN DECK: the other 41 states (all from the
start) and 65 stories; 5 lie face up on the exchange. A state is bought
for a side at that side's threshold and is the strongest card at its
price. STAKE: instead of playing its hand, an outlet may spend its turn
setting one card from its hand aside, face down, on Trump or Harris. At
the end the candidate with the most electoral votes wins (states count
for the side they were called or bought for; states nobody took go as in
2024). The score is in electoral votes: each state you hold is worth its
EVs, a story's prestige counts STORY_EV per star, and a staked card scores
only if its candidate won -- its own worth plus the stake bonus.

Content: docs/states-2024.csv (tools/build_states_2024.py) and
docs/deck-2024.csv (tools/build_deck_2024.py).

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
KINDS = ("Political", "Economic", "Social")          # story kinds (compounding, chains, per office)
PARTIES = ("rep", "dem")
SIDES = ("trump", "harris")
PARTY = {"trump": "rep", "harris": "dem"}
SIDE = {"rep": "trump", "dem": "harris"}
INT = ("cost", "vp", "gen", "themed", "campaign", "draw", "trash", "gain_upto", "chain", "per_same",
       "per_office", "defense", "ongoing_gen", "ongoing_draw", "others_bonus", "copies", "retract")
STORY_EV = 6        # electoral votes per star of a story's prestige


def load():
    cards = {}
    with open(os.path.join(DOCS, "deck-2024.csv"), encoding="utf-8-sig") as fh:
        for r in csv.DictReader(fh):
            c = dict(r)
            for f in INT:
                c[f] = int(r.get(f) or 0)
            c["kind"] = r["theme"] or None
            c["lean"] = r["lean"] or None
            c["step"] = int(r["step"]) if r["step"] else None
            cards[c["key"]] = c
    states = {}
    with open(os.path.join(DOCS, "states-2024.csv"), encoding="utf-8-sig") as fh:
        for r in csv.DictReader(fh):
            s = dict(r)
            for f in ("ev", "order"):
                s[f] = int(r[f])
            s["margin"] = float(r["margin"])
            for side in SIDES:
                for f in ("threshold", "gen", "party", "draw", "trash"):
                    s["%s_%s" % (side, f)] = int(r["%s_%s" % (side, f)])
            states[s["key"]] = s
    return cards, states


CARDS, STATES = load()
with open(os.path.join(DOCS, "papers-dc.csv"), encoding="utf-8-sig") as fh:
    PAPERS = {r["key"]: r for r in csv.DictReader(fh)}      # stand-ins for the 2024 outlets
assert len(STATES) == 51 and sum(s["ev"] for s in STATES.values()) == 538
STORIES = [k for k, c in CARDS.items() if c["step"] is not None]
BIG = [k for k, s in STATES.items() if s["deck"] == "elections"]
SMALL = [k for k, s in STATES.items() if s["deck"] == "main"]
STEPS = len(BIG)


def card(cid):
    """'letter#0.3' -> the letter; 'st#pa:harris' -> Pennsylvania for Harris;
    'st#pa' -> Pennsylvania on the exchange, no side yet."""
    if cid.startswith("st#"):
        key, _, side = cid[3:].partition(":")
        s = STATES[key]
        c = dict(key=cid, name=s["state"], type="State", kind=None, lean=PARTY.get(side), vp=s["ev"], cost=0,
                 others_theme="", step=None, attack="", **{f: 0 for f in INT if f not in ("vp", "cost")})
        if side:
            c.update(name="%s for %s" % (s["state"], side.title()), gen=s[side + "_gen"],
                     themed=s[side + "_party"], draw=s[side + "_draw"], trash=s[side + "_trash"],
                     cost=s[side + "_threshold"])
        return c
    return CARDS[cid.split("#")[0]]


def state_key(cid):
    return cid[3:].split(":")[0]


def state_side(cid):
    return cid.split(":")[1]


DEFAULTS = dict(hand=5, exchange_size=5, max_rounds=80,
                story_ev=STORY_EV,
                office_div=3,               # per-office stories: one office per this many states held
                papers=True,
                power_choice=0.5,           # bots weigh a card's worth against the neutral it costs
                sun_draw=3,
                north_star_at=2, north_star_draw=1,
                argus_ev=6,                 # the Argus: electoral votes per big state called
                herald_cost=2,
                aurora_per=1,
                attack_reward=1,
                finish_round=True,          # when the last big state is called, the round is played out
                extra_editorials=(0, 1, 2, 3),  # catch-up: Editorials in each seat's starting deck
                stake=True,
                stake_bonus=12,             # EV for a staked card on the winner, on top of its own worth
                stake_turn_value=None,      # bots: what a played turn is worth (None: learn it as they go)
                unclaimed="history")        # states nobody took: 'history' (as in 2024) / 'none'


class Player:
    def __init__(self, seat, strategy):
        self.seat = seat
        self.strategy, _, self.paper = strategy.partition("@")    # "balanced@globe"
        self.paper = self.paper or None
        self.deck = ["letter#%d.%d" % (seat, i) for i in range(7)] + ["notice#%d.%d" % (seat, i) for i in range(3)]
        self.hand, self.discard, self.locations = [], [], []
        self.staked = []            # (card, side), face down
        self.stats = dict(turns=0, called=0, bought=0, states_bought=0, attacks=0, scandals=0, trashed=0,
                          gen=0, party=0, draws=0, unhistorical=0, ev=0, stakes=0, stake_won=0, stake_ev=0,
                          played_turns=0, played_ev=0)

    def owned(self):
        return self.deck + self.hand + self.discard + self.locations

    def states(self):
        return [c for c in self.owned() if c.startswith("st#")]

    def card_ev(self, cid, cfg):
        c = card(cid)
        return c["vp"] if c["type"] == "State" else cfg["story_ev"] * c["vp"]

    def score(self, cfg, winner):
        ev = sum(self.card_ev(c, cfg) for c in self.owned())
        if self.paper == "argus":
            ev += cfg["argus_ev"] * sum(1 for c in self.states() if state_key(c) in BIG)
        for cid, side in self.staked:
            if side == winner:
                ev += self.card_ev(cid, cfg) + cfg["stake_bonus"]
        return ev


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
        for p in self.players:
            p.deck += ["editorial#s%d.%d" % (p.seat, i) for i in range(self.cfg["extra_editorials"][p.seat])]
            self.rng.shuffle(p.deck)
            self.draw(p, self.cfg["hand"])
        self.big = list(BIG)
        self.rng.shuffle(self.big)      # the elections deck, one at a time
        self.e = 0
        self.step = 0
        self.main = ["st#" + k for k in SMALL]
        self.release(0)
        self.exchange = []
        self.refill()
        self.editorials = CARDS["editorial"]["copies"]
        self.scandals = CARDS["scandal"]["copies"]
        self.ended, self.rounds = None, 0
        self.log = []                   # (state, seat, side, round, 'call' / 'buy')
        self.turn_ev = []               # EV gained by played turns, for the bots' stake decisions

    def release(self, step):
        self.main += [k for k in STORIES if CARDS[k]["step"] == step]
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

    # ---- the national count ---------------------------------------------
    def tally(self):
        """Electoral votes for each candidate: every claimed state for its
        side; unclaimed states as in 2024 (if the 'unclaimed' rule says so)."""
        ev = {s: 0 for s in SIDES}
        claimed = set()
        for p in self.players:
            for cid in p.states() + [c for c, _ in p.staked if c.startswith("st#")]:
                ev[state_side(cid)] += STATES[state_key(cid)]["ev"]
                claimed.add(state_key(cid))
        unclaimed = {s: 0 for s in SIDES}
        for k, s in STATES.items():
            if k not in claimed:
                unclaimed[s["winner"]] += s["ev"]
        if self.cfg["unclaimed"] == "history":
            for s in SIDES:
                ev[s] += unclaimed[s]
        return ev, unclaimed

    def winner(self):
        ev, _ = self.tally()
        if ev["trump"] == ev["harris"]:
            return None
        return max(SIDES, key=lambda s: ev[s])

    # ---- one turn ---------------------------------------------------------
    def turn(self, p):
        bot = BOTS[p.strategy]
        p.stats["turns"] += 1
        if self.cfg["stake"] and p.hand:
            pick = bot.stake(self, p)
            if pick:
                cid, side = pick
                p.hand.remove(cid)
                p.staked.append((cid, side))
                p.stats["stakes"] += 1
                p.discard += p.hand
                p.hand = []
                self.draw(p, self.cfg["hand"])
                return
        before = p.score(self.cfg, None)
        self.play_turn(p, bot)
        gained = p.score(self.cfg, None) - before
        self.turn_ev.append(gained)
        p.stats["played_turns"] += 1
        p.stats["played_ev"] += gained

    def play_turn(self, p, bot):
        gen, campaign = 0, 0
        party = {x: 0 for x in PARTIES}
        for loc in p.locations:
            c = card(loc)
            gen += c["ongoing_gen"]
            p.stats["draws"] += len(self.draw(p, c["ongoing_draw"]))
        for q in self.players:
            if q is not p:
                for loc in q.locations:
                    c = card(loc)
                    party[c["lean"]] += c["others_bonus"]     # the others' bonus, in the event's party currency

        if p.paper == "sun":
            junk = [c for c in p.hand if card(c)["type"] == "Scandal"]
            if junk:
                p.hand.remove(junk[0])
                p.discard.append(junk[0])
                p.stats["draws"] += len(self.draw(p, self.cfg["sun_draw"]))

        played = []
        count = {t: 0 for t in KINDS}
        star_done = False
        while p.hand:
            cid = p.hand.pop(0)
            c = card(cid)
            played.append(cid)
            if c["kind"]:
                count[c["kind"]] += 1
                if p.paper == "journal" and c["kind"] == "Economic" and count["Economic"] <= 2:
                    gen += 1
                if (p.paper == "north_star" and c["kind"] == "Social"
                        and count["Social"] == self.cfg["north_star_at"] and not star_done):
                    star_done = True
                    p.stats["draws"] += len(self.draw(p, self.cfg["north_star_draw"]))
            gen += c["gen"]
            if c["lean"]:
                party[c["lean"]] += c["themed"]
            campaign += c["campaign"]
            if c["draw"]:
                p.stats["draws"] += len(self.draw(p, c["draw"]))
            for _ in range(c["trash"]):
                self.trash(p, bot)
            for _ in range(c["retract"]):
                self.retract(p)
            if c["gain_upto"]:
                options = [x for x in self.exchange if not x.startswith("st#") and card(x)["cost"] <= c["gain_upto"]]
                pick = bot.choose(self, p, options)
                if pick:
                    self.exchange.remove(pick)
                    p.discard.append(pick)
                    self.refill()
            if c["attack"]:
                p.stats["attacks"] += 1
                hits = sum(self.attack(q, c["attack"]) for q in self.players if q is not p)
                gen += self.cfg["attack_reward"] * min(1, hits)
        by_kind = {t: sum(1 for x in played if card(x)["kind"] == t) for t in KINDS}
        offices = len(p.states()) // self.cfg["office_div"]
        for cid in played:
            c = card(cid)
            if c["per_same"]:
                gen += c["per_same"] * max(0, by_kind[c["kind"]] - 1)
            if c["chain"] and by_kind[c["kind"]] >= 2:
                gen += c["chain"]
            if c["per_office"]:
                party[c["lean"]] += c["per_office"] * offices
        if p.paper == "aurora":
            gen += self.cfg["aurora_per"] * len({card(x)["key"] for x in played if card(x)["type"] == "Negative story"})
        p.stats["gen"] += gen
        p.stats["party"] += sum(party.values())
        pool = dict(gen=gen, campaign=campaign, **party)

        # Call the big state up (once a turn).
        if self.e < len(self.big):
            key = self.big[self.e]
            best = None
            for side in SIDES:
                need = STATES[key][side + "_threshold"]
                if self.affordable(p, pool, side, need, campaign=True):
                    rank = bot.side_rank(self, p, "st#%s:%s" % (key, side), self.neutral_needed(p, pool, side, need, True))
                    if best is None or rank > best[0]:
                        best = (rank, side, need)
            if best:
                _, side, need = best
                self.pay(p, pool, side, need, campaign=True)
                p.discard.append("st#%s:%s" % (key, side))
                p.stats["called"] += 1
                p.stats["ev"] += STATES[key]["ev"]
                p.stats["unhistorical"] += side != STATES[key]["winner"]
                self.log.append((key, p.seat, side, self.rounds, "call"))
                self.e += 1
                if self.e >= len(self.big):
                    self.ended = "elections called"
                else:
                    self.step += 1
                    self.release(self.step)
                    self.refill()

        # Buy: stories, states (for a side), Editorials.
        while True:
            options = []
            for x in self.exchange:
                if x.startswith("st#"):
                    for side in SIDES:
                        cid = x + ":" + side
                        if self.affordable(p, pool, side, card(cid)["cost"]):
                            options.append(cid)
                else:
                    c = card(x)
                    if pool["gen"] + (pool[c["lean"]] if c["lean"] else 0) >= c["cost"]:
                        options.append(x)
            if self.editorials and pool["gen"] >= CARDS["editorial"]["cost"]:
                options.append("editorial")
            pick = bot.choose(self, p, options, buying=True, pool=pool)
            if not pick:
                break
            c = card(pick)
            if pick.startswith("st#"):
                side = state_side(pick)
                self.pay(p, pool, side, c["cost"])
                self.exchange.remove(pick.split(":")[0])
                p.stats["states_bought"] += 1
                p.stats["ev"] += c["vp"]
                p.stats["unhistorical"] += side != STATES[state_key(pick)]["winner"]
                self.log.append((state_key(pick), p.seat, side, self.rounds, "buy"))
            else:
                cost = c["cost"]
                if c["lean"]:
                    use = min(pool[c["lean"]], cost)
                    pool[c["lean"]] -= use
                    cost -= use
                pool["gen"] -= cost
                if pick == "editorial":
                    self.editorials -= 1
                    pick = "editorial#%d" % self.editorials
                else:
                    self.exchange.remove(pick)
            p.discard.append(pick)
            p.stats["bought"] += 1
        if (p.paper == "herald" and pool["gen"] >= self.cfg["herald_cost"] and self.main
                and not self.main[-1].startswith("st#") and bot.scoop(self, p)):   # a scoop is a story, not a state
            pool["gen"] -= self.cfg["herald_cost"]
            p.hand.append(self.main.pop())
            p.stats["bought"] += 1
        self.refill()

        for cid in played:
            (p.locations if card(cid)["type"] == "Media event" else p.discard).append(cid)
        self.draw(p, self.cfg["hand"])

    # ---- paying for a side -------------------------------------------------
    def partisan(self, p, pool, side):
        """Currency that counts for this side: its party's, and for the Globe
        the other party's too."""
        own = pool[PARTY[side]]
        if p.paper == "globe":
            own += pool[PARTY["harris" if side == "trump" else "trump"]]
        return own

    def neutral_needed(self, p, pool, side, need, campaign=False):
        return max(0, need - self.partisan(p, pool, side) - (pool["campaign"] if campaign else 0))

    def affordable(self, p, pool, side, need, campaign=False):
        return self.neutral_needed(p, pool, side, need, campaign) <= pool["gen"]

    def pay(self, p, pool, side, need, campaign=False):
        order = [PARTY[side]] + (["campaign"] if campaign else [])
        if p.paper == "globe":
            order.append(PARTY["harris" if side == "trump" else "trump"])
        for k in order:
            use = min(pool[k], need)
            pool[k] -= use
            need -= use
        pool["gen"] -= need
        assert pool["gen"] >= 0

    def retract(self, p):
        p.stats["draws"] += len(self.draw(p, 1))
        for pile in (p.hand, p.discard):
            sc = next((c for c in pile if c.startswith("scandal#")), None)
            if sc:
                pile.remove(sc)
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
        if kind == "scandal" and q.paper != "intelligencer" and self.scandals:
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
    def __init__(self, attack=1.0, big=False, min_value=1.5, stake_margin=0.0, partisan=None):
        self.attack_w, self.big, self.min_value = attack, big, min_value
        self.stake_margin = stake_margin    # extra EV a stake must promise over a played turn
        self.partisan = partisan            # 'trump' / 'harris': always backs this side

    def late(self, game):
        return game.e >= len(game.big) - 2

    def leaning(self, game, p):
        """The side this outlet has a stake in (most staked worth), if any."""
        if self.partisan:
            return self.partisan
        w = {s: sum(p.card_ev(c, game.cfg) + game.cfg["stake_bonus"] for c, side in p.staked if side == s)
             for s in SIDES}
        return max(SIDES, key=lambda s: w[s]) if any(w.values()) else None

    def score(self, game, p, cid):
        c = card(cid)
        v = value(c)
        if c["attack"]:
            v *= self.attack_w
        if self.big and c["type"] not in ("Editorial", "State") and c["cost"] < 5:
            v = 0
        if self.late(game):                 # late in the game only electoral votes count
            v = (c["vp"] / STORY_EV if c["type"] == "State" else c["vp"]) * 2 + 0.2 * v
        if c["type"] == "State" and cid.count(":"):
            side = state_side(cid)
            if side == self.leaning(game, p):
                v += 1.0                    # it helps the candidate this outlet has bet on
            v -= 0.15 * (c["cost"] - STATES[state_key(cid)][STATES[state_key(cid)]["winner"] + "_threshold"])
        return v

    def side_rank(self, game, p, cid, neutral):
        return self.score(game, p, cid) - game.cfg["power_choice"] * neutral

    def choose(self, game, p, options, buying=False, pool=None):
        if not options:
            return None
        def key(x):
            v = self.score(game, p, x)
            if pool is not None and x.startswith("st#"):
                v -= game.cfg["power_choice"] * 0.3 * game.neutral_needed(p, pool, state_side(x), card(x)["cost"])
            return (v, card(x)["cost"] if x != "editorial" else 3)
        best = max(options, key=key)
        if buying and self.score(game, p, best) < self.min_value:
            return None
        return best

    def stake(self, game, p):
        """Stake when the bet beats playing the hand: the chance the projected
        winner wins, times the card's worth plus the bonus, against what a
        played turn has been worth this game (and the card's own EV, kept
        either way if not staked)."""
        ev, unclaimed = game.tally()
        left = sum(STATES[k]["ev"] for k in game.big[game.e:])
        lead = max(SIDES, key=lambda s: ev[s])
        if self.partisan:
            lead = self.partisan
        margin = ev[lead] - ev["harris" if lead == "trump" else "trump"]
        # Uncalled big states and unclaimed small ones can still swing.
        swing = left + 0.5 * sum(STATES[k]["ev"] for k in SMALL
                                 if k not in {state_key(c) for q in game.players for c in q.states()})
        prob = min(0.98, max(0.02, 0.5 + 0.5 * margin / max(1.0, swing)))
        turn_value = game.cfg["stake_turn_value"]
        if turn_value is None:
            turn_value = statistics.mean(game.turn_ev[-30:]) if len(game.turn_ev) >= 6 else 15.0
        best = None
        for cid in p.hand:
            worth = p.card_ev(cid, game.cfg)
            gain = prob * (worth + game.cfg["stake_bonus"]) - worth       # it scored anyway if kept
            gain -= 0.3 * value(card(cid)) * max(0, len(game.big) - game.e - 1)   # its future plays
            if best is None or gain > best[0]:
                best = (gain, cid)
        if best and best[0] > turn_value + self.stake_margin:
            return best[1], lead
        return None

    def scoop(self, game, p):
        return not self.late(game)

    def trash_letters(self, game, p):
        bought = sum(1 for c in p.owned() if not c.startswith(("letter#", "notice#", "scandal#")))
        return bought >= 8


BOTS = {
    "balanced": Bot(),
    "attacker": Bot(attack=2.5),
    "pacifist": Bot(attack=0.0),
    "bigmoney": Bot(big=True),
    "nostake": Bot(stake_margin=1e9),
    "staker": Bot(stake_margin=-8),
    "trump": Bot(partisan="trump"),
    "harris": Bot(partisan="harris"),
}


# =====================================================================
# Experiments
# =====================================================================

def run_matchup(strategies, games, config=None, seed=0):
    rng = random.Random(seed)
    wins = [0.0] * len(strategies)
    vp = [[] for _ in strategies]
    stats = [[] for _ in strategies]
    ended, rounds, unhist, claims = {}, [], 0, 0
    harris_wins, early_lead_wins, story_share, claimed_share = 0, 0, [], []
    for _ in range(games):
        g = Game(list(strategies), config, random.Random(rng.randrange(1 << 30))).run()
        ended[g.ended] = ended.get(g.ended, 0) + 1
        rounds.append(g.rounds)
        w = g.winner()
        harris_wins += w == "harris"
        scores = [p.score(g.cfg, w) for p in g.players]
        top = max(scores)
        leaders = [i for i, s in enumerate(scores) if s == top]
        for i in leaders:
            wins[i] += 1 / len(leaders)
        for p in g.players:
            vp[p.seat].append(scores[p.seat])
            st = dict(p.stats)
            st["stake_won"] = sum(1 for c, side in p.staked if side == w)
            st["stake_ev"] = sum(p.card_ev(c, g.cfg) + g.cfg["stake_bonus"] for c, side in p.staked if side == w)
            stats[p.seat].append(st)
            if scores[p.seat] > 0:
                story_share.append(1 - p.stats["ev"] / scores[p.seat])
        unhist += sum(1 for k, s, side, t, how in g.log if side != STATES[k]["winner"])
        claims += len(g.log)
        _, unclaimed = g.tally()
        claimed_share.append(1 - sum(unclaimed.values()) / 538)
        early = {}
        for k, s, side, t, how in g.log[:10]:
            early[s] = early.get(s, 0) + STATES[k]["ev"]
        if early:
            lead = max(early, key=early.get)
            early_lead_wins += (lead in leaders) / len(leaders)
    return dict(strategies=strategies, games=games, wins=wins, vp=vp, stats=stats, ended=ended,
                rounds=rounds, unhistorical=unhist / max(1, claims), harris=harris_wins / games,
                early_lead=early_lead_wins / games, story_share=mean(story_share),
                claimed=mean(claimed_share))


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
        print("    rounds %.1f, score %.0f EV (stories and stakes %s of it); per outlet: %.1f big states called, "
              "%.1f states bought, %.1f stories bought"
              % (mean(r["rounds"]), mean([x for v in r["vp"] for x in v]), pct(r["story_share"]),
                 mean([x["called"] for x in st]), mean([x["states_bought"] for x in st]),
                 mean([x["bought"] - x["states_bought"] for x in st])))
        print("    states claimed %s of the EVs; history rewritten %s; Harris wins %s; early leader wins %s"
              % (pct(r["claimed"]), pct(r["unhistorical"]), pct(r["harris"]), pct(r["early_lead"])))
        print("    stakes per outlet %.2f (won %.2f, %.0f EV); turns %.1f, a played turn worth %.1f EV"
              % (mean([x["stakes"] for x in st]), mean([x["stake_won"] for x in st]), mean([x["stake_ev"] for x in st]),
                 mean([x["turns"] for x in st]),
                 sum(x["played_ev"] for x in st) / max(1, sum(x["played_turns"] for x in st))))


def report(games, seed, config=None):
    print("=" * 78)
    print("MIRRORS: seat advantage and game shape (balanced bots)")
    print("=" * 78)
    shape(games, seed, config)
    print()
    print("=" * 78)
    print("STRATEGIES: one among balanced outlets (fair = 1/n)")
    print("=" * 78)
    for n in (3, 4):
        row = []
        for s in ("nostake", "staker", "trump", "harris", "attacker", "pacifist", "bigmoney"):
            share = rotated([s] + ["balanced"] * (n - 1), games, config, seed)
            row.append("%s %s" % (s, pct(share[s])))
        print("  %d outlets (fair %s): %s" % (n, pct(1 / n), "  ".join(row)))
    print()
    print("=" * 78)
    print("OUTLETS: each stand-in newspaper among two balanced outlets (fair 33.3%)")
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
    print("Read %d stories, %d main-deck states and %d big states (%d EV)"
          % (len(STORIES), len(SMALL), len(BIG), sum(STATES[k]["ev"] for k in BIG)))
    print()
    if args.shape:
        shape(args.games, args.seed)
    else:
        report(args.games, args.seed)


if __name__ == "__main__":
    main()
