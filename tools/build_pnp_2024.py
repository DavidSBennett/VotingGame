"""Build the print-and-play: docs/print/print-and-play-2024.html (and .pdf).

One file with everything needed to play at a table: a cover with the
contents and how to print, the rulebook (docs/rules-2024.md, a copy of the
"Board Game Rules" doc, without its card appendices), the race sheet that
stands in for the map board, five player aids, every card (the main deck,
the state cards, the outlets, starters, Editorials and Scandals) nine to a
US-letter page, and the photograph credits (docs/art-2024.csv).

    py -X utf8 tools/build_pnp_2024.py            # the page, then the PDF
    py -X utf8 tools/build_pnp_2024.py --no-pdf

Needs the `markdown` package (py -m pip install markdown). The PDF is printed
by Microsoft Edge, headless; without Edge, open the page and print it to PDF
(US letter, 100% scale, background graphics on).
"""
import argparse
import csv
import html
import os
import re
import subprocess
import sys

import markdown

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import build_print_deck_2024 as deck  # noqa: E402
import build_print_outlets_2024 as outlets  # noqa: E402
import build_print_states_2024 as states  # noqa: E402

ROOT, DOCS = deck.ROOT, deck.DOCS
OUT = os.path.join(DOCS, "print", "print-and-play-2024.html")
PDF = OUT[:-5] + ".pdf"
EDGE = (r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Microsoft\Edge\Application\msedge.exe")
DROP = ("Appendix: the 51 states", "Appendix: the main deck", "Designer's notes")

PNP_CSS = """
.doc{background:#fff;color:#111;max-width:8.5in;margin:14px auto;padding:.55in .6in;font-size:9.6pt;line-height:1.38}
.doc h1{font-family:'Cormorant Garamond',Georgia,serif;font-size:30pt;line-height:1.05;margin:0 0 4pt}
.doc h2{font-family:'Cormorant Garamond',Georgia,serif;font-size:17pt;margin:14pt 0 4pt;border-bottom:.6pt solid var(--gold7);break-after:avoid}
.doc h3{font-family:'Cormorant Garamond',Georgia,serif;font-size:13pt;margin:10pt 0 2pt;break-after:avoid}
.doc p{margin:0 0 6pt} .doc ul,.doc ol{margin:0 0 6pt;padding-left:16pt} .doc li{margin-bottom:2pt}
.doc table{border-collapse:collapse;width:100%;margin:4pt 0 8pt;font-size:8.6pt}
.doc th,.doc td{border:.5pt solid #999;padding:2pt 4pt;text-align:left;vertical-align:top}
.doc th{background:var(--paper2)}
.doc tr{break-inside:avoid}
.cover .kicker{font-family:'JetBrains Mono',monospace;font-size:8pt;letter-spacing:.25em;text-transform:uppercase;color:var(--gold7)}
.cover .lead{font-size:12pt;font-style:italic;margin:6pt 0 14pt}
.cover .hero{display:grid;grid-template-columns:repeat(4,1fr);gap:4pt;margin:10pt 0 14pt}
.cover .hero div{height:1.25in;background-size:cover;background-position:center 25%;border-radius:3pt}
.cols{columns:2;column-gap:.3in}
.race table{font-size:8pt} .race td.box{width:.42in} .race td.who{width:.9in}
.race .totals{display:grid;grid-template-columns:1fr 1fr;gap:.2in;margin-top:8pt}
.race .totals div{border:.8pt solid #111;border-radius:3pt;padding:5pt 7pt;font-size:10pt}
.race .totals b{font-family:'Cormorant Garamond',serif;font-size:14pt}
.race .totals .t{border-color:var(--rep)} .race .totals .h{border-color:var(--dem)}
.credits table{font-size:6.6pt;line-height:1.2} .credits td{padding:1pt 3pt;word-break:break-word}
.aid{background:var(--paper)} .aid .head{justify-content:center}
.aid h4{font-family:'JetBrains Mono',monospace;font-size:4.8pt;letter-spacing:.14em;text-transform:uppercase;color:var(--gold7);
margin:1mm 0 .3mm;border-bottom:.2mm solid var(--gold)}
.aid p,.aid li{font-size:5.5pt;line-height:1.2;margin:0} .aid ol,.aid ul{margin:0;padding-left:3.4mm}
.aid b.n{color:#555} .aid b.r{color:var(--rep)} .aid b.d{color:var(--dem)} .aid b.c{color:var(--gold7)}
@media print{
 .doc{margin:0;max-width:none;padding:.3in .22in;break-before:page}
 .doc.cover{break-before:auto}
 .section{display:block!important;break-before:page;break-after:avoid;color:var(--gold7);margin:0 0 2mm;padding:0;font-size:7pt}
}
"""

AID = """<div class="card aid"><div class="head"><div class="name"><div class="eyebrow">Player aid</div>
<div class="title">Your turn</div></div></div>
<h4>Start</h4><p>Take the bonus of each plank you have in play. Then <b>stake</b> or <b>play</b>.</p>
<h4>Stake (in place of the turn)</h4><p>One card from your hand, face down, on Trump or Harris. Say how many you have staked.
Discard your hand, draw 5.</p>
<h4>Play</h4><ol><li><b>Use cards:</b> a story on its top or its bottom, a plank into play, a state&rsquo;s power.</li>
<li><b>Buy states:</b> a face-up state for either side, with its House seats if you like. The next state turns up and its
reveal hits everyone.</li><li><b>Buy cards:</b> stories and planks off the exchange, or an Editorial. To your discard pile.</li>
<li><b>Clean up:</b> planks stay; discard the rest; draw 5.</li></ol>
<h4>End of every turn</h4><p>Refill the exchange to 5. Then the oldest card (leftmost) goes under the main deck; slide left,
deal one.</p>
<h4>Currency (your whole hand pays)</h4><ul><li><b class="n">Neutral:</b> anything.</li><li><b class="r">Republican:</b>
Trump&rsquo;s half of a state, Republican cards.</li><li><b class="d">Democratic:</b> Harris&rsquo;s half, Democratic
cards.</li><li><b class="c">Campaign:</b> states and House seats only.</li></ul>
<h4>The end</h4><p>270 ends it; finish the round. Only cards staked on the winner score. 269&ndash;269: more House
seats wins.</p></div>"""


def e(s):
    return html.escape(s or "", quote=True)


def rules():
    """The rulebook as HTML, without the title block, the card appendices and the designer's notes."""
    md = open(os.path.join(DOCS, "rules-2024.md"), encoding="utf-8").read()
    chunks = re.split(r"(?m)^(?=## )", md)
    keep = [c for c in chunks[1:] if not any(c.startswith("## " + d) for d in DROP)]
    return markdown.markdown("".join(keep), extensions=["tables", "sane_lists"])


def race_sheet(rows):
    rows = sorted(rows, key=lambda r: (-int(r["ev"]), r["state"]))
    per = (len(rows) + 2) // 3
    cols = []
    for i in range(0, len(rows), per):
        body = "".join('<tr><td>%s</td><td>%s</td><td>%s</td><td class="box"></td><td class="box"></td><td class="who"></td></tr>'
                       % (e(r["state"]), e(r["ev"]), e(r["house_seats"]), ) for r in rows[i:i + per])
        cols.append('<table><tr><th>State</th><th>EV</th><th>House</th><th>Trump</th><th>Harris</th><th>Claimed by</th></tr>%s</table>'
                    % body)
    return ('<div class="doc race"><h2>The race sheet</h2><p>This sheet stands in for the map board, its cubes, discs and '
            'markers. When a state is claimed, tick its side and write the outlet that claimed it (ties in wealth go to the outlet '
            'with the most electoral votes claimed). If the House delegation was bought with it, circle the House number. Keep '
            'the running totals below in pencil. <b>270 electoral votes wins</b>; at 269&ndash;269 the side with more House '
            'seats wins.</p><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.12in">%s</div>'
            '<div class="totals"><div class="t"><b>Trump</b><br>Electoral votes: ______________ &nbsp; House seats: __________</div>'
            '<div class="h"><b>Harris</b><br>Electoral votes: ______________ &nbsp; House seats: __________</div></div></div>'
            % "".join(cols))


def credits():
    rows = list(csv.DictReader(open(os.path.join(DOCS, "art-2024.csv"), encoding="utf-8-sig")))
    body = "".join("<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>"
                   % (e(r["card"]), e(r["file"]), e(r["author"] or "see source"), e(r["license"])) for r in rows)
    return ('<div class="doc credits"><h2>Photograph credits</h2><p>Every photograph is from Wikimedia Commons '
            '(commons.wikimedia.org/wiki/File:&lt;file name&gt;), used under the license shown, cropped to fit the card.</p>'
            '<div class="cols"><table><tr><th>Card</th><th>File</th><th>Author</th><th>License</th></tr>%s</table></div></div>' % body)


def cover(counts, pages):
    hero = "".join('<div style="background-image:url(art/%s.jpg)"></div>' % k
                   for k in ("the_mug_shot", "the_state_of_the_union", "state-pa", "the_raleigh_plan"))
    items = "".join("<tr><td>%s</td><td>%s</td><td>%s</td></tr>" % row for row in (
        ("Rulebook", "", "Read first; the Components table lists the boxed game, the notes here say what replaces what."),
        ("Race sheet", "1 page", "Replaces the map board, claim cubes, House discs and vote markers. Print one a game."),
        ("Player aids", "5 cards", "One per player."),
        ("Main deck", "%d cards" % counts["deck"], "The Biden set, the switch card, the Harris set: sort by the line under each card's name."),
        ("State cards", "%d cards" % counts["states"], "Three decks (large, medium, small): sort by the line above each state's name."),
        ("Outlets", "%d cards" % counts["outlets"], "One per player; they replace the outlet boards."),
        ("Letters to the Editor", "%d cards" % counts["letter"], "7 per player."),
        ("Local Notices", "%d cards" % counts["notice"], "3 per player."),
        ("Editorials", "%d cards" % counts["editorial"], "The supply."),
        ("Scandals", "%d cards" % counts["scandal"], "The supply."),
    ))
    return ('<div class="doc cover"><div class="kicker">Print and play · prototype</div><h1>The Fourth Estate: 2024</h1>'
            '<div class="lead">News outlets call the 2024 election, one state at a time, and bet their best stories on who '
            'wins. 2 to 5 players, about 60 to 90 minutes.</div><div class="hero">%s</div>'
            '<h2>What is in this file</h2><table><tr><th>Part</th><th>Count</th><th>Notes</th></tr>%s</table>'
            '<h2>How to print</h2><ul><li>US letter, <b>100%% scale</b> (no &ldquo;fit to page&rdquo;), background graphics on. '
            'The file is about %d pages; the cards start after the race sheet, nine to a page.</li>'
            '<li>Card stock, or plain paper slipped into poker-size sleeves (63 &times; 88 mm) in front of an old playing card. '
            'Cut on the dashed lines.</li>'
            '<li>The cards have no printed backs. Sleeve the main deck, the three state decks and the players&rsquo; cards in '
            'different colours, or keep each deck in its own pile and never mix them: only a player&rsquo;s own deck is '
            'shuffled together.</li></ul>'
            '<h2>What you add</h2><ul><li>A pencil for the race sheet.</li>'
            '<li>Something to hide each player&rsquo;s two stake piles: a propped-up book or a folded sheet as a screen, with '
            'the Trump pile on its left and the Harris pile on its right.</li></ul></div>'
            % (hero, items, pages))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--no-pdf", action="store_true")
    a = ap.parse_args()
    deck_secs, _ = deck.sections()
    state_secs, state_rows = states.sections()
    outlet_secs, counts = outlets.sections()
    counts = dict(counts, deck=sum(len(c) for _, c in deck_secs), states=len(state_rows))
    card_secs = [("Player aids · 5 cards", [AID] * 5)] + deck_secs + state_secs + outlet_secs
    n_cards = sum(len(c) for _, c in card_secs)
    pages = sum((len(c) + 8) // 9 for _, c in card_secs) + 9
    cards = "".join('<div class="section">%s</div><div class="sheet">%s</div>' % (t, "".join(cs)) for t, cs in card_secs)
    page = ('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<title>The Fourth Estate 2024: print and play</title>'
            '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=Spectral:ital,wght@0,400;0,500;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">'
            '<style>%s%s%s%s</style></head><body>%s<div class="doc rules"><h1>Rules</h1><div class="cols">%s</div></div>%s%s%s%s</body></html>'
            % (deck.CSS, states.STATE_CSS, outlets.EXTRA_CSS, PNP_CSS, cover(counts, pages), rules(), race_sheet(state_rows),
               cards, credits(), states.FIT_STATES))
    with open(OUT, "w", encoding="utf-8") as fh:
        fh.write(page)
    print("wrote %s: %d cards" % (os.path.relpath(OUT, ROOT), n_cards))
    if a.no_pdf:
        return
    edge = next((p for p in EDGE if os.path.exists(p)), None)
    if not edge:
        print("no Edge found: open the page and print it to PDF")
        return
    import pathlib
    import tempfile
    import time
    if os.path.exists(PDF):
        os.remove(PDF)
    # Its own profile, so a running Edge does not swallow the call. Edge returns at once and writes the
    # file later: wait for it to appear and stop growing.
    profile = tempfile.mkdtemp(prefix="edge-pnp-")
    subprocess.run([edge, "--headless=new", "--disable-gpu", "--no-pdf-header-footer", "--virtual-time-budget=30000",
                    "--user-data-dir=" + profile, "--print-to-pdf=" + PDF, pathlib.Path(OUT).as_uri()], check=False, timeout=600)
    size, deadline = -1, time.time() + 300
    while time.time() < deadline:
        time.sleep(3)
        now = os.path.getsize(PDF) if os.path.exists(PDF) else -1
        if now > 0 and now == size:
            break
        size = now
    if size <= 0:
        print("Edge did not write the PDF: open the page and print it to PDF")
        return
    print("wrote %s (%.1f MB)" % (os.path.relpath(PDF, ROOT), os.path.getsize(PDF) / 1e6))


if __name__ == "__main__":
    main()
