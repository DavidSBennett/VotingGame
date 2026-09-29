"""Export the DC-style Fourth Estate content (docs/*-dc.csv) to docs/fourth-estate-cards.xlsx.

    py -X utf8 tools/export_cards_xlsx.py

A copy for reading and editing; the CSVs stay the source of truth."""
import csv
import os
from openpyxl import Workbook
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "docs", "fourth-estate-cards.xlsx")

FONT = "Arial"
HEAD_FILL = PatternFill("solid", start_color="143138")      # the game's ink
HEAD_FONT = Font(name=FONT, bold=True, color="F4EAD0", size=10)
BODY_FONT = Font(name=FONT, size=10)
TITLE_FONT = Font(name=FONT, bold=True, size=14)
thin = Side(style="thin", color="B8923A")
INTS = {"space", "year", "vp", "cost", "gen", "themed", "campaign", "draw", "trash", "gain_upto", "chain", "per_same",
        "per_office", "defense", "retract", "ongoing_gen", "ongoing_draw", "others_bonus", "released", "copies",
        "nation_threshold", "states_threshold", "patron_themed", "p_gen", "p_draw", "p_trash", "p_trash_draw",
        "p_gain_upto", "p_recover", "p_per_kind", "p_scry", "p_stay_gen", "fa_n"}
WIDE = {"card_text": 60, "flavor": 50, "power_text": 50, "fa_text": 45, "ability": 55, "name": 30, "card_name": 28,
        "nation": 22, "states": 22, "fa_name": 24}


def read(name):
    with open(os.path.join(ROOT, "docs", name), encoding="utf-8-sig") as fh:
        return list(csv.DictReader(fh))


def table(ws, rows):
    cols = list(rows[0])
    ws.append(cols)
    for r in rows:
        ws.append([int(r[c]) if c in INTS and str(r[c]).lstrip("-").isdigit() else (r[c] if r[c] != "" else None) for c in cols])
    for i, c in enumerate(cols, 1):
        cell = ws.cell(row=1, column=i)
        cell.font, cell.fill = HEAD_FONT, HEAD_FILL
        cell.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
        ws.column_dimensions[get_column_letter(i)].width = WIDE.get(c, max(8, min(18, len(c) + 3)))
    for row in ws.iter_rows(min_row=2):
        for cell in row:
            cell.font = BODY_FONT
            cell.alignment = Alignment(vertical="top", wrap_text=cell.column_letter in [
                get_column_letter(cols.index(c) + 1) for c in cols if c in WIDE])
    ws.freeze_panes = "C2"
    ws.auto_filter.ref = ws.dimensions
    ws.row_dimensions[1].height = 30
    return cols


wb = Workbook()

# ---- Read Me ------------------------------------------------------------
rm = wb.active
rm.title = "Read Me"
lines = [
    ("The Fourth Estate (variant) -- card content", TITLE_FONT),
    ("Exported from docs/deck-dc.csv, docs/elections-dc.csv and docs/papers-dc.csv on the variant branch.", None),
    ("Those CSV files are what the game reads; this workbook is a copy. Column names are unchanged, so edited rows can be imported back.", None),
    ("", None),
    ("Sheets", Font(name=FONT, bold=True, size=11)),
    ("Stories -- every card kind: stories, media events, starters (Letter, Local Notice), Editorial, Scandal.", None),
    ("Elections -- the 17 election cards: two candidates each, thresholds, the card's power and its First Appearance event.", None),
    ("Newspapers -- the eight papers (DC's Super Heroes) and their abilities.", None),
    ("Summary -- counts by type, theme and era, as formulas over the Stories sheet.", None),
    ("", None),
    ("Stories columns", Font(name=FONT, bold=True, size=11)),
    ("cost = price on the exchange; vp = prestige (the score); gen = plain influence; themed = influence of the card's theme;", None),
    ("campaign = influence for elections only; draw = cards drawn; trash = cards you may destroy; gain_upto = gain a story costing that or less;", None),
    ("chain = +N if you play another story of the theme; per_same = +N per other story of the theme; per_office = +N per election won;", None),
    ("attack = discard or scandal (hits every rival); defense = 1 if it blocks an attack; retract = Retraction (draw a card, destroy a Scandal);", None),
    ("ongoing_gen / ongoing_draw = a media event's bonus to its owner each turn; others_bonus / others_theme = its bonus to every other paper;", None),
    ("released = the election whose campaign releases it into the main deck; year = the event's date; copies = how many exist.", None),
    ("", None),
    ("Elections columns", Font(name=FONT, bold=True, size=11)),
    ("nation / states = the two candidates; *_theme = the influence that counts toward him; *_threshold = influence needed to elect him.", None),
    ("historical_winner = history's choice (2 cheaper); vp = prestige of the card; patron_themed = influence of the elected candidate's theme when played.", None),
    ("p_* = the card's power when played: p_gen influence, p_draw, p_trash (destroy up to N), p_trash_draw (draw one per card destroyed),", None),
    ("p_gain_upto, p_recover (cards from discard to hand), p_per_kind (+N per different kind of card played), p_scry (look at the top N, keep 1),", None),
    ("p_stay_gen (stays in play: +N each turn; none now), p_attack.", None),
    ("fa_kind / fa_n = the First Appearance when the campaign opens: discard, discard_leader, discard_dearest, destroy_cheapest, draw, draw_fewest,", None),
    ("scandal, scandal_leader, unscandal, sweep; fa_n = how many.", None),
]
for text, font in lines:
    rm.append([text])
    rm.cell(row=rm.max_row, column=1).font = font or BODY_FONT
rm.column_dimensions["A"].width = 150

# ---- the three tables -----------------------------------------------------
st = wb.create_sheet("Stories")
scols = table(st, read("deck-dc.csv"))
el = wb.create_sheet("Elections")
table(el, read("elections-dc.csv"))
pp = wb.create_sheet("Newspapers")
table(pp, read("papers-dc.csv"))

# ---- Summary: formulas over the Stories sheet -------------------------------
sm = wb.create_sheet("Summary")
n = st.max_row
col = lambda name: get_column_letter(scols.index(name) + 1)
T, TH, R, C, V = col("type"), col("theme"), col("released"), col("copies"), col("vp")
rng = lambda c: "Stories!$%s$2:$%s$%d" % (c, c, n)
sm["A1"] = "Summary of the Stories sheet (formulas: edit the Stories sheet and these update)"
sm["A1"].font = TITLE_FONT
sm.append([])
sm.append(["Card type", "Kinds", "Copies", "Total prestige (vp x copies)"])
types = ["Political story", "Economic story", "Social story", "Negative story", "Media event", "Starter", "Editorial", "Scandal"]
start = sm.max_row + 1
for t in types:
    r = sm.max_row + 1
    sm.append([t,
               '=COUNTIFS(%s,$A%d)' % (rng(T), r),
               '=SUMIFS(%s,%s,$A%d)' % (rng(C), rng(T), r),
               '=SUMPRODUCT((%s=$A%d)*%s*%s)' % (rng(T), r, rng(V), rng(C))])
end = sm.max_row
sm.append(["Total", "=SUM(B%d:B%d)" % (start, end), "=SUM(C%d:C%d)" % (start, end), "=SUM(D%d:D%d)" % (start, end)])
sm.append([])
sm.append(["Theme (stories and negative stories)", "Era I", "Era II", "Era III", "All"])
era_releases = {"Era I": (1796, 1804), "Era II": (1808, 1828), "Era III": (1832, 1860)}
for th in ["Political", "Economic", "Social"]:
    r = sm.max_row + 1
    row = [th]
    for lo, hi in era_releases.values():
        row.append('=COUNTIFS(%s,$A%d,%s,">=%d",%s,"<=%d")' % (rng(TH), r, rng(R), lo, rng(R), hi))
    row.append("=SUM(B%d:D%d)" % (r, r))
    sm.append(row)
sm.append([])
sm.append(["Elections: total prestige on the 17 cards", "=SUM(Elections!$D$2:$D$18)"])
for rrow in sm.iter_rows(min_row=2):
    for cell in rrow:
        cell.font = BODY_FONT
for r in (3, sm.max_row - 6):
    for cell in sm[r]:
        if cell.value is not None:
            cell.font, cell.fill = HEAD_FONT, HEAD_FILL
sm.column_dimensions["A"].width = 42
for c in "BCDE":
    sm.column_dimensions[c].width = 16

from openpyxl.workbook.properties import CalcProperties
wb.calculation = CalcProperties(fullCalcOnLoad=True)   # Excel computes every formula on open
wb.save(OUT)
print("wrote", OUT)
