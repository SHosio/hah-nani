"""Emit the `## Kanji` table for a Genki lesson.

Same idea as build_vocab.py. Reads the vendored kanji list, applies any
corrections, and prints markdown table rows.

    python3 tools/build_kanji.py 21
"""

import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, "data")

HEADER = [
    "| kanji | readings | meaning | examples |",
    "|---|---|---|---|",
]

MAX_EXAMPLES = 4


def load(name):
    with open(os.path.join(DATA, name), encoding="utf-8") as f:
        return json.load(f)


def lesson_entries(lesson):
    kanji = load("genki-kanji.json")
    overrides = load("kanji-overrides.json")

    entries = [k for k in kanji if k["Lesson"] == lesson]
    if not entries:
        raise SystemExit(f"no kanji found for lesson {lesson}")

    out = []
    for entry in entries:
        examples = [
            f"{e['Example']} ({e['Reading']}) {e['Definition']}"
            for e in entry.get("Examples", [])[:MAX_EXAMPLES]
        ]
        row = {
            "kanji": entry["Kanji"],
            "readings": entry["Reading"],
            "meaning": entry["Definition"],
            "examples": examples,
        }
        row.update(overrides.get(f"{lesson}:{entry['Kanji']}", {}))
        if not row["examples"]:
            raise SystemExit(
                f"lesson {lesson}, kanji {row['kanji']}: no examples in the "
                f"dataset. Add them to data/kanji-overrides.json."
            )
        out.append(row)
    return out


def cell(value):
    return (value or "").replace("|", "\\|").strip() or "—"


def main():
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    lesson = int(sys.argv[1])

    print("\n".join(HEADER))
    for row in lesson_entries(lesson):
        cells = [
            cell(row["kanji"]),
            cell(row["readings"]),
            cell(row["meaning"]),
            cell("; ".join(row["examples"])),
        ]
        print("| " + " | ".join(cells) + " |")


if __name__ == "__main__":
    main()
