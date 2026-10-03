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
- `step`: the calendar step (0-9) the story is released on, from its year
  (the variant's 17 election years spread over the 10 big-state calls).
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
KEEP = {"Political story": 15, "Economic story": 16, "Social story": 13, "Negative story": 16, "Media event": 5}
STEPS = 10


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


def main():
    src = list(csv.DictReader(open(os.path.join(DOCS, "deck-dc.csv"), encoding="utf-8-sig")))
    fields = list(src[0].keys()) + ["stars", "lean", "step", "date"]
    news = stories_2024()
    for r in src:
        r["stars"] = r["vp"]
        if r["type"] == "Starter":
            r["vp"] = STARTER_VP
        elif r["type"] != "Scandal":
            r["vp"] = PRESTIGE[int(r["vp"])]
    years = sorted({int(r["released"]) for r in src if r["released"]})
    out = []
    for r in src:
        if not r["released"]:
            row = dict(r, lean="", step="", date="")
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
            step = years.index(int(r["released"])) * STEPS // len(years)
            row = dict(r, lean=lean, step=step)
            row["card_text"] = text_2024(row)
            s = news[r["key"]]              # the 2024 story on this slot
            row.update(key=s["key"], name=s["name"], date=s["date"], year=s["date"][:4], flavor=s["flavor"],
                       released="")
            out.append(row)
        flip += k            # alternate which party a kind starts with
    with open(os.path.join(DOCS, "deck-2024.csv"), "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=fields)
        w.writeheader()
        w.writerows(out)
    stories = [r for r in out if r["step"] != ""]
    print("%d stories: %s" % (len(stories), {k: sum(1 for r in stories if r["type"] == k) for k in KEEP}))
    print("lean:", {l: sum(1 for r in stories if r["lean"] == l) for l in ("rep", "dem")},
          " by step:", [sum(1 for r in stories if int(r["step"]) == s) for s in range(STEPS)])
    for kind in KEEP:
        print("  %-15s rep %s | dem %s" % (kind, sorted(int(r["cost"]) for r in stories if r["type"] == kind and r["lean"] == "rep"),
                                         sorted(int(r["cost"]) for r in stories if r["type"] == kind and r["lean"] == "dem")))


if __name__ == "__main__":
    main()
