"""Build docs/deck-dc.csv and docs/elections-dc.csv from the card templates
below (The Fourth Estate as a DC Deck-Building style game).

WARNING: this OVERWRITES both CSVs. After the first build the CSVs are the
source of truth; rerun this only to regenerate from templates."""
import csv
import os
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")

src = list(csv.DictReader(open(os.path.join(DOCS, "deck-v2.csv"), encoding="utf-8-sig")))
cands = list(csv.DictReader(open(os.path.join(DOCS, "candidates.csv"), encoding="utf-8-sig")))

NEGATIVE = {
    "stamp_act", "boston_massacre", "newburgh", "shays_rebellion", "whiskey_rebellion", "citizen_genet",
    "alien_sedition", "xyz_affair", "burr_conspiracy", "hartford_convention", "gerrymander",
    "panic_of_1819", "denmark_vesey", "crawford_radicals", "anti_masonic_party", "gag_rule",
    "panic_of_1837", "tyler_vetoes", "dorr_rebellion", "nashville_convention", "fugitive_slave_act",
    "ostend_manifesto", "filibusters", "sumner_caning", "bleeding_kansas", "lecompton_constitution",
    "john_browns_raid",
}
TYPE_OF_THEME = {"Political": "Political story", "Economic": "Economic story", "Social": "Social story"}

FIELDS = ["key", "name", "type", "theme", "cost", "vp", "gen", "themed", "campaign", "draw", "trash",
          "gain_upto", "chain", "per_same", "per_office", "attack", "defense", "retract",
          "ongoing_gen", "ongoing_draw", "others_bonus", "others_theme",
          "released", "year", "copies", "card_text", "flavor"]


def blank(**kw):
    row = {f: "" for f in FIELDS}
    row.update({f: 0 for f in ("cost", "vp", "gen", "themed", "campaign", "draw", "trash", "gain_upto",
                               "chain", "per_same", "per_office", "defense", "retract", "ongoing_gen",
                               "ongoing_draw", "others_bonus")})
    row["copies"] = 1
    row.update(kw)
    return row


# Templates by theme and cost: the theme's identity.
POLITICAL = {
    2: dict(gen=1, themed=2),
    3: dict(gen=1, themed=2, campaign=1, retract=1),
    4: dict(gen=1, themed=2, per_office=1, retract=1),
    5: dict(gen=2, themed=2, campaign=2, retract=1),
    6: dict(gen=2, themed=3, per_office=1, retract=1),
    7: dict(gen=2, themed=3, campaign=3, retract=1),
    8: dict(gen=3, themed=4, per_office=1, retract=1),
}
ECONOMIC = {        # every Economic story compounds: +1 per other Economic story this turn
    2: dict(gen=2, per_same=1),
    3: dict(gen=1, themed=1, trash=1, per_same=1),
    4: dict(gen=2, themed=1, per_same=1),
    5: dict(gen=2, themed=1, trash=1, per_same=1),
    6: dict(gen=2, themed=2, gain_upto=4, per_same=1),
    7: dict(gen=3, themed=2, per_same=1),
    8: dict(gen=4, themed=2, gain_upto=5, per_same=1),
}
SOCIAL = {
    2: dict(gen=1, draw=1, defense=1),
    3: dict(themed=1, draw=1, chain=1, defense=1),
    4: dict(themed=2, draw=1, chain=1),
    5: dict(gen=1, themed=2, draw=1),
    6: dict(themed=2, draw=1, chain=2, defense=1),
    7: dict(gen=1, themed=2, draw=2, chain=1),
    8: dict(gen=1, themed=3, draw=2, chain=2),
}
NEG = {
    2: dict(gen=1, attack="discard"),
    3: dict(gen=1, themed=1, attack="discard"),
    4: dict(gen=1, themed=1, attack="scandal"),
    5: dict(gen=2, themed=1, attack="scandal"),
    6: dict(gen=2, themed=2, attack="scandal"),
    7: dict(gen=3, themed=2, attack="scandal"),
    8: dict(gen=3, themed=3, attack="scandal"),
}
MEDIA = {
    "pennsylvania_packet": dict(cost=5, ongoing_gen=1, others_bonus=1, others_theme="Economic"),
    "postal_act_1792": dict(cost=6, ongoing_draw=1, others_bonus=1, others_theme="Political"),
    "niles_register": dict(cost=5, ongoing_gen=1, others_bonus=1, others_theme="Political"),
    "penny_press": dict(cost=6, ongoing_draw=1, others_bonus=1, others_theme="Social"),
    "telegraph": dict(cost=7, ongoing_gen=1, ongoing_draw=1, others_bonus=1, others_theme=""),
    "associated_press": dict(cost=6, ongoing_gen=2, others_bonus=1, others_theme=""),
    "rotary_press": dict(cost=7, ongoing_gen=1, ongoing_draw=1, others_bonus=1, others_theme="Economic"),
    "cheap_postage": dict(cost=5, ongoing_gen=1, others_bonus=1, others_theme="Social"),
}


def vp_of(cost):
    return 1 if cost <= 5 else 2 if cost <= 7 else 3


def text_of(r):
    t, th, bits = r["type"], r["theme"], []
    if t == "Media event":
        own = []
        if r["ongoing_gen"]:
            own.append("+%d influence" % r["ongoing_gen"])
        if r["ongoing_draw"]:
            own.append("draw %d card" % r["ongoing_draw"])
        other = "+%d %s influence" % (r["others_bonus"], r["others_theme"]) if r["others_theme"] else "+%d influence" % r["others_bonus"]
        return ("Stays in play for the rest of the game. Ongoing: at the start of each of your turns, %s. "
                "Every other paper gets %s at the start of each of its turns." % (" and ".join(own), other))
    if r["gen"]:
        bits.append("+%d influence" % r["gen"])
    if r["themed"]:
        bits.append("+%d %s" % (r["themed"], th))
    if r["campaign"]:
        bits.append("+%d Campaign (elections only)" % r["campaign"])
    if r["draw"]:
        bits.append("draw %d card%s" % (r["draw"], "s" if r["draw"] > 1 else ""))
    s = ", ".join(bits)
    s = s[0].upper() + s[1:] + "." if s else ""
    if r["per_office"]:
        s += " +%d %s for each election you have won." % (r["per_office"], th)
    if r["per_same"]:
        s += " +%d influence for each other %s story you play this turn." % (r["per_same"], th)
    if r["chain"]:
        s += " If you play another %s story this turn, +%d influence." % (th, r["chain"])
    if r["trash"]:
        s += " You may destroy a card in your hand or discard pile."
    if r["gain_upto"]:
        s += " Gain a story costing %d or less from the exchange." % r["gain_upto"]
    if r["attack"] == "discard":
        s += " Attack: each rival paper discards a card at random; +1 influence if it hits any of them."
    if r["attack"] == "scandal":
        s += " Attack: each rival paper gains a Scandal; +1 influence if it hits any of them."
    if r["retract"]:
        s += " Retraction: draw a card, and you may destroy a Scandal in your hand or discard pile."
    if r["type"] == "Negative story":
        s += " Worth no prestige."
    if r["defense"]:
        s += " Defense: discard this from your hand to ignore a negative story, and draw a card."
    return s.strip()


rows = [
    blank(key="letter", name="Letter to the Editor", type="Starter", cost=0, vp=0, gen=1, copies=7,
          flavor="A subscriber's opinion, printed at no charge."),
    blank(key="notice", name="Local Notice", type="Starter", cost=0, vp=0, copies=3,
          flavor="Strayed cattle, a church supper. Fills the column; moves no one."),
    blank(key="editorial", name="Editorial", type="Editorial", cost=3, vp=1, gen=2, copies=16,
          flavor="The editor's own voice, always on offer."),
    blank(key="scandal", name="Scandal", type="Scandal", cost=0, vp=-1, copies=20,
          flavor="Something printed about you. It will not go away."),
]
for s in src:
    if s["deck"] != "history":
        continue
    key, era = s["key"], s["era"]
    old = int(s["profit"])
    if s["kind"] == "trade":
        m = MEDIA[key]
        r = blank(key=key, name=s["name"], type="Media event", theme="", vp=vp_of(m["cost"]), **m)
    else:
        cost = max(2, min(8, old + (1 if era == "III" else 0)))
        if key in NEGATIVE:
            tmpl, typ = NEG, "Negative story"
        else:
            tmpl = {"Political": POLITICAL, "Economic": ECONOMIC, "Social": SOCIAL}[s["theme"]]
            typ = TYPE_OF_THEME[s["theme"]]
        vp = 0 if typ == "Negative story" else vp_of(cost)      # nobody is honoured for a smear
        r = blank(key=key, name=s["name"], type=typ, theme=s["theme"], cost=cost, vp=vp, **tmpl[cost])
    r.update(released=s["released"], year=s["year"], flavor=s["flavor"])
    rows.append(r)
for r in rows:
    if r["type"] not in ("Starter", "Scandal"):
        r["card_text"] = text_of(r)
rows[0]["card_text"] = "+1 influence."
rows[1]["card_text"] = "No effect. Destroy it when you can."
rows[3]["card_text"] = "-1 prestige at the end of the game."

with open(os.path.join(DOCS, "deck-dc.csv"), "w", newline="", encoding="utf-8-sig") as fh:
    w = csv.DictWriter(fh, fieldnames=FIELDS)
    w.writeheader()
    w.writerows(rows)

# Elections: two thresholds, rising linearly; the man history elected is
# cheaper, rewriting history costs +2. Prestige rises linearly too.
erows = []
for i, c in enumerate(cands):
    base_t = 8 + i // 2                 # 8 .. 16
    vp = 3 + i // 4                     # 3 .. 7
    bonus = 2 + i // 6                  # themed influence when played later: 2 .. 4
    hist = c["historical_winner"]
    erows.append(dict(
        space=i + 1, year=c["year"], era=c["era"], vp=vp,
        nation=c["nation_candidate"], nation_theme=c["nation_theme"],
        nation_threshold=base_t + (0 if hist == "nation" else 2),
        states=c["states_candidate"], states_theme=c["states_theme"],
        states_threshold=base_t + (0 if hist == "states" else 2),
        historical_winner=hist, patron_gen=1, patron_themed=bonus,
        card_text="Elect one man: reach his threshold (%s or %s influence count toward him). "
                  "Gain this card as his Patron: when you play it, +1 influence and +%d of his theme. "
                  "Worth %d prestige." % (c["nation_theme"], c["states_theme"], bonus, vp),
    ))
with open(os.path.join(DOCS, "elections-dc.csv"), "w", newline="", encoding="utf-8-sig") as fh:
    w = csv.DictWriter(fh, fieldnames=list(erows[0]))
    w.writeheader()
    w.writerows(erows)

stories = [r for r in rows if r["type"] not in ("Starter", "Editorial", "Scandal")]
print("stories:", len(stories), dict(Counter(r["type"] for r in stories)))
print("by theme (non-media):", dict(Counter(r["theme"] for r in stories if r["theme"])))
print("costs:", sorted(Counter(r["cost"] for r in stories).items()))
print("thresholds:", [(e["year"], e["nation_threshold"], e["states_threshold"], e["vp"]) for e in erows])
