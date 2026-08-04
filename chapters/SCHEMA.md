# Chapter file schema

Every file under `chapters/g<book>/l<NN>.md` follows this structure exactly.
The files are source data first and reading material second. Anything built on
top of them (flashcard decks, audio lesson scripts, quizzes) parses them, so
regularity across chapters matters more than elegance in any single one.

`tools/validate_chapters.py` enforces everything described here. Run it after
editing any chapter.

## File naming

```
chapters/g1/l01.md  ...  chapters/g1/l12.md     Genki I, lessons 1-12
chapters/g2/l13.md  ...  chapters/g2/l23.md     Genki II, lessons 13-23
chapters/g2/l21.vocab.md                        generated word lists, not committed
```

Lesson numbers are zero-padded to two digits so the files sort correctly.

Each chapter has a companion `l<NN>.vocab.md` holding its vocabulary and kanji
tables. Those are Genki's own chapter word lists, and this is a public repo, so
they are generated locally and git-ignored. The chapter file carries only the
counts and a pointer. Build them with:

```bash
python3 tools/fetch_data.py     # once per clone
python3 tools/sync_tables.py
```

Tooling that globs for chapters must use `l[0-9][0-9].md`, since `l*.md` also
matches the companions.

## Frontmatter

YAML, delimited by `---` lines, as the first thing in the file.

```yaml
---
book: 2
lesson: 21
slug: g2-l21
title: Burglar
title_jp: どろぼう
grammar:
  - id: g2-l21-passive
    name: Passive Sentences
  - id: g2-l21-tearu
    name: ～てある
vocab_count: 56
kanji_count: 15
sources:
  - Genki II, 2nd edition, Lesson 21
  - https://example.com/whatever-was-used
---
```

| field | type | rule |
|---|---|---|
| `book` | int | `1` or `2` |
| `lesson` | int | 1-12 for book 1, 13-23 for book 2 |
| `slug` | string | exactly `g<book>-l<lesson>`, lesson not padded |
| `title` | string | the chapter's English title |
| `title_jp` | string | the chapter's Japanese title |
| `grammar` | list | one `{id, name}` per grammar point, in book order |
| `vocab_count` | int | must equal the row count of the `## Vocabulary` table |
| `kanji_count` | int | must equal the row count of the `## Kanji` table |
| `sources` | list of strings | where the content came from |

Grammar IDs are `<slug>-<short-name>`, lowercase, hyphen-separated, ASCII only.
They are the stable handle downstream artifacts use to reference a grammar
point, so treat them as permanent once a chapter is committed. Renaming a
grammar point's `name` is fine; renaming its `id` breaks anything already built
on it.

## Body

Five `##` sections, in this order, no others:

```
# Genki II Lesson 21 (Burglar)

## Overview
## Grammar
## Vocabulary
## Kanji
## Practice prompts
```

The `#` title line is `Genki <I|II> Lesson <n> (<title>)`.

### `## Overview`

Two to four sentences on what the chapter is for and how its points relate,
then a quick-reference table with one row per grammar point:

```
| point | id | one-line summary |
|---|---|---|
```

The `point` column matches the `name` in frontmatter, the `id` column matches
the `id`, and the rows are in the same order.

### `## Grammar`

One `###` heading per grammar point, in frontmatter order, each named exactly
as its `name`. Immediately under the heading, an HTML comment carrying the ID:

```
### Passive Sentences
<!-- id: g2-l21-passive -->
```

The comment is what a parser keys on. Frontmatter order is a cross-check, not
the source of truth.

Each grammar point then contains these blocks, in this order. `**Formation**`,
`**Examples**` and `**Notes**` are required; `**Contrast**` is optional and
appears only where a point is genuinely confusable with another.

- `**What it does.**` followed by a plain-language paragraph.
- `**Formation**` and a table. Columns vary by point, since a conjugation rule
  and a sentence pattern do not have the same shape.
- `**Examples**` and a table with exactly the columns `jp`, `romaji`, `en`.
  At least three rows. This table is what flashcard and audio generation reads,
  so every row must stand alone without surrounding prose.
- `**Notes**` and a bullet list of gotchas, restrictions, and register notes.
- `**Contrast**` and a bullet list, each bullet naming the confusable pattern.

### `## Vocabulary` and `## Kanji`

In the chapter file these hold a single generated pointer line and nothing
else. Putting a table here is an error the validator rejects, because it would
commit the textbook's word list.

```
_56 entries. Generated into `l21.vocab.md` by tools/sync_tables.py, and not committed. See data/README.md._
```

The number must match `vocab_count` or `kanji_count` in frontmatter.

The tables themselves live in the companion file, written by
`tools/sync_tables.py` from `tools/build_vocab.py` and `tools/build_kanji.py`:

```
| kanji | kana | romaji | english | pos |
| kanji | readings | meaning | examples |
```

`kanji` is `—` for words written in kana only. `pos` is one of `noun`,
`i-adjective`, `na-adjective`, `u-verb`, `ru-verb`, `irregular-verb`,
`adverb/other`. Rows stay in Genki's own order, which groups by part of speech.
`readings` uses `；` between readings, following the source data, and `examples`
is a `; `-joined list of `word (reading) gloss`.

Do not hand-edit the companion. Corrections go in `data/vocab-overrides.json`,
`data/kanji-overrides.json` and `data/pos-groups.json`, then regenerate, so the
fix survives the next rebuild.

Genki I lessons 1 and 2 introduce no kanji. Those two chapters carry
`kanji_count: 0`, and their companion's `## Kanji` section holds the single line
`_No kanji introduced in this lesson._`.

The validator checks the companion when it is present and reports it as skipped
when it is not, so a fresh clone still passes.

### `## Practice prompts`

A numbered list of prompts written for a generator rather than for a human.
Each names the grammar ID it exercises, so a downstream tool can select prompts
by point:

```
1. (g2-l21-passive) Produce 8 sentences where the speaker is inconvenienced ...
```

## What is deliberately not here

The reading and writing section (読み書き編) passages. They are long, they do
not compress into notes usefully, and the scanned PDF is the better source when
they are actually needed.
