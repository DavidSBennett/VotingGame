"""Search the Library of Congress for period prints for every card.

    py -X utf8 tools/find_period_art.py            # writes docs/art-candidates.json
    py -X utf8 tools/find_period_art.py --only embargo_act,1840-nation

For each card it runs one or more searches of loc.gov/photos (Prints and
Photographs) and keeps up to eight results dated 1875 or earlier, with
their title, date, item page and thumbnail. Nothing is downloaded: the
results feed the review page (tools/art_review_page.py), where the user
picks an image per card. Only picked images are fetched, after approval.
"""
import argparse
import csv
import json
import os
import re
import time
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(ROOT, "docs")
OUT = os.path.join(DOCS, "art-candidates.json")
LATEST = 1875
KEEP = 8

# Searches per card; the first finds the most. Cards not listed search their name.
QUERIES = {
    "letter": ["letter writing 18th century", "man writing letter quill"],
    "notice": ["village green church", "broadside notice"],
    "editorial": ["printing office", "editor newspaper office"],
    "scandal": ["political caricature", "gossip"],
    "stamp_act": ["stamp act", "stamp act repeal"],
    "boston_massacre": ["bloody massacre king street", "boston massacre"],
    "tea_party": ["destruction of tea boston harbor", "boston tea party"],
    "common_sense": ["thomas paine", "common sense paine"],
    "declaration": ["declaration of independence 1776", "declaration independence reading"],
    "saratoga": ["surrender of burgoyne", "saratoga"],
    "valley_forge": ["valley forge", "washington valley forge"],
    "articles_confederation": ["continental congress", "congress voting independence"],
    "newburgh": ["washington officers newburgh", "washington resigning commission"],
    "treaty_of_paris": ["treaty of paris 1783", "peace 1783"],
    "pennsylvania_packet": ["pennsylvania packet", "philadelphia 18th century street"],
    "shays_rebellion": ["shays rebellion", "daniel shays"],
    "antifederalist_papers": ["anti-federalist", "federal edifice"],
    "constitutional_convention": ["constitutional convention", "signing of the constitution"],
    "federalist_papers": ["federalist", "alexander hamilton"],
    "northwest_ordinance": ["ohio river 18th century", "marietta ohio"],
    "gazette_of_the_us": ["federal hall new york", "printing press 18th century"],
    "judiciary_act": ["supreme court justices early", "john jay chief justice"],
    "assumption": ["alexander hamilton treasury", "speculators"],
    "residence_act": ["city of washington plan", "potomac river"],
    "bank_of_the_us": ["bank of the united states philadelphia", "first bank united states"],
    "bill_of_rights": ["liberty allegory", "freedom of the press"],
    "national_gazette": ["philip freneau", "thomas jefferson"],
    "report_manufactures": ["cotton mill", "manufactory"],
    "rights_of_man": ["thomas paine rights of man", "liberty cap"],
    "postal_act_1792": ["post rider", "mail stage"],
    "citizen_genet": ["genet", "french revolution america"],
    "democratic_societies": ["democratic society", "liberty pole"],
    "whiskey_rebellion": ["whiskey insurrection", "whiskey rebellion"],
    "jay_treaty": ["jay treaty", "john jay"],
    "alien_sedition": ["sedition act", "congressional pugilists"],
    "kentucky_resolutions": ["james madison", "thomas jefferson"],
    "xyz_affair": ["property protected a la francoise", "xyz affair"],
    "marbury": ["john marshall", "supreme court marshall"],
    "louisiana_purchase": ["louisiana purchase", "new orleans 1803"],
    "burr_conspiracy": ["aaron burr", "burr trial"],
    "chesapeake_affair": ["chesapeake leopard", "frigate chesapeake"],
    "embargo_act": ["ograbme", "embargo 1808"],
    "niles_register": ["niles register", "baltimore 1815"],
    "tippecanoe": ["battle of tippecanoe", "tippecanoe"],
    "war_of_1812": ["constitution guerriere", "war of 1812 naval"],
    "gerrymander": ["gerry-mander", "gerrymander"],
    "hartford_convention": ["hartford convention", "leap no leap"],
    "treaty_of_ghent": ["treaty of ghent", "peace of ghent"],
    "second_bank": ["second bank of the united states", "bank of the united states"],
    "tariff_of_1816": ["custom house", "tariff"],
    "cumberland_road": ["national road", "cumberland road"],
    "mcculloch": ["john marshall", "supreme court"],
    "adams_onis": ["florida 1819", "john quincy adams"],
    "panic_of_1819": ["hard times", "bank run"],
    "missouri_compromise": ["missouri compromise", "henry clay"],
    "denmark_vesey": ["charleston 1822", "charleston south carolina"],
    "crawford_radicals": ["william h. crawford", "caucus"],
    "monroe_doctrine": ["monroe doctrine", "james monroe"],
    "american_system": ["henry clay american system", "henry clay"],
    "erie_canal": ["erie canal", "lockport"],
    "lowell_mills": ["lowell mills", "lowell massachusetts"],
    "freedoms_journal": ["freedom's journal", "african american press"],
    "anti_masonic_party": ["anti-masonic", "morgan masonic"],
    "cherokee_phoenix": ["cherokee phoenix", "sequoyah"],
    "sc_exposition": ["john c. calhoun", "calhoun"],
    "tariff_abominations": ["tariff 1828", "tariff caricature"],
    "cotton_boom": ["cotton plantation", "cotton steamboat"],
    "indian_removal": ["indian removal", "trail of tears"],
    "webster_hayne": ["webster replying to hayne", "daniel webster"],
    "the_liberator": ["william lloyd garrison", "liberator garrison"],
    "bank_war": ["king andrew the first", "bank war jackson"],
    "force_bill": ["nullification", "andrew jackson nullification"],
    "penny_press": ["newsboy", "new york sun"],
    "gag_rule": ["john quincy adams congress", "house of representatives chamber"],
    "specie_circular": ["specie claws", "specie circular"],
    "panic_of_1837": ["times panic 1837", "hard times 1837"],
    "amistad": ["amistad", "cinque"],
    "log_cabin_campaign": ["log cabin hard cider", "harrison log cabin"],
    "tyler_vetoes": ["john tyler veto", "tyler"],
    "dorr_rebellion": ["dorr rebellion", "thomas dorr"],
    "webster_ashburton": ["webster ashburton", "daniel webster"],
    "telegraph": ["morse telegraph", "telegraph"],
    "texas_annexation": ["texas annexation", "texas 1845"],
    "oregon_treaty": ["oregon question", "fifty-four forty"],
    "associated_press": ["steamship arrival", "news room"],
    "mexican_war": ["chapultepec", "mexican war battle"],
    "wilmot_proviso": ["wilmot proviso", "free soil"],
    "rotary_press": ["hoe printing press", "lightning press"],
    "free_soil": ["free soil", "van buren free soil"],
    "gold_rush": ["california gold", "gold diggers"],
    "compromise_1850": ["united states senate 1850", "clay senate 1850"],
    "fugitive_slave_act": ["fugitive slave law", "effects of the fugitive-slave-law"],
    "georgia_platform": ["georgia 1850", "milledgeville"],
    "nashville_convention": ["nashville 1850", "southern rights"],
    "cheap_postage": ["post office", "country post office"],
    "uncle_toms_cabin": ["uncle tom's cabin", "eliza crossing"],
    "transcontinental_railroad": ["pacific railroad survey", "railroad 1855"],
    "gadsden_purchase": ["gadsden purchase", "arizona 1854"],
    "kansas_nebraska": ["kansas nebraska", "forcing slavery down the throat"],
    "ostend_manifesto": ["ostend doctrine", "cuba 1854"],
    "personal_liberty_laws": ["anthony burns", "fugitive slave boston"],
    "bleeding_kansas": ["bleeding kansas", "lawrence kansas 1856"],
    "sumner_caning": ["southern chivalry argument versus club's", "sumner brooks"],
    "filibusters": ["william walker nicaragua", "filibuster nicaragua"],
    "dred_scott": ["dred scott", "dred scott family"],
    "lecompton_constitution": ["lecompton", "kansas 1857"],
    "freeport_doctrine": ["stephen a. douglas", "douglas"],
    "lincoln_douglas": ["lincoln douglas", "lincoln douglas debate"],
    "ableman_v_booth": ["sherman booth", "milwaukee 1854"],
    "john_browns_raid": ["harper's ferry", "john brown"],
    # the candidate cards
    "1796-nation": ["john adams president", "john adams"],
    "1796-states": ["thomas jefferson 1796", "thomas jefferson"],
    "1800-nation": ["john adams", "federalist 1800"],
    "1800-states": ["jefferson 1800 election", "thomas jefferson"],
    "1804-nation": ["thomas jefferson president", "louisiana 1803"],
    "1804-states": ["charles cotesworth pinckney", "pinckney"],
    "1808-nation": ["james madison", "madison president"],
    "1808-states": ["charles cotesworth pinckney", "free trade sailors rights"],
    "1812-nation": ["james madison", "madison war 1812"],
    "1812-states": ["dewitt clinton", "clinton"],
    "1816-nation": ["james monroe", "monroe president"],
    "1816-states": ["rufus king", "king rufus"],
    "1820-nation": ["john quincy adams", "j.q. adams"],
    "1820-states": ["james monroe", "monroe"],
    "1824-nation": ["john quincy adams", "corrupt bargain"],
    "1824-states": ["andrew jackson", "general jackson"],
    "1828-nation": ["john quincy adams 1828", "john quincy adams"],
    "1828-states": ["andrew jackson president", "jackson inauguration"],
    "1832-nation": ["henry clay 1832", "henry clay"],
    "1832-states": ["andrew jackson bank", "king andrew"],
    "1836-nation": ["william henry harrison", "harrison 1836"],
    "1836-states": ["martin van buren", "van buren 1836"],
    "1840-nation": ["harrison log cabin", "william henry harrison 1840"],
    "1840-states": ["martin van buren 1840", "sub-treasury"],
    "1844-nation": ["henry clay 1844", "clay frelinghuysen"],
    "1844-states": ["james k. polk", "polk dallas"],
    "1848-nation": ["zachary taylor", "rough and ready"],
    "1848-states": ["lewis cass", "cass butler"],
    "1852-nation": ["winfield scott", "scott graham"],
    "1852-states": ["franklin pierce", "pierce king"],
    "1856-nation": ["john c. fremont", "fremont 1856"],
    "1856-states": ["james buchanan", "buchanan 1856"],
    "1860-nation": ["abraham lincoln 1860", "lincoln hamlin"],
    "1860-states": ["stephen a. douglas", "douglas johnson 1860"],
}


def year_of(date):
    m = re.search(r"\b(1[5-9]\d\d)\b", str(date or ""))
    return int(m.group(1)) if m else None


def search(q):
    url = "https://www.loc.gov/photos/?" + urllib.parse.urlencode({"q": q, "fo": "json", "c": 40, "at": "results"})
    for attempt in range(4):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": "FourthEstate-art-research/1.0"})
            with urllib.request.urlopen(req, timeout=40) as fh:
                return json.load(fh).get("results", [])
        except Exception as e:      # rate limits (429) and timeouts: back off and retry
            time.sleep(600)                 # loc.gov blocks for up to an hour; wait it out
            last = e
    print("  failed:", q, last)
    return []


def cards():
    out = []
    for r in csv.DictReader(open(os.path.join(DOCS, "art-prompts.csv"), encoding="utf-8-sig")):
        out.append((r["key"], r["name"], int(r["year"])))
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--only", default="")
    ap.add_argument("--resume", action="store_true", help="skip cards that already have results")
    args = ap.parse_args()
    only = set(filter(None, args.only.split(",")))
    found = json.load(open(OUT, encoding="utf-8")) if os.path.exists(OUT) else {}
    for key, name, year in cards():
        if only and key not in only:
            continue
        if args.resume and found.get(key, {}).get("results"):
            continue
        seen, keep = set(), []
        for q in QUERIES.get(key, [name.replace("The ", "")]):
            for r in search(q):
                y = year_of(r.get("date"))
                img = (r.get("image_url") or [None])[0]
                fmt = " ".join(r.get("original_format") or [])
                if "print" not in fmt and "drawing" not in fmt:      # prints and drawings, not book pages
                    continue
                if not img or y is None or y > LATEST or r.get("url") in seen:
                    continue
                seen.add(r["url"])
                keep.append(dict(title=r.get("title", "")[:200], date=r.get("date"), url=r["url"],
                                 thumb=img.split("#")[0], query=q))
            time.sleep(4)                   # stay under loc.gov's rate limit
            if len(keep) >= KEEP:
                break
        found[key] = dict(name=name, year=year, results=keep[:KEEP])
        print("%-26s %d" % (key, len(found[key]["results"])), flush=True)
        json.dump(found, open(OUT, "w", encoding="utf-8"), indent=1)
    print("wrote", os.path.relpath(OUT, ROOT), "-", sum(1 for v in found.values() if v["results"]), "cards with results of", len(found))


if __name__ == "__main__":
    main()
