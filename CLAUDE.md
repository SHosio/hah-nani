# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Two parts that will eventually meet:

1. **Chapter notes** (`chapters/`) are structured markdown, one file per Genki lesson. They are source data for generating study material, not prose to read. Genki 2nd edition.
2. **Flashcard app** (`index.php`) is a single-page PHP app with CSV decks, SQLite for mastered card persistence, and optional OpenRouter LLM integration for sentence explanations.

The decks in `cards/` are still hand-built and predate the chapter notes.

## Chapter notes

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
- The source PDFs in Google Drive are image scans with no text layer, so nothing can be grepped or extracted from them without OCR.

See `data/README.md` for dataset provenance and `docs/superpowers/specs/2026-08-04-genki-chapter-notes-design.md` for why the design is shaped this way.

## Running

```bash
php -S localhost:8000
```

Open `http://localhost:8000`. Requires PHP 8.0+ with SQLite and cURL.

## Architecture

Single `index.php` (~670 lines) serves both API endpoints and the HTML/CSS/JS frontend:

- **PHP backend** (top of file): `.env` loading, SQLite init, `loadDecks()` from CSV, three API routes (`?action=cards|master|explain`)
- **HTML/CSS** (middle): Dark theme (#080810), 3D flip animation, Google Fonts (Noto Serif JP, Space Mono)
- **JavaScript** (bottom): Vanilla JS with a single `state` object and `render()` function that updates all DOM elements

### Data Flow

- Cards stored in `cards/*.csv` (filename = deck name)
- CSV format: `front,front_sub,front_romaji,back,back_romaji,example_jp,example_romaji,example_en`
- Cards with non-empty `front_sub` render as rule cards; others render as verb cards
- Mastered cards tracked in `db/flashcards.db` SQLite via MD5 hash of `front+back`
- OpenRouter API key from `.env` file; explain button hidden when no key configured

### Key State

```javascript
state = { selectedDecks, showMastered, deck, index, flipped, score, done, masteredHashes }
```

Deck colors cycle through: #e94560, #4fc3f7, #f5a623, #a78bfa, #34d399
