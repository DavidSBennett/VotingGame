"""Write docs/art-prompts.csv: one image-generation prompt per card.

    py -X utf8 tools/build_art_prompts.py

Every card (each story kind, the starters, Editorial, Scandal, and the 34
candidate cards) gets a prompt for an image model. The style follows the
printing of the card's own year, the way American newspapers and prints
changed across the game:

    to 1819     woodcut           (crude cuts in broadsides and almanacs)
    1820-1835   wood engraving    (fine-line cuts in the penny magazines)
    1836-1860   lithograph        (crayon on stone: Currier, Sarony, campaign prints)

A prompt is the period style plus a subject written for the card. Save the
image as frontend/public/art/<file> (the 'file' column); the game shows it
on the card when it is there, and the plain card when it is not.
"""
import csv
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")

STYLES = {
    "woodcut": (
        "Early American newspaper woodcut, c. {year}. A crude hand-cut woodblock print: bold solid black masses, "
        "white-line cutting, coarse parallel gouge marks for sky, water and ground, flat naive perspective, uneven ink "
        "and press grain, black ink on cream rag paper, a heavy double-rule border, like a cut from a colonial broadside "
        "or almanac."),
    "wood engraving": (
        "American wood engraving, c. {year}. A fine end-grain wood engraving as in a penny magazine or almanac of the "
        "1820s and 1830s: crisp parallel lines and crosshatching for tone, careful detail, small figures, a thin rule "
        "border, black ink on off-white newsprint."),
    "lithograph": (
        "American lithograph, c. {year}, in the manner of a Currier or Sarony print: soft crayon-on-stone tone, smooth "
        "gradations from deep black to paper white, stippled grain, highlights scraped out of the stone, naturalistic "
        "drawing, the scene fading in a soft oval vignette into cream paper."),
}
TAIL = ("Monochrome black ink on cream paper, no color, no hand-coloring. No lettering, captions, signatures or "
        "watermarks. Landscape 4:3 composition with the subject centered and readable at small size.")
DIGNITY = (" Depict every person with dignity and individual character, as a sympathetic period illustrator would; "
           "no caricature.")


def style_of(year):
    if year <= 1819:
        return "woodcut"
    if year <= 1835:
        return "wood engraving"
    return "lithograph"


# key -> (subject, dignity note needed)
STORIES = {
    "letter": ("a country gentleman in a tricorne hat at a writing desk by candlelight, quill in hand, sealing a letter addressed to the printer", False),
    "notice": ("a small-town village green with a church steeple, a stray cow and a notice board where a man tacks up a handbill", False),
    "editorial": ("an editor in shirtsleeves at a cluttered desk in a print shop, a hand press behind him, writing with fierce concentration", False),
    "scandal": ("a crowd of townspeople whispering behind their hands around a freshly posted broadside, one man pointing accusingly", False),
    "stamp_act": ("a crowd burning a heap of stamped paper in a town square at night, a printer holding up a newspaper bordered in black like a tombstone", False),
    "boston_massacre": ("British redcoats in a line firing into a crowd of colonists on a snowy Boston street, smoke from the muskets, a brick State House behind", False),
    "tea_party": ("men disguised in blankets on a merchant ship at night dumping chests of tea into Boston harbor, a crowd watching from the wharf", False),
    "common_sense": ("a colonist reading a small pamphlet aloud to a rapt group in a tavern, a printer stacking more copies on the counter", False),
    "declaration": ("a man reading a large broadside aloud from courthouse steps to a crowd raising their hats, a flag on a pole", False),
    "saratoga": ("a British general handing over his sword to an American general before ranks of soldiers in a wooded clearing", False),
    "valley_forge": ("ragged Continental soldiers huddled around a campfire in deep snow beside log huts, a general on horseback watching", False),
    "articles_confederation": ("thirteen delegates seated at a long table, each with his own state's flag, no one at the head of the table", False),
    "newburgh": ("George Washington putting on his spectacles to read a letter to a hall of officers, the officers moved and silent", False),
    "treaty_of_paris": ("diplomats in powdered wigs signing a treaty at a table in a Paris salon, a map of North America on the wall", False),
    "pennsylvania_packet": ("a busy Philadelphia street at dawn, a newsboy carrying a bundle of fresh daily papers past shop fronts", False),
    "shays_rebellion": ("armed farmers in rough coats blocking the steps of a county courthouse, a judge turned away at the door", False),
    "antifederalist_papers": ("a stern writer in a Roman toga as 'Brutus' at a desk with a quill, a pile of newspapers and a shadow of a great building looming", False),
    "constitutional_convention": ("delegates in Independence Hall with the windows shut, Washington presiding from a high-backed chair carved with a rising sun", False),
    "federalist_papers": ("three gentlemen writing at a candlelit table, a stack of New York newspapers and a bust of a Roman orator", False),
    "northwest_ordinance": ("surveyors with a chain and compass marking square townships in a forest beside the Ohio River, a flatboat on the water", False),
    "gazette_of_the_us": ("a printer pulling the bar of a wooden hand press in a New York shop, sheets drying on lines overhead", False),
    "judiciary_act": ("robed judges riding circuit on horseback along a muddy country road toward a small courthouse", False),
    "assumption": ("speculators in fine coats buying up old war certificates from weary veterans at a tavern table", False),
    "residence_act": ("a surveyor's party on a hill overlooking a wide river and empty wooded land where a capital will be built", False),
    "bank_of_the_us": ("a classical marble bank building with columns on a Philadelphia street, merchants climbing its steps", False),
    "bill_of_rights": ("a printing press with a fresh sheet on it, a church, a citizen speaking from a stump and a jury box, arranged as an allegory of liberty", False),
    "national_gazette": ("a poet-editor at a desk writing sharply, a quill in hand and a French liberty cap on a peg behind him", False),
    "report_manufactures": ("a water-powered mill on a river with a spinning wheel and a plough in the foreground, as an allegory of industry", False),
    "rights_of_man": ("a crowd in a city square raising a liberty pole topped with a liberty cap, a man reading from a book", False),
    "postal_act_1792": ("a post rider galloping along a forest road with saddlebags stuffed with newspapers, a post horn at his lips", False),
    "citizen_genet": ("a flamboyant French diplomat in a sash addressing a cheering crowd from a balcony, a cockade on his hat", False),
    "democratic_societies": ("mechanics and farmers raising glasses at a long tavern table under a French tricolour and an American flag", False),
    "whiskey_rebellion": ("a column of militia marching over the Allegheny mountains, a farmer's still smoking in a log shed by the road", False),
    "jay_treaty": ("a crowd burning a straw effigy in a coat and wig on a pole at night, torches raised", False),
    "alien_sedition": ("a printer being led from his shop in handcuffs by a marshal, his press and type cases behind", False),
    "kentucky_resolutions": ("two gentlemen conferring privately in a Virginia study, a manuscript between them, shutters half closed", False),
    "xyz_affair": ("three masked men in fine clothes holding out their hands for a purse before an American envoy who refuses", False),
    "marbury": ("the Chief Justice in a black robe reading an opinion in a small candlelit courtroom", False),
    "louisiana_purchase": ("a French flag lowered and an American flag raised over a square in New Orleans, ships on the river", False),
    "burr_conspiracy": ("a gentleman in a long cloak standing trial in a crowded courtroom, guards at his side", False),
    "chesapeake_affair": ("a British warship firing a broadside into an American frigate at close range on a calm sea", False),
    "embargo_act": ("idle merchant ships with furled sails at a quiet wharf, grass growing between the planks, sailors sitting idle", False),
    "niles_register": ("a reader in spectacles in an armchair surrounded by bound volumes of a weekly journal, prices and speeches pinned on the wall", False),
    "tippecanoe": ("a battle at dawn in a wooded river valley, militia firing from behind trees, a general on a white horse", True),
    "war_of_1812": ("an American frigate dueling a British frigate under full sail, cannon smoke billowing", False),
    "gerrymander": ("a monstrous winged salamander-dragon made of the outline of a political district, with claws and teeth, drawn as a map", False),
    "hartford_convention": ("grim gentlemen in black coats meeting behind closed shutters by lamplight in a New England hall", False),
    "treaty_of_ghent": ("British and American diplomats shaking hands across a table in a Flemish chamber", False),
    "second_bank": ("a Greek-temple bank on a Philadelphia street with a crowd outside, banknotes fluttering", False),
    "tariff_of_1816": ("a customs house on a busy wharf, crates of imported cloth stacked and weighed by officials", False),
    "cumberland_road": ("Conestoga wagons and a stagecoach on a stone-paved road crossing a stone bridge in the mountains", False),
    "mcculloch": ("the Supreme Court bench with robed justices, a lawyer arguing before them, a bank note on the table", False),
    "adams_onis": ("a Spanish envoy and an American Secretary of State signing a treaty beside a large map with a line drawn to the Pacific", False),
    "panic_of_1819": ("a crowd of anxious depositors at the shut doors of a bank, a sheriff posting a foreclosure notice", False),
    "missouri_compromise": ("a fire bell ringing in a dark tower at night over a sleeping town", False),
    "denmark_vesey": ("a dignified Black carpenter standing calm and upright in a Charleston courtroom before stern white judges", True),
    "crawford_radicals": ("politicians in a smoky caucus room voting by candlelight, many chairs empty", False),
    "monroe_doctrine": ("an American eagle spreading its wings over a map of the western hemisphere as European warships turn away", False),
    "american_system": ("a factory, a canal, a road and a bank arranged around a farmer's field, an allegory of national improvement", False),
    "erie_canal": ("a canal boat pulled by mules along a towpath past locks, a canal town behind", False),
    "lowell_mills": ("young women mill workers walking in rows to a large brick textile mill beside a river at dawn", False),
    "freedoms_journal": ("Black editors at work in a New York print office, one reading a proof sheet, a hand press in the background", True),
    "anti_masonic_party": ("a crowd at a torchlit rally around a speaker holding up a Masonic apron and compass", False),
    "cherokee_phoenix": ("a Cherokee printer at a hand press in a log print shop at New Echota, a newspaper page in the syllabary on the press", True),
    "sc_exposition": ("a gaunt statesman with a shock of hair writing at night by a single candle, a palmetto tree outside the window", False),
    "tariff_abominations": ("a monstrous many-headed creature made of crates and bales labelled with goods, menacing a cotton field", False),
    "cotton_boom": ("cotton bales piled high on a river steamboat landing, ships waiting downstream", False),
    "indian_removal": ("a line of Cherokee families walking west through a winter forest with carts and blankets, soldiers riding alongside", True),
    "webster_hayne": ("a senator with a dark brow orating in the Senate chamber, hand raised, the galleries packed and silent", False),
    "the_liberator": ("a printer and editor in a cramped Boston garret printing a weekly paper by lamplight", False),
    "bank_war": ("Andrew Jackson with a cane confronting a many-headed hydra emerging from a classical bank building", False),
    "force_bill": ("federal soldiers and a revenue cutter in Charleston harbor, cannon trained on a fort", False),
    "penny_press": ("newsboys shouting in a crowded New York street holding up papers, gas lamps and omnibuses behind", False),
    "gag_rule": ("a congressman pushing a tall stack of petitions off the House clerk's desk while an old member rises to object", False),
    "specie_circular": ("a land-office counter where a settler empties a purse of gold and silver coins while paper notes are refused", False),
    "panic_of_1837": ("a bank run on a city street, crowds pressing on shut doors, a sign of suspended payment, a broken merchant on the curb", False),
    "amistad": ("a schooner at anchor off Long Island with a group of African captives standing proud on its deck", True),
    "log_cabin_campaign": ("a log cabin with a barrel of hard cider by the door, a coonskin on the wall and a crowd of cheering Whigs with banners", False),
    "tyler_vetoes": ("a lone president at his desk signing a veto as cabinet members walk out of the room in a body", False),
    "dorr_rebellion": ("a ragtag militia with an old cannon outside the Providence arsenal at night in fog", False),
    "webster_ashburton": ("two diplomats shaking hands over a map of the Maine border, lumberjacks felling pines in the window behind", False),
    "telegraph": ("a telegraph operator tapping a key at a desk, telegraph poles and wire running across the countryside through the window", False),
    "texas_annexation": ("a lone-star flag lowered and the American flag raised over the steps of a frontier capitol", False),
    "oregon_treaty": ("covered wagons crossing a vast western prairie toward snowy mountains", False),
    "associated_press": ("a steamship arriving at a New York wharf as reporters race in small boats to collect dispatches", False),
    "mexican_war": ("American troops storming a hilltop castle in a cloud of cannon smoke, the castle walls high above", False),
    "wilmot_proviso": ("a congressman standing in the crowded House chamber reading a short amendment, the members in uproar", False),
    "rotary_press": ("a huge rotary printing press with great cylinders and pressmen feeding sheets, pulleys and belts overhead", False),
    "free_soil": ("a farmer with a plough on open western land under a rising sun, his family at the cabin door", False),
    "gold_rush": ("miners panning for gold in a mountain stream, tents and a sluice box, a clipper ship in a bay below", False),
    "compromise_1850": ("an aged senator addressing the Senate, gesturing to a scroll, senators leaning in", False),
    "fugitive_slave_act": ("a crowd of Boston citizens confronting marshals leading a manacled Black man toward the harbor", True),
    "georgia_platform": ("a convention of Georgia delegates voting by raised hands in a state house hall", False),
    "nashville_convention": ("southern delegates meeting in a Nashville hall, a large map of the territories on the wall", False),
    "cheap_postage": ("a country post office with a postmaster sorting bundles of weekly newspapers into pigeonholes", False),
    "uncle_toms_cabin": ("a mother carrying her child across ice floes on a river at night, fleeing", True),
    "transcontinental_railroad": ("surveyors with theodolites on a western plain, a steam locomotive drawn faintly in the distance", False),
    "gadsden_purchase": ("a desert landscape with saguaro cactus and a surveyor's flag, a railroad grade traced across it", False),
    "kansas_nebraska": ("a prairie divided by a torn map line, settlers' cabins on both sides, a storm gathering", False),
    "ostend_manifesto": ("three diplomats in a European parlor pointing at a map of Cuba, a fourth man peering through the door", False),
    "personal_liberty_laws": ("a northern courthouse where townspeople block the doors against a federal marshal", False),
    "bleeding_kansas": ("a burning frontier town, men with rifles in the street and smoke over the prairie", False),
    "sumner_caning": ("a congressman beating a seated senator with a cane on the Senate floor, the senator trapped under his desk", False),
    "filibusters": ("a small band of armed adventurers wading ashore from a steamer onto a tropical beach under palm trees", False),
    "dred_scott": ("a dignified Black man and his wife standing together on the steps of a St. Louis courthouse", True),
    "lecompton_constitution": ("a ballot box guarded by armed men in a frontier town, voters turned away", False),
    "freeport_doctrine": ("two politicians debating on a wooden platform in a small Illinois town square before a large crowd", False),
    "lincoln_douglas": ("a tall lanky man and a short stocky man debating on an outdoor platform before a huge crowd, reporters writing below", False),
    "ableman_v_booth": ("an editor addressing a crowd outside a Milwaukee jail, the courthouse dome behind", False),
    "john_browns_raid": ("a brick engine house under siege by marines at dawn, its doors being battered in", False),
}

# The 34 candidate cards: the candidate himself, or his cause.
CANDIDATES = {
    "1796-nation": "John Adams in a formal coat at a desk in Federal Hall, Washington's farewell address on the table before him",
    "1796-states": "a Democratic-Republican society meeting in a tavern, mechanics toasting liberty, Thomas Jefferson's portrait on the wall",
    "1800-nation": "John Adams signing papers as a marshal leads a printer away through the window behind him",
    "1800-states": "Thomas Jefferson walking alone to his inauguration through the unfinished capital, crowds cheering",
    "1804-nation": "Thomas Jefferson studying a great map of the West, the Mississippi and New Orleans marked",
    "1804-states": "Charles Cotesworth Pinckney on a busy Charleston wharf among merchant ships and cotton bales",
    "1808-nation": "James Madison at a desk beneath a window showing empty ships idle in harbor",
    "1808-states": "Charles Cotesworth Pinckney beside a sailor with a banner, a merchant ship putting out to sea",
    "1812-nation": "James Madison reviewing troops before a burning capital at night",
    "1812-states": "DeWitt Clinton on a New York dock as merchants cheer, ships ready to sail in peace",
    "1816-nation": "James Monroe on horseback greeted by a cheering crowd on a goodwill tour of New England",
    "1816-states": "Rufus King, an elderly Federalist statesman, standing alone in an empty party hall",
    "1820-nation": "John Quincy Adams writing in his diary late at night by candlelight, a globe beside him",
    "1820-states": "James Monroe signing a bill as congressmen draw a line across a map of the West",
    "1824-nation": "John Quincy Adams and Henry Clay shaking hands in a dim parlor, a third figure watching from the door",
    "1824-states": "Andrew Jackson on horseback greeted by a vast cheering crowd of farmers and frontiersmen",
    "1828-nation": "John Quincy Adams standing beside a canal lock and a new road, symbols of internal improvement",
    "1828-states": "crowds of ordinary people pouring into the White House for Andrew Jackson's inauguration",
    "1832-nation": "Henry Clay addressing the Senate, a classical bank building drawn behind him",
    "1832-states": "Andrew Jackson brandishing a veto before a toppling marble bank building",
    "1836-nation": "William Henry Harrison in a frontier coat at the head of a regional Whig rally",
    "1836-states": "Martin Van Buren in a fine coat counting gold coins at a treasury counter",
    "1840-nation": "William Henry Harrison at the door of a log cabin with a cider barrel, a parade with banners passing",
    "1840-states": "Martin Van Buren beside a strongbox in a vault, an independent treasury, guards at the door",
    "1844-nation": "Henry Clay at a writing desk drafting a letter, a map of Texas on the wall behind him",
    "1844-states": "James K. Polk pointing west across a map of the continent to the Pacific",
    "1848-nation": "Zachary Taylor in a plain coat and straw hat on his old warhorse, a military camp behind",
    "1848-states": "Lewis Cass addressing settlers on a western prairie, each family at its own ballot box",
    "1852-nation": "Winfield Scott in full dress uniform with plumed hat, reviewing troops",
    "1852-states": "Franklin Pierce taking the oath of office in falling snow before the Capitol",
    "1856-nation": "John C. Fremont on a mountain peak in the Rockies raising a flag, his expedition below",
    "1856-states": "James Buchanan at a desk as the Kansas prairie burns in the window behind him",
    "1860-nation": "Abraham Lincoln standing tall in a dark coat, a rail fence and the unfinished Capitol dome behind him",
    "1860-states": "Stephen A. Douglas, short and stocky, speaking from a railway car platform to a crowd",
}
DIGNITY_CANDIDATES = set()


def main():
    deck = list(csv.DictReader(open(os.path.join(DOCS, "deck-dc.csv"), encoding="utf-8-sig")))
    cands = list(csv.DictReader(open(os.path.join(DOCS, "candidates-dc.csv"), encoding="utf-8-sig")))
    rows = []
    for r in deck:
        subject, dignity = STORIES[r["key"]]
        year = int(r["year"]) if r["year"] else 1796
        rows.append((r["key"], r["name"], r["type"], year, subject, dignity))
    for c in cands:
        rows.append((c["key"], "%s (%s %s)" % (c["card_name"], c["candidate"], c["year"]), "Election",
                     int(c["year"]), CANDIDATES[c["key"]], c["key"] in DIGNITY_CANDIDATES))
    out = []
    for key, name, kind, year, subject, dignity in rows:
        style = style_of(year)
        prompt = "%s Subject: %s.%s %s" % (STYLES[style].format(year=year), subject, DIGNITY if dignity else "", TAIL)
        out.append(dict(key=key, name=name, type=kind, year=year, style=style, subject=subject,
                        prompt=prompt, file="%s.png" % key))
    missing = [r["key"] for r in deck if r["key"] not in STORIES] + [c["key"] for c in cands if c["key"] not in CANDIDATES]
    assert not missing, missing
    path = os.path.join(DOCS, "art-prompts.csv")
    with open(path, "w", newline="", encoding="utf-8-sig") as fh:
        w = csv.DictWriter(fh, fieldnames=list(out[0]))
        w.writeheader()
        w.writerows(out)
    by = {}
    for o in out:
        by[o["style"]] = by.get(o["style"], 0) + 1
    print("wrote %s: %d prompts %s" % (os.path.relpath(path, ROOT), len(out), by))


if __name__ == "__main__":
    main()
