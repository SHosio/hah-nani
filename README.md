# Häh?何? (hah-nani)

Confused in Finnish AND Japanese. A single-page PHP app for studying Japanese,
built around Genki II.

Two views:

- **CARDS** is a flashcard trainer. CSV decks, 3D flip, mastered-card tracking,
  an AI explain button per card.
- **STUDY** browses Genki II chapter by chapter as terse grammar bullets, lets
  you tick points across chapters, and generates a lesson or a flashcard deck
  on demand from whatever you type in the box.

The thing that connects them is `chapters/`, structured notes covering Genki II
lessons 13 to 23. When you generate something, the selected grammar points'
full notes are sent along as context, so the output is grounded in the notes
rather than in the model's memory of a textbook.

## Requirements

- PHP 8.0+ with SQLite and cURL (both usually built in)
- Python 3 with PyYAML, for the chapter tooling only
- An OpenRouter API key, if you want generation and explanations

## Setup

```bash
cp .env.example .env          # add your OpenRouter key
python3 tools/fetch_data.py   # download the vocabulary and kanji datasets
python3 tools/sync_tables.py  # build the per-chapter word lists
php -S localhost:8000
```

Then open `http://localhost:8000`.

The two Python steps are needed because Genki's own chapter word lists are not
committed to this repo. See `data/README.md`.

## Chapter notes

`chapters/g2/l13.md` through `l23.md`, one file per lesson, 57 grammar points.
They are source data first and reading material second, so the structure is
fixed and machine-checkable rather than merely conventional.

`chapters/SCHEMA.md` is the contract. `tools/validate_chapters.py` enforces it
and exits non-zero when a chapter drifts, which is what keeps eleven files
written at different times consistent enough to build on.

```bash
python3 tools/validate_chapters.py       # check every chapter
python3 tools/test_validate_chapters.py  # check the checker still catches breakage
```

Content is written against scans of Genki II 2nd edition. The explanations and
example sentences are our own; the textbook's prose, its example sentences and
its chapter word lists are deliberately not reproduced here.

**Verification status.** All eleven chapters have been through an adversarial
review against the scanned grammar pages and had the findings applied. Every
chapter had defects, including chapters written from the book in the first
place. What the reviews caught most often: formation-table rows that generate
ungrammatical forms, restrictions stated more absolutely than the book states
them, wrong lesson cross-references, and textbook sentences reproduced verbatim.

What this does not cover: the example sentences are our own, so the book cannot
confirm they sound natural. That would need a native speaker or a separate
check.

## Generating study material

Pick grammar points in the STUDY view, type what you want, choose an output
kind. Two kinds exist:

- **Text lesson** — markdown, rendered in the page and saved to the library.
- **Flashcard deck** — written as a real CSV into `cards/`, prefixed `★`, and
  studied in the CARDS view like any other deck.

Everything generated is stored in SQLite with the inputs that produced it, so
the library below the composer reopens anything you made before. Deleting an
entry deletes its deck too.

Adding a third kind means one entry in `GENERATION_KINDS` and one prompt builder
in `lib/templates.php`.

Generation takes 20 to 90 seconds depending on how many points you select.

## Adding decks by hand

Drop a CSV in `cards/`. The filename minus `.csv` becomes the deck name.

```csv
front,front_sub,front_romaji,back,back_romaji,example_jp,example_romaji,example_en
```

| Column | Description | Required |
|--------|-------------|----------|
| front | Main text shown on card front | Yes |
| front_sub | Subtitle (e.g. grammar category) | No |
| front_romaji | Romaji for front text | No |
| back | Answer shown on card back | Yes |
| back_romaji | Romaji for answer | No |
| example_jp | Example sentence in Japanese | No |
| example_romaji | Romaji for example | No |
| example_en | English translation of example | No |

Cards with a non-empty `front_sub` render as rule cards (with a "RULE CARD"
badge). Cards without it render as verb/vocabulary cards.

## Models

Both are OpenRouter model ids, set in `.env`, and both are optional:

```
OPENROUTER_API_KEY=sk-or-...
EXPLAIN_MODEL=google/gemini-2.5-flash    # the per-card explain button
LESSON_MODEL=anthropic/claude-sonnet-5   # generated lessons and decks
```

Without a key the app still works as a flashcard trainer; the explain button and
the generator report that no key is configured.

## A Note on the CSV Files

The hand-built card decks were lovingly vibe-coded by a human who is actually
out here trying to learn Japanese instead of moderating r/LearnJapanese from a
bean bag chair. Are they perfect? Probably not. Will someone with 4,000 hours of
anime consumption and strong opinions about pitch accent find something to
complain about? Certainly.

To those people: this is an open-source flashcard app with a CSV-based deck
system. You know what that means. Fork it, fix it, make your own pristine decks,
and bless the world with your knowledge. The `cards/` directory is right there.
PRs with better data are welcome. Unsolicited Reddit essays about my romaji are
not.

The chapter notes are held to a higher standard, since things get built on top
of them. If you find an error in one, that is a real bug and worth reporting.
