# PHP Flashcards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the React flashcard app into a single-page PHP application with CSV decks, SQLite mastered-card tracking, and OpenRouter LLM sentence explanations.

**Architecture:** Single `index.php` serves both the HTML/CSS/JS frontend and JSON API endpoints via `?action=` query parameter routing. Cards live in `cards/*.csv` files (one per deck). SQLite stores mastered card hashes. OpenRouter calls are proxied server-side.

**Tech Stack:** PHP 8.0+, PDO SQLite, vanilla JS, CSS (all inline in index.php)

---

### Task 1: Project scaffolding and CSV data migration

**Files:**
- Create: `.gitignore`
- Create: `.env.example`
- Create: `cards/GENKI II L22 - Passive Verbs.csv`
- Create: `cards/GENKI II L22 - Passive Rules.csv`
- Create: `cards/GENKI II L22 - Potential Verbs.csv`
- Create: `cards/GENKI II L22 - Potential Rules.csv`
- Create: `cards/GENKI II L22 - Causative Verbs.csv`
- Create: `cards/GENKI II L22 - Causative Rules.csv`
- Create: `cards/GENKI II L22 - Causative-Passive Verbs.csv`
- Create: `cards/GENKI II L22 - Causative-Passive Rules.csv`
- Create: `cards/GENKI II L22 - Volitional Verbs.csv`
- Create: `cards/GENKI II L22 - Volitional Rules.csv`

- [ ] **Step 1: Create .gitignore**

```
.env
db/
*.db
.DS_Store
```

- [ ] **Step 2: Create .env.example**

```
OPENROUTER_API_KEY=your-api-key-here
```

- [ ] **Step 3: Create cards/ directory and all 10 CSV files**

CSV header for all files:
```csv
front,front_sub,front_romaji,back,back_romaji,example_jp,example_romaji,example_en
```

Extract data from `japanese_flashcards.jsx`:

**Verb CSVs** — map fields: `verb` → `front`, (empty) → `front_sub`, `romaji` → `front_romaji`, `answer` → `back`, `answerRomaji` → `back_romaji`, `example` → `example_jp`, `exampleRomaji` → `example_romaji`, `exampleEn` → `example_en`

**Rule CSVs** — map fields: `front` → `front`, `frontSub` → `front_sub`, (empty) → `front_romaji`, `back` → `back`, `backRomaji` → `back_romaji`, `example` → `example_jp`, `exampleRomaji` → `example_romaji`, `exampleEn` → `example_en`

Split by form: Passive (18 verbs, 4 rules), Potential (17 verbs, 4 rules), Causative (16 verbs, 4 rules), Causative-Passive (15 verbs, 5 rules), Volitional (16 verbs, 4 rules).

- [ ] **Step 4: Verify CSV files parse correctly**

Run: `php -r "print_r(array_map('str_getcsv', file('cards/GENKI II L22 - Passive Verbs.csv')));"`
Expected: Array of arrays with 8 elements each, first row is header.

- [ ] **Step 5: Commit**

```bash
git add .gitignore .env.example cards/
git commit -m "feat: project scaffolding and CSV data migration from React app"
```

---

### Task 2: PHP backend — env loading, SQLite init, API routing

**Files:**
- Create: `index.php` (backend portion only — API routes, no HTML yet)

- [ ] **Step 1: Write index.php with env loading and SQLite init**

```php
<?php
// Load .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// SQLite init
$dbDir = __DIR__ . '/db';
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
$db = new PDO('sqlite:' . $dbDir . '/japanese.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE IF NOT EXISTS mastered_cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    card_hash TEXT UNIQUE NOT NULL,
    mastered_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// Load all decks from cards/ directory
function loadDecks(): array {
    $decks = [];
    $cardsDir = __DIR__ . '/cards';
    foreach (glob($cardsDir . '/*.csv') as $file) {
        $deckName = pathinfo($file, PATHINFO_FILENAME);
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        $cards = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === count($header)) {
                $card = array_combine($header, $row);
                $card['deck'] = $deckName;
                $card['hash'] = md5($card['front'] . $card['back']);
                $cards[] = $card;
            }
        }
        fclose($handle);
        $decks[$deckName] = $cards;
    }
    ksort($decks);
    return $decks;
}
```

- [ ] **Step 2: Add API action routing**

```php
$action = $_GET['action'] ?? null;

if ($action === 'cards') {
    header('Content-Type: application/json');
    $decks = loadDecks();
    $masteredHashes = $db->query('SELECT card_hash FROM mastered_cards')->fetchAll(PDO::FETCH_COLUMN);
    $result = [];
    foreach ($decks as $deckName => $cards) {
        foreach ($cards as &$card) {
            $card['mastered'] = in_array($card['hash'], $masteredHashes);
        }
        $result[$deckName] = $cards;
    }
    echo json_encode($result);
    exit;
}

if ($action === 'master') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $hash = $input['card_hash'] ?? '';
    if (!$hash) { echo json_encode(['error' => 'Missing card_hash']); exit; }
    $exists = $db->prepare('SELECT id FROM mastered_cards WHERE card_hash = ?');
    $exists->execute([$hash]);
    if ($exists->fetch()) {
        $stmt = $db->prepare('DELETE FROM mastered_cards WHERE card_hash = ?');
        $stmt->execute([$hash]);
        echo json_encode(['mastered' => false]);
    } else {
        $stmt = $db->prepare('INSERT INTO mastered_cards (card_hash) VALUES (?)');
        $stmt->execute([$hash]);
        echo json_encode(['mastered' => true]);
    }
    exit;
}

if ($action === 'explain') {
    header('Content-Type: application/json');
    $apiKey = $_ENV['OPENROUTER_API_KEY'] ?? '';
    if (!$apiKey) { echo json_encode(['error' => 'No API key configured']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $sentence = $input['sentence'] ?? '';
    if (!$sentence) { echo json_encode(['error' => 'Missing sentence']); exit; }

    $payload = json_encode([
        'model' => 'google/gemini-2.5-flash',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a Japanese language tutor. Explain the following Japanese sentence simply and clearly. Break down the grammar, vocabulary, and any conjugations used. Keep the explanation concise.'],
            ['role' => 'user', 'content' => $sentence],
        ],
    ]);

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo json_encode(['error' => 'OpenRouter request failed', 'details' => json_decode($response)]);
        exit;
    }

    $data = json_decode($response, true);
    $explanation = $data['choices'][0]['message']['content'] ?? 'No explanation returned.';
    echo json_encode(['explanation' => $explanation]);
    exit;
}
```

- [ ] **Step 3: Test API endpoints**

Run: `php -S localhost:8000 &`
Then: `curl http://localhost:8000/index.php?action=cards`
Expected: JSON object with deck names as keys and card arrays as values.

Then: `curl -X POST http://localhost:8000/index.php?action=master -d '{"card_hash":"test123"}' -H 'Content-Type: application/json'`
Expected: `{"mastered":true}`

Then: same curl again.
Expected: `{"mastered":false}` (toggled off)

- [ ] **Step 4: Commit**

```bash
git add index.php
git commit -m "feat: PHP backend with env loading, SQLite, and API routing"
```

---

### Task 3: HTML/CSS frontend — pixel-perfect dark theme

**Files:**
- Modify: `index.php` — add HTML output after the API routing block

- [ ] **Step 1: Add the HTML shell with CSS**

After all the `if ($action === ...)` blocks, add the full HTML page. This includes:

- `<!DOCTYPE html>` with viewport meta tag
- Google Fonts import (Noto Serif JP, Space Mono)
- All CSS matching the original React app's inline styles:
  - Dark background `#080810`
  - Card face styling with `backface-visibility: hidden`
  - 3D flip animation `.ci` with 450ms cubic-bezier(.4,2,.55,1)
  - `.fl` class for flipped state (rotateY 180deg)
  - `.fc` for card faces, `.bk` for back face
  - `.btn` and `.pill` button styles
  - Progress bar styles
  - Deck picker checkbox styles
  - Mastered badge styles
  - Explain panel slide-down styles

Key CSS (must match original exactly):
```css
body { min-height:100vh; background:#080810; display:flex; flex-direction:column; align-items:center; font-family:'Noto Serif JP',Georgia,serif; padding:24px 16px 48px; margin:0; }
* { box-sizing:border-box; margin:0; padding:0; }
.ci { transition:transform .45s cubic-bezier(.4,2,.55,1); transform-style:preserve-3d; width:100%; height:100%; }
.fl { transform:rotateY(180deg); }
.fc { position:absolute; width:100%; height:100%; backface-visibility:hidden; -webkit-backface-visibility:hidden; border-radius:18px; }
.bk { transform:rotateY(180deg); }
.btn { cursor:pointer; border:none; border-radius:10px; font-family:'Space Mono',monospace; transition:all .18s; }
.btn:hover { transform:translateY(-2px); opacity:.85; }
.pill { cursor:pointer; border:none; border-radius:20px; font-family:'Space Mono',monospace; transition:all .18s; }
.pill:hover { transform:translateY(-1px); }
```

- [ ] **Step 2: Add HTML structure**

Embed deck data as JSON in a `<script>` tag:
```php
<?php
$decks = loadDecks();
$masteredHashes = $db->query('SELECT card_hash FROM mastered_cards')->fetchAll(PDO::FETCH_COLUMN);
$hasApiKey = !empty($_ENV['OPENROUTER_API_KEY'] ?? '');
?>
<script>
const ALL_DECKS = <?= json_encode($decks) ?>;
const MASTERED_HASHES = new Set(<?= json_encode($masteredHashes) ?>);
const HAS_API_KEY = <?= $hasApiKey ? 'true' : 'false' ?>;
</script>
```

HTML structure:
```html
<div id="app">
  <!-- Header -->
  <div class="header">...</div>
  <!-- Deck picker -->
  <div id="deck-picker">...</div>
  <!-- Show mastered toggle -->
  <div id="mastered-toggle">...</div>
  <!-- Progress bar -->
  <div id="progress">...</div>
  <!-- Card -->
  <div id="card-container">...</div>
  <!-- Buttons -->
  <div id="controls">...</div>
  <!-- Explain panel -->
  <div id="explain-panel">...</div>
  <!-- Legend -->
  <div id="legend">...</div>
  <!-- Footer -->
  <div class="footer">...</div>
</div>
```

- [ ] **Step 3: Commit**

```bash
git add index.php
git commit -m "feat: HTML/CSS frontend shell with pixel-perfect dark theme"
```

---

### Task 4: JavaScript — deck picker, card rendering, flip animation

**Files:**
- Modify: `index.php` — add `<script>` block with all JS logic

- [ ] **Step 1: Write core state and deck management**

```javascript
const DECK_COLORS = ['#e94560','#4fc3f7','#f5a623','#a78bfa','#34d399'];
let state = {
    selectedDecks: new Set(Object.keys(ALL_DECKS)),
    showMastered: false,
    deck: [],        // current shuffled deck
    index: 0,
    flipped: false,
    score: { correct: 0, incorrect: 0 },
    done: false,
    masteredHashes: MASTERED_HASHES,
};

function getDeckColor(deckName) {
    const names = Object.keys(ALL_DECKS);
    return DECK_COLORS[names.indexOf(deckName) % DECK_COLORS.length];
}

function shuffle(arr) {
    const a = [...arr];
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

function getFilteredCards() {
    let cards = [];
    for (const [name, deckCards] of Object.entries(ALL_DECKS)) {
        if (!state.selectedDecks.has(name)) continue;
        for (const card of deckCards) {
            if (!state.showMastered && state.masteredHashes.has(card.hash)) continue;
            cards.push(card);
        }
    }
    return cards;
}

function resetSession() {
    const cards = getFilteredCards();
    state.deck = shuffle(cards);
    state.index = 0;
    state.flipped = false;
    state.score = { correct: 0, incorrect: 0 };
    state.done = false;
    render();
}
```

- [ ] **Step 2: Write deck picker rendering**

Generate checkbox pills for each deck with card counts and color dots. Toggling a deck calls `resetSession()`.

- [ ] **Step 3: Write card rendering (front and back faces)**

Render the card matching the original React layout exactly:
- Front: large Japanese text + romaji + form prompt (for verb-like cards) or front + front_sub (for rule-like cards with front_sub)
- Back: answer + romaji + example section with divider
- Card accent color based on deck color
- "TAP TO REVEAL" hint on front
- Mastered toggle button on back (small icon in top-right corner)
- Explain button on back (if HAS_API_KEY is true)

Detection of card "type": if `front_sub` is non-empty, render as rule card; otherwise render as verb card.

- [ ] **Step 4: Write navigation and scoring logic**

```javascript
function go(dir) {
    state.flipped = false;
    render();
    setTimeout(() => {
        const next = state.index + dir;
        if (next >= state.deck.length) { state.done = true; }
        else { state.index = Math.max(0, next); }
        render();
    }, 160);
}

function mark(correct) {
    state.score[correct ? 'correct' : 'incorrect']++;
    go(1);
}
```

- [ ] **Step 5: Write completion screen**

Match original: 🎌 emoji, "SESSION COMPLETE", score display, motivational message (完璧！/ よくできました！/ もう一回！), restart button.

- [ ] **Step 6: Write progress bar rendering**

Card counter (`N / total`), score display (✓ correct / ✗ incorrect), animated progress bar.

- [ ] **Step 7: Wire up render() function**

Single `render()` function that updates all DOM elements based on current `state`. Called after every state change.

- [ ] **Step 8: Test in browser**

Run: `php -S localhost:8000`
Open: `http://localhost:8000`
Verify: deck picker shows all 10 decks, cards render with flip animation, navigation works, scoring works, completion screen shows.

- [ ] **Step 9: Commit**

```bash
git add index.php
git commit -m "feat: JS deck picker, card rendering, flip animation, scoring"
```

---

### Task 5: Mastered cards feature

**Files:**
- Modify: `index.php` — add mastered toggle JS

- [ ] **Step 1: Write mastered toggle handler**

```javascript
async function toggleMastered(hash) {
    const res = await fetch('?action=master', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ card_hash: hash }),
    });
    const data = await res.json();
    if (data.mastered) {
        state.masteredHashes.add(hash);
    } else {
        state.masteredHashes.delete(hash);
    }
    render();
}
```

- [ ] **Step 2: Add "Show mastered" toggle to deck picker area**

Toggle pill button. When off (default), mastered cards are excluded from `getFilteredCards()`. Toggling calls `resetSession()`.

- [ ] **Step 3: Add mastered indicator on card back**

Small button in top-right corner of back face. Shows filled checkmark when mastered, outline when not. Clicking calls `toggleMastered(card.hash)`.

- [ ] **Step 4: Test mastered flow**

1. Flip a card, click mastered toggle → verify SQLite entry created
2. Reset session → verify mastered card is excluded
3. Toggle "Show mastered" → verify card reappears
4. Click mastered toggle again → verify SQLite entry removed

- [ ] **Step 5: Commit**

```bash
git add index.php
git commit -m "feat: mastered cards with SQLite persistence and show/hide toggle"
```

---

### Task 6: OpenRouter explain feature

**Files:**
- Modify: `index.php` — add explain button JS and panel

- [ ] **Step 1: Write explain button and panel**

On back of card, below the example section, add "Explain this sentence" button (only if `HAS_API_KEY`). Clicking it:

```javascript
async function explainSentence(card) {
    const sentence = card.example_jp || (card.front + ' → ' + card.back);
    const panel = document.getElementById('explain-panel');
    panel.style.display = 'block';
    panel.innerHTML = '<div class="explain-loading">Thinking...</div>';

    const res = await fetch('?action=explain', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sentence }),
    });
    const data = await res.json();
    if (data.error) {
        panel.innerHTML = '<div class="explain-error">' + data.error + '</div>';
    } else {
        panel.innerHTML = '<div class="explain-text">' + formatExplanation(data.explanation) + '</div>';
    }
}
```

- [ ] **Step 2: Style the explain panel**

Slide-down panel below the card. Dark background (#0c0c1e), bordered, with the card's accent color top border. Monospace font for readability. Close button in corner.

`formatExplanation()` converts markdown-like text (bold, line breaks) to simple HTML.

- [ ] **Step 3: Test explain feature**

1. Set up `.env` with a real API key
2. Flip a card with an example sentence
3. Click "Explain" → verify loading state appears
4. Verify explanation renders in panel
5. Test with no API key → verify button is hidden

- [ ] **Step 4: Commit**

```bash
git add index.php
git commit -m "feat: OpenRouter explain feature with slide-down panel"
```

---

### Task 7: README.md and final polish

**Files:**
- Create: `README.md`
- Modify: `index.php` — any final CSS/layout tweaks

- [ ] **Step 1: Create README.md**

```markdown
# Japanese Flashcards 🎌

A single-page PHP flashcard app for studying Japanese grammar and vocabulary.

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
2. Copy `.env.example` to `.env` and add your OpenRouter API key (optional)
3. Start the PHP development server:

```bash
php -S localhost:8000
```

4. Open `http://localhost:8000` in your browser

## Adding Decks

Create a CSV file in the `cards/` directory. The filename becomes the deck name.

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

## OpenRouter

The "Explain" button uses OpenRouter to provide AI-powered sentence breakdowns. Set your API key in `.env`:

```
OPENROUTER_API_KEY=sk-or-...
```

Uses `google/gemini-2.5-flash` by default.
```

- [ ] **Step 2: Final visual QA**

Compare the PHP app side-by-side with the React original. Check:
- Font sizes and weights match
- Colors match exactly (#080810, #0c0c1e, accent colors)
- Card dimensions and border radius (18px)
- Flip animation timing (450ms cubic-bezier(.4,2,.55,1))
- Progress bar appearance
- Button styles and hover effects
- Completion screen layout
- Mobile responsiveness

- [ ] **Step 3: Commit**

```bash
git add README.md index.php
git commit -m "feat: README and final polish"
```
