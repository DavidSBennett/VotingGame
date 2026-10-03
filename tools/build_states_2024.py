"""Build docs/states-2024.csv: the 51 contests of the 2024 electoral college
(the 50 states and D.C., each winner-take-all), from the certified results
below and the threshold and power templates.

The results are the certified statewide totals as compiled on Wikipedia's
"2024 United States presidential election" (Results by state), fetched
2026-10-03. Maine and Nebraska split their votes by district in 2024; here
each is winner-take-all (Maine to Harris, Nebraska to Trump), which leaves
the total where it was: Trump 312, Harris 226.

WARNING: this OVERWRITES docs/states-2024.csv. After the first build the
CSV is the source of truth for hand edits; rerun this to regenerate from
the templates.

    py -X utf8 tools/build_states_2024.py
"""
import csv
import math
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")

# abbr, state, electoral votes, Trump %, Harris %.
RESULTS = [
    ("AL", "Alabama", 9, 64.57, 34.10),
    ("AK", "Alaska", 3, 54.54, 41.41),
    ("AZ", "Arizona", 11, 52.22, 46.69),
    ("AR", "Arkansas", 6, 64.20, 33.56),
    ("CA", "California", 54, 38.33, 58.47),
    ("CO", "Colorado", 10, 43.14, 54.13),
    ("CT", "Connecticut", 7, 41.89, 56.40),
    ("DE", "Delaware", 3, 41.79, 56.49),
    ("DC", "District of Columbia", 3, 6.47, 90.28),
    ("FL", "Florida", 30, 56.09, 42.99),
    ("GA", "Georgia", 16, 50.72, 48.53),
    ("HI", "Hawaii", 4, 37.48, 60.59),
    ("ID", "Idaho", 4, 66.87, 30.38),
    ("IL", "Illinois", 19, 43.47, 54.37),
    ("IN", "Indiana", 11, 58.58, 39.62),
    ("IA", "Iowa", 6, 55.73, 42.52),
    ("KS", "Kansas", 6, 57.16, 41.04),
    ("KY", "Kentucky", 8, 64.47, 33.94),
    ("LA", "Louisiana", 8, 60.22, 38.21),
    ("ME", "Maine", 4, 45.46, 52.40),
    ("MD", "Maryland", 10, 34.08, 62.62),
    ("MA", "Massachusetts", 11, 36.02, 61.22),
    ("MI", "Michigan", 15, 49.73, 48.31),
    ("MN", "Minnesota", 10, 46.68, 50.92),
    ("MS", "Mississippi", 6, 60.89, 38.00),
    ("MO", "Missouri", 10, 58.49, 40.08),
    ("MT", "Montana", 4, 58.39, 38.46),
    ("NE", "Nebraska", 5, 59.32, 38.86),
    ("NV", "Nevada", 6, 50.59, 47.49),
    ("NH", "New Hampshire", 4, 47.87, 50.65),
    ("NJ", "New Jersey", 14, 46.06, 51.97),
    ("NM", "New Mexico", 5, 45.85, 51.85),
    ("NY", "New York", 28, 43.31, 55.91),
    ("NC", "North Carolina", 16, 50.86, 47.65),
    ("ND", "North Dakota", 3, 66.96, 30.51),
    ("OH", "Ohio", 17, 55.14, 43.93),
    ("OK", "Oklahoma", 7, 66.16, 31.90),
    ("OR", "Oregon", 8, 40.97, 55.27),
    ("PA", "Pennsylvania", 19, 50.37, 48.66),
    ("RI", "Rhode Island", 4, 41.76, 55.54),
    ("SC", "South Carolina", 9, 58.23, 40.36),
    ("SD", "South Dakota", 3, 63.43, 34.24),
    ("TN", "Tennessee", 11, 64.19, 34.47),
    ("TX", "Texas", 40, 56.14, 42.46),
    ("UT", "Utah", 6, 59.38, 37.79),
    ("VT", "Vermont", 3, 32.32, 63.83),
    ("VA", "Virginia", 13, 46.05, 51.83),
    ("WA", "Washington", 12, 39.01, 57.23),
    ("WV", "West Virginia", 4, 69.97, 28.10),
    ("WI", "Wisconsin", 10, 49.60, 48.74),
    ("WY", "Wyoming", 3, 71.60, 25.84),
]
# The currencies (the user, 2026-10-03): neutral spends on anything;
# Republican only on Trump (and Republican stories), Democratic only on
# Harris (and Democratic stories). A state's card for a side pays that
# side's currency, and is bought or called with it.
PARTY = {"trump": "rep", "harris": "dem"}


def tier(margin):
    m = abs(margin)
    return "Toss-up" if m < 3 else "Lean" if m < 8 else "Likely" if m < 15 else "Safe"


# Thresholds. History's winner: BASE + PER_EV * EV ** EV_POWER. The other
# side costs 1 more for every full PER_POINT points of 2024 margin (the
# user, 2026-10-03: the gap reflects how close the state was to flipping),
# so the seven swing states -- and NH, MN, VA, NJ, as close -- cost the
# same either way; Texas +2, California +3, D.C. +13.
BASE, PER_EV, EV_POWER, PER_POINT = 1.0, 1.8, 0.5, 6.0


def thresholds(ev, margin):
    won = round(BASE + PER_EV * ev ** EV_POWER)
    lost = won + math.floor(abs(margin) / PER_POINT)
    return won, lost


# Prestige (the user, 2026-10-03): every card has 1-12; California, the
# biggest state, is 12 and the rest scale with their electoral votes.
def prestige(ev):
    return max(1, round(12 * ev / 54))


# The state's card: the strongest card at its price (the user, 2026-10-03).
# Card worth is the simulator's value(): power, plus VP_WEIGHT per prestige.
# A state's card is worth MARGIN more than the best story costing as much
# or less (docs/deck-2024.csv) at its history's-side cost; the big ten (the
# elections deck) BIG_MARGIN more again. Two versions of equal worth
# alternate between the sides: A pays more currency, B draws and destroys.
MARGIN, BIG_MARGIN, VP_WEIGHT = 0.6, 1.5, 0.6
WEIGHT = dict(gen=1.0, party=0.8, draw=1.3, trash=0.8)
POWER_FIELDS = ("gen", "party", "draw", "trash")


def story_value(c):
    """The simulator's value() for a story row of docs/deck-2024.csv."""
    n = lambda f: int(c[f] or 0)
    if c["type"] == "Media event":
        return VP_WEIGHT * n("vp") + 3 * (n("ongoing_gen") + 1.3 * n("ongoing_draw")) - 0.5 * n("others_bonus")
    v = (VP_WEIGHT * n("vp") + n("gen") + 0.8 * n("themed") + 0.6 * n("campaign") + 1.3 * n("draw")
         + 0.8 * n("trash") + 0.4 * n("gain_upto") + 0.6 * n("chain") + 0.8 * n("per_same")
         + n("per_office") + 0.3 * n("defense") + 0.8 * n("retract"))
    return v + {"scandal": 1.5, "discard": 1.0}.get(c["attack"], 0)


def best_by_cost():
    rows = [r for r in csv.DictReader(open(os.path.join(DOCS, "deck-2024.csv"), encoding="utf-8-sig")) if r["step"]]
    best, top = {}, 0.0
    for cost in range(1, max(int(r["cost"]) for r in rows) + 1):
        top = max([top] + [story_value(r) for r in rows if int(r["cost"]) == cost])
        best[cost] = round(top, 2)
    return best


BEST = best_by_cost()


def best_story(cost):
    last = max(BEST)
    return BEST[cost] if cost <= last else BEST[last] + 0.6 * (cost - last)


def worth(p):
    return sum(WEIGHT[f] * p.get(f, 0) for f in WEIGHT)


def powers(ev, cost, big):
    target = best_story(cost) + MARGIN + (BIG_MARGIN if big else 0) - VP_WEIGHT * prestige(ev)
    a = dict(draw=1, party=max(1, round(cost / 3)), trash=0)
    b = dict(draw=2 if target >= 7 else 1, party=max(1, round(cost / 4)), trash=1 if cost >= 6 else 0)
    for p in (a, b):
        p["gen"] = max(0, math.ceil(target - worth(p) - 1e-9))
    return a, b, target


def power_text(p, side):
    parts = []
    if p.get("gen"):
        parts.append("+%d neutral" % p["gen"])
    if p.get("party"):
        parts.append("+%d %s" % (p["party"], "Republican" if side == "trump" else "Democratic"))
    if p.get("draw"):
        parts.append("draw %d card%s" % (p["draw"], "s" if p["draw"] > 1 else ""))
    text = ", ".join(parts)
    if p.get("trash"):
        text += ", then you may destroy a card in your hand or discard pile"
    return text[0].upper() + text[1:] + "."


ORDER = {"Safe": 0, "Likely": 1, "Lean": 2, "Toss-up": 3}
# The ten biggest states are the elections deck (DC's Super-Villains), called
# one at a time; the other 41 are shuffled into the main deck and bought off
# the exchange (the user, 2026-10-03).
ELECTIONS = 10


# Each half's name (docs/state-cards-2024.csv): where that side's 2024 vote
# came from in the state, or "Upset in ..." where it had no real base.
def card_names():
    with open(os.path.join(DOCS, "state-cards-2024.csv"), encoding="utf-8") as fh:
        return {r["key"]: r for r in csv.DictReader(fh)}


def flavor(state, ev, t, h):
    return "%s, %d electoral votes. 2024: Trump %.1f%%, Harris %.1f%%." % (state, ev, t, h)


def main():
    names = card_names()
    big_keys = {r[0] for r in sorted(RESULTS, key=lambda r: -r[2])[:ELECTIONS]}
    assert sorted((r[2] for r in RESULTS), reverse=True)[ELECTIONS - 1] > sorted(
        (r[2] for r in RESULTS), reverse=True)[ELECTIONS]
    rows = []
    for i, (abbr, state, ev, t, h) in enumerate(RESULTS):
        margin = round(t - h, 2)
        winner = "trump" if margin > 0 else "harris"
        won, lost = thresholds(ev, margin)
        big = abbr in big_keys
        a, b, target = powers(ev, won, big)
        mine, theirs = (a, b) if i % 2 == 0 else (b, a)
        side_power = {winner: mine, ("harris" if winner == "trump" else "trump"): theirs}
        row = dict(key=abbr.lower(), abbr=abbr, state=state, ev=ev, trump_pct=t, harris_pct=h, margin=margin,
                   winner=winner, tier=tier(margin), deck="elections" if big else "main", vp=prestige(ev),
                   trump_name=names[abbr.lower()]["trump_name"], harris_name=names[abbr.lower()]["harris_name"],
                   flavor=flavor(state, ev, t, h))
        for side in ("trump", "harris"):
            p = side_power[side]
            row[side + "_threshold"] = won if side == winner else lost
            for f in POWER_FIELDS:
                row["%s_%s" % (side, f)] = p.get(f, 0)
            row[side + "_text"] = power_text(p, side)
            row[side + "_worth"] = round(worth(p) + VP_WEIGHT * prestige(ev), 1)
        row["best_story"] = best_story(won)
        rows.append(row)
    # Safe states first, the closest last (ties: the bigger margin first).
    rows.sort(key=lambda r: (ORDER[r["tier"]], -abs(r["margin"])))
    for n, r in enumerate(rows, 1):
        r["order"] = n
    fields = ["key", "abbr", "state", "ev", "vp", "trump_name", "harris_name", "flavor", "trump_pct", "harris_pct", "margin", "winner", "tier", "order", "deck"]
    for side in ("trump", "harris"):
        fields += [side + "_threshold"] + ["%s_%s" % (side, f) for f in POWER_FIELDS] + [side + "_text", side + "_worth"]
    fields.append("best_story")
    with open(os.path.join(DOCS, "states-2024.csv"), "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=fields)
        w.writeheader()
        w.writerows(rows)
    ev = {s: sum(r["ev"] for r in rows if r["winner"] == s) for s in ("trump", "harris")}
    big = [r for r in rows if r["deck"] == "elections"]
    print("elections deck:", ", ".join("%s %d" % (r["abbr"], r["ev"]) for r in sorted(big, key=lambda r: -r["ev"])),
          "= %d EV; main deck %d states, %d EV" % (sum(r["ev"] for r in big), 51 - len(big),
                                                  538 - sum(r["ev"] for r in big)))
    print("best story by cost (prestige counted): %s" % BEST)
    print("%d contests, %d electoral votes: %s; tiers %s"
          % (len(rows), sum(r["ev"] for r in rows), ev, {t: sum(1 for r in rows if r["tier"] == t) for t in ORDER}))
    for r in sorted(rows, key=lambda r: -r["ev"]):
        s = r["winner"]
        print("  %-3s %2d EV %2d prestige  costs T %2d / H %2d  best story %4.1f  cards T %4.1f / H %4.1f   %s: %s"
              % (r["abbr"], r["ev"], r["vp"], r["trump_threshold"], r["harris_threshold"], r["best_story"],
                 r["trump_worth"], r["harris_worth"], s, r[s + "_text"]))
        assert min(r["trump_worth"], r["harris_worth"]) > r["best_story"], r["abbr"]
    assert set(names) == {r["key"] for r in rows}, "docs/state-cards-2024.csv must name every state"
    assert len(rows) == 51 and sum(r["ev"] for r in rows) == 538 and ev == {"trump": 312, "harris": 226}


if __name__ == "__main__":
    main()
