"""Check every chapter file against chapters/SCHEMA.md.

Chapter files are consumed by generators, so a chapter that quietly grows an
extra heading or loses a column breaks whatever was built on top of it. This
fails loudly instead.

    python3 tools/validate_chapters.py
    python3 tools/validate_chapters.py chapters/g2/l21.md

Exits non-zero if anything is wrong.
"""

import glob
import os
import re
import sys

import yaml

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

SECTIONS = ["Overview", "Grammar", "Vocabulary", "Kanji", "Practice prompts"]
GRAMMAR_BLOCKS = ["**Formation**", "**Examples**", "**Notes**"]
VOCAB_COLUMNS = ["kanji", "kana", "romaji", "english", "pos"]
KANJI_COLUMNS = ["kanji", "readings", "meaning", "examples"]
EXAMPLE_COLUMNS = ["jp", "romaji", "en"]
POS_VALUES = {
    "noun",
    "i-adjective",
    "na-adjective",
    "u-verb",
    "ru-verb",
    "irregular-verb",
    "adverb/other",
}
BOOK_LESSONS = {1: range(1, 13), 2: range(13, 24)}
ID_PATTERN = re.compile(r"^[a-z0-9]+(-[a-z0-9]+)*$")
MIN_EXAMPLE_ROWS = 3


class Report:
    def __init__(self, path):
        self.path = os.path.relpath(path, ROOT)
        self.errors = []
        self.skipped = []

    def check(self, condition, message):
        if not condition:
            self.errors.append(message)
        return condition


def split_frontmatter(text, report):
    if not text.startswith("---\n"):
        report.check(False, "file does not start with YAML frontmatter")
        return None, text
    end = text.find("\n---\n", 3)
    if end == -1:
        report.check(False, "frontmatter is not terminated by a --- line")
        return None, text
    try:
        meta = yaml.safe_load(text[4:end])
    except yaml.YAMLError as err:
        report.check(False, f"frontmatter is not valid YAML: {err}")
        return None, text[end + 5:]
    return meta, text[end + 5:]


def table_rows(lines, start):
    """Read a markdown table beginning at `start`. Returns (header, rows, end)."""
    if start + 1 >= len(lines) or not lines[start].startswith("|"):
        return None, [], start
    header = [c.strip() for c in lines[start].strip().strip("|").split("|")]
    rows = []
    i = start + 2  # skip the |---| separator
    while i < len(lines) and lines[i].startswith("|"):
        rows.append([c.strip() for c in lines[i].strip().strip("|").split("|")])
        i += 1
    return header, rows, i


def find_table_after(lines, index):
    """Find the first table starting at or after `index`, stopping at headings."""
    for i in range(index, len(lines)):
        if lines[i].startswith("#"):
            return None, [], i
        if lines[i].startswith("|"):
            return table_rows(lines, i)
    return None, [], len(lines)


def check_frontmatter(meta, report):
    if meta is None:
        return

    book = meta.get("book")
    lesson = meta.get("lesson")
    report.check(book in BOOK_LESSONS, f"book must be 1 or 2, got {book!r}")
    if book in BOOK_LESSONS:
        report.check(
            lesson in BOOK_LESSONS[book],
            f"lesson {lesson!r} does not belong to book {book}",
        )
        report.check(
            meta.get("slug") == f"g{book}-l{lesson}",
            f"slug should be 'g{book}-l{lesson}', got {meta.get('slug')!r}",
        )

    for field in ("title", "title_jp"):
        report.check(
            isinstance(meta.get(field), str) and meta[field].strip(),
            f"{field} is missing or empty",
        )

    for field in ("vocab_count", "kanji_count"):
        report.check(
            isinstance(meta.get(field), int),
            f"{field} is missing or not an integer",
        )

    sources = meta.get("sources")
    report.check(
        isinstance(sources, list) and sources and all(isinstance(s, str) for s in sources),
        "sources must be a non-empty list of strings",
    )

    grammar = meta.get("grammar")
    if not report.check(
        isinstance(grammar, list) and grammar, "grammar must be a non-empty list"
    ):
        return

    seen = set()
    for entry in grammar:
        if not report.check(
            isinstance(entry, dict) and "id" in entry and "name" in entry,
            f"grammar entry {entry!r} needs both id and name",
        ):
            continue
        gid = entry["id"]
        report.check(
            ID_PATTERN.match(gid) is not None,
            f"grammar id {gid!r} must be lowercase ASCII words joined by hyphens",
        )
        report.check(
            gid.startswith(f"{meta.get('slug')}-"),
            f"grammar id {gid!r} should start with the chapter slug",
        )
        report.check(gid not in seen, f"duplicate grammar id {gid!r}")
        seen.add(gid)


def check_sections(lines, report):
    found = [line[3:].strip() for line in lines if line.startswith("## ")]
    report.check(
        found == SECTIONS,
        f"## sections must be exactly {SECTIONS}, found {found}",
    )

    titles = [line for line in lines if line.startswith("# ")]
    report.check(len(titles) == 1, f"expected exactly one # title, found {len(titles)}")


def check_overview(lines, meta, report):
    try:
        start = lines.index("## Overview")
    except ValueError:
        return
    header, rows, _ = find_table_after(lines, start + 1)
    if not report.check(header is not None, "Overview has no quick-reference table"):
        return
    report.check(
        header == ["point", "id", "one-line summary"],
        f"Overview table columns must be point/id/one-line summary, got {header}",
    )

    grammar = meta.get("grammar") or []
    report.check(
        len(rows) == len(grammar),
        f"Overview table has {len(rows)} rows but frontmatter lists {len(grammar)} points",
    )
    for row, entry in zip(rows, grammar):
        if len(row) != 3 or not isinstance(entry, dict):
            continue
        report.check(
            row[0] == entry.get("name"),
            f"Overview row {row[0]!r} does not match frontmatter name {entry.get('name')!r}",
        )
        report.check(
            row[1] == entry.get("id"),
            f"Overview row for {row[0]!r} has id {row[1]!r}, frontmatter says {entry.get('id')!r}",
        )


def check_grammar(lines, meta, report):
    try:
        start = lines.index("## Grammar")
    except ValueError:
        return
    end = next(
        (i for i in range(start + 1, len(lines)) if lines[i].startswith("## ")),
        len(lines),
    )
    body = lines[start:end]

    headings = [(i, line[4:].strip()) for i, line in enumerate(body) if line.startswith("### ")]
    grammar = meta.get("grammar") or []

    report.check(
        [name for _, name in headings] == [e.get("name") for e in grammar if isinstance(e, dict)],
        f"### headings {[n for _, n in headings]} do not match frontmatter names "
        f"{[e.get('name') for e in grammar if isinstance(e, dict)]}",
    )

    for pos, (index, name) in enumerate(headings):
        stop = headings[pos + 1][0] if pos + 1 < len(headings) else len(body)
        block = body[index:stop]

        comment = block[1] if len(block) > 1 else ""
        match = re.match(r"^<!-- id: (\S+) -->$", comment.strip())
        if not report.check(
            match is not None,
            f"{name!r} must be followed by an '<!-- id: ... -->' comment, got {comment!r}",
        ):
            continue
        if pos < len(grammar) and isinstance(grammar[pos], dict):
            report.check(
                match.group(1) == grammar[pos].get("id"),
                f"{name!r} carries id {match.group(1)!r}, frontmatter says "
                f"{grammar[pos].get('id')!r}",
            )

        report.check(
            any(line.startswith("**What it does.**") for line in block),
            f"{name!r} is missing its '**What it does.**' paragraph",
        )
        for required in GRAMMAR_BLOCKS:
            report.check(
                required in block, f"{name!r} is missing a {required} block"
            )

        if "**Examples**" in block:
            at = block.index("**Examples**")
            header, rows, _ = find_table_after(block, at + 1)
            if report.check(header is not None, f"{name!r} has no examples table"):
                report.check(
                    header == EXAMPLE_COLUMNS,
                    f"{name!r} examples table columns must be {EXAMPLE_COLUMNS}, got {header}",
                )
                report.check(
                    len(rows) >= MIN_EXAMPLE_ROWS,
                    f"{name!r} has {len(rows)} example rows, needs at least {MIN_EXAMPLE_ROWS}",
                )
                for row in rows:
                    report.check(
                        len(row) == 3 and all(c and c != "—" for c in row),
                        f"{name!r} has an incomplete example row: {row}",
                    )


def check_pointer(lines, meta, heading, count_field, companion, report):
    """The chapter itself carries a pointer to the companion file, not a table."""
    try:
        start = lines.index(f"## {heading}")
    except ValueError:
        return
    header, _, _ = find_table_after(lines, start + 1)
    if not report.check(
        header is None,
        f"{heading} section contains a table. Word lists belong in {companion}, "
        f"which is not committed. Run tools/sync_tables.py.",
    ):
        return

    body = [line for line in lines[start + 1:start + 6] if line.strip()]
    text = body[0] if body else ""
    match = re.match(r"^_(\d+) entries\. Generated into `([^`]+)`", text)
    if not report.check(
        match is not None, f"{heading} section is missing its generated pointer line"
    ):
        return
    report.check(
        int(match.group(1)) == meta.get(count_field),
        f"{heading} pointer says {match.group(1)} entries but {count_field} is "
        f"{meta.get(count_field)}",
    )
    report.check(
        match.group(2) == companion,
        f"{heading} pointer names {match.group(2)!r}, expected {companion!r}",
    )


def check_companion(path, meta, report):
    """Validate the generated word lists when they exist locally."""
    companion = os.path.join(
        os.path.dirname(path), os.path.basename(path).replace(".md", ".vocab.md")
    )
    if not os.path.exists(companion):
        report.skipped.append(
            "word lists not generated locally, run tools/sync_tables.py to check them"
        )
        return

    with open(companion, encoding="utf-8") as f:
        lines = f.read().split("\n")

    try:
        start = lines.index("## Vocabulary")
    except ValueError:
        report.check(False, f"{os.path.basename(companion)} has no Vocabulary section")
        return
    header, rows, _ = find_table_after(lines, start + 1)
    if report.check(header is not None, "companion file has no vocabulary table"):
        report.check(
            header == VOCAB_COLUMNS,
            f"Vocabulary columns must be {VOCAB_COLUMNS}, got {header}",
        )
        report.check(
            len(rows) == meta.get("vocab_count"),
            f"companion has {len(rows)} vocabulary rows but vocab_count is "
            f"{meta.get('vocab_count')}",
        )
        for row in rows:
            if not report.check(len(row) == 5, f"malformed vocabulary row: {row}"):
                continue
            report.check(row[4] in POS_VALUES, f"unknown pos {row[4]!r} in row {row}")
            for column, value in zip(VOCAB_COLUMNS[1:4], row[1:4]):
                report.check(
                    value and value != "—", f"vocabulary row {row} has empty {column}"
                )

    try:
        start = lines.index("## Kanji")
    except ValueError:
        report.check(False, f"{os.path.basename(companion)} has no Kanji section")
        return
    expected = meta.get("kanji_count")
    header, rows, _ = find_table_after(lines, start + 1)

    if expected == 0:
        report.check(header is None, "kanji_count is 0 but a kanji table is present")
        report.check(
            "_No kanji introduced in this lesson._" in lines[start:start + 6],
            "a chapter with no kanji must say so with the standard line",
        )
        return

    if not report.check(header is not None, "companion file has no kanji table"):
        return
    report.check(
        header == KANJI_COLUMNS, f"Kanji columns must be {KANJI_COLUMNS}, got {header}"
    )
    report.check(
        len(rows) == expected,
        f"companion has {len(rows)} kanji rows but kanji_count is {expected}",
    )
    for row in rows:
        if not report.check(len(row) == 4, f"malformed kanji row: {row}"):
            continue
        for column, value in zip(KANJI_COLUMNS, row):
            report.check(value and value != "—", f"kanji row {row} has empty {column}")


def check_prompts(lines, meta, report):
    try:
        start = lines.index("## Practice prompts")
    except ValueError:
        return
    body = "\n".join(lines[start:])
    numbered = re.findall(r"^\s*\d+\.\s", body, re.MULTILINE)
    report.check(numbered, "Practice prompts must be a numbered list")

    known = {e["id"] for e in (meta.get("grammar") or []) if isinstance(e, dict) and "id" in e}
    referenced = set()
    for group in re.findall(r"^\s*\d+\.\s*\(([^)]*)\)", body, re.MULTILINE):
        for gid in (part.strip() for part in group.split(",")):
            referenced.add(gid)
            report.check(
                gid in known,
                f"practice prompt references unknown grammar id {gid!r}",
            )

    for gid in sorted(known - referenced):
        report.check(False, f"no practice prompt exercises {gid!r}")


def validate(path):
    report = Report(path)
    with open(path, encoding="utf-8") as f:
        text = f.read()

    meta, body = split_frontmatter(text, report)
    check_frontmatter(meta, report)

    if meta is not None:
        lines = body.split("\n")
        expected_name = f"l{meta.get('lesson'):02d}.md" if isinstance(meta.get("lesson"), int) else None
        if expected_name:
            report.check(
                os.path.basename(path) == expected_name,
                f"file should be named {expected_name}",
            )
        check_sections(lines, report)
        check_overview(lines, meta, report)
        check_grammar(lines, meta, report)
        companion = os.path.basename(path).replace(".md", ".vocab.md")
        check_pointer(lines, meta, "Vocabulary", "vocab_count", companion, report)
        check_pointer(lines, meta, "Kanji", "kanji_count", companion, report)
        check_companion(path, meta, report)
        check_prompts(lines, meta, report)

    return report


def main():
    # Deliberately not l*.md, which would also match the generated l<NN>.vocab.md
    # companions.
    targets = sys.argv[1:] or sorted(
        glob.glob(os.path.join(ROOT, "chapters", "g*", "l[0-9][0-9].md"))
    )
    if not targets:
        raise SystemExit("no chapter files found")

    failed = 0
    for path in targets:
        report = validate(os.path.abspath(path))
        if report.errors:
            failed += 1
            print(f"FAIL {report.path}")
            for error in report.errors:
                print(f"  {error}")
        else:
            note = f"  ({report.skipped[0]})" if report.skipped else ""
            print(f"ok   {report.path}{note}")

    print(f"\n{len(targets) - failed}/{len(targets)} chapters valid")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
