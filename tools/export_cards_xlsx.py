"""Export the DC-style Fourth Estate content (docs/*-dc.csv) to docs/fourth-estate-cards.xlsx.

    py -X utf8 tools/export_cards_xlsx.py

A copy for reading and editing; the CSVs stay the source of truth."""
import csv
import os
from openpyxl import Workbook
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
import sys
# An output name may be given (the default file may be open in Excel, which locks it).
OUT = os.path.join(ROOT, "docs", sys.argv[1] if len(sys.argv) > 1 else "fourth-estate-cards.xlsx")

FONT = "Arial"
HEAD_FILL = PatternFill("solid", start_color="143138")      # the game's ink
HEAD_FONT = Font(name=FONT, bold=True, color="F4EAD0", size=10)
BODY_FONT = Font(name=FONT, size=10)
TITLE_FONT = Font(name=FONT, bold=True, size=14)
thin = Side(style="thin", color="B8923A")
INTS = {"Prestige", "Prestige per office", "Influence (plain)", "Political influence", "Economic influence",
        "Social influence", "Campaign influence", "Candidate-theme influence", "Draw", "Destroy",
        "Draw per destroyed", "Gain (cost up to)", "Recover", "Per kind played", "Look at top", "Stays in play",
        "First Appearance: how many", "threshold",
        "space", "year", "vp", "vp_per_office", "cost", "gen", "themed", "campaign", "draw", "trash", "gain_upto", "chain", "per_same",
        "per_office", "defense", "retract", "ongoing_gen", "ongoing_draw", "others_bonus", "released", "copies",
        "nation_threshold", "states_threshold", "patron_themed", "p_gen", "p_draw", "p_trash", "p_trash_draw",
        "p_gain_upto", "p_recover", "p_per_kind", "p_scry", "p_stay_gen", "fa_n"}
WIDE = {"prompt": 110, "subject": 50, "Action 1": 16, "Action 2": 34, "Action 3": 34, "Power": 55, "First Appearance": 45, "card_text": 60, "candidate": 22, "flavor": 50, "power_text": 50, "fa_text": 45, "ability": 55, "name": 30, "card_name": 28,
        "nation": 22, "states": 22, "fa_name": 24}


def read(name):
    with open(os.path.join(ROOT, "docs", name), encoding="utf-8-sig") as fh:
        return list(csv.DictReader(fh))


# Readable columns: influence and prestige each in their own column, next to
# the cost. The CSV keeps one "themed" number (the card's theme says which);
# here it is split into one column per theme.
THEMES = ["Political", "Economic", "Social"]


def stories_rows(rows):
    out = []
    for r in rows:
        n = {k: r[k] for k in ("key", "name", "type", "theme", "cost")}
        n["Prestige"] = r["vp"]
        n["Influence (plain)"] = r["gen"]
        for t in THEMES:
            n[t + " influence"] = r["themed"] if (r["theme"] == t and r["themed"] not in ("", "0")) else "0"
        n["Campaign influence"] = r["campaign"]
        for k, v in r.items():
            if k not in n and k not in ("vp", "gen", "themed", "campaign"):
                n[k] = v
        out.append(n)
    return out


def plural(n, word):
    return "%d %s%s" % (n, word, "" if n == 1 else "s")


def actions(c):
    """A candidate card's actions as short phrases. Action 1 is always the draw
    (blank if the card draws nothing); Actions 2 and 3 are the rest, in order."""
    n = lambda f: int(c[f] or 0)
    first = "Draw %s" % plural(n("p_draw"), "card") if n("p_draw") else ""
    rest = []
    if n("p_trash"):
        rest.append("Destroy up to %s%s" % (plural(n("p_trash"), "card"), ", draw 1 for each" if n("p_trash_draw") else ""))
    if n("p_gain_upto"):
        rest.append("Gain a story costing %d or less" % n("p_gain_upto"))
    if n("p_recover"):
        rest.append("Recover %s from discard" % plural(n("p_recover"), "card"))
    if n("p_per_kind"):
        rest.append("+%d influence per kind of card played" % n("p_per_kind"))
    if n("p_scry"):
        rest.append("Look at the top %d, keep 1" % n("p_scry"))
    if n("p_stay_gen"):
        rest.append("Stays in play: +%d influence each turn" % n("p_stay_gen"))
    if c["p_attack"]:
        rest.append("Attack: " + ("each rival discards a card" if c["p_attack"] == "discard" else "each rival gains a Scandal"))
    assert len(rest) <= 2, (c["key"], rest)
    return [first] + rest + [""] * (2 - len(rest))


def elections_rows(rows, cands):
    """One row per candidate's card (34): the election, the candidate, his card's
    prestige and power in readable columns, then the election's First Appearance."""
    by = {(c["year"], c["side"]): c for c in cands}
    out = []
    for r in rows:
        for side in ("nation", "states"):
            c = by[(r["year"], side)]
            n = {"key": c["key"], "year": r["year"], "era": r["era"], "side": side, "candidate": c["candidate"],
                 "History's choice": "yes" if r["historical_winner"] == side else "",
                 "theme": c["theme"], "threshold": c["threshold"], "card_name": c["card_name"],
                 "Prestige": c["vp"], "Prestige per office": c["vp_per_office"]}
            n["Influence (plain)"] = c["p_gen"]
            n["Candidate-theme influence"] = c["themed"]
            n["Action 1"], n["Action 2"], n["Action 3"] = actions(c)
            n["Power"] = c["power_text"]
            n["fa_name"] = r["fa_name"]
            n["First Appearance"] = r["fa_text"]
            n["fa_kind"] = r["fa_kind"]
            n["First Appearance: how many"] = r["fa_n"]
            out.append(n)
    return out


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
    ("Exported from docs/deck-dc.csv, docs/elections-dc.csv, docs/candidates-dc.csv and docs/papers-dc.csv on the variant branch.", None),
    ("Those CSV files are what the game reads; this workbook is a copy. Influence and prestige are split into readable columns here;", None),
    ("every other column keeps its CSV name, and the key column identifies each card, so edited rows can be imported back.", None),
    ("", None),
    ("Sheets", Font(name=FONT, bold=True, size=11)),
    ("Stories -- every card kind: stories, media events, starters (Letter, Local Notice), Editorial, Scandal.", None),
    ("Elections -- 34 election cards, one per candidate (two rows per year): his card's prestige and power, one column per effect,", None),
    ("             and the First Appearance event that opens that year's campaign (the same on both rows).", None),
    ("Newspapers -- the eight papers (DC's Super Heroes) and their abilities.", None),
    ("Art prompts -- one image-generation prompt per card (144): woodcut to 1819, wood engraving 1820-1835, lithograph 1836-1860.", None),
    ("             Save each image as frontend/public/art/<file>; the card shows it when it is there. Edit the subject in tools/build_art_prompts.py.", None),
    ("Summary -- counts by type, theme and era, as formulas over the Stories sheet.", None),
    ("", None),
    ("Stories columns", Font(name=FONT, bold=True, size=11)),
    ("cost = price on the exchange; Prestige = the score; Influence (plain) = spends on anything;", None),
    ("Political / Economic / Social influence = influence of that theme (a story has at most one); Campaign influence = elections only;", None),
    ("draw = cards drawn; trash = cards you may destroy; gain_upto = gain a story costing that or less;", None),
    ("chain = +N if you play another story of the theme; per_same = +N per other story of the theme; per_office = +N per election won;", None),
    ("attack = discard or scandal (hits every rival); defense = 1 if it blocks an attack; retract = Retraction (draw a card, destroy a Scandal);", None),
    ("ongoing_gen / ongoing_draw = a media event's bonus to its owner each turn; others_bonus / others_theme = its bonus to every other paper;", None),
    ("released = the election whose campaign releases it into the main deck; year = the event's date; copies = how many exist.", None),
    ("", None),
    ("Elections columns", Font(name=FONT, bold=True, size=11)),
    ("key = year-side (nation or states); History's choice = yes on the candidate history elected (2 cheaper to elect);", None),
    ("theme = the influence that counts toward him; threshold = influence needed to elect him; card_name = the card his Patron gains;", None),
    ("Prestige = the card's prestige; Prestige per office = prestige for each election card its holder owns (1860);", None),
    ("when played: Influence (plain) = plain influence; Candidate-theme influence = influence of his theme;", None),
    ("Action 1 = the draw (Draw N cards; blank if none); Action 2 and Action 3 = its other actions: Destroy up to N cards (draw 1 for each),", None),
    ("Gain a story costing N or less, Recover N cards from discard, +N influence per kind of card played, Look at the top N (keep 1),", None),
    ("Attack (each rival discards a card / gains a Scandal); Power = the card's full text.", None),
    ("First Appearance = what happens to every paper as that year's campaign opens; fa_kind = which event: discard, discard_leader,", None),
    ("discard_dearest, destroy_cheapest, draw, draw_fewest, scandal, scandal_leader, unscandal, sweep; First Appearance: how many = its number.", None),
]
for text, font in lines:
    rm.append([text])
    rm.cell(row=rm.max_row, column=1).font = font or BODY_FONT
rm.column_dimensions["A"].width = 150

# ---- the three tables -----------------------------------------------------
st = wb.create_sheet("Stories")
scols = table(st, stories_rows(read("deck-dc.csv")))
el = wb.create_sheet("Elections")
ccols = table(el, elections_rows(read("elections-dc.csv"), read("candidates-dc.csv")))
# Shade every other year, so each election's two candidate cards read as a pair.
BAND = PatternFill("solid", start_color="F3EAD3")
for row in el.iter_rows(min_row=2):
    if (row[0].row - 2) // 2 % 2:
        for cell in row:
            cell.fill = BAND
el.freeze_panes = "F2"     # key, year, era, side and candidate stay in view
pp = wb.create_sheet("Newspapers")
table(pp, read("papers-dc.csv"))
ap = wb.create_sheet("Art prompts")
table(ap, read("art-prompts.csv"))

# ---- Summary: formulas over the Stories sheet -------------------------------
sm = wb.create_sheet("Summary")
n = st.max_row
col = lambda name: get_column_letter(scols.index(name) + 1)
T, TH, R, C, V = col("type"), col("theme"), col("released"), col("copies"), col("Prestige")
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
EV = get_column_letter(ccols.index("Prestige") + 1)
sm.append(["Elections: fixed prestige on all 34 cards (1860 is also worth 1 per office held)",
           "=SUM(Elections!$%s$2:$%s$35)" % (EV, EV)])
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
