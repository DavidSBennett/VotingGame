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

# abbr, state, electoral votes, Trump %, Harris %, beat.
# The beat is what the story there was taken to be about: Politics (the
# race itself), Economy (prices, jobs), Culture (the border, abortion,
# faith, guns). Chosen to split the 538 votes about evenly. History's
# winner is called on a beat (the state's own where it can be) and the
# other side on a different one (assign_beats), so the beat an outlet
# covers can make rewriting history the cheaper call; neither candidate
# owns a beat.
RESULTS = [
    ("AL", "Alabama", 9, 64.57, 34.10, "Culture"),
    ("AK", "Alaska", 3, 54.54, 41.41, "Politics"),
    ("AZ", "Arizona", 11, 52.22, 46.69, "Culture"),
    ("AR", "Arkansas", 6, 64.20, 33.56, "Culture"),
    ("CA", "California", 54, 38.33, 58.47, "Politics"),
    ("CO", "Colorado", 10, 43.14, 54.13, "Politics"),
    ("CT", "Connecticut", 7, 41.89, 56.40, "Economy"),
    ("DE", "Delaware", 3, 41.79, 56.49, "Economy"),
    ("DC", "District of Columbia", 3, 6.47, 90.28, "Politics"),
    ("FL", "Florida", 30, 56.09, 42.99, "Culture"),
    ("GA", "Georgia", 16, 50.72, 48.53, "Politics"),
    ("HI", "Hawaii", 4, 37.48, 60.59, "Politics"),
    ("ID", "Idaho", 4, 66.87, 30.38, "Culture"),
    ("IL", "Illinois", 19, 43.47, 54.37, "Economy"),
    ("IN", "Indiana", 11, 58.58, 39.62, "Economy"),
    ("IA", "Iowa", 6, 55.73, 42.52, "Economy"),
    ("KS", "Kansas", 6, 57.16, 41.04, "Economy"),
    ("KY", "Kentucky", 8, 64.47, 33.94, "Economy"),
    ("LA", "Louisiana", 8, 60.22, 38.21, "Culture"),
    ("ME", "Maine", 4, 45.46, 52.40, "Politics"),
    ("MD", "Maryland", 10, 34.08, 62.62, "Politics"),
    ("MA", "Massachusetts", 11, 36.02, 61.22, "Politics"),
    ("MI", "Michigan", 15, 49.73, 48.31, "Economy"),
    ("MN", "Minnesota", 10, 46.68, 50.92, "Economy"),
    ("MS", "Mississippi", 6, 60.89, 38.00, "Culture"),
    ("MO", "Missouri", 10, 58.49, 40.08, "Culture"),
    ("MT", "Montana", 4, 58.39, 38.46, "Culture"),
    ("NE", "Nebraska", 5, 59.32, 38.86, "Economy"),
    ("NV", "Nevada", 6, 50.59, 47.49, "Economy"),
    ("NH", "New Hampshire", 4, 47.87, 50.65, "Politics"),
    ("NJ", "New Jersey", 14, 46.06, 51.97, "Economy"),
    ("NM", "New Mexico", 5, 45.85, 51.85, "Culture"),
    ("NY", "New York", 28, 43.31, 55.91, "Politics"),
    ("NC", "North Carolina", 16, 50.86, 47.65, "Politics"),
    ("ND", "North Dakota", 3, 66.96, 30.51, "Economy"),
    ("OH", "Ohio", 17, 55.14, 43.93, "Economy"),
    ("OK", "Oklahoma", 7, 66.16, 31.90, "Economy"),
    ("OR", "Oregon", 8, 40.97, 55.27, "Politics"),
    ("PA", "Pennsylvania", 19, 50.37, 48.66, "Economy"),
    ("RI", "Rhode Island", 4, 41.76, 55.54, "Politics"),
    ("SC", "South Carolina", 9, 58.23, 40.36, "Culture"),
    ("SD", "South Dakota", 3, 63.43, 34.24, "Culture"),
    ("TN", "Tennessee", 11, 64.19, 34.47, "Culture"),
    ("TX", "Texas", 40, 56.14, 42.46, "Culture"),
    ("UT", "Utah", 6, 59.38, 37.79, "Culture"),
    ("VT", "Vermont", 3, 32.32, 63.83, "Politics"),
    ("VA", "Virginia", 13, 46.05, 51.83, "Politics"),
    ("WA", "Washington", 12, 39.01, 57.23, "Politics"),
    ("WV", "West Virginia", 4, 69.97, 28.10, "Economy"),
    ("WI", "Wisconsin", 10, 49.60, 48.74, "Economy"),
    ("WY", "Wyoming", 3, 71.60, 25.84, "Culture"),
]
# The engine's three themes, under their 2024 names.
THEME = {"Politics": "Political", "Economy": "Economic", "Culture": "Social"}
BEATS = list(THEME)


def assign_beats(rows):
    """Each side's beat in each state: two different beats, chosen so that
    each candidate's electoral votes fall about evenly on the three beats
    (no beat belongs to a side). The biggest states are placed first; the
    state's own beat goes to history's winner when that costs little."""
    load = {s: {b: 0 for b in BEATS} for s in ("trump", "harris")}
    target = {s: sum(r["ev"] for r in rows if r["winner"] == s) / 3 + sum(
        r["ev"] for r in rows if r["winner"] != s) / 3 for s in ("trump", "harris")}
    out = {}
    for r in sorted(rows, key=lambda r: -r["ev"]):
        best = None
        for tb in BEATS:
            for hb in BEATS:
                if tb == hb:
                    continue
                pick = {"trump": tb, "harris": hb}
                cost = 0
                for s in ("trump", "harris"):
                    after = dict(load[s])
                    after[pick[s]] += r["ev"]
                    cost += sum((v - target[s] / 3) ** 2 for v in after.values())
                if pick[r["winner"]] != r["beat"]:
                    cost += 40 * r["ev"]          # prefer the state's own beat for history's winner
                if best is None or cost < best[0]:
                    best = (cost, pick)
        for s, b in best[1].items():
            load[s][b] += r["ev"]
        out[r["key"]] = best[1]
    return out


def tier(margin):
    m = abs(margin)
    return "Toss-up" if m < 3 else "Lean" if m < 8 else "Likely" if m < 15 else "Safe"


# Thresholds. History's winner: BASE + PER_EV * EV ** EV_POWER. The other
# side costs more, by how far history has to be rewritten: half of
# 1 + |margin| / PER_POINT, rounded up (the user halved it, 2026-10-03).
BASE, PER_EV, EV_POWER, PER_POINT = 1.0, 1.8, 0.5, 5.0


def thresholds(ev, margin):
    won = round(BASE + PER_EV * ev ** EV_POWER)
    lost = won + math.ceil((1 + math.floor(abs(margin) / PER_POINT)) / 2)
    return won, lost


# The state's card, by its size, in two versions of about equal worth (the
# simulator's value()). History's winner takes version A in alternate
# states and version B in the others, so neither side has a style of its own.
POWERS = [  # (most EV, A, B)
    (4, dict(gen=1), dict(draw=1)),
    (8, dict(gen=2), dict(gen=1, draw=1)),
    (13, dict(gen=2, themed=1), dict(draw=2)),
    (19, dict(gen=3, draw=1), dict(gen=2, draw=1, trash=1)),
    (54, dict(gen=4, draw=1), dict(gen=2, draw=2)),
]
POWER_FIELDS = ("gen", "themed", "draw", "trash")


def power_text(p, beat):
    parts = []
    if p.get("gen"):
        parts.append("+%d influence" % p["gen"])
    if p.get("themed"):
        parts.append("+%d %s" % (p["themed"], beat))
    if p.get("draw"):
        parts.append("draw %d card%s" % (p["draw"], "s" if p["draw"] > 1 else ""))
    text = ", ".join(parts)
    if p.get("trash"):
        text += ", then you may destroy a card in your hand or discard pile"
    return text[0].upper() + text[1:] + "."


ORDER = {"Safe": 0, "Likely": 1, "Lean": 2, "Toss-up": 3}


def main():
    rows = []
    for i, (abbr, state, ev, t, h, beat) in enumerate(RESULTS):
        margin = round(t - h, 2)
        winner = "trump" if margin > 0 else "harris"
        won, lost = thresholds(ev, margin)
        a, b = next((a, b) for most, a, b in POWERS if ev <= most)
        mine, theirs = (a, b) if i % 2 == 0 else (b, a)
        side_power = {winner: mine, ("harris" if winner == "trump" else "trump"): theirs}
        row = dict(key=abbr.lower(), abbr=abbr, state=state, ev=ev, trump_pct=t, harris_pct=h, margin=margin,
                   winner=winner, tier=tier(margin), beat=beat)
        for side in ("trump", "harris"):
            p = side_power[side]
            row[side + "_threshold"] = won if side == winner else lost
            for f in POWER_FIELDS:
                row["%s_%s" % (side, f)] = p.get(f, 0)
            row[side + "_power"] = p
        rows.append(row)
    beats = assign_beats(rows)
    for row in rows:
        for side in ("trump", "harris"):
            b = beats[row["key"]][side]
            row[side + "_beat"], row[side + "_theme"] = b, THEME[b]
            row[side + "_text"] = power_text(row.pop(side + "_power"), b)
    # Safe states first, the closest last (ties: the bigger margin first).
    rows.sort(key=lambda r: (ORDER[r["tier"]], -abs(r["margin"])))
    for n, r in enumerate(rows, 1):
        r["order"] = n
    fields = ["key", "abbr", "state", "ev", "trump_pct", "harris_pct", "margin", "winner", "tier", "order", "beat"]
    for side in ("trump", "harris"):
        fields += [side + "_beat", side + "_theme", side + "_threshold"] + ["%s_%s" % (side, f) for f in POWER_FIELDS] + [side + "_text"]
    with open(os.path.join(DOCS, "states-2024.csv"), "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=fields)
        w.writeheader()
        w.writerows(rows)
    ev = {s: sum(r["ev"] for r in rows if r["winner"] == s) for s in ("trump", "harris")}
    beats = {b: sum(r["ev"] for r in rows if r["beat"] == b) for b in THEME}
    for side in ("trump", "harris"):
        print(side, "beats by EV:", {b: sum(r["ev"] for r in rows if r[side + "_beat"] == b) for b in THEME})
    tiers = {t: sum(1 for r in rows if r["tier"] == t) for t in ORDER}
    cheap = sum(min(r["trump_threshold"], r["harris_threshold"]) for r in rows)
    print("%d contests, %d electoral votes: %s; beats %s; tiers %s; sum of history's thresholds %d"
          % (len(rows), sum(r["ev"] for r in rows), ev, beats, tiers, cheap))
    assert len(rows) == 51 and sum(r["ev"] for r in rows) == 538 and ev == {"trump": 312, "harris": 226}


if __name__ == "__main__":
    main()
