"""Search Wikimedia Commons for a photograph for every 2024 card.

    py -X utf8 tools/find_art_2024.py                  # writes docs/art-candidates-2024.json
    py -X utf8 tools/find_art_2024.py --only the_mug_shot,pa

For each card (the main deck, the starters, the 51 states, the 8 outlets) it
runs one or more searches of commons.wikimedia.org and keeps up to eight
photographs, with their title, page, thumbnail, author and license. Nothing
is downloaded here: the picks live in docs/art-2024.csv, and
tools/fetch_art_2024.py fetches only those.
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
OUT = os.path.join(DOCS, "art-candidates-2024.json")
API = "https://commons.wikimedia.org/w/api.php"
UA = "FourthEstate2024Prototype/0.1 (card art search)"
KEEP = 8

# Searches per card; the first finds the most. States search their capitol.
QUERIES = {
    "letter": ["letter to the editor newspaper", "handwritten letter envelope pen"],
    "notice": ["community bulletin board flyers", "public notice sign town"],
    "editorial": ["newspaper editorial page", "newsroom editors desk"],
    "scandal": ["tabloid newspapers", "newspaper headlines newsstand", "paparazzi photographers"],
    "trump_announces": ["Donald Trump speaking 2022", "Trump rally 2022", "Donald Trump November 2022"],
    "finish_the_job": ["President Biden speech union workers 2023", "Joe Biden rally Philadelphia", "President Joe Biden remarks 2023 podium"],
    "trump_takes_iowa": ["Trump Iowa 2024", "Donald Trump Iowa 2023", "Iowa caucus 2024"],
    "beshear_holds_kentucky": ["Andy Beshear 2023", "Andy Beshear governor"],
    "guilty_and_fundraising": ["Trump Tower May 2024", "Trump press conference Trump Tower 2024"],
    "virginia_flips_the_house": ["Virginia State Capitol Richmond", "Virginia House of Delegates chamber"],
    "south_carolina": ["Nikki Haley 2024 campaign", "Trump Haley 2024", "Trump rally February 2024"],
    "34_counts": ["NYC Trump court trial 2024", "Manhattan Criminal Courthouse Centre Street"],
    "rfk_jr_endorses_trump": ["Robert F. Kennedy Jr. Trump Glendale 2024", "Robert F. Kennedy Jr. 2024 Trump rally"],
    "the_special_elections": ["Tom Suozzi 2024", "polling place voters 2024"],
    "the_primary_lead": ["Donald Trump rally 2023", "Trump rally crowd 2024"],
    "the_state_of_the_union": ["2024 State of the Union Address", "Biden State of the Union 2024"],
    "the_joe_rogan_experience": ["Joe Rogan 2023", "Joe Rogan UFC commentator", "podcast studio microphone"],
    "biden_steps_aside": ["Biden Oval Office address July 2024", "Biden Harris July 2024"],
    "bank_your_vote": ["early voting line 2024", "early voting site sign 2024"],
    "inflation_cools": ["gas station price sign 2023", "consumer prices supermarket checkout"],
    "mortgage_rates": ["house for sale sign", "mortgage rates sign bank"],
    "the_chip_factories": ["TSMC Arizona", "Biden TSMC Phoenix 2022"],
    "the_price_of_groceries": ["grocery store eggs price 2023", "supermarket aisle shopping cart"],
    "under_four_percent": ["now hiring sign", "job fair 2023"],
    "tariffs_on_everything": ["Port of Los Angeles containers", "container ship port cranes"],
    "the_fed_signals_cuts": ["Jerome Powell press conference 2023", "Powell FOMC press conference"],
    "out_of_reach": ["new houses under construction suburb", "sold sign house"],
    "loan_forgiveness": ["Biden student debt relief 2022", "cancel student debt rally"],
    "no_tax_on_tips": ["Trump Las Vegas rally June 2024", "tip jar"],
    "the_raleigh_plan": ["Kamala Harris Raleigh August 2024", "Kamala Harris North Carolina 2024"],
    "no_tax_on_overtime": ["Trump Tucson 2024", "time clock punch card"],
    "record_highs": ["New York Stock Exchange trading floor 2024", "Charging Bull Wall Street"],
    "drill_baby_drill": ["fracking drilling rig Pennsylvania", "oil pumpjack"],
    "the_fed_cuts": ["Eccles Building Federal Reserve", "Jerome Powell September 2024"],
    "the_economy_gap": ["empty wallet", "Trump rally Pennsylvania 2024", "cash register receipt groceries"],
    "on_the_picket_line": ["Biden UAW picket", "United Auto Workers Strike 2023"],
    "the_mug_shot": ["Donald Trump mugshot", "Trump mug shot"],
    "the_1864_law": ["Arizona Supreme Court building", "abortion rights protest Arizona 2024"],
    "the_bitcoin_conference": ["bitcoin coin", "Bitcoin 2024 Nashville", "Donald Trump Nashville July 2024"],
    "the_respect_for_marriage_act": ["Respect for Marriage Act signing", "Biden signs Respect for Marriage Act"],
    "the_bud_light_boycott": ["Bud Light cans", "Bud Light"],
    "the_write_in": ["New Hampshire primary polling place", "New Hampshire voting booth", "write-in ballot"],
    "eagle_pass": ["Eagle Pass Texas", "Operation Lone Star razor wire", "Texas National Guard Rio Grande concertina wire"],
    "the_coconut_tree": ["coconut palm tree", "Kamala Harris speech 2023"],
    "the_atlanta_debate": ["Biden Trump debate", "presidential debate stage lecterns", "CNN Techwood Atlanta"],
    "abortion_on_the_ballot": ["abortion rights rally Ohio", "abortion rights march 2022 signs", "Ohio Statehouse protest abortion"],
    "the_bronx_rally": ["Crotona Park", "Trump rally New York 2024", "South Bronx street"],
    "swift_endorses_harris": ["Taylor Swift Eras Tour 2024", "Taylor Swift 2024"],
    "too_old": ["President Joe Biden 2024", "Joe Biden press conference 2024", "Biden boards Air Force One 2024"],
    "the_carroll_verdict": ["E. Jean Carroll", "Daniel Patrick Moynihan United States Courthouse"],
    "the_campus_protests": ["Columbia University Gaza Solidarity Encampment", "Columbia encampment April 2024"],
    "four_indictments": ["Fulton County Courthouse Atlanta", "Jack Smith special counsel"],
    "the_border_numbers": ["migrants border Texas 2023", "Border Patrol migrants processing 2023"],
    "the_hush_money_trial": ["NYC Trump court trial 2024-04", "Trump trial courthouse April 2024"],
    "the_hunter_biden_verdict": ["Hunter Biden", "J. Caleb Boggs Federal Building", "Hunter Biden 2023"],
    "the_civil_fraud_trial": ["Letitia James", "New York County Courthouse 60 Centre Street"],
    "the_phillips_challenge": ["Dean Phillips 2024 campaign", "Dean Phillips New Hampshire"],
    "childless_cat_ladies": ["JD Vance 2024", "woman with cats"],
    "the_border_czar": ["Kamala Harris El Paso 2021", "Kamala Harris border visit"],
    "springfield": ["Springfield Ohio downtown", "Springfield Ohio city hall"],
    "not_a_thing_comes_to_mind": ["Kamala Harris interview 2024", "Kamala Harris October 2024"],
    "arlington": ["Arlington National Cemetery Section 60", "Arlington National Cemetery headstones"],
    "the_2019_positions": ["Kamala Harris 2019 campaign", "Kamala Harris Oakland 2019 rally"],
    "project_2025": ["Heritage Foundation", "Heritage Foundation headquarters Washington", "Kevin Roberts Heritage"],
    "seal_the_border": ["border wall Arizona", "US Mexico border fence"],
    "mass_deportation": ["ICE removal flight", "ICE officers arrest"],
    "end_inflation": ["gas prices sign", "dollar bills wallet"],
    "energy_dominance": ["oil refinery Texas", "offshore oil platform Gulf of Mexico"],
    "manufacturing_superpower": ["automobile assembly line workers", "factory workers assembly line United States", "steel worker furnace"],
    "tax_cuts_for_workers": ["waitress", "diner waitress coffee", "bartender"],
    "defend_the_constitution": ["Constitution of the United States We the People", "National Archives rotunda Constitution"],
    "end_the_weaponization": ["J. Edgar Hoover Building", "Department of Justice building Washington"],
    "keep_men_out_of_womens_sports": ["women's track and field NCAA", "women's 100 m hurdles", "girls high school soccer game"],
    "secure_our_elections": ["voter ID polling place sign", "paper ballots counting"],
    "work_not_wealth": ["construction workers United States", "union rally workers"],
    "corporate_greed": ["Wall Street sign", "corporate skyscrapers Manhattan"],
    "drug_prices": ["prescription pill bottles pharmacy", "insulin vials"],
    "paid_leave": ["preschool classroom", "daycare children teacher", "kindergarten classroom United States"],
    "protect_social_security": ["Social Security card", "Social Security Administration building"],
    "clean_energy": ["solar farm", "wind farm Iowa", "solar panels wind turbines"],
    "gun_safety": ["March for Our Lives", "gun store rifles"],
    "voting_rights": ["Edmund Pettus Bridge", "voting rights march 2021", "John Lewis"],
    "reproductive_freedom": ["abortion rights rally Supreme Court 2022", "Bans Off Our Bodies"],
    "freedom_of_the_press": ["White House press briefing room", "newspapers newsstand"],
    # the outlets
    "outlet:globe": ["NewsNation", "NewsNation logo"],
    "outlet:argus": ["Associated Press", "AP Associated Press building", "Associated Press reporter"],
    "outlet:intelligencer": ["New York Times Building", "The New York Times front page"],
    "outlet:journal": ["Wall Street Journal newspaper", "Wall Street Journal"],
    "outlet:herald": ["Politico", "Politico newsroom"],
    "outlet:sun": ["New York Post", "New York Post newspaper box", "New York Post headline"],
    "outlet:north_star": ["Fox News headquarters", "Fox News studio"],
    "outlet:aurora": ["CNN Center Atlanta", "CNN studio"],
    # states whose capitol search needs help
    "state:wy": ["Wyoming State Capitol", "Wyoming Capitol Cheyenne"],
    "state:dc": ["United States Capitol west front", "Washington Monument"],
    "state:ne": ["Nebraska State Capitol Lincoln"],
    "state:me": ["Maine State House Augusta"],
    "state:nh": ["New Hampshire State House Concord"],
    "state:ma": ["Massachusetts State House Boston"],
    "state:vt": ["Vermont State House Montpelier"],
    "state:nj": ["New Jersey State House Trenton"],
    "state:md": ["Maryland State House Annapolis"],
    "state:oh": ["Ohio Statehouse Columbus"],
    "state:in": ["Indiana Statehouse Indianapolis"],
    "state:sc": ["South Carolina State House Columbia"],
    "state:de": ["Delaware Legislative Hall Dover"],
    "state:nc": ["North Carolina State Capitol", "North Carolina Capitol Raleigh"],
    "state:wa": ["Washington State Capitol Olympia"],
    "state:ga": ["Georgia State Capitol Atlanta"],
    "state:ny": ["New York State Capitol Albany"],
}


def api(params):
    params = dict(params, format="json")
    req = urllib.request.Request(API + "?" + urllib.parse.urlencode(params), headers={"User-Agent": UA})
    for attempt in range(4):
        try:
            with urllib.request.urlopen(req, timeout=30) as fh:
                return json.load(fh)
        except Exception as e:
            print("  retry:", repr(e)[:100], flush=True)
            time.sleep(2 + 3 * attempt)
    return {}


def strip(s):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", s or "")).strip()


def search(q):
    d = api({"action": "query", "generator": "search", "gsrsearch": q + " filetype:bitmap", "gsrnamespace": 6,
             "gsrlimit": 12, "prop": "imageinfo", "iiprop": "url|size|mime|extmetadata", "iiurlwidth": 480})
    pages = sorted((d.get("query") or {}).get("pages", {}).values(), key=lambda p: p.get("index", 99))
    out = []
    for p in pages:
        ii = (p.get("imageinfo") or [{}])[0]
        if ii.get("mime") not in ("image/jpeg", "image/png") or ii.get("width", 0) < 700:
            continue
        m = ii.get("extmetadata") or {}
        out.append({
            "file": p["title"][5:],
            "url": ii.get("descriptionurl"),
            "thumb": ii.get("thumburl"),
            "full": ii.get("url"),
            "w": ii.get("width"), "h": ii.get("height"),
            "author": strip((m.get("Artist") or {}).get("value"))[:120],
            "license": strip((m.get("LicenseShortName") or {}).get("value")),
            "date": strip((m.get("DateTimeOriginal") or {}).get("value"))[:20],
            "desc": strip((m.get("ImageDescription") or {}).get("value"))[:200],
        })
    return out


def cards():
    out = []
    for r in csv.DictReader(open(os.path.join(DOCS, "deck-2024.csv"), encoding="utf-8-sig")):
        out.append((r["key"], r["name"], QUERIES.get(r["key"]) or [r["name"]]))
    for r in csv.DictReader(open(os.path.join(DOCS, "states-2024.csv"), encoding="utf-8-sig")):
        k = "state:" + r["key"]
        out.append((k, r["state"], QUERIES.get(k) or [r["state"] + " State Capitol"]))
    for r in csv.DictReader(open(os.path.join(DOCS, "outlets-2024.csv"), encoding="utf-8-sig")):
        k = "outlet:" + r["key"]
        out.append((k, r["name"], QUERIES.get(k) or [r["name"]]))
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--only", default="")
    ap.add_argument("--per-query", type=int, default=KEEP, help="at most this many results from one search")
    ap.add_argument("--resume", action="store_true", help="skip cards that already have results")
    a = ap.parse_args()
    only = set(filter(None, a.only.split(",")))
    data = json.load(open(OUT, encoding="utf-8")) if os.path.exists(OUT) else {}
    for key, name, queries in cards():
        if only and key not in only and key.split(":")[-1] not in only:
            continue
        if a.resume and data.get(key, {}).get("results"):
            continue
        results, seen = [], set()
        for q in queries:
            took = 0
            for r in search(q):
                if r["file"] not in seen and len(results) < KEEP and took < a.per_query:
                    seen.add(r["file"])
                    results.append(dict(r, query=q))
                    took += 1
            time.sleep(0.3)
            if len(results) >= KEEP:
                break
        data[key] = {"name": name, "queries": queries, "results": results}
        print("%-32s %d" % (key, len(results)), flush=True)
        json.dump(data, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    json.dump(data, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)


if __name__ == "__main__":
    main()
