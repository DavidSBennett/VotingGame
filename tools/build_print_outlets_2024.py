"""Build docs/print/outlets-starters-2024.html: the eight outlet cards, the
starters (Letters to the Editor, Local Notices), the Editorials and the
Scandals as printable cards.

The same card frame as the main deck (tools/build_print_deck_2024.py).
Starters are printed for a full table (STARTERS_PER_OUTLET x MAX_OUTLETS);
the Editorials and Scandals at their copies in docs/deck-2024.csv.

    py -X utf8 tools/build_print_outlets_2024.py
"""
import csv
import html
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from build_print_deck_2024 import CSS, FIT, ROOT, DOCS  # noqa: E402

OUT = os.path.join(DOCS, "print", "outlets-starters-2024.html")
MAX_OUTLETS = 5          # the most outlets at a table (backend/engine_2024.php max_players)

EXTRA_CSS = """
.outlet-card{background:var(--ink);color:var(--paper);border-color:var(--gold);text-align:center}
.outlet-card .eyebrow{color:var(--gold)}
.outlet-card .big{font-family:'Cormorant Garamond',serif;font-weight:700;font-size:17pt;line-height:1;margin:2.5mm 0 1mm}
.outlet-card .rule{height:.3mm;background:var(--gold);margin:1.5mm 8mm}
.outlet-card .power{background:var(--paper);color:var(--ink);border-radius:1.5mm;padding:2mm;margin:1mm 0;flex:1 1 0;
display:flex;flex-direction:column;min-height:0;overflow:hidden}
.outlet-card .power .tag{color:var(--gold7)}
.outlet-card .power .ability{font-family:'Cormorant Garamond',serif;font-weight:700;font-size:13pt;line-height:1.05;margin:.6mm 0}
.outlet-card .power .text{font-size:7.4pt;line-height:1.25;margin-top:auto;margin-bottom:auto}
.outlet-card .flavor{font-size:6.2pt;font-style:italic;color:#e6d4a8;line-height:1.25;flex:.55 1 0;min-height:0;overflow:hidden}
.plain .body{flex:1 1 0;display:flex;flex-direction:column;justify-content:center;text-align:center;border-radius:1.5mm;
padding:2mm;margin-top:1mm;background:var(--paper2);min-height:0}
.plain .body .text{font-family:'Cormorant Garamond',serif;font-weight:600;font-size:12pt;line-height:1.1}
.plain .body .flavor{font-size:6.4pt;font-style:italic;color:var(--muted);margin-top:2mm}
.plain.scandal{border-color:#4a1212} .plain.scandal .body{background:var(--repbg)}
.plain.editorial .body{background:#efe6c8}
.copies{font-family:'JetBrains Mono',monospace;font-size:4.4pt;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);text-align:center;margin-top:1mm}
"""


def e(s):
    return html.escape(s or "", quote=True)


def outlet_card(o):
    return ('<div class="card outlet-card"><div class="eyebrow">Your outlet</div><div class="big">%s</div>'
            '<div class="rule"></div><div class="power"><div class="tag">Ability</div><div class="ability">%s</div>'
            '<div class="text">%s</div></div><div class="flavor">%s</div></div>'
            % (e(o["name"]), e(o["ability_name"]), e(o["ability"]), e(o["flavor"])))


def plain_card(r, eyebrow, note):
    return ('<div class="card plain %s"><div class="head"><div class="cost" title="Cost">%s</div><div class="name">'
            '<div class="eyebrow">%s</div><div class="title">%s</div></div><div class="wealth" title="Wealth">%s$%d</div></div>'
            '<div class="body"><div class="text">%s</div><div class="flavor">%s</div></div><div class="copies">%s</div></div>'
            % (r["key"], e(r["cost"]), e(eyebrow), e(r["name"]), "−" if int(r["vp"]) < 0 else "", abs(int(r["vp"])),
               e(r["card_text"]), e(r["flavor"]), e(note)))


def main():
    outlets = list(csv.DictReader(open(os.path.join(DOCS, "outlets-2024.csv"), encoding="utf-8-sig")))
    deck = {r["key"]: r for r in csv.DictReader(open(os.path.join(DOCS, "deck-2024.csv"), encoding="utf-8-sig"))}
    groups = [("Outlets · 8 cards · one per player", [outlet_card(o) for o in outlets])]
    counts = {"outlets": len(outlets)}
    for key, plural, eyebrow, per in (("letter", "Letters to the Editor", "Starter", True),
                                      ("notice", "Local Notices", "Starter", True),
                                      ("editorial", "Editorials", "Always on offer", False),
                                      ("scandal", "Scandals", "Given by reveals", False)):
        r = deck[key]
        n = int(r["copies"]) * (MAX_OUTLETS if per else 1)
        note = ("%s in each outlet's starting deck · %d printed for %d outlets" % (r["copies"], n, MAX_OUTLETS)) if per \
            else ("%d in the supply" % n)
        groups.append(("%s · %d cards" % (plural, n),
                       [plain_card(r, eyebrow, note)] * n))
        counts[key] = n
    parts = "".join('<div class="section">%s</div><div class="sheet">%s</div>' % (t, "".join(cs)) for t, cs in groups)
    total = sum(counts.values())
    page = ('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<title>The Fourth Estate 2024: outlets and starters</title>'
            '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=Spectral:ital,wght@0,400;0,500;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">'
            '<style>%s%s</style></head><body><header class="page"><h1>The Fourth Estate · 2024 · Outlets and starters</h1>'
            '<p>%d cards: the eight outlets, then the starters for a table of %d, the Editorials and the Scandals. Print on '
            'US letter at 100%% scale: nine cards a page, cut on the dashed lines.</p></header>%s%s</body></html>'
            % (CSS, EXTRA_CSS, total, MAX_OUTLETS, parts, FIT))
    with open(OUT, "w", encoding="utf-8") as fh:
        fh.write(page)
    print("wrote %s: %d cards %s" % (os.path.relpath(OUT, ROOT), total, counts))


if __name__ == "__main__":
    main()
