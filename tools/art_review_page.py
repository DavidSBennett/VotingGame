"""Build docs/art-review.html from docs/art-candidates.json.

    py -X utf8 tools/art_review_page.py

One row per card with the Library of Congress results as thumbnails (shown
from loc.gov; nothing is downloaded). Click a thumbnail to pick it, click
again to unpick; "Copy picks" puts the choices on the clipboard to paste
back. Picks are remembered in the browser while you work.
"""
import html
import json
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "docs", "art-candidates.json")
OUT = os.path.join(ROOT, "docs", "art-review.html")


def main():
    data = json.load(open(SRC, encoding="utf-8"))
    rows = []
    for key, v in data.items():
        thumbs = []
        for i, r in enumerate(v["results"], 1):
            thumbs.append(
                '<figure class="t" data-key="%s" data-i="%d">'
                '<img loading="lazy" src="%s" alt="">'
                '<figcaption><span class="n">%d</span> %s <i>%s</i> <a href="%s" target="_blank" rel="noopener">LoC&nbsp;&#8599;</a></figcaption>'
                "</figure>" % (html.escape(key), i, html.escape(r["thumb"]), i, html.escape(r["title"][:90]),
                               html.escape(str(r["date"])[:4]), html.escape(r["url"])))
        body = "".join(thumbs) or '<p class="none">No prints found. I will search again with other words, or draw this one.</p>'
        rows.append('<section id="%s"><h2>%s <small>%s &middot; %s</small><span class="pick" data-for="%s"></span></h2>'
                    '<div class="row">%s</div></section>'
                    % (html.escape(key), html.escape(v["name"]), v["year"], html.escape(key), html.escape(key), body))
    page = """<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Period Art Review</title>
<style>
:root { --paper:#f4ecd8; --ink:#1c1813; --gold:#a8812e; --muted:#6b604f; --pick:#7a1f1f; }
* { box-sizing:border-box }
body { margin:0; background:var(--paper); color:var(--ink); font:15px/1.4 Georgia, serif; }
header { position:sticky; top:0; z-index:5; background:var(--ink); color:var(--paper); padding:10px 16px; display:flex; gap:12px; align-items:center; flex-wrap:wrap }
header h1 { font-size:18px; margin:0; flex:1 }
header button { font:inherit; background:var(--gold); color:var(--ink); border:0; padding:6px 12px; cursor:pointer }
#count { font-family:monospace }
main { padding:8px 16px 40px; max-width:1400px; margin:0 auto }
.intro { color:var(--muted); max-width:70ch }
section { border-top:1px solid #cdbf9c; padding:10px 0 }
h2 { font-size:17px; margin:0 0 6px; display:flex; gap:8px; align-items:baseline; flex-wrap:wrap }
h2 small { color:var(--muted); font-size:12px; font-family:monospace }
.pick { margin-left:auto; font-size:12px; font-family:monospace; color:var(--pick) }
.row { display:flex; gap:10px; overflow-x:auto; padding-bottom:6px }
.t { margin:0; width:150px; flex:0 0 auto; cursor:pointer; border:3px solid transparent; background:#fff8; padding:3px }
.t img { width:100%; height:130px; object-fit:contain; background:#e9dfc6; display:block }
.t figcaption { font-size:11px; line-height:1.25; margin-top:3px; max-height:5.2em; overflow:hidden }
.t .n { font-family:monospace; font-weight:bold }
.t a { color:var(--gold) }
.t.on { border-color:var(--pick); background:#fff }
.none { color:var(--muted); font-style:italic; margin:4px 0 }
</style></head><body>
<header><h1>Period art for The Fourth Estate</h1><span id="count"></span>
<button id="copy">Copy picks</button></header>
<main>
<p class="intro">Prints and drawings dated 1875 or earlier from the Library of Congress, searched for each card. Click one to pick it
for that card (click again to unpick); "LoC" opens its record. When done, press <b>Copy picks</b> and paste the result into the chat.
Rows you leave empty I will search again or fill another way. Nothing has been downloaded yet.</p>
@@ROWS@@
</main>
<script>
const picks = (() => { try { return JSON.parse(localStorage.getItem('art-picks') || '{}'); } catch (e) { return {}; } })();
function save() { try { localStorage.setItem('art-picks', JSON.stringify(picks)); } catch (e) {} }
function render() {
  document.querySelectorAll('.t').forEach(t => t.classList.toggle('on', picks[t.dataset.key] === +t.dataset.i));
  document.querySelectorAll('.pick').forEach(p => { const k = p.dataset.for; p.textContent = picks[k] ? 'picked #' + picks[k] : ''; });
  document.getElementById('count').textContent = Object.keys(picks).length + ' of @@TOTAL@@ picked';
}
document.addEventListener('click', e => {
  if (e.target.closest('a')) return;
  const t = e.target.closest('.t'); if (!t) return;
  const k = t.dataset.key, i = +t.dataset.i;
  if (picks[k] === i) delete picks[k]; else picks[k] = i;
  save(); render();
});
document.getElementById('copy').addEventListener('click', async () => {
  const text = 'art picks: ' + Object.entries(picks).map(([k, i]) => k + '=' + i).join(', ');
  try { await navigator.clipboard.writeText(text); document.getElementById('copy').textContent = 'Copied'; }
  catch (e) { prompt('Copy this:', text); }
  setTimeout(() => document.getElementById('copy').textContent = 'Copy picks', 1500);
});
render();
</script></body></html>
""".replace("@@ROWS@@", "\n".join(rows)).replace("@@TOTAL@@", str(len(data)))
    open(OUT, "w", encoding="utf-8").write(page)
    print("wrote", os.path.relpath(OUT, ROOT), len(data), "cards")


if __name__ == "__main__":
    main()
