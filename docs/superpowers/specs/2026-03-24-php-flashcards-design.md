# Japanese Flashcards — PHP Conversion Design

## Overview

Convert the existing single-file React flashcard component into a single-page PHP application with CSV-based decks, SQLite persistence, and OpenRouter LLM integration for sentence explanations.

## File Structure

```
├── index.php              # Single entry point: routing, API, HTML/CSS/JS
├── cards/                 # Each CSV = one deck
│   ├── GENKI II L22 - Passive Verbs.csv
│   ├── GENKI II L22 - Passive Rules.csv
│   ├── GENKI II L22 - Potential Verbs.csv
│   ├── GENKI II L22 - Potential Rules.csv
│   ├── GENKI II L22 - Causative Verbs.csv
│   ├── GENKI II L22 - Causative Rules.csv
│   ├── GENKI II L22 - Causative-Passive Verbs.csv
│   ├── GENKI II L22 - Causative-Passive Rules.csv
│   ├── GENKI II L22 - Volitional Verbs.csv
│   └── GENKI II L22 - Volitional Rules.csv
├── db/
│   └── japanese.db        # SQLite (auto-created on first run)
├── .env                   # OPENROUTER_API_KEY (gitignored)
├── .env.example
├── .gitignore
└── README.md
```

## CSV Format

Generic front/back format. All fields after `front` are optional (empty string if not applicable):

```csv
front,front_sub,front_romaji,back,back_romaji,example_jp,example_romaji,example_en
食べる,,taberu,食べられる,taberareru,魚を猫に食べられた,sakana wo neko ni taberareta,The fish was eaten by the cat
受身形 (Passive),受身形・る動詞,,Verb stem + れる/られる,,友達に食べられた,tomodachi ni taberareta,It was eaten by my friend
```

- `front_sub` — optional subtitle shown below the front text (used for rule cards to show sub-categorization like "受身形・る動詞")
- Filename minus `.csv` extension = deck name displayed in UI
- Any new CSV dropped into `cards/` automatically appears as a new deck

## SQLite Schema

```sql
CREATE TABLE IF NOT EXISTS mastered_cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    card_hash TEXT UNIQUE NOT NULL,
    mastered_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

- `card_hash` = MD5 of `front + back` — identifies a card regardless of which deck it's in
- Editing a card's front or back text will reset its mastered status (by design — the card has changed)
- Mastered cards are excluded from study sessions by default
- Toggle "Show mastered" to include them

## PHP Routing

Query-parameter based routing in `index.php`:

| Route | Method | Description |
|-------|--------|-------------|
| `index.php` | GET | Serve full HTML page |
| `index.php?action=cards` | GET | Return all cards as JSON with deck info and mastered status |
| `index.php?action=master` | POST | Toggle mastered status for a card (body: `{card_hash}`) |
| `index.php?action=explain` | POST | Proxy sentence to OpenRouter, return explanation (body: `{sentence}`) |

## UI Design

Pixel-perfect recreation of the existing React app's dark theme:

### Preserved from original
- Dark background (#080810)
- 3D card flip animation (450ms cubic-bezier)
- Progress bar
- Score tracking (correct/incorrect)
- Card layout: front shows Japanese + romaji (+ front_sub if present), back shows answer + romaji + example
- Completion screen with motivational messages
- Noto Serif JP + Space Mono fonts from Google Fonts
- Mobile-responsive layout with viewport meta tag, max-width container

### Changed from original
- **Deck picker** replaces the form filter and type filter buttons
  - Checkbox-style selection — pick one or more decks to study
  - Shows card count per deck
  - Color accent assigned per deck (cycling through: #e94560, #4fc3f7, #f5a623, #a78bfa, #34d399)
- **Mastered toggle** — small button on the **back** of the card (visible when flipped)
  - Toggles mastered on/off for that card
  - Visual indicator: checkmark or "mastered" badge when mastered
  - Un-mastering a card makes it available in the current session after reset
- **"Show mastered" toggle** in the deck picker area
  - Off by default (mastered cards hidden)
  - Not persisted across page reloads — always resets to off
- **"Explain" button** — visible on back of flipped card, triggers OpenRouter call
  - Sends `example_jp` to OpenRouter; if no example, sends `front + " → " + back`
  - Explanation appears in a slide-down panel below the card
  - Loading indicator while waiting for response
  - Button hidden if no API key is configured

### Session Behavior
- Cards are shuffled (Fisher-Yates) on initial load and on deck selection change
- Changing deck selection resets the session (new shuffle, score reset)
- Scoring ("Got it" / "Again") is in-memory only, not persisted — purely for the current study session
- Prev / Next buttons for card navigation
- Flip button to reveal answer
- Reset button to reshuffle and restart

## OpenRouter Integration

POST to `https://openrouter.ai/api/v1/chat/completions`

```json
{
  "model": "google/gemini-2.5-flash",
  "messages": [
    {"role": "system", "content": "You are a Japanese language tutor. Explain the following Japanese sentence simply and clearly. Break down the grammar, vocabulary, and any conjugations used. Keep the explanation concise."},
    {"role": "user", "content": "<example_jp or front → back>"}
  ]
}
```

- API key from `.env` file (simple `KEY=value` format, one per line)
- PHP proxies the request server-side (no key exposure to browser)
- Response displayed in explanation panel below card
- Default model: `google/gemini-2.5-flash` (cheap, fast, good for explanations)
- If API key is missing or request fails, explanation panel shows error message

## Data Migration

Split the existing React component's hardcoded data into 10 CSV files:
- 5 verb CSVs (one per form: Passive, Potential, Causative, Causative-Passive, Volitional)
- 5 rule CSVs (one per form, matching the verb forms — rule cards use `front_sub` for sub-categorization)

## Technical Notes

- Run with `php -S localhost:8000`
- PHP 8.0+ required
- PDO SQLite — built into PHP, no extensions needed
- `.env` parsing: simple line-by-line `KEY=value`, no quoted values or multiline support needed
- All JS is vanilla, inline in index.php
- All CSS is inline in index.php
- Viewport meta tag for mobile responsiveness
