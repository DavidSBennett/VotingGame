"""Build docs/print/deck-2024.html: the 2024 main deck as printable cards.

Every story card shows its two framings as real headlines: the positive side
on top (its outlet at the lower right of the headline), the oppositional side
below (its outlet at the upper right), each with what that side does. Planks
show their platform wording; the switch card sits between the Biden and the
Harris sets. Poker size (63 x 88 mm), nine to a US-letter page with cut lines.

    py -X utf8 tools/build_print_deck_2024.py
"""
import csv
import html
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")
OUT = os.path.join(DOCS, "print", "deck-2024.html")
PARTY = {"rep": "Republican", "dem": "Democratic"}
OTHER = {"rep": "dem", "dem": "rep"}
SET = {"biden": "Biden set", "harris": "Harris set", "switch": "The switch"}
ORDER = {"biden": 0, "switch": 1, "harris": 2}


def e(s):
    return html.escape(s or "", quote=True)


def head(r, eyebrow):
    wealth = int(r["vp"] or 0)
    return ('<div class="head"><div class="cost" title="Cost">%s</div><div class="name"><div class="eyebrow">%s</div>'
            '<div class="title">%s</div></div><div class="wealth" title="Wealth">$%d</div></div>'
            % (e(r["cost"]), e(eyebrow), e(r["name"]), wealth))


def story(r):
    top, bot = r["lean"], OTHER[r["lean"]]
    kind = r["type"].replace(" story", "")
    return ('<div class="card story">%s'
            '<div class="hand">In hand: %s</div>'
            '<div class="side %s top"><div class="tag">%s side</div>'
            '<div class="headline fit">“%s”</div><div class="outlet">%s</div>'
            '<div class="does">%s</div></div>'
            '<div class="or">or</div>'
            '<div class="side %s bottom"><div class="row"><div class="tag">%s side</div><div class="outlet">%s</div></div>'
            '<div class="headline fit">“%s”</div>'
            '<div class="does">%s</div></div>'
            '<div class="foot">%s · %s</div></div>'
            % (head(r, "%s · %s" % (kind, SET[r["era"]])), e(r["card_text"]),
               top, PARTY[top], e(r["top_title"]), e(r["top_outlet"]), e(r["top_text"]),
               bot, PARTY[bot], e(r["bottom_outlet"]), e(r["bottom_title"]), e(r["bottom_text"]),
               e(r["date"]), e(r["flavor"])))


def plank(r):
    return ('<div class="card plank %s">%s'
            '<div class="side %s top tall"><div class="tag">From the platform</div>'
            '<div class="headline fit">“%s”</div><div class="outlet">%s</div>'
            '<div class="does">%s</div></div>'
            '<div class="flavor fit">%s</div>'
            '<div class="foot">%s · Plank · stays in play</div></div>'
            % (r["lean"], head(r, "%s plank · %s" % (PARTY[r["lean"]], SET[r["era"]])), r["lean"],
               e(r["top_title"]), e(r["top_outlet"]), e(r["top_text"]), e(r["flavor"]), e(r["top_date"])))


def switch(r):
    return ('<div class="card switch"><div class="eyebrow">Between the two sets</div><div class="big">%s</div>'
            '<div class="rule"></div><div class="date">%s</div><div class="flavor">%s</div>'
            '<div class="rule"></div><div class="does">%s</div>'
            '<div class="pair"><div><div class="headline">“%s”</div><div class="outlet">%s</div></div>'
            '<div><div class="headline">“%s”</div><div class="outlet">%s</div></div></div></div>'
            % (e(r["name"]), e(r["date"]), e(r["flavor"]), e(r["top_text"]), e(r["top_title"]), e(r["top_outlet"]),
               e(r["bottom_title"]), e(r["bottom_outlet"])))


CSS = """
:root{--ink:#0c1f22;--paper:#fdf8ea;--paper2:#f4ead0;--gold:#b8923a;--gold7:#665015;--rep:#6b1e1e;--rep5:#9b3a2e;
--repbg:#f6e3dc;--dem:#2c4a68;--dem5:#4f7398;--dembg:#e1e9f2;--muted:#5b5446}
*{box-sizing:border-box}
body{margin:0;background:#143138;color:var(--ink);font-family:Spectral,Georgia,serif}
header.page{color:#f4ead0;padding:20px 16px 8px;max-width:1100px;margin:auto}
header.page h1{font-family:'Cormorant Garamond',Georgia,serif;font-size:34px;margin:0}
header.page p{margin:6px 0 0;color:#e6d4a8;font-style:italic}
.section{color:#d8b968;font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.25em;text-transform:uppercase;
max-width:1100px;margin:18px auto 6px;padding:0 16px}
.sheet{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;max-width:1100px;margin:0 auto;padding:0 16px 16px}
.card{width:63mm;height:88mm;background:var(--paper);border:1.2mm solid var(--gold7);border-radius:3mm;padding:2mm 2.4mm;
display:flex;flex-direction:column;overflow:hidden;position:relative;box-shadow:inset 0 0 0 .3mm var(--gold)}
.head{display:flex;align-items:center;gap:1.5mm}
.cost,.wealth{flex:none;font-family:'Cormorant Garamond',serif;font-weight:700;font-size:13pt;line-height:1}
.cost{width:7mm;height:7mm;border-radius:50%;background:var(--ink);color:var(--paper);display:flex;align-items:center;justify-content:center}
.wealth{color:var(--gold7);font-size:11pt}
.name{flex:1;text-align:center;min-width:0}
.eyebrow{font-family:'JetBrains Mono',monospace;font-size:4.6pt;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.title{font-family:'Cormorant Garamond',serif;font-weight:700;font-size:11pt;line-height:1.02}
.hand{font-size:5.6pt;text-align:center;border-top:.2mm solid var(--gold);border-bottom:.2mm solid var(--gold);margin:1mm 0;padding:.5mm 0}
.side{flex:1 1 0;display:flex;flex-direction:column;border-radius:1.5mm;padding:1.2mm 1.6mm;min-height:0;overflow:hidden}
.side.rep{background:var(--repbg);border-left:1mm solid var(--rep)}
.side.dem{background:var(--dembg);border-left:1mm solid var(--dem)}
.tag{font-family:'JetBrains Mono',monospace;font-size:4.4pt;letter-spacing:.14em;text-transform:uppercase}
.rep .tag{color:var(--rep)} .dem .tag{color:var(--dem)}
.row{display:flex;justify-content:space-between;align-items:baseline;gap:1mm}
.headline{flex:0 1 auto;min-height:0;font-family:'Cormorant Garamond',serif;font-weight:600;font-style:italic;font-size:9.5pt;line-height:1.08;overflow:hidden}
.outlet{font-family:'JetBrains Mono',monospace;font-size:5pt;letter-spacing:.06em;text-transform:uppercase;text-align:right;font-weight:500}
.top .outlet{margin-top:.3mm} .bottom .headline{margin-top:.3mm}
.rep .outlet{color:var(--rep5)} .dem .outlet{color:var(--dem5)}
.does{font-size:6pt;line-height:1.2;margin-top:auto;padding-top:.6mm;border-top:.2mm dotted rgba(12,31,34,.35)}
.or{text-align:center;font-family:'Cormorant Garamond',serif;font-style:italic;font-size:7pt;color:var(--gold7);line-height:1;margin:.6mm 0}
.foot{font-size:4.6pt;color:var(--muted);margin-top:1mm;line-height:1.2;max-height:5.6mm;overflow:hidden}
.plank .side.tall{flex:1.6}
.plank .flavor{flex:.5;min-height:0;font-size:6.2pt;font-style:italic;color:var(--muted);margin-top:1.2mm;overflow:hidden}
.plank.rep{border-color:var(--rep)} .plank.dem{border-color:var(--dem)}
.switch{background:var(--ink);color:var(--paper);border-color:var(--gold);text-align:center;justify-content:space-evenly}
.switch .eyebrow{color:var(--gold)}
.switch .big{font-family:'Cormorant Garamond',serif;font-weight:700;font-size:20pt;line-height:1;margin:2mm 0}
.switch .rule{height:.3mm;background:var(--gold);margin:1.5mm 6mm}
.switch .date{font-family:'JetBrains Mono',monospace;font-size:6pt;color:var(--gold)}
.switch .flavor{font-size:7pt;font-style:italic;margin:1mm 0}
.switch .does{font-size:7pt;border:0}
.switch .pair{display:flex;gap:2mm;margin-top:2mm;text-align:left}
.switch .pair>div{flex:1;background:rgba(255,255,255,.06);padding:1.2mm;border-radius:1mm}
.switch .headline{font-size:7pt;color:var(--paper)} .switch .outlet{color:var(--gold)}
@media print{
 @page{size:letter;margin:7mm 13mm}
 body{background:#fff}
 header.page,.section{display:none}
 .sheet{display:grid;grid-template-columns:repeat(3,63mm);gap:0;padding:0;margin:0;justify-content:start}
 .card{box-shadow:none;outline:.1mm dashed #999;break-inside:avoid}
 .sheet .card:nth-child(9n){break-after:page}
 *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
"""

FIT = """
<script>
// Shrink each headline until it fits its box (never below 5.5pt).
function fit(){document.querySelectorAll('.fit').forEach(function(el){
  var box=el.closest('.side')||el.parentElement, size=parseFloat(getComputedStyle(el).fontSize);
  while(box.scrollHeight>box.clientHeight+0.5&&size>7.3){size-=0.4;el.style.fontSize=size+'px';}
});}
if(document.fonts&&document.fonts.ready){document.fonts.ready.then(fit);}else{window.addEventListener('load',fit);}
</script>
"""


def main():
    rows = [r for r in csv.DictReader(open(os.path.join(DOCS, "deck-2024.csv"), encoding="utf-8-sig")) if r["era"]]
    rows.sort(key=lambda r: (ORDER[r["era"]], r["type"] == "Plank", r["date"], r["name"]))
    parts, counts = [], {}
    current = None
    cards = []
    for r in rows:
        group = SET[r["era"]]
        if group != current:
            if cards:
                parts.append('<div class="sheet">%s</div>' % "".join(cards))
                cards = []
            n = sum(1 for x in rows if x["era"] == r["era"])
            parts.append('<div class="section">%s · %d card%s</div>' % (group, n, "" if n == 1 else "s"))
            current = group
        if r["type"] == "Plank":
            cards.append(plank(r))
        elif r["type"] == "Switch":
            cards.append(switch(r))
        else:
            cards.append(story(r))
        counts[r["type"]] = counts.get(r["type"], 0) + 1
    parts.append('<div class="sheet">%s</div>' % "".join(cards))
    page = ('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<title>The Fourth Estate 2024: main deck</title>'
            '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=Spectral:ital,wght@0,400;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">'
            '<style>%s</style></head><body><header class="page"><h1>The Fourth Estate · 2024 · Main deck</h1>'
            '<p>%d cards: the Biden set, the switch, then the Harris set. Print on US letter at 100%% scale: nine cards a page, '
            'cut on the dashed lines.</p></header>%s%s</body></html>'
            % (CSS, len(rows), "".join(parts), FIT))
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as fh:
        fh.write(page)
    print("wrote %s: %d cards %s" % (os.path.relpath(OUT, ROOT), len(rows), counts))


if __name__ == "__main__":
    main()
