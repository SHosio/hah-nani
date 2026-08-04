"""Mutate a known-good chapter and confirm the validator objects.

Without the control case at the end, the suite can pass vacuously: an early
version reported every breakage as caught because a filename check was firing
on the temp file and masking the real assertions.

    python3 tools/test_validate_chapters.py
"""

import os
import shutil
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
VALIDATOR = os.path.join(ROOT, "tools", "validate_chapters.py")
GOOD = os.path.join(ROOT, "chapters", "g2", "l21.md")
GOOD_COMPANION = os.path.join(ROOT, "chapters", "g2", "l21.vocab.md")

if not os.path.exists(GOOD_COMPANION):
    raise SystemExit(
        "chapters/g2/l21.vocab.md is missing. Run tools/fetch_data.py then "
        "tools/sync_tables.py before running these tests."
    )

with open(GOOD, encoding="utf-8") as f:
    CHAPTER = f.read()
with open(GOOD_COMPANION, encoding="utf-8") as f:
    COMPANION = f.read()

# (name, file to mutate, find, replace). "chapter" is l21.md, "companion" is
# the generated word list beside it.
CASES = [
    ("pointer count disagrees with frontmatter", "chapter",
     "vocab_count: 56", "vocab_count: 50"),
    ("id comment mismatch", "chapter",
     "<!-- id: g2-l21-tearu -->", "<!-- id: g2-l21-WRONG -->"),
    ("prompt cites unknown id", "chapter",
     "(g2-l21-tehoshii) Write 6", "(g2-l21-nosuch) Write 6"),
    ("Notes block removed", "chapter", "**Notes**", "NOTES-REMOVED"),
    ("examples column dropped", "chapter",
     "| jp | romaji | en |", "| jp | en |"),
    ("extra ## section", "chapter", "## Kanji", "## Kanji\n\n## Extra\n"),
    ("slug does not match lesson", "chapter", "slug: g2-l21", "slug: g2-l99"),
    ("grammar heading renamed", "chapter", "### ～てある", "### Something Else"),
    ("word list pasted back into chapter", "chapter",
     "## Vocabulary\n\n_56 entries.",
     "## Vocabulary\n\n| kanji | kana | romaji | english | pos |\n"
     "|---|---|---|---|---|\n| 蚊 | か | ka | mosquito | noun |\n\n_56 entries."),
    ("unknown pos value", "companion",
     "| akachan | baby | noun |", "| akachan | baby | nown |"),
    ("vocabulary row emptied", "companion",
     "| 蚊 | か | ka | mosquito | noun |", "| 蚊 | か |  | mosquito | noun |"),
    ("kanji row dropped", "companion",
     "| 妹 | いもうと；マイ | young sister |", "| 妹 | いもうと；マイ |"),
]


def run(workdir):
    return subprocess.run(
        [sys.executable, VALIDATOR, os.path.join(workdir, "l21.md")],
        capture_output=True,
        text=True,
    )


def write_pair(workdir, chapter, companion):
    with open(os.path.join(workdir, "l21.md"), "w", encoding="utf-8") as f:
        f.write(chapter)
    with open(os.path.join(workdir, "l21.vocab.md"), "w", encoding="utf-8") as f:
        f.write(companion)


def main():
    workdir = tempfile.mkdtemp(prefix="chapter-validator-")
    failures = []

    for name, target, old, new in CASES:
        source = CHAPTER if target == "chapter" else COMPANION
        if old not in source:
            failures.append(f"{name}: fixture string not found, test is stale")
            print(f"STALE   {name}")
            continue

        write_pair(
            workdir,
            CHAPTER.replace(old, new, 1) if target == "chapter" else CHAPTER,
            COMPANION.replace(old, new, 1) if target == "companion" else COMPANION,
        )
        result = run(workdir)
        caught = result.returncode != 0
        first = next(
            (ln.strip() for ln in result.stdout.splitlines() if ln.startswith("  ")), ""
        )
        print(f"{'CAUGHT ' if caught else 'MISSED '} {name}")
        if caught:
            print(f"          {first}")
        else:
            failures.append(name)

    # A fresh clone has no companion file. That must pass, with a note.
    write_pair(workdir, CHAPTER, COMPANION)
    os.remove(os.path.join(workdir, "l21.vocab.md"))
    result = run(workdir)
    if result.returncode == 0 and "not generated locally" in result.stdout:
        print("CAUGHT  fresh clone without word lists still validates")
    else:
        failures.append("missing companion should pass with a note")
        print("MISSED  fresh clone without word lists")

    # Control: unmodified pair must pass, or nothing above proves anything.
    write_pair(workdir, CHAPTER, COMPANION)
    if run(workdir).returncode == 0:
        print("CAUGHT  control: unmodified chapter passes")
    else:
        failures.append("unmodified chapter failed validation")
        print("MISSED  control: unmodified chapter should pass")

    shutil.rmtree(workdir, ignore_errors=True)

    print()
    if failures:
        print(f"{len(failures)} problem(s): {failures}")
        return 1
    print(f"all {len(CASES)} breakages detected, both controls pass")
    return 0


if __name__ == "__main__":
    sys.exit(main())
