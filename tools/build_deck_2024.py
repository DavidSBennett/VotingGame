"""Build docs/deck-2024.csv: the 2024 game's starters, Editorial, Scandal and
stories, from the variant's docs/deck-dc.csv.

For now the stories keep the variant's mechanics, names and flavor (the
2024 rewrite comes later). What changes:

- 65 of the 106 stories are kept, so that with the 41 main-deck states the
  main deck stays near its old size (the user, 2026-10-03). Each kind keeps
  its share (Political 15, Economic 16, Social 13, Negative 16, Media 5),
  chosen evenly across its costs.
- Each story leans Republican or Democratic: its themed influence becomes
  that party's currency (`lean`), half of each kind each way.
- `theme` stays as the story's KIND (Political / Economic / Social) for the
  rules that count kinds (compounding, chains, per office).
- `era`: no calendar (the user, 2026-10-05). The main deck is two sets
  stacked: the Biden set on top (stories before 2024-07-21, where Biden is
  the Democratic figure), the switch card (Biden Steps Aside), then the
  Harris set (stories from 2024-07-21, plus HARRIS_SET). Each set gets five
  planks of each party.
- `vp` is PRESTIGE on the game's 1-12 scale (the user, 2026-10-03: every
  card has a prestige value; California, the biggest state, is 12): stars
  become 1 / 3 / 4 (one old star was 6 EV, and 12 prestige is 54 EV);
  every card worth no stars -- Letters, Local Notices, negative stories --
  is worth 1. Scandals stay at -1. The old stars are kept in `stars`.
- Starters (Letters, Local Notices) are worth -1 when staked (the user,
  2026-10-03), so staking a starter costs you.
- The score is called WEALTH (the user, 2026-10-03); the column stays `vp`.
- Each story is a 2024 news story (docs/stories-2024.csv: its key, name,
  date and flavor, and the card slot whose mechanics it carries); the
  starters, Editorial and Scandal get 2024 flavor (STARTER_FLAVOR).
- `card_text` is rewritten for the 2024 rules (text_2024): influence is
  "neutral", a story's themed influence is its party's currency, Campaign
  is for big states, per-office counts every 3 states held, and media
  events give rivals their party's currency.
- Party planks replace the media events (the user, 2026-10-05): 20 cards, ten
  from each party's 2024 platform (docs/planks-2024.csv). A plank played
  stays in play, paying its owner each turn while every other outlet gets
  +1 of the plank's party, until a rival knocks it out. Twenty stories'
  bottom framings knock out a plank (STRIKERS per party): the party whose
  planks a story hits is the one its oppositional headline is aimed at
  (a Fox bottom hits a Democratic plank, an MSNBC bottom a Republican one).
- The bottom framing pays the OTHER party's currency (the user, 2026-10-05:
  every card has a positive and a negative side, split evenly between the
  parties), in place of the old discard / Scandal attacks: +1 at cost 4 or
  less, +2 above. The plank knockouts stay on their twenty bottoms.

WARNING: this OVERWRITES docs/deck-2024.csv.

    py -X utf8 tools/build_deck_2024.py
"""
import csv
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")
PRESTIGE = {0: 1, 1: 1, 2: 3, 3: 4}      # stars -> wealth
STARTER_VP = -1                          # a staked Letter or Local Notice
KEEP = {"Political story": 15, "Economic story": 16, "Social story": 13, "Negative story": 16}
SWITCH = "biden_steps_aside"              # the card between the two sets
HARRIS_FROM = "2024-07-21"
HARRIS_SET = {"the_coconut_tree", "project_2025"}      # earlier, but Harris-era stories
# The planks: five of each party in each set, at matching costs (Biden 4 4 5 6 7, Harris 4 5 5 6 7).
PLANK_SET = {"seal_the_border": "biden", "energy_dominance": "biden", "end_inflation": "biden",
             "mass_deportation": "biden", "end_the_weaponization": "biden",
             "protect_social_security": "biden", "gun_safety": "biden", "drug_prices": "biden",
             "voting_rights": "biden", "reproductive_freedom": "biden"}
OTHER = {"rep": "dem", "dem": "rep"}
STRIKERS = 10                            # stories per party whose bottom knocks out a plank
# A plank's power by its cost: (ongoing neutral, ongoing draw, wealth).
PLANK_TIER = {4: (1, 0, 1), 5: (0, 1, 1), 6: (2, 0, 3), 7: (1, 1, 3)}
PLATFORM = {"rep": ("2024 Republican Platform", "https://www.presidency.ucsb.edu/documents/2024-republican-party-platform",
                    "2024-07-08"),
            "dem": ("2024 Democratic Platform", "https://www.presidency.ucsb.edu/documents/2024-democratic-party-platform",
                    "2024-08-19")}


PARTY = {"rep": "Republican", "dem": "Democratic"}
STARTER_FLAVOR = {
    "letter": "A reader writes in. Printed free, read by a few.",
    "notice": "Road closures, a bake sale, a zoning hearing. Fills the page; moves no one.",
    "editorial": "The editorial board's own voice, always on offer.",
    "scandal": "Something was published about you. It will not go away.",
}


def stories_2024():
    with open(os.path.join(DOCS, "stories-2024.csv"), encoding="utf-8") as fh:
        return {r["slot"]: r for r in csv.DictReader(fh)}


def text_2024(r):
    """The card's rules text under the 2024 currencies."""
    s = r["card_text"]
    party = PARTY.get(r.get("lean") or "", "")
    s = re.sub(r"Every other paper gets \+(\d+) (?:\w+ )?influence", r"Every other outlet gets +\g<1> " + party, s)
    s = re.sub(r"\+(\d+) (Political|Economic|Social) for each election you have won",
               r"+\g<1> " + party + " for every 3 states you hold", s)
    s = re.sub(r"\+(\d+) (Political|Economic|Social)(?= *[,.]| draw)", r"+\g<1> " + party, s)
    s = s.replace("Campaign (elections only)", "Campaign (big states only)")
    s = s.replace("influence", "neutral").replace("rival paper", "rival outlet")
    s = s.replace("Worth no prestige.", "Worth 1 wealth.")
    s = s.replace("-1 prestige at the end of the game.", "-1 wealth if staked. Destroy it when you can.")
    if r.get("type") == "Starter":
        s += " -1 wealth if staked."
    return s


HEADLINE_FIELDS = ["top_title", "top_outlet", "top_url", "top_date",
                   "bottom_title", "bottom_outlet", "bottom_url", "bottom_date"]


def frames_2024():
    """The real headlines for each framing (docs/frames-2024.csv), by story key."""
    path = os.path.join(DOCS, "frames-2024.csv")
    if not os.path.exists(path):
        return {}
    with open(path, encoding="utf-8") as fh:
        return {r["key"]: r for r in csv.DictReader(fh)}


def n(r, f):
    return int(r.get(f) or 0)


def currency_text(r):
    """What the card pays from the hand."""
    party = PARTY.get(r.get("lean") or "", "")
    parts = []
    if n(r, "gen"):
        parts.append("+%d neutral" % n(r, "gen"))
    if n(r, "themed") and party:
        parts.append("+%d %s" % (n(r, "themed"), party))
    if n(r, "campaign"):
        parts.append("+%d Campaign (states only)" % n(r, "campaign"))
    text = ", ".join(parts) + "." if parts else "No currency."
    if n(r, "per_office") and party:
        text += " +%d %s for every 3 states you hold." % (n(r, "per_office"), party)
    if n(r, "defense"):
        text += " Defense: discard this from your hand to ignore an attack, and draw a card."
    return text


def top_text(r):
    """The positive framing: the card's own ability."""
    if r["type"] == "Media event":
        return r["card_text"].replace("Stays in play for the rest of the game. ", "Stays in play. ")
    kind = r["theme"]
    parts = []
    if n(r, "top_party"):
        parts.append("+%d %s." % (n(r, "top_party"), PARTY[r["lean"]]))
    if n(r, "draw"):
        parts.append("Draw %d card%s." % (n(r, "draw"), "s" if n(r, "draw") > 1 else ""))
    if n(r, "trash"):
        parts.append("You may destroy a card in your hand or discard pile.")
    if n(r, "gain_upto"):
        parts.append("Gain a story costing %d or less from the exchange." % n(r, "gain_upto"))
    if n(r, "chain"):
        parts.append("If you use another %s story this turn, +%d neutral." % (kind, n(r, "chain")))
    if n(r, "per_same"):
        parts.append("+%d neutral for each other %s story you use this turn." % (n(r, "per_same"), kind))
    if n(r, "retract"):
        parts.append("Retraction: draw a card, and you may destroy a Scandal in your hand or discard pile.")
    return " ".join(parts)


def bottom_text(r):
    """The oppositional framing: the other party's currency (and maybe a plank knockout)."""
    text = "+%d %s." % (n(r, "bottom_party"), PARTY[OTHER[r["lean"]]])
    if r["bottom_attack"] == "plank":
        text += " Knock out a %s plank a rival has in play." % PARTY[r["strike"]]
    return text


def frame(r, heads):
    """Two framings on every story (the user, 2026-10-04): the top is the
    positive one (the card's own ability), the bottom the oppositional one
    (the other party's currency). Negative stories stop being a kind: their
    attack goes, and their top draws a card."""
    r["bottom_attack"] = ""
    r["bottom_party"] = 1 if n(r, "cost") <= 4 else 2
    if r["type"] == "Negative story":
        r["attack"] = ""
        r["type"] = r["theme"] + " story"
        if not any(n(r, f) for f in ("draw", "trash", "gain_upto", "chain", "per_same", "retract")):
            r["draw"] = 1
    # A story with no ability of its own frames positively for +1 of its party.
    r["top_party"] = 0
    if r["type"] != "Media event" and not any(n(r, f) for f in ("draw", "trash", "gain_upto", "chain", "per_same", "retract")):
        r["top_party"] = 1
    r["top_text"] = top_text(r)
    r["bottom_text"] = bottom_text(r)
    r["card_text"] = currency_text(r)
    h = heads.get(r["key"], {})
    for f in HEADLINE_FIELDS:
        r[f] = h.get(f, "")


def planks_2024():
    """The 20 party planks (docs/planks-2024.csv) as deck rows."""
    rows = []
    for p in csv.DictReader(open(os.path.join(DOCS, "planks-2024.csv"), encoding="utf-8")):
        gen, draw, vp = PLANK_TIER[int(p["cost"])]
        source, url, date = PLATFORM[p["lean"]]
        ongoing = " and ".join(x for x in ("+%d neutral" % gen if gen else "", "draw 1 card" if draw else "") if x)
        rows.append(dict(key=p["key"], name=p["name"], type="Plank", theme=p["theme"], cost=p["cost"], vp=vp,
                         ongoing_gen=gen, ongoing_draw=draw, others_bonus=1, copies=1, year=date[:4],
                         card_text="No currency.", flavor=p["flavor"], stars="", lean=p["lean"], era=PLANK_SET.get(p["key"], "harris"),
                         date=date, top_text="Stays in play until a rival knocks it out. Ongoing: at the start of "
                         "each of your turns, %s. Every other outlet gets +1 %s at the start of each of its turns."
                         % (ongoing, PARTY[p["lean"]]),
                         top_title=p["platform_title"], top_outlet=source, top_url=url, top_date=date))
    return rows


def strikers(stories):
    """Pick the stories whose bottom framing knocks out a plank: STRIKERS
    aimed at each party, spread evenly across the costs."""
    def target(r):                       # the oppositional view of the story's own party
        return r["lean"]
    for party in ("rep", "dem"):
        group = sorted((r for r in stories if target(r) == party), key=lambda r: (int(r["cost"]), r["date"], r["key"]))
        assert len(group) >= STRIKERS, (party, len(group))
        for i in range(STRIKERS):
            r = group[round(i * (len(group) - 1) / (STRIKERS - 1))]
            r["bottom_attack"], r["strike"] = "plank", party
            r["bottom_text"] = bottom_text(r)


def main():
    src = list(csv.DictReader(open(os.path.join(DOCS, "deck-dc.csv"), encoding="utf-8-sig")))
    fields = list(src[0].keys()) + ["stars", "lean", "era", "date", "bottom_attack", "top_party", "bottom_party", "top_text", "bottom_text"]
    fields += HEADLINE_FIELDS + ["strike"]
    news = stories_2024()
    heads = frames_2024()
    for r in src:
        r["stars"] = r["vp"]
        if r["type"] == "Starter":
            r["vp"] = STARTER_VP
        elif r["type"] != "Scandal":
            r["vp"] = PRESTIGE[int(r["vp"])]
    out = []
    for r in src:
        if not r["released"]:
            row = dict(r, lean="", era="", date="", bottom_attack="", top_party="", top_text="", bottom_text="",
                       **{f: "" for f in HEADLINE_FIELDS})
            row["card_text"] = text_2024(row)
            row["flavor"] = STARTER_FLAVOR[row["key"]]
            out.append(row)
    flip = 0
    for kind, k in KEEP.items():
        group = sorted((r for r in src if r["type"] == kind),
                       key=lambda r: (int(r["cost"]), int(r["released"]), r["key"]))
        n = len(group)
        picked = [group[round(i * (n - 1) / (k - 1))] for i in range(k)]
        assert len({r["key"] for r in picked}) == k, kind
        for i, r in enumerate(picked):
            lean = ("rep", "dem")[(i + flip) % 2]
            row = dict(r, lean=lean)
            row["card_text"] = text_2024(row)
            s = news[r["key"]]              # the 2024 story on this slot
            row.update(key=s["key"], name=s["name"], date=s["date"], year=s["date"][:4], flavor=s["flavor"],
                       released="")
            row["era"] = "harris" if s["date"] >= HARRIS_FROM or s["key"] in HARRIS_SET else "biden"
            frame(row, heads)
            if s["key"] == SWITCH:
                row.update(type="Switch", era="switch", cost=0, vp=0, card_text="", top_party=0, bottom_party=0,
                           draw=0, gen=0, themed=0, campaign=0, trash=0, gain_upto=0, chain=0, per_same=0,
                           per_office=0, defense=0, retract=0,
                           top_text="The switch. When this card comes up, set it aside: every story below it "
                           "is a Harris story.", bottom_text="")
            out.append(row)
        flip += k            # alternate which party a kind starts with
    strikers([r for r in out if r["era"] in ("biden", "harris")])
    out += planks_2024()
    with open(os.path.join(DOCS, "deck-2024.csv"), "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=fields)
        w.writeheader()
        w.writerows(out)
    stories = [r for r in out if r["era"]]
    kinds = sorted({r["type"] for r in stories})
    print("%d stories: %s" % (len(stories), {k: sum(1 for r in stories if r["type"] == k) for k in kinds}))
    print("plank strikes:", {p: sum(1 for r in stories if r.get("strike") == p) for p in ("rep", "dem")},
          " headlines found:", sum(1 for r in stories if r.get("top_title")) + sum(1 for r in stories if r.get("bottom_title")), "of", 2 * len(stories) - sum(1 for r in stories if r["type"] == "Plank"))
    for era in ("biden", "harris"):
        st = [r for r in stories if r["era"] == era and r["type"] != "Plank"]
        pl = [r for r in stories if r["era"] == era and r["type"] == "Plank"]
        print("%s set: %d stories, positive side R %d / D %d; planks R %d / D %d" % (
            era, len(st), sum(r["lean"] == "rep" for r in st), sum(r["lean"] == "dem" for r in st),
            sum(r["lean"] == "rep" for r in pl), sum(r["lean"] == "dem" for r in pl)))



if __name__ == "__main__":
    main()
