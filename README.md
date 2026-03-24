# Häh?何? (hah-nani)

Confused in Finnish AND Japanese. A single-page PHP flashcard app for studying Japanese grammar and vocabulary.

## Features

- **CSV-based decks** — drop CSV files into `cards/` to add new decks
- **Mastered cards** — mark cards as mastered to exclude them from study sessions
- **AI explanations** — get sentence breakdowns via OpenRouter (optional)
- **Dark theme** — easy on the eyes for late-night study sessions

## Requirements

- PHP 8.0+
- SQLite (built into PHP)
- cURL extension (for OpenRouter, built into PHP)

## Setup

1. Clone the repository
2. Copy `.env.example` to `.env` and add your OpenRouter API key (optional):
   ```
   cp .env.example .env
   ```
3. Start the PHP development server:
   ```bash
   php -S localhost:8000
   ```
4. Open `http://localhost:8000` in your browser

## Adding Decks

Create a CSV file in the `cards/` directory. The filename (minus `.csv`) becomes the deck name.

CSV format:

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

Cards with a non-empty `front_sub` render as rule cards (with a "RULE CARD" badge). Cards without it render as verb/vocabulary cards.

## OpenRouter

The "Explain" button uses OpenRouter to provide AI-powered sentence breakdowns. Set your API key in `.env`:

```
OPENROUTER_API_KEY=sk-or-...
```

Uses `google/gemini-2.5-flash` by default for fast, inexpensive explanations.

## Included Decks

10 decks from GENKI II Lesson 22 covering 5 verb conjugation forms:

- Passive (受身形)
- Potential (可能形)
- Causative (使役形)
- Causative-Passive (使役受身形)
- Volitional (意向形)

Each form has a verb deck and a rules deck.
