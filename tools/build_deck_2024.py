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

WARNING: this OVERWRITES docs/deck-2024.csv.

    py -X utf8 tools/build_deck_2024.py
"""
import csv
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")
KEEP = {"Political story": 15, "Economic story": 16, "Social story": 13, "Negative story": 16, "Media event": 5}
STEPS = 10


def main():
    src = list(csv.DictReader(open(os.path.join(DOCS, "deck-dc.csv"), encoding="utf-8-sig")))
    fields = list(src[0].keys()) + ["lean", "step"]
    years = sorted({int(r["released"]) for r in src if r["released"]})
    out = []
    for r in src:
        if not r["released"]:
            out.append(dict(r, lean="", step=""))
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
            out.append(dict(r, lean=lean, step=step))
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
