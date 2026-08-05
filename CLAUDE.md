# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A single-page PHP app with two views:

1. **CARDS** is the original flashcard trainer. CSV decks in `cards/`, 3D flip, SQLite for mastered cards, an explain button per card.
2. **STUDY** browses `chapters/` as tabs of terse grammar bullets, lets you tick points across chapters, and generates study material on demand from a free-text request.

The link between them is `chapters/`, structured markdown notes (one file per Genki lesson, 2nd edition) that are source data rather than prose to read. A generation sends the selected grammar points' full note sections to the model, so output is grounded in the notes rather than the model's recollection of Genki.

Generated lessons are stored in SQLite. Generated decks are written as real CSVs into `cards/` (prefixed `★`) and studied in the CARDS view like any other deck.

## Chapter notes

**Current coverage:** Genki II lessons 13-23, 11 chapters, 57 grammar points. Genki I (lessons 1-12) is not written yet; `g1.pdf` sits beside `g2.pdf` in the same Drive folder and the same method applies.

**Verification status**, which matters before you trust or extend a chapter:

| chapters | how written | adversarially reviewed against the book |
|---|---|---|
| L13-L20 | from the book's grammar pages | no |
| L21 | from recall, then fully corrected | yes, 17 defects found and fixed |
| L22, L23 | from recall, then corrected against the book's contents pages | partially |

`chapters/SCHEMA.md` is the contract every chapter file follows. Read it before editing anything under `chapters/`.

```bash
python3 tools/fetch_data.py           # once per clone, downloads the word lists
python3 tools/sync_tables.py          # build chapters/**/l<NN>.vocab.md
python3 tools/validate_chapters.py    # enforce the schema, exits non-zero on drift
python3 tools/test_validate_chapters.py   # prove the validator still catches breakage
```

Rules that are easy to get wrong:

- **This repo is public and must not contain Genki's word lists.** Vocabulary and kanji tables live in git-ignored `l<NN>.vocab.md` companions; the chapter file carries only counts and a pointer. The validator rejects a table pasted into a chapter file.
- The Vocabulary and Kanji tables are **generated**. Corrections go in `data/vocab-overrides.json`, `data/kanji-overrides.json` or `data/pos-groups.json`, then re-sync. Hand-edits are overwritten.
- Glob chapters with `l[0-9][0-9].md`, since `l*.md` also matches the generated companions.
- Grammar IDs (`g2-l21-passive`) are permanent once committed. Downstream artifacts reference them. Renaming a point's `name` is fine, renaming its `id` is not.
- **The textbook is the ground truth, and you can read it.** The PDFs in Google Drive are image scans with no text layer, so nothing can be grepped from them. But rendering a page and reading the PNG works well, and needs no OCR:

  ```bash
  G2="/Users/simohosio/Library/CloudStorage/GoogleDrive-simo.hosio@gmail.com/My Drive/Japanese/g2.pdf"
  pdftoppm -f 258 -l 258 -r 90 -png "$G2" /tmp/page   # then Read /tmp/page-258.png
  ```

  PDF page numbers equal book page numbers. Lesson start pages are on the contents pages (PDF 6-9). A lesson's grammar section sits at roughly start+4 to start+12, with dialogue and vocabulary before it and 練習 practice after.

  Writing chapters from recall instead produced roughly one structural error per chapter (an invented grammar point in L22, a missing one in L23, two invented formation rows in L21). Always check the book.
- **The vendored word list is incomplete.** Eleven words that appear in the book's own chapter lists are absent from `genki-db`. Use `data/vocab-additions.json` to add one; overrides can only correct a word that already exists.

## Writing or verifying a chapter

Both jobs fit one subagent per chapter, run in parallel. A chapter's worth of book pages plus the schema plus a worked example is a lot of context, and eight of them will not fit in one window. Subagents each get their own, so a full pass over the book is one session, not eight.

What made this work:

- Give the agent its lesson's page images (`pdftoppm` at 90 DPI is legible) and tell it to read them. No OCR step.
- Give it the grammar point list **from the book's own contents pages**, and say to use exactly those, in that order. This is what stops the invented-point failure.
- Give it `chapters/g2/l22.md` as a worked example and `SCHEMA.md` as the contract.
- Scope it to writing one file. Have it **report** vocabulary metadata (part-of-speech boundaries, romaji overrides) rather than writing to `data/*.json`, since parallel agents would clobber each other there. Merge centrally, and verify the reported part-of-speech counts sum to the dataset total before trusting them.
- For verification, tell the agent to find errors rather than to review, and give it the same pages. A polite reviewer returns nothing useful.

Spot-check surprising findings against the scan yourself before applying them.

See `data/README.md` for dataset provenance and `docs/superpowers/specs/2026-08-04-genki-chapter-notes-design.md` for why the design is shaped this way.

## Running

```bash
php -S localhost:8000
```

Open `http://localhost:8000`. Requires PHP 8.0+ with SQLite and cURL.

## Architecture

`index.php` holds the routes and the whole frontend. The backend lives in `lib/`, split out when the study view would have pushed the single file past 1400 lines:

```
lib/chapters.php    parse chapters/**/l<NN>.md into structures (frontmatter, per-point bodies)
lib/store.php       SQLite schema and all queries
lib/openrouter.php  shared API client for explain and generate
lib/templates.php   one prompt builder per output kind, plus deck CSV writing
```

API routes, all on `?action=`: `cards`, `master`, `explain`, `chapters`, `generate`, `generations`, `generation`, `delete_generation`.

### Data flow

- Cards stored in `cards/*.csv` (filename = deck name)
- CSV format: `front,front_sub,front_romaji,back,back_romaji,example_jp,example_romaji,example_en`
- Cards with non-empty `front_sub` render as rule cards; others render as verb cards
- Mastered cards tracked in `db/flashcards.db` via MD5 hash of `front+back`
- Generated lessons and decks stored in the `generations` table with the inputs that produced them
- Deleting a generation also deletes its CSV, so the picker cannot offer a deck the library has dropped

### Adding an output kind

Add an entry to `GENERATION_KINDS` and a prompt builder in `lib/templates.php`. Nothing else needs to know about it; the dropdown is built from that constant.

### Things that will bite you

- **Generation needs more than 30s.** `max_execution_time` defaults to 30 and PHP kills the request mid-flight. `openrouterChat()` calls `set_time_limit()` for this reason. A three-point deck takes ~85s.
- **Card generation asks for JSON, not CSV.** Models reliably forget to quote a field containing a comma, which silently corrupts a row. The app writes the CSV itself from parsed JSON.
- **Generated decks are prefixed `★`** and are git-ignored user data. `isGeneratedDeck()` also suppresses the "→ X FORM?" prompt, since a generated card already asks its own question.

### Key state

```javascript
state = { selectedDecks, showMastered, deck, index, flipped, score, done, masteredHashes }
study = { view, activeChapter, selected, busy }   // selected persists across chapter tabs
```

Deck colors cycle through: #e94560, #4fc3f7, #f5a623, #a78bfa, #34d399
