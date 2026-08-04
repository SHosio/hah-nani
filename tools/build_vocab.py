"""Emit the `## Vocabulary` table for a Genki lesson.

Reads the vendored word list, applies part-of-speech grouping and any
corrections, generates romaji, and prints markdown table rows ready to paste
into a chapter file.

    python3 tools/build_vocab.py 21
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from romaji import to_romaji  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, "data")

HEADER = [
    "| kanji | kana | romaji | english | pos |",
    "|---|---|---|---|---|",
]


def load(name):
    with open(os.path.join(DATA, name), encoding="utf-8") as f:
        return json.load(f)


def lesson_entries(lesson):
    vocab = load("genki-vocab.json")
    groups = load("pos-groups.json").get(str(lesson))
    overrides = load("vocab-overrides.json")

    entries = [w for w in vocab if w["Lesson"] == lesson]
    if not entries:
        raise SystemExit(f"no vocabulary found for lesson {lesson}")

    if groups is None:
        raise SystemExit(
            f"lesson {lesson} has no part-of-speech grouping yet. "
            f"Add an entry to data/pos-groups.json (total words: {len(entries)})."
        )

    expected = sum(count for _, count in groups)
    if expected != len(entries):
        raise SystemExit(
            f"lesson {lesson}: pos-groups.json accounts for {expected} words "
            f"but the dataset has {len(entries)}"
        )

    out = []
    i = 0
    for pos, count in groups:
        for entry in entries[i:i + count]:
            row = {
                "kanji": entry["Kanji"],
                "kana": entry["Kana"],
                "english": entry["Meaning"],
                "pos": pos,
            }
            row["romaji"] = to_romaji(row["kana"])
            # Overrides land last so a hand-written romaji wins over the
            # generated one, which matters for phrases containing particles.
            row.update(overrides.get(f"{lesson}:{entry['Kana']}", {}))
            out.append(row)
        i += count

    return apply_additions(out, lesson)


def apply_additions(rows, lesson):
    """Splice in words the book lists but the dataset omits.

    Each addition goes after the last existing row sharing its part of speech,
    which keeps Genki's own grouping order intact. A part of speech with no
    existing rows appends at the end.
    """
    additions = load("vocab-additions.json").get(str(lesson))
    if not additions:
        return rows

    for entry in additions:
        missing = [f for f in ("kanji", "kana", "english", "pos") if f not in entry]
        if missing:
            raise SystemExit(
                f"lesson {lesson}: addition {entry!r} is missing {missing}"
            )
        row = dict(entry)
        row.setdefault("romaji", to_romaji(row["kana"]))

        last = max(
            (i for i, r in enumerate(rows) if r["pos"] == row["pos"]), default=None
        )
        rows.insert(len(rows) if last is None else last + 1, row)

    return rows


def cell(value):
    """Escape a value for a markdown table cell."""
    return (value or "").replace("|", "\\|").strip() or "—"


def main():
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    lesson = int(sys.argv[1])

    print("\n".join(HEADER))
    for row in lesson_entries(lesson):
        cells = [cell(row[k]) for k in ("kanji", "kana", "romaji", "english", "pos")]
        print("| " + " | ".join(cells) + " |")


if __name__ == "__main__":
    main()
