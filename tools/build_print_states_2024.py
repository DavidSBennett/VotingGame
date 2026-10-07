"""Build docs/print/states-2024.html: the 51 state cards as printable cards.

The same card frame as the main deck (tools/build_print_deck_2024.py). Each
state card carries its reveal on top: the real headline from the state's own
press, its outlet at the lower right of the headline, and what the reveal does
to every outlet. Below are the two halves the state can be bought as: Trump's
(cost, name, power) and Harris's. Large, medium and small decks in turn.

    py -X utf8 tools/build_print_states_2024.py
"""
import csv
import html
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from build_print_deck_2024 import CSS, FIT, ROOT, DOCS  # noqa: E402

OUT = os.path.join(DOCS, "print", "states-2024.html")
DECKS = (("large", "Large deck"), ("medium", "Medium deck"), ("small", "Small deck"))
EFFECT = {"scandal": "Every outlet takes a Scandal.", "discard": "Every outlet discards a card at random.",
          "discard_dearest": "Every outlet discards its dearest card.",
          "sweep": "The stories on the exchange are swept away and dealt fresh."}

STATE_CSS = """
.state .ev{flex:none;width:9mm;height:9mm;border-radius:1.5mm;background:var(--ink);color:var(--paper);display:flex;
flex-direction:column;align-items:center;justify-content:center;line-height:1}
.state .ev b{font-family:'Cormorant Garamond',serif;font-size:12pt}
.state .ev span{font-family:'JetBrains Mono',monospace;font-size:3.6pt;letter-spacing:.1em}
.reveal{flex:1.25 1 0;display:flex;flex-direction:column;min-height:0;overflow:hidden;border-radius:1.5mm;padding:1.2mm 1.6mm;
background:var(--paper2);border-left:1mm solid var(--gold7)}
.reveal .tag{color:var(--gold7)} .reveal .outlet{color:var(--gold7);margin-top:.3mm}
.reveal .headline{font-size:9pt}
.reveal .summary{font-size:5.4pt;line-height:1.2;font-style:italic;color:var(--muted);margin-top:.6mm}
.reveal .does{font-weight:500}
.half{flex:1 1 0;display:flex;flex-direction:column;min-height:0;overflow:hidden;border-radius:1.5mm;padding:1.1mm 1.6mm;margin-top:1mm}
.half.trump{background:var(--repbg);border-left:1mm solid var(--rep)}
.half.harris{background:var(--dembg);border-left:1mm solid var(--dem)}
.half .row{align-items:center}
.half .who{font-family:'JetBrains Mono',monospace;font-size:4.4pt;letter-spacing:.14em;text-transform:uppercase}
.trump .who{color:var(--rep)} .harris .who{color:var(--dem)}
.half .price{flex:none;width:5.6mm;height:5.6mm;border-radius:50%;color:var(--paper);font-family:'Cormorant Garamond',serif;
font-weight:700;font-size:9.5pt;display:flex;align-items:center;justify-content:center}
.trump .price{background:var(--rep)} .harris .price{background:var(--dem)}
.half .who-name{font-family:'Cormorant Garamond',serif;font-weight:700;font-size:9pt;line-height:1.05;margin-top:.3mm}
.half .power{font-size:5.4pt;line-height:1.15;margin-top:auto;padding-top:.4mm}
.state.large{border-color:#3b2a0c} .state .deckmark{font-family:'JetBrains Mono',monospace;font-size:4.4pt;letter-spacing:.12em;
text-transform:uppercase;color:var(--muted)}
.placeholder .headline{color:var(--muted)}
.house{font-size:4.8pt;font-weight:500;margin-top:.6mm;padding:.3mm 1mm;border:.2mm solid var(--gold);border-radius:1mm;text-align:center}
"""

FIT_STATES = FIT.replace("el.closest('.side')", "el.closest('.side,.reveal,.half')")


def e(s):
    return html.escape(s or "", quote=True)


def summary_and_effect(r):
    """The reveal text is a one-sentence summary followed by the effect."""
    effect = EFFECT.get(r["reveal_kind"], "")
    n = int(r["reveal_n"] or 1)
    text = r["reveal_text"] or ""
    for eff in EFFECT.values():
        text = text.replace(eff, "")
    text = re.sub(r"\s*Every outlet discards (two|three) cards at random\.", "", text).strip()
    if n > 1 and r["reveal_kind"] == "discard":
        effect = "Every outlet discards %s cards at random." % {2: "two", 3: "three"}.get(n, str(n))
    return text, effect


def card(r):
    text, effect = summary_and_effect(r)
    swing = int(r["trump_strike"] or 0)
    eyebrow = "%s · %s%s" % (dict(DECKS)[r["deck"]], r["tier"], " · swing state" if swing else "")
    if r["reveal_title"]:
        outlet = re.sub(r"\s*\(.*\)$", "", r["reveal_outlet"])
        reveal = ('<div class="reveal"><div class="tag">When revealed · %s</div><div class="headline fit">“%s”</div>'
                  '<div class="outlet">%s</div><div class="summary">%s</div><div class="does">%s</div></div>'
                  % (e(r["reveal_date"]), e(r["reveal_title"]), e(outlet), e(text), e(effect)))
    else:
        reveal = ('<div class="reveal placeholder"><div class="tag">When revealed</div><div class="headline">The state\'s '
                  'own press: article to come.</div><div class="does">%s</div></div>' % e(effect))
    seats, hc = int(r["house_seats"] or 0), int(r["house_cost"] or 0)
    house = ("House: %d seat%s · +%d with the state (269-269 tiebreak)" % (seats, "" if seats == 1 else "s", hc)
             if seats else "No House seats")
    halves = ""
    for side, who in (("trump", "Trump"), ("harris", "Harris")):
        halves += ('<div class="half %s"><div class="row"><div class="who">For %s%s</div><div class="price" title="Cost">%s</div></div>'
                   '<div class="who-name">%s</div><div class="power">%s</div></div>'
                   % (side, who, " · won 2024" if r["winner"] == side else "", e(r[side + "_threshold"]),
                      e(r[side + "_name"]), e(r[side + "_text"])))
    return ('<div class="card state %s"><div class="head"><div class="ev"><b>%s</b><span>EV</span></div><div class="name">'
            '<div class="eyebrow">%s</div><div class="title">%s</div></div><div class="wealth" title="Wealth">$%s</div></div>'
            '%s%s<div class="house">%s</div><div class="foot">%s</div></div>'
            % (r["deck"], e(r["ev"]), e(eyebrow), e(r["state"]), e(r["vp"]), reveal, halves, e(house), e(r["flavor"])))


def main():
    rows = list(csv.DictReader(open(os.path.join(DOCS, "states-2024.csv"), encoding="utf-8-sig")))
    parts = []
    for deck, label in DECKS:
        group = sorted((r for r in rows if r["deck"] == deck), key=lambda r: (-int(r["ev"]), r["state"]))
        ev = sum(int(r["ev"]) for r in group)
        parts.append('<div class="section">%s · %d states · %d electoral votes</div>' % (label, len(group), ev))
        parts.append('<div class="sheet">%s</div>' % "".join(card(r) for r in group))
    page = ('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<title>The Fourth Estate 2024: state cards</title>'
            '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=Spectral:ital,wght@0,400;0,500;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">'
            '<style>%s%s</style></head><body><header class="page"><h1>The Fourth Estate · 2024 · State cards</h1>'
            '<p>51 states in three decks. On top, the reveal: a headline from the state\'s own press and what it does when '
            'the card turns face up. Below, the card bought for Trump or for Harris. Print on US letter at 100%% scale: nine '
            'cards a page, cut on the dashed lines.</p></header>%s%s</body></html>'
            % (CSS, STATE_CSS, "".join(parts), FIT_STATES))
    with open(OUT, "w", encoding="utf-8") as fh:
        fh.write(page)
    print("wrote %s: %d cards, %s" % (os.path.relpath(OUT, ROOT), len(rows),
                                       {d: sum(r["deck"] == d for r in rows) for d, _ in DECKS}))


if __name__ == "__main__":
    main()
