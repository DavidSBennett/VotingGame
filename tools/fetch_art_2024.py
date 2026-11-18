"""Fetch the picked card photographs into docs/print/art/.

    py -X utf8 tools/fetch_art_2024.py            # only the missing ones
    py -X utf8 tools/fetch_art_2024.py --force

docs/art-2024.csv has one row per card: key, file (the Wikimedia Commons
file name), url (its page), author, license. Each is fetched 960 px wide (a
standard Wikimedia thumbnail size), scaled to 720 px and saved as docs/print/art/<key>.jpg (":" in a key becomes "-").
"""
import argparse
import csv
import io
import os
import time
import urllib.parse
import urllib.request

from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "docs", "art-2024.csv")
OUT = os.path.join(ROOT, "docs", "print", "art")
UA = "FourthEstate2024Prototype/0.1 (card art search)"


def name(key):
    return key.replace(":", "-") + ".jpg"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--force", action="store_true")
    a = ap.parse_args()
    os.makedirs(OUT, exist_ok=True)
    done = failed = 0
    for r in csv.DictReader(open(SRC, encoding="utf-8-sig")):
        path = os.path.join(OUT, name(r["key"]))
        if not r["file"] or (os.path.exists(path) and not a.force):
            continue
        url = "https://commons.wikimedia.org/wiki/Special:FilePath/%s?width=960" % urllib.parse.quote(r["file"].replace(" ", "_"))
        for attempt in range(4):
            try:
                req = urllib.request.Request(url, headers={"User-Agent": UA})
                with urllib.request.urlopen(req, timeout=60) as fh:
                    im = Image.open(io.BytesIO(fh.read())).convert("RGB")
                im.thumbnail((720, 2000))
                im.save(path, "JPEG", quality=76, optimize=True)
                done += 1
                break
            except Exception as ex:
                if attempt == 3:
                    failed += 1
                    print("FAILED %s: %r" % (r["key"], ex), flush=True)
                time.sleep(3 + 4 * attempt)
        time.sleep(0.5)
    print("fetched %d, failed %d" % (done, failed))


if __name__ == "__main__":
    main()
