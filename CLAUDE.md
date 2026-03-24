# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A single-page PHP flashcard app for studying Japanese grammar and vocabulary. CSV-based decks, SQLite for mastered card persistence, optional OpenRouter LLM integration for sentence explanations.

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
