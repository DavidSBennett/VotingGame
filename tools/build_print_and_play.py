"""Build the print-and-play edition: docs/fourth-estate-print-and-play.pdf.

    py -X utf8 tools/build_print_and_play.py

The rules first (written from the rules engine, backend/engine_dc.php),
then every card, nine to a US Letter page at Magic: The Gathering size (63 x 88 mm),
read from the same spreadsheets the game plays:

    docs/deck-dc.csv        stories, starters, Editorial, Scandal
    docs/elections-dc.csv   the 17 elections (order, history's choice, First Appearance)
    docs/candidates-dc.csv  the 34 candidates and their Patron cards
    docs/papers-dc.csv      the eight newspapers

Edit a spreadsheet and rerun this; the cards follow. Card text is written
from the card's numbers (not its card_text), so what is printed is what
the engine does.
"""
import csv
import io
import os

from pypdf import PdfReader, PdfWriter
from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import inch, mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas
from reportlab.platypus import (Flowable, KeepTogether, PageBreak, Paragraph, SimpleDocTemplate, Spacer, Table,
                                TableStyle)

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")
OUT = os.path.join(DOCS, "fourth-estate-print-and-play.pdf")
PLAYERS = 5                     # starting decks printed for this many papers

FONTS = r"C:\Windows\Fonts"
for name, file in (("Serif", "georgia.ttf"), ("Serif-Bold", "georgiab.ttf"), ("Serif-Italic", "georgiai.ttf"),
                   ("Serif-BoldItalic", "georgiaz.ttf")):
    pdfmetrics.registerFont(TTFont(name, os.path.join(FONTS, file)))
pdfmetrics.registerFont(TTFont("Sym", os.path.join(FONTS, "seguisym.ttf")))   # the star, which Georgia lacks
from reportlab.pdfbase.pdfmetrics import registerFontFamily
registerFontFamily("Serif", normal="Serif", bold="Serif-Bold", italic="Serif-Italic", boldItalic="Serif-BoldItalic")

INK = colors.HexColor("#1c1813")
MUTED = colors.HexColor("#6b604f")
GOLD = colors.HexColor("#a8812e")
PAPER = colors.HexColor("#fbf7ec")
THEME = {"Political": colors.HexColor("#2b4f7e"), "Economic": colors.HexColor("#9a5b1e"),
         "Social": colors.HexColor("#2f6b45")}
TYPE_COLOR = {"Political story": THEME["Political"], "Economic story": THEME["Economic"],
              "Social story": THEME["Social"], "Negative story": colors.HexColor("#7a1f1f"),
              "Media event": colors.HexColor("#8a6d1f"), "Starter": colors.HexColor("#6f6a60"),
              "Editorial": colors.HexColor("#3d3a35"), "Scandal": colors.HexColor("#111111"),
              "Election": colors.HexColor("#143138"), "Campaign": colors.HexColor("#143138"),
              "Newspaper": colors.HexColor("#4b3b5c"), "Reference": colors.HexColor("#143138")}

CW, CH = 63 * mm, 88 * mm      # a Magic: The Gathering card


def read(name):
    with open(os.path.join(DOCS, name), encoding="utf-8-sig") as fh:
        return list(csv.DictReader(fh))


def n(v):
    return int(v or 0)


def plural(k, word):
    return "%d %s%s" % (k, word, "" if k == 1 else "s")


# ---- card text from the numbers ------------------------------------------------

def story_abilities(c):
    th = c["theme"]
    out = []
    if n(c["draw"]):
        out.append("Draw %s." % plural(n(c["draw"]), "card"))
    if n(c["trash"]):
        out.append("<b>Destroy</b> up to %s from your hand or discard pile." % plural(n(c["trash"]), "card"))
    if n(c["gain_upto"]):
        out.append("<b>Gain</b> a story costing %d or less from the exchange." % n(c["gain_upto"]))
    if n(c["per_same"]):
        out.append("+%d influence for each other %s story you play this turn." % (n(c["per_same"]), th))
    if n(c["chain"]):
        out.append("<b>Chain:</b> +%d influence if you play another %s story this turn." % (n(c["chain"]), th))
    if n(c["per_office"]):
        out.append("+%d %s for each election card you own." % (n(c["per_office"]), th))
    if n(c["retract"]):
        out.append("<b>Retraction.</b>")
    if c["attack"]:
        what = "discards a card at random" if c["attack"] == "discard" else "gains a Scandal"
        out.append("<b>Attack:</b> each rival %s. +1 influence if it hits anyone." % what)
    if n(c["defense"]):
        out.append("<b>Defense.</b>")
    if c["type"] == "Media event":
        mine = []
        if n(c["ongoing_gen"]):
            mine.append("+%d influence" % n(c["ongoing_gen"]))
        if n(c["ongoing_draw"]):
            mine.append("draw %s" % plural(n(c["ongoing_draw"]), "card"))
        out.append("<b>Stays in play.</b> At the start of each of your turns: %s." % " and ".join(mine))
        if n(c["others_bonus"]):
            out.append("Each rival, at the start of each of its turns: +%d %s." % (
                n(c["others_bonus"]), c["others_theme"] or "influence"))
    if c["type"] == "Negative story":
        out.append("<i>Worth no prestige.</i>")
    if c["key"] == "notice":
        out.append("No effect. Destroy it when you can.")
    if c["key"] == "scandal":
        out.append("A dead card: −1 prestige at the end of the game.")
    return out


def patron_abilities(k):
    out = []
    if n(k["p_draw"]):
        out.append("Draw %s." % plural(n(k["p_draw"]), "card"))
    if n(k["p_trash"]):
        out.append("<b>Destroy</b> up to %s from your hand or discard pile%s." % (
            plural(n(k["p_trash"]), "card"), "; draw a card for each" if n(k["p_trash_draw"]) else ""))
    if n(k["p_gain_upto"]):
        out.append("<b>Gain</b> a story costing %d or less from the exchange." % n(k["p_gain_upto"]))
    if n(k["p_recover"]):
        out.append("<b>Recover</b> %s: put %s from your discard pile into your hand." % (
            n(k["p_recover"]), "a card" if n(k["p_recover"]) == 1 else "up to %d cards" % n(k["p_recover"])))
    if n(k["p_per_kind"]):
        out.append("+%d influence for each different kind of card you play this turn." % n(k["p_per_kind"]))
    if n(k["p_scry"]):
        out.append("<b>Look at the top %d</b> of the main deck: put one into your hand, the rest on the bottom." % n(k["p_scry"]))
    if k["p_attack"]:
        what = "discards a card at random" if k["p_attack"] == "discard" else "gains a Scandal"
        out.append("<b>Attack:</b> each rival %s. +1 influence if it hits anyone." % what)
    if n(k["vp_per_office"]):
        out.append("The last election: the game ends after this turn.")
    return out


# ---- drawing helpers -------------------------------------------------------------

def para(c, text, style, x, y_top, w, h, min_size=5.5):
    """Draw `text` in the box (x, y_top-h .. y_top), shrinking the font until it fits. Returns height used."""
    size = style.fontSize
    while True:
        st = ParagraphStyle("fit", parent=style, fontSize=size, leading=size * 1.22)
        p = Paragraph(text, st)
        _, ph = p.wrap(w, h)
        if ph <= h or size <= min_size:
            p.drawOn(c, x, y_top - ph)
            return ph
        size -= 0.25


def star(c, cx, cy, r, fill, stroke=None):
    import math
    pts = []
    for i in range(10):
        a = math.pi / 2 + i * math.pi / 5
        rr = r if i % 2 == 0 else r * 0.45
        pts.append((cx + rr * math.cos(a), cy + rr * math.sin(a)))
    p = c.beginPath()
    p.moveTo(*pts[0])
    for pt in pts[1:]:
        p.lineTo(*pt)
    p.close()
    c.setFillColor(fill)
    c.setStrokeColor(stroke or fill)
    c.setLineWidth(0.6)
    c.drawPath(p, fill=1, stroke=1)


def diamond(c, cx, cy, r, fill):
    p = c.beginPath()
    p.moveTo(cx, cy + r)
    p.lineTo(cx + r, cy)
    p.lineTo(cx, cy - r)
    p.lineTo(cx - r, cy)
    p.close()
    c.setFillColor(fill)
    c.setStrokeColor(INK)
    c.setLineWidth(0.6)
    c.drawPath(p, fill=1, stroke=1)


def centered(c, text, x, y, font, size, color=INK):
    c.setFont(font, size)
    c.setFillColor(color)
    c.drawCentredString(x, y, text)


def chip(c, x, y, label, value, color):
    """An influence chip: a rounded box with the value and a tiny label. Returns its width."""
    w = 30
    c.setFillColor(color)
    c.setStrokeColor(color)
    c.roundRect(x, y, w, 20, 4, fill=1, stroke=0)
    centered(c, "+%d" % value, x + w / 2, y + 7.5, "Serif-Bold", 11, colors.white)
    centered(c, label, x + w / 2, y + 1.8, "Serif", 4.6, colors.white)
    return w


ABILITY = ParagraphStyle("ab", fontName="Serif", fontSize=8, leading=9.8, alignment=TA_CENTER, textColor=INK)
FLAVOR = ParagraphStyle("fl", fontName="Serif-Italic", fontSize=6.2, leading=7.4, alignment=TA_CENTER, textColor=MUTED)
TITLE = ParagraphStyle("ti", fontName="Serif-Bold", fontSize=11.5, leading=13, alignment=TA_CENTER, textColor=INK)


def frame(c, x, y, band, label):
    """The card outline, inner rule and the colored header band with its label."""
    c.setFillColor(PAPER)
    c.setStrokeColor(colors.HexColor("#999999"))
    c.setLineWidth(0.4)
    c.rect(x, y, CW, CH, fill=1, stroke=1)          # the cut line
    c.setStrokeColor(GOLD)
    c.setLineWidth(0.5)
    c.rect(x + 7, y + 7, CW - 14, CH - 14, fill=0, stroke=1)
    c.setFillColor(band)
    c.rect(x + 7, y + CH - 31, CW - 14, 24, fill=1, stroke=0)
    centered(c, label.upper(), x + CW / 2, y + CH - 22.5, "Serif-Bold", 6.6, colors.white)


def corners(c, x, y, cost=None, vp=None, per_office=0):
    top = y + CH - 19
    if cost:
        diamond(c, x + 22, top, 10.5, colors.white)
        centered(c, str(cost), x + 22, top - 3.6, "Serif-Bold", 10)
    if vp is not None:
        star(c, x + CW - 22, top, 12.5, GOLD if vp >= 0 else INK, INK)
        centered(c, str(vp), x + CW - 22, top - 3.4, "Serif-Bold", 9.5, colors.white)
        if per_office:
            centered(c, "+%d per office" % per_office, x + CW - 22, top - 20, "Serif-Bold", 5.2, INK)


def footer(c, x, y, left, right=""):
    c.setFont("Serif", 5.8)
    c.setFillColor(MUTED)
    c.drawString(x + 11, y + 10.5, left)
    c.drawRightString(x + CW - 11, y + 10.5, right)


def body(c, x, y, top, chips, abilities, flavor):
    """Influence chips under the title, then the abilities, then the flavor at the foot."""
    if chips:
        total = sum(30 for _ in chips) + 4 * (len(chips) - 1)
        cx = x + (CW - total) / 2
        for label, value, color in chips:
            chip(c, cx, top - 20, label, value, color)
            cx += 34
        top -= 27
    flavor_h = 0
    if flavor:
        st = ParagraphStyle("f2", parent=FLAVOR)
        p = Paragraph(flavor, st)
        _, flavor_h = p.wrap(CW - 30, 60)
        if flavor_h > 34:
            flavor_h = 34
        para(c, flavor, FLAVOR, x + 15, y + 22 + flavor_h, CW - 30, flavor_h + 2, 5)
    if abilities:
        avail = top - (y + 26 + flavor_h + 4)
        para(c, "<br/>".join(abilities), ABILITY, x + 14, top - 2, CW - 28, avail)


def title_block(c, x, y, title, sub):
    used = para(c, title, TITLE, x + 13, y + CH - 36, CW - 26, 30, 8)
    top = y + CH - 36 - used - 2
    if sub:
        centered(c, sub, x + CW / 2, top - 7, "Serif-Italic", 6.4, MUTED)
        top -= 11
    return top - 4


def chips_for(gen, themed, theme, campaign=0):
    out = []
    if gen:
        out.append(("influence", gen, colors.HexColor("#55504a")))
    if themed and theme in THEME:
        out.append((theme.lower(), themed, THEME[theme]))
    if campaign:
        out.append(("campaign", campaign, colors.HexColor("#143138")))
    return out


# ---- the card kinds -----------------------------------------------------------------

def draw_story(c, x, y, k):
    label = k["type"]
    if k["type"] in ("Political story", "Economic story", "Social story"):
        label = k["type"]
    elif k["type"] == "Negative story":
        label = "Negative story · %s" % k["theme"]
    frame(c, x, y, TYPE_COLOR[k["type"]], label)
    corners(c, x, y, n(k["cost"]), n(k["vp"]) if k["type"] not in ("Starter",) else None)
    sub = k["year"] or ""
    top = title_block(c, x, y, k["name"], sub)
    chips = chips_for(n(k["gen"]), n(k["themed"]), k["theme"], n(k["campaign"]))
    body(c, x, y, top, chips, story_abilities(k), k["flavor"])
    if k["released"]:
        footer(c, x, y, "News of %s" % k["released"], "")
    elif k["type"] == "Starter":
        footer(c, x, y, "Starting deck", "")
    elif k["type"] == "Editorial":
        footer(c, x, y, "Always for sale · 3 plain influence", "")
    elif k["type"] == "Scandal":
        footer(c, x, y, "Scandal supply", "")


def draw_patron(c, x, y, e, side, k):
    theme = k["theme"]
    frame(c, x, y, THEME.get(theme, INK), "Election card")
    corners(c, x, y, None, n(k["vp"]), n(k["vp_per_office"]))
    hist = "history's choice" if e["historical_winner"] == side else "rewrites history"
    top = title_block(c, x, y, k["card_name"], "%s, %s · %s" % (k["candidate"], k["year"], hist))
    chips = chips_for(n(k["p_gen"]), n(k["themed"]), theme)
    body(c, x, y, top, chips, patron_abilities(k), "")
    footer(c, x, y, "Election of %s" % k["year"], "Elected at %s %s" % (k["threshold"], theme))


FA = ParagraphStyle("fa", fontName="Serif", fontSize=7.4, leading=9, alignment=TA_CENTER, textColor=INK)


def draw_election(c, x, y, e, i, total):
    frame(c, x, y, TYPE_COLOR["Campaign"], "The campaign · %d of %d · Era %s" % (i + 1, total, e["era"]))
    centered(c, "The Election of %s" % e["year"], x + CW / 2, y + CH - 52, "Serif-Bold", 13)
    top = y + CH - 62
    for side in ("nation", "states"):
        k = e["cards"][side]
        col = THEME[k["theme"]]
        c.setStrokeColor(col)
        c.setLineWidth(1)
        c.setFillColor(colors.white)
        c.rect(x + 14, top - 48, CW - 28, 46, fill=1, stroke=1)
        c.setFillColor(col)
        c.rect(x + 14, top - 48, 5, 46, fill=1, stroke=0)
        para(c, "<b>%s</b>" % k["candidate"], ParagraphStyle("cn", parent=ABILITY, fontSize=9.5, alignment=TA_LEFT),
             x + 24, top - 5, CW - 80, 22, 7)
        c.setFont("Serif", 6.2)
        c.setFillColor(col)
        c.drawString(x + 24, top - 33, "%s influence" % k["theme"])
        c.setFillColor(MUTED)
        c.setFont("Serif-Italic", 5.8)
        c.drawString(x + 24, top - 42, "history's choice" if e["historical_winner"] == side else "rewrites history")
        centered(c, k["threshold"], x + CW - 34, top - 28, "Serif-Bold", 20, col)
        centered(c, "to elect", x + CW - 34, top - 40, "Serif", 5.5, MUTED)
        top -= 54
    if e["fa_name"]:
        text = "<b>First Appearance · %s</b><br/>%s" % (e["fa_name"], e["fa_text"])
    else:
        text = "<i>The first campaign: no First Appearance.</i>"
    para(c, text, FA, x + 15, top - 2, CW - 30, top - (y + 50))
    if i == total - 1:
        note = "The last election: the game ends after the turn it is decided."
    else:
        note = "When decided: the %s news enters the main deck." % e["next_year"]
    para(c, "<i>%s</i>" % note, FLAVOR, x + 15, y + 40, CW - 30, 22)


def draw_paper(c, x, y, p):
    frame(c, x, y, TYPE_COLOR["Newspaper"], "Newspaper · leans %s" % p["leans"])
    top = title_block(c, x, y, p["name"], "")
    body(c, x, y, top - 6, [], [p["ability"]], p["flavor"])
    footer(c, x, y, "One per paper", "")


REF = ParagraphStyle("rf", fontName="Serif", fontSize=7, leading=8.6, alignment=TA_LEFT, textColor=INK)


def draw_reference(c, x, y):
    frame(c, x, y, TYPE_COLOR["Reference"], "Your turn")
    text = ("<b>Play</b> cards from your hand, in any order, for influence and effects.<br/>"
            "<b>Elect</b> once a turn: reach a candidate's threshold with his theme, Campaign or plain influence; "
            "take his card into your discard pile.<br/>"
            "<b>Buy</b> stories off the exchange with their theme or plain influence; an Editorial for 3 plain. "
            "Bought cards go to your discard pile.<br/>"
            "<b>Paper</b>: use your newspaper's ability.<br/>"
            "<b>End</b>: discard everything played and in hand, draw 5, refill the exchange to 5.<br/><br/>"
            "<b>When an election is decided:</b> add the next year's news to the main deck and shuffle it; "
            "refill the exchange; the new campaign's First Appearance hits at once.")
    para(c, text, REF, x + 15, y + CH - 40, CW - 30, CH - 60, 5.5)
    footer(c, x, y, "Reference", "")


# ---- the card list --------------------------------------------------------------

def card_list():
    deck = read("deck-dc.csv")
    elections = read("elections-dc.csv")
    cands = {(r["year"], r["side"]): r for r in read("candidates-dc.csv")}
    for i, e in enumerate(elections):
        e["cards"] = {s: cands[(e["year"], s)] for s in ("nation", "states")}
        e["next_year"] = elections[i + 1]["year"] if i + 1 < len(elections) else ""
    papers = read("papers-dc.csv")
    out = []                                  # (section, draw function)
    for i, e in enumerate(elections):
        out.append(("The 17 campaign cards", lambda c, x, y, e=e, i=i: draw_election(c, x, y, e, i, len(elections))))
    for e in elections:
        for side in ("nation", "states"):
            out.append(("The 34 election cards", lambda c, x, y, e=e, s=side: draw_patron(c, x, y, e, s, e["cards"][s])))
    for p in papers:
        out.append(("The eight newspapers", lambda c, x, y, p=p: draw_paper(c, x, y, p)))
    for _ in range(PLAYERS):
        out.append(("Reference cards", lambda c, x, y: draw_reference(c, x, y)))
    by_key = {k["key"]: k for k in deck}
    for key, copies in (("letter", 7 * PLAYERS), ("notice", 3 * PLAYERS)):
        for _ in range(copies):
            out.append(("Starting decks", lambda c, x, y, k=by_key[key]: draw_story(c, x, y, k)))
    for key in ("editorial", "scandal"):
        for _ in range(n(by_key[key]["copies"])):
            out.append(("Editorials and Scandals", lambda c, x, y, k=by_key[key]: draw_story(c, x, y, k)))
    stories = sorted((k for k in deck if k["released"]), key=lambda k: (int(k["released"]), int(k["year"] or 0)))
    for k in stories:
        for _ in range(n(k["copies"]) or 1):
            out.append(("The news, by campaign", lambda c, x, y, k=k: draw_story(c, x, y, k)))
    return out, deck, elections


def card_pages(cards):
    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=letter)
    c.setTitle("The Fourth Estate: cards")
    W, H = letter
    x0, y0 = (W - 3 * CW) / 2, (H - 3 * CH) / 2
    for start in range(0, len(cards), 9):
        page = cards[start:start + 9]
        c.setFont("Serif-Italic", 7)
        c.setFillColor(MUTED)
        sections = []
        for s, _ in page:
            if s not in sections:
                sections.append(s)
        c.drawCentredString(W / 2, H - 0.16 * inch, "The Fourth Estate · " + " · ".join(sections)
                            + " · sheet %d of %d" % (start // 9 + 1, (len(cards) + 8) // 9))
        for i, (_, fn) in enumerate(page):
            col, row = i % 3, i // 3
            fn(c, x0 + col * CW, y0 + (2 - row) * CH)
        # crop marks in the margins, on every cut line
        c.setStrokeColor(INK)
        c.setLineWidth(0.4)
        for k in range(4):
            xx = x0 + k * CW
            c.line(xx, y0 - 10, xx, y0 - 2)
            c.line(xx, y0 + 3 * CH + 2, xx, y0 + 3 * CH + 10)
            yy = y0 + k * CH
            c.line(x0 - 10, yy, x0 - 2, yy)
            c.line(x0 + 3 * CW + 2, yy, x0 + 3 * CW + 10, yy)
        c.showPage()
    c.save()
    return buf.getvalue()


# ---- the rules ---------------------------------------------------------------------

H1 = ParagraphStyle("h1", fontName="Serif-Bold", fontSize=26, leading=30, alignment=TA_CENTER, textColor=INK)
DEK = ParagraphStyle("dek", fontName="Serif-Italic", fontSize=11.5, leading=15, alignment=TA_CENTER, textColor=MUTED)
H2 = ParagraphStyle("h2", fontName="Serif-Bold", fontSize=12.5, leading=15, spaceBefore=10, spaceAfter=3, textColor=INK)
P = ParagraphStyle("p", fontName="Serif", fontSize=9.6, leading=12.6, spaceAfter=4, textColor=INK)
LI = ParagraphStyle("li", parent=P, leftIndent=12, bulletIndent=2, spaceAfter=2.5)
SMALL = ParagraphStyle("sm", parent=P, fontSize=8.4, leading=10.8)


class CardFlow(Flowable):
    """A card drawn into the rulebook (for the anatomy example)."""

    def __init__(self, fn):
        super().__init__()
        self.fn, self.width, self.height = fn, CW, CH

    def draw(self):
        self.fn(self.canv, 0, 0)


def li(text):
    return Paragraph(text, LI, bulletText="•")


def rules_pages(cards, deck, elections):
    story_count = sum(1 for k in deck if k["released"])
    sheets = (len(cards) + 8) // 9
    by_key = {k["key"]: k for k in deck}
    sample = by_key["declaration"]
    e0 = elections[0]
    s = []
    s += [Spacer(1, 6), Paragraph("The Fourth Estate", H1),
          Paragraph("A deck-building game of the partisan press, 1796&ndash;1860 &middot; 2&ndash;5 players &middot; about 60 minutes", DEK),
          Spacer(1, 10)]

    s += [Paragraph("The idea", H2), Paragraph(
        "Each player runs a partisan newspaper, a <b>paper</b>, through seventeen presidential elections. You begin with a "
        "thin deck of letters and notices. Each turn you play your hand for <b>influence</b>, spend it to buy the news of the day "
        "and to <b>elect</b> candidates, and your deck grows stronger. Every election you win puts that candidate's card into "
        "your deck, with a power of its own. When 1860 is decided the game ends, and the paper with the most "
        "<b>prestige</b> (<font name='Sym'>&#9733;</font> on the cards it owns) is the most honoured paper in the Union.", P)]

    s += [Paragraph("What you need", H2), Paragraph(
        "Print the %d card sheets that follow on card stock (or print on paper and sleeve each card over an old playing card, "
        "so the backs match), and cut along the lines. You also need a way to count influence each turn: coins, beads, "
        "a die, or scratch paper." % sheets, P)]
    rows = [["17 campaign cards", "the elections, 1796 to 1860: two candidates, their thresholds, a First Appearance"],
            ["34 election cards", "one per candidate: the card his Patron takes into their deck"],
            ["8 newspapers", "one per paper, each with its own ability"],
            ["%d starting cards" % (10 * PLAYERS), "7 Letters to the Editor and 3 Local Notices for each of %d papers" % PLAYERS],
            ["%s Editorials, %s Scandals" % (by_key["editorial"]["copies"], by_key["scandal"]["copies"]), "two supply piles"],
            ["%d stories" % story_count, "the news, each marked with the campaign that brings it into play"],
            ["%d reference cards" % PLAYERS, "a turn summary for each player"]]
    t = Table([[Paragraph("<b>%s</b>" % a, SMALL), Paragraph(b, SMALL)] for a, b in rows], colWidths=[1.6 * inch, 5.3 * inch])
    t.setStyle(TableStyle([("VALIGN", (0, 0), (-1, -1), "TOP"), ("BOTTOMPADDING", (0, 0), (-1, -1), 1.5),
                           ("TOPPADDING", (0, 0), (-1, -1), 1.5), ("LINEBELOW", (0, 0), (-1, -2), 0.25, colors.HexColor("#d8ccb0"))]))
    s += [t]

    s += [Paragraph("Setting up", H2)]
    s += [li("<b>The campaigns.</b> Stack the campaign cards in order, 1796 on top, face up. Lay the two election cards of the "
             "top campaign beside it (for 1796: %s and %s). Keep the other election cards with their campaigns."
             % (e0["cards"]["nation"]["card_name"], e0["cards"]["states"]["card_name"])),
          li("<b>The news.</b> Sort the stories by the year at the foot of each card (\"News of 1804\"). Shuffle the "
             "<b>News of 1796</b> face down: this is the <b>main deck</b>. Keep the other years' piles aside."),
          li("<b>The exchange.</b> Deal 5 stories from the main deck face up in a row: the stories for sale."),
          li("<b>Supplies.</b> Put the Editorials and the Scandals in two face-up piles."),
          li("<b>Papers.</b> Each player chooses or is dealt a newspaper, and takes 7 Letters to the Editor and 3 Local Notices, "
             "shuffles them as their own deck, and draws a hand of 5."),
          li("Choose a first player; play goes to the left. The second player gets +1 influence on their first turn, the third +2, "
             "and so on, to make up for going later.")]

    anatomy = Table([[CardFlow(lambda c, x, y: draw_story(c, x, y, sample)), [
        Paragraph("Reading a card", H2),
        Paragraph("<b>Diamond, top left:</b> the cost to buy it.", SMALL),
        Paragraph("<b>Star, top right:</b> its prestige at the end of the game.", SMALL),
        Paragraph("<b>Chips:</b> the influence it makes when played. <b>Grey</b> is plain influence. "
                  "<font color='#2b4f7e'><b>Blue</b></font> Political, <font color='#9a5b1e'><b>brown</b></font> Economic and "
                  "<font color='#2f6b45'><b>green</b></font> Social influence are themed. <b>Teal</b> is Campaign.", SMALL),
        Paragraph("<b>Text:</b> what else it does when played. Words in bold are explained under <i>The words on the cards</i>.", SMALL),
        Paragraph("<b>Foot:</b> which campaign's news brings it into the main deck.", SMALL)]]],
        colWidths=[CW + 14, 6.9 * inch - CW - 14])
    anatomy.setStyle(TableStyle([("VALIGN", (0, 0), (-1, -1), "TOP"), ("LEFTPADDING", (0, 0), (0, 0), 0)]))
    s += [Spacer(1, 6), anatomy]

    s += [Paragraph("Influence", H2), Paragraph(
        "Influence is made by the cards you play and lasts only for your turn: whatever you have not spent when the turn "
        "ends is lost. There are three kinds:", P),
          li("<b>Plain influence</b> spends on anything."),
          li("<b>Themed influence</b> (Political, Economic or Social) spends only on a story of that theme, or on electing a "
             "candidate of that theme."),
          li("<b>Campaign influence</b> spends only on electing a candidate."),
          Paragraph("You may mix kinds to pay one price: the Declaration's +2 plain, +2 Political and +2 Campaign together elect "
                    "a Political candidate who needs 6.", P)]

    s += [Paragraph("Your turn", H2), Paragraph("Do these in any order, as many times as you like, except where noted:", P),
          li("<b>Play</b> a card from your hand: take its influence and do what it says, in order. Played cards stay in front "
             "of you until the end of the turn."),
          li("<b>Elect</b> (once a turn): if your influence of the candidate's theme, Campaign and plain together reach "
             "either candidate's threshold, pay it, take his election card and put it into your discard pile. "
             "History's choice is the cheaper of the two; electing the other rewrites history. Then the next campaign opens "
             "(below). You may go on playing and buying, but not elect again this turn."),
          li("<b>Buy</b> a story off the exchange, paying its cost with influence of its theme or plain influence (a media "
             "event or an untyped card takes plain only); or an <b>Editorial</b> from its pile for 3 plain. Bought cards go "
             "into your discard pile. Leave the gap on the exchange until your turn ends."),
          li("<b>Use your newspaper</b>, if its ability is one you choose to use."),
          li("<b>End your turn.</b> Put every card you played, and every card left in your hand, into your discard pile; "
             "media events you played go in front of you instead, and stay. Draw 5. Refill the exchange to 5 from the main deck."),
          Paragraph("Whenever you must draw and your deck is empty, shuffle your discard pile to make a new deck.", P)]

    s += [Paragraph("A new campaign", H2), Paragraph(
        "As soon as an election is decided, before the turn goes on:", P),
          li("Put the decided campaign card and the losing candidate's card away. The next campaign card is now on top; lay its "
             "two election cards beside it."),
          li("Shuffle that year's news into the main deck (the stories marked \"News of\" its year), and refill the exchange to 5."),
          li("Its <b>First Appearance</b> happens at once: an event of the year that hits every paper, or the leaders, as the "
             "card says. \"Offices\" are election cards owned; \"at random\" means the player shuffles their hand and a "
             "neighbour draws one blind. Defense does not stop a First Appearance.")]

    s += [Paragraph("The words on the cards", H2)]
    for term, text in (
            ("Attack", "A negative story attacks every rival at once: each discards a card from hand at random, or takes a "
                       "Scandal from the supply into their discard pile, as the card says. If it hits anyone, you get +1 plain "
                       "influence. Negative stories are worth no prestige: nobody is honoured for a smear."),
            ("Defense", "When you are attacked and hold a card with Defense, you must discard it and draw a card; the attack "
                        "misses you. One Defense card stops one attack."),
            ("Scandal", "A dead card in your deck, worth −1 prestige at the end. When the supply runs out, attacks give no more Scandals."),
            ("Retraction", "Draw a card, then destroy a Scandal from your hand or discard pile, if you have one."),
            ("Destroy", "Remove a card from the game for good (back in the box). Thin out your Local Notices this way."),
            ("Gain", "Take a story from the exchange into your discard pile without paying; refill its place at once."),
            ("Recover", "Put a card from your discard pile into your hand."),
            ("Look at the top", "Look at that many stories from the top of the main deck; put one into your hand and the rest "
                                "on the bottom."),
            ("Chain / for each other", "Count every story of that theme you play this turn, before or after this one."),
            ("Kind of card", "Starter, Political story, Economic story, Social story, Negative story, Media event, Editorial and "
                             "Election card are the kinds."),
            ("Media event", "Stays in front of you for the rest of the game once played (from the end of that turn). At the "
                            "start of each of your turns it gives you its bonus, and every rival gets its smaller bonus at the "
                            "start of theirs. It still counts as yours for prestige."),
            ("Election card", "Play it like any card for its influence and power. Its themed influence is of the candidate's "
                              "theme. 1860's cards are worth prestige for each election card you own, themselves included.")):
        s.append(li("<b>%s.</b> %s" % (term, text)))

    s += [Paragraph("The three kinds of story", H2),
          li("<font color='#2b4f7e'><b>Political</b></font> stories win offices: themed and Campaign influence, more for each "
             "office held, and Retraction."),
          li("<font color='#9a5b1e'><b>Economic</b></font> stories build an engine: more for every other Economic story played, "
             "and ways to destroy weak cards and gain stories."),
          li("<font color='#2f6b45'><b>Social</b></font> stories bring momentum: they draw cards, reward playing two together, "
             "and many have Defense.")]

    s += [Paragraph("The end", H2), Paragraph(
        "The game ends after the turn in which the election of 1860 is decided. Every player adds up the prestige on all the "
        "cards they own (deck, hand, discard pile and media events in play), less 1 for each Scandal, plus any newspaper bonus. "
        "The most prestige wins; a tie goes to the paper that won more elections.", P)]

    s += [Paragraph("Fewer players", H2), Paragraph(
        "With fewer than five, set aside the unused starting cards and newspapers. Everything else is used at every player count.", P)]
    return s


def build():
    cards, deck, elections = card_list()
    buf = io.BytesIO()
    doc = SimpleDocTemplate(buf, pagesize=letter, leftMargin=0.8 * inch, rightMargin=0.8 * inch,
                            topMargin=0.7 * inch, bottomMargin=0.7 * inch, title="The Fourth Estate: print and play",
                            author="The Fourth Estate")

    def page_no(c, d):
        c.setFont("Serif-Italic", 8)
        c.setFillColor(MUTED)
        c.drawCentredString(letter[0] / 2, 0.4 * inch, "The Fourth Estate · rules · %d" % d.page)

    doc.build(rules_pages(cards, deck, elections), onFirstPage=page_no, onLaterPages=page_no)
    out = PdfWriter()
    for part in (buf.getvalue(), card_pages(cards)):
        for page in PdfReader(io.BytesIO(part)).pages:
            out.add_page(page)
    out.add_metadata({"/Title": "The Fourth Estate: print and play"})
    with open(OUT, "wb") as fh:
        out.write(fh)
    rules = len(PdfReader(io.BytesIO(buf.getvalue())).pages)
    print("wrote %s: %d pages of rules, %d cards on %d sheets" % (os.path.relpath(OUT, ROOT), rules, len(cards),
                                                                  (len(cards) + 8) // 9))


if __name__ == "__main__":
    build()
