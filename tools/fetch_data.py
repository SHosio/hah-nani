"""Download the vocabulary and kanji datasets the chapter tables are built from.

These are Genki's own word lists, so they are not committed. Run this once on a
fresh clone, then `tools/sync_tables.py`.

    python3 tools/fetch_data.py
"""

import json
import os
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, "data")

BASE = "https://raw.githubusercontent.com/cemulate/genki-db/master/src/assets"
FILES = {
    "genki-vocab.json": f"{BASE}/vocab.json",
    "genki-kanji.json": f"{BASE}/kanji.json",
}


def fetch(name, url):
    target = os.path.join(DATA, name)
    with urllib.request.urlopen(url, timeout=30) as response:
        payload = response.read()

    # Fail before overwriting anything if the response is not what we expect.
    records = json.loads(payload)
    if not isinstance(records, list) or not records:
        raise SystemExit(f"{url} did not return a non-empty JSON list")
    if "Lesson" not in records[0]:
        raise SystemExit(f"{url} returned records without a 'Lesson' field")

    with open(target, "wb") as f:
        f.write(payload)
    print(f"{name}: {len(records)} records")


def main():
    os.makedirs(DATA, exist_ok=True)
    for name, url in FILES.items():
        fetch(name, url)
    print("\nNow run: python3 tools/sync_tables.py")


if __name__ == "__main__":
    main()
