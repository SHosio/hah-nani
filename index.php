<?php
// ─── ENV ───
$env = [];
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $env[trim($key)] = trim($val);
    }
}
$hasApiKey = !empty($env['OPENROUTER_API_KEY']);

// ─── SQLite ───
$dbDir = __DIR__ . '/db';
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
$db = new SQLite3($dbDir . '/flashcards.db');
$db->exec('CREATE TABLE IF NOT EXISTS mastered_cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    card_hash TEXT UNIQUE NOT NULL,
    mastered_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// ─── Load Decks ───
function loadDecks() {
    $decks = [];
    $files = glob(__DIR__ . '/cards/*.csv');
    foreach ($files as $file) {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $handle = fopen($file, 'r');
        if (!$handle) continue;
        $header = fgetcsv($handle, 0, ',', '"', '');
        $cards = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) < 8) continue;
            $card = array_combine($header, $row);
            $card['hash'] = md5($card['front'] . $card['back']);
            $card['deck'] = $name;
            $cards[] = $card;
        }
        fclose($handle);
        $decks[$name] = $cards;
    }
    return $decks;
}

$decks = loadDecks();

// ─── Mastered hashes ───
function getMasteredHashes($db) {
    $hashes = [];
    $result = $db->query('SELECT card_hash FROM mastered_cards');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $hashes[] = $row['card_hash'];
    }
    return $hashes;
}
$masteredHashes = getMasteredHashes($db);

// ─── API Routes ───
$action = $_GET['action'] ?? null;
if ($action) {
    error_reporting(0);
    header('Content-Type: application/json');

    if ($action === 'cards') {
        $masteredSet = array_flip($masteredHashes);
        $result = [];
        foreach ($decks as $name => $cards) {
            foreach ($cards as &$c) {
                $c['mastered'] = isset($masteredSet[$c['hash']]);
            }
            $result[$name] = $cards;
        }
        echo json_encode($result);
        exit;
    }

    if ($action === 'master' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $hash = $input['card_hash'] ?? '';
        if (!$hash) { echo json_encode(['error' => 'Missing card_hash']); exit; }

        $stmt = $db->prepare('SELECT id FROM mastered_cards WHERE card_hash = :hash');
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $exists = $stmt->execute()->fetchArray();

        if ($exists) {
            $stmt = $db->prepare('DELETE FROM mastered_cards WHERE card_hash = :hash');
            $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
            $stmt->execute();
            echo json_encode(['mastered' => false]);
        } else {
            $stmt = $db->prepare('INSERT INTO mastered_cards (card_hash) VALUES (:hash)');
            $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
            $stmt->execute();
            echo json_encode(['mastered' => true]);
        }
        exit;
    }

    if ($action === 'explain' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$hasApiKey) {
            echo json_encode(['error' => 'No API key configured. Add OPENROUTER_API_KEY to .env']);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $sentence = $input['sentence'] ?? '';
        if (!$sentence) { echo json_encode(['error' => 'Missing sentence']); exit; }

        $payload = json_encode([
            'model' => 'google/gemini-2.5-flash',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a Japanese grammar tutor. Explain the grammar point or sentence briefly and clearly. Break down the conjugation steps. Keep it concise (3-5 sentences). Use romaji alongside Japanese where helpful.'],
                ['role' => 'user', 'content' => "Explain this Japanese grammar/sentence:\n$sentence"]
            ]
        ]);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $env['OPENROUTER_API_KEY'],
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        unset($ch);

        if ($err) {
            echo json_encode(['error' => "cURL error: $err"]);
            exit;
        }
        $data = json_decode($response, true);
        $explanation = $data['choices'][0]['message']['content'] ?? 'No explanation returned.';
        echo json_encode(['explanation' => $explanation]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ─── HTML Output ───
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Häh?何? · Japanese Flashcards</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+JP:wght@400;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:#080810;display:flex;flex-direction:column;align-items:center;font-family:'Noto Serif JP',Georgia,serif;padding:24px 16px 48px;}
.ci{transition:transform .45s cubic-bezier(.4,2,.55,1);transform-style:preserve-3d;width:100%;height:100%;}
.fl{transform:rotateY(180deg);}
.fc{position:absolute;width:100%;height:100%;backface-visibility:hidden;-webkit-backface-visibility:hidden;border-radius:18px;}
.bk{transform:rotateY(180deg);}
.btn{cursor:pointer;border:none;border-radius:10px;font-family:'Space Mono',monospace;transition:all .18s;}
.btn:hover{transform:translateY(-2px);opacity:.85;}
.btn:disabled{cursor:default;opacity:.5;}
.btn:disabled:hover{transform:none;opacity:.5;}
.pill{cursor:pointer;border:none;border-radius:20px;font-family:'Space Mono',monospace;transition:all .18s;}
.pill:hover{transform:translateY(-1px);}
.mono{font-family:'Space Mono',monospace;}

/* Deck picker */
#deck-picker{display:flex;gap:6px;margin-bottom:7px;flex-wrap:wrap;justify-content:center;max-width:500px;}
#deck-picker .pill{font-size:11px;padding:6px 13px;display:flex;align-items:center;gap:6px;}
.deck-dot{width:7px;height:7px;border-radius:50%;display:inline-block;flex-shrink:0;}
.deck-count{opacity:.7;font-size:10px;}

/* Show mastered toggle */
#mastered-toggle{margin-bottom:20px;}
#mastered-toggle .pill{font-size:11px;padding:5px 14px;}

/* Progress */
#progress-area{width:100%;max-width:440px;margin-bottom:14px;}
.progress-bar{height:3px;background:#2a2a45;border-radius:2px;}
.progress-fill{height:100%;border-radius:2px;transition:width .4s;}

/* Card */
#card-wrapper{perspective:1000px;width:100%;max-width:440px;margin-bottom:16px;cursor:pointer;}
.card-front,.card-back{background:#0c0c1e;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;}
.card-front{padding:32px;}
.card-back{padding:28px;}

.rule-badge{margin-bottom:10px;font-family:'Space Mono',monospace;font-size:11px;color:#aaa;letter-spacing:2px;border:1px dashed #555;padding:3px 12px;border-radius:8px;}
.form-badge{padding:3px 12px;border-radius:12px;font-family:'Space Mono',monospace;font-size:11px;font-weight:700;letter-spacing:1px;margin-bottom:12px;display:inline-block;}
.verb-jp{font-size:50px;color:#fff;margin-bottom:8px;}
.verb-romaji{font-family:'Space Mono',monospace;color:#bbb;font-size:15px;margin-bottom:20px;}
.form-prompt{font-family:'Space Mono',monospace;font-size:12px;letter-spacing:2px;}
.rule-front{font-size:17px;color:#fff;line-height:1.6;margin-bottom:8px;}
.rule-frontsub{font-family:'Space Mono',monospace;color:#aaa;font-size:12px;}
.tap-hint{position:absolute;bottom:16px;font-family:'Space Mono',monospace;color:#555;font-size:11px;letter-spacing:2px;}

.answer-jp{font-size:38px;color:#fff;margin-bottom:6px;}
.answer-romaji{font-family:'Space Mono',monospace;font-size:15px;margin-bottom:18px;}
.rule-back{font-size:17px;color:#fff;line-height:1.6;}
.rule-backromaji{font-family:'Space Mono',monospace;font-size:13px;margin-bottom:18px;}
.divider{width:100%;border-top:1px solid #2a2a45;padding-top:14px;margin-top:0;}
.ex-jp{color:#eee;font-size:15px;margin-bottom:6px;line-height:1.6;}
.ex-romaji{font-family:'Space Mono',monospace;color:#aaa;font-size:12px;margin-bottom:5px;}
.ex-en{color:#777;font-size:13px;font-style:italic;}

/* Mastered star */
.master-btn{position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;font-size:20px;opacity:.7;transition:all .18s;z-index:10;}
.master-btn:hover{opacity:1;transform:scale(1.2);}

/* Explain */
.explain-btn{margin-top:12px;background:none;border:1px solid #2a2a45;color:#aaa;font-family:'Space Mono',monospace;font-size:11px;padding:6px 16px;border-radius:10px;cursor:pointer;transition:all .18s;}
.explain-btn:hover{border-color:#4a4a70;color:#ccc;}
#explain-panel{width:100%;max-width:440px;background:#0c0c1e;border:1px solid #2a2a45;border-radius:18px;padding:20px;margin-bottom:16px;position:relative;display:none;}
#explain-panel.visible{display:block;}
#explain-panel .close-btn{position:absolute;top:10px;right:14px;background:none;border:none;color:#666;font-size:16px;cursor:pointer;font-family:'Space Mono',monospace;}
#explain-panel .close-btn:hover{color:#aaa;}
#explain-panel .content{color:#ccc;font-size:13px;line-height:1.7;font-family:'Space Mono',monospace;}
#explain-panel .content p{margin:0;}
#explain-panel .content strong{color:#fff;}
#explain-panel .content em{color:#aaa;}
#explain-panel .content li{margin-bottom:4px;}

/* Buttons row */
#btn-row{display:flex;gap:12px;margin-bottom:14px;}
#btn-row-nav{display:flex;gap:10px;margin-bottom:14px;}

/* Done screen */
#done-screen{text-align:center;color:#fff;margin-top:40px;}

/* Legend */
#legend{display:flex;gap:14px;flex-wrap:wrap;justify-content:center;margin-top:4px;}
#legend .item{font-family:'Space Mono',monospace;font-size:11px;color:#777;display:flex;align-items:center;gap:5px;}

/* Footer */
#footer{margin-top:32px;font-family:'Space Mono',monospace;font-size:11px;color:#555;letter-spacing:2px;}

/* No cards */
#no-cards{color:#aaa;font-family:'Space Mono',monospace;font-size:13px;margin-top:40px;display:none;}
</style>
</head>
<body>

<!-- Header -->
<div style="text-align:center;margin-bottom:20px;">
  <div class="mono" style="color:#888;font-size:11px;letter-spacing:4px;margin-bottom:6px;">HÄH?何?</div>
  <h1 style="color:#fff;font-size:22px;font-weight:700;letter-spacing:2px;margin-bottom:4px;">日本語フラッシュカード</h1>
  <div class="mono" id="card-total" style="color:#888;font-size:11px;letter-spacing:3px;">GRAMMAR FLASHCARDS</div>
</div>

<!-- Deck picker -->
<div id="deck-picker"></div>

<!-- Mastered toggle -->
<div id="mastered-toggle"></div>

<!-- Progress -->
<div id="progress-area" style="display:none;">
  <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
    <span class="mono" id="counter" style="font-size:13px;color:#aaa;"></span>
    <div style="display:flex;gap:12px;">
      <span class="mono" id="score-correct" style="font-size:13px;color:#34d399;"></span>
      <span class="mono" id="score-incorrect" style="font-size:13px;color:#e94560;"></span>
    </div>
  </div>
  <div class="progress-bar"><div class="progress-fill" id="progress-fill"></div></div>
</div>

<!-- Card -->
<div id="card-wrapper" style="display:none;" onclick="toggleFlip()">
  <div class="ci" id="card-inner">
    <!-- Front -->
    <div class="fc card-front" id="card-front"></div>
    <!-- Back -->
    <div class="fc bk card-back" id="card-back" style="position:relative;"></div>
  </div>
</div>

<!-- Explain panel -->
<div id="explain-panel">
  <div style="border-top:3px solid #4fc3f7;border-radius:18px 18px 0 0;position:absolute;top:0;left:0;right:0;height:3px;" id="explain-accent"></div>
  <button class="close-btn" onclick="closeExplain()">✕</button>
  <div class="content" id="explain-content">Loading...</div>
</div>

<!-- Buttons (flipped) -->
<div id="btn-row" style="display:none;">
  <button class="btn" onclick="mark(false)" style="background:#2a0d14;color:#f87191;border:1px solid #e9456066;font-size:14px;padding:11px 26px;font-weight:700;">✗ Again</button>
  <button class="btn" id="mastered-btn" style="background:#1c1c32;color:#aaa;border:1px solid #2e2e50;font-size:14px;padding:11px 16px;font-weight:700;">☆</button>
  <button class="btn" onclick="mark(true)" style="background:#0d2a18;color:#4ade80;border:1px solid #34d39966;font-size:14px;padding:11px 26px;font-weight:700;">✓ Got it</button>
</div>
<!-- Explain button (flipped, only with API key) -->
<div id="explain-row" style="display:none;margin-bottom:14px;justify-content:center;">
  <button class="btn" id="explain-btn-main" style="background:none;border:1px solid #2a2a45;color:#aaa;font-size:12px;padding:8px 20px;" onclick="explainCurrent()">💡 Explain this sentence</button>
</div>

<!-- Buttons (not flipped) -->
<div id="btn-row-nav" style="display:none;">
  <button class="btn" id="prev-btn" onclick="go(-1)" style="background:#1c1c32;font-size:13px;padding:10px 16px;">← Prev</button>
  <button class="btn" id="flip-btn" onclick="toggleFlip()" style="color:#080810;font-weight:700;font-size:14px;padding:10px 24px;">Flip</button>
  <button class="btn" onclick="go(1)" style="background:#1c1c32;color:#ccc;border:1px solid #4a4a70;font-size:13px;padding:10px 16px;">Next →</button>
</div>

<!-- Legend -->
<div id="legend"></div>

<!-- Done screen -->
<div id="done-screen" style="display:none;"></div>

<!-- No cards -->
<div id="no-cards">No cards match this filter.</div>

<!-- Footer -->
<div id="footer">HÄH?何? · 日本語フラッシュカード</div>

<script>
const ALL_DECKS = <?= json_encode($decks) ?>;
const MASTERED_HASHES = new Set(<?= json_encode(array_values($masteredHashes)) ?>);
const HAS_API_KEY = <?= $hasApiKey ? 'true' : 'false' ?>;

const DECK_COLORS = ['#e94560','#4fc3f7','#f5a623','#a78bfa','#34d399'];
const deckNames = Object.keys(ALL_DECKS).sort();
const deckColorMap = {};
deckNames.forEach((n, i) => deckColorMap[n] = DECK_COLORS[i % DECK_COLORS.length]);

let state = {
    selectedDecks: new Set(Object.keys(ALL_DECKS)),
    showMastered: false,
    deck: [],
    index: 0,
    flipped: false,
    score: { correct: 0, incorrect: 0 },
    done: false,
    masteredHashes: MASTERED_HASHES,
};

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
        for (const c of deckCards) {
            if (!state.showMastered && state.masteredHashes.has(c.hash)) continue;
            cards.push(c);
        }
    }
    return cards;
}

function getDeckCardCount(name) {
    const cards = ALL_DECKS[name] || [];
    if (state.showMastered) return cards.length;
    return cards.filter(c => !state.masteredHashes.has(c.hash)).length;
}

function resetSession() {
    const filtered = getFilteredCards();
    state.deck = shuffle(filtered);
    state.index = 0;
    state.flipped = false;
    state.score = { correct: 0, incorrect: 0 };
    state.done = false;
    closeExplain();
    render();
}

function go(dir) {
    state.flipped = false;
    closeExplain();
    render();
    setTimeout(() => {
        const next = state.index + dir;
        if (next >= state.deck.length) { state.done = true; }
        else state.index = Math.max(0, next);
        render();
    }, 160);
}

function mark(correct) {
    if (correct) state.score.correct++;
    else state.score.incorrect++;
    go(1);
}

function toggleFlip() {
    state.flipped = !state.flipped;
    render();
}

function toggleDeck(name) {
    if (state.selectedDecks.has(name)) state.selectedDecks.delete(name);
    else state.selectedDecks.add(name);
    resetSession();
}

function toggleShowMastered() {
    state.showMastered = !state.showMastered;
    resetSession();
}

function isRuleCard(card) {
    return card.front_sub && card.front_sub.trim() !== '';
}

function getAccent(card) {
    return deckColorMap[card.deck] || '#fff';
}

function getDeckFormName(deckName) {
    // Extract form name: "GENKI II L22 - Passive Verbs" -> "Passive"
    const m = deckName.match(/- (.+?) (Verbs|Rules)$/);
    return m ? m[1] : deckName;
}

function getDeckShortName(deckName) {
    const m = deckName.match(/- (.+)$/);
    if (!m) return deckName;
    let s = m[1];
    s = s.replace('Causative-Passive', 'Caus-Pass');
    return s;
}

// ─── Mastered toggle ───
async function toggleMastered(hash) {
    event && event.stopPropagation();
    const res = await fetch('?action=master', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ card_hash: hash }),
    });
    const data = await res.json();
    if (data.mastered) state.masteredHashes.add(hash);
    else state.masteredHashes.delete(hash);
    render();
}

// ─── Explain ───
async function explainSentence(card) {
    event && event.stopPropagation();
    const panel = document.getElementById('explain-panel');
    const content = document.getElementById('explain-content');
    const accentBar = document.getElementById('explain-accent');
    const accent = getAccent(card);
    accentBar.style.borderTopColor = accent;
    panel.classList.add('visible');
    content.textContent = 'Loading explanation...';

    const sentence = card.example_jp || (card.front + ' → ' + card.back);
    try {
        const res = await fetch('?action=explain', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sentence }),
        });
        const data = await res.json();
        if (data.error) content.textContent = 'Error: ' + data.error;
        else content.innerHTML = renderMarkdown(data.explanation);
    } catch (e) {
        content.textContent = 'Error: ' + e.message;
    }
}

function explainCurrent() {
    const card = state.deck[state.index];
    if (card) explainSentence(card);
}

function closeExplain() {
    document.getElementById('explain-panel').classList.remove('visible');
}

// ─── Render ───
function render() {
    const card = state.deck[state.index];
    const total = state.deck.length;
    const totalAll = Object.values(ALL_DECKS).flat().length;

    // Card total
    document.getElementById('card-total').textContent = 'GRAMMAR FLASHCARDS · ' + totalAll + ' CARDS';

    // Deck picker
    const picker = document.getElementById('deck-picker');
    picker.innerHTML = '';
    deckNames.forEach(name => {
        const sel = state.selectedDecks.has(name);
        const color = deckColorMap[name];
        const count = getDeckCardCount(name);
        const btn = document.createElement('button');
        btn.className = 'pill';
        btn.style.background = sel ? color : '#1c1c32';
        btn.style.color = sel ? '#080810' : '#aaa';
        btn.style.border = '1px solid ' + (sel ? 'transparent' : '#2e2e50');
        btn.style.fontWeight = sel ? '700' : '400';
        btn.innerHTML = '<span class="deck-dot" style="background:' + color + '"></span>' +
            getDeckShortName(name) + ' <span class="deck-count">' + count + '</span>';
        btn.onclick = () => toggleDeck(name);
        picker.appendChild(btn);
    });

    // Mastered toggle
    const mt = document.getElementById('mastered-toggle');
    mt.innerHTML = '';
    const mtBtn = document.createElement('button');
    mtBtn.className = 'pill';
    mtBtn.style.background = state.showMastered ? '#fff' : '#1c1c32';
    mtBtn.style.color = state.showMastered ? '#080810' : '#aaa';
    mtBtn.style.border = '1px solid ' + (state.showMastered ? 'transparent' : '#2e2e50');
    mtBtn.style.fontWeight = state.showMastered ? '700' : '400';
    mtBtn.style.fontSize = '11px';
    mtBtn.style.padding = '5px 14px';
    const masteredCount = Object.values(ALL_DECKS).flat().filter(c => state.masteredHashes.has(c.hash)).length;
    mtBtn.textContent = 'Show mastered (' + masteredCount + ')';
    mtBtn.onclick = toggleShowMastered;
    mt.appendChild(mtBtn);

    // Hide everything first
    document.getElementById('progress-area').style.display = 'none';
    document.getElementById('card-wrapper').style.display = 'none';
    document.getElementById('btn-row').style.display = 'none';
    document.getElementById('btn-row-nav').style.display = 'none';
    document.getElementById('legend').style.display = 'none';
    document.getElementById('done-screen').style.display = 'none';
    document.getElementById('no-cards').style.display = 'none';

    if (state.done) {
        // Done screen
        const ds = document.getElementById('done-screen');
        ds.style.display = 'block';
        const pct = state.score.correct + state.score.incorrect;
        let msg = 'もう一回！Try again!';
        if (state.score.incorrect === 0) msg = '完璧！Perfect! 🎯';
        else if (state.score.correct > state.score.incorrect) msg = 'よくできました！';
        ds.innerHTML = '<div style="font-size:52px;margin-bottom:16px;">🎌</div>' +
            '<div class="mono" style="font-size:12px;color:#aaa;letter-spacing:3px;margin-bottom:12px;">SESSION COMPLETE</div>' +
            '<div style="font-size:44px;font-weight:700;margin-bottom:8px;">' +
            '<span style="color:#34d399;">' + state.score.correct + '</span>' +
            '<span style="color:#888;"> / ' + pct + '</span></div>' +
            '<div class="mono" style="color:#bbb;font-size:14px;margin-bottom:32px;">' + msg + '</div>' +
            '<button class="btn" onclick="resetSession()" style="background:#fff;color:#080810;font-weight:700;font-size:13px;padding:12px 28px;">Shuffle &amp; Restart →</button>';
        return;
    }

    if (!card || total === 0) {
        document.getElementById('no-cards').style.display = 'block';
        return;
    }

    const accent = getAccent(card);
    const isRule = isRuleCard(card);

    // Progress
    document.getElementById('progress-area').style.display = 'block';
    document.getElementById('counter').textContent = (state.index + 1) + ' / ' + total;
    document.getElementById('score-correct').textContent = '✓ ' + state.score.correct;
    document.getElementById('score-incorrect').textContent = '✗ ' + state.score.incorrect;
    const fill = document.getElementById('progress-fill');
    fill.style.width = ((state.index / total) * 100) + '%';
    fill.style.background = accent;

    // Card wrapper
    const wrapper = document.getElementById('card-wrapper');
    wrapper.style.display = 'block';
    wrapper.style.height = isRule ? '350px' : '310px';

    // Flip state
    const inner = document.getElementById('card-inner');
    if (state.flipped) inner.classList.add('fl');
    else inner.classList.remove('fl');

    // Front
    const front = document.getElementById('card-front');
    front.style.border = '1px solid #2a2a45';
    front.style.borderTop = '3px solid ' + accent;

    const formName = getDeckFormName(card.deck);
    let frontHtml = '';
    if (isRule) {
        frontHtml += '<div class="rule-badge">RULE CARD</div>';
    }
    frontHtml += '<div class="form-badge" style="background:' + accent + '33;color:' + accent + ';">' + formName + '</div>';

    if (!isRule) {
        // Verb card
        frontHtml += '<div class="verb-jp">' + escHtml(card.front) + '</div>';
        frontHtml += '<div class="verb-romaji">' + escHtml(card.front_romaji) + '</div>';
        frontHtml += '<div class="form-prompt" style="color:' + accent + ';">→ ' + formName.toUpperCase() + ' FORM?</div>';
    } else {
        frontHtml += '<div class="rule-front">' + escHtml(card.front) + '</div>';
        frontHtml += '<div class="rule-frontsub">' + escHtml(card.front_sub) + '</div>';
    }
    frontHtml += '<div class="tap-hint">TAP TO REVEAL</div>';
    front.innerHTML = frontHtml;

    // Back
    const back = document.getElementById('card-back');
    back.style.border = '1px solid ' + accent + '66';
    back.style.borderTop = '3px solid ' + accent;

    let backHtml = '';
    // Mastered button
    const isMastered = state.masteredHashes.has(card.hash);
    backHtml += '<button class="master-btn" onclick="toggleMastered(\'' + card.hash + '\')" title="' + (isMastered ? 'Unmaster' : 'Master') + '">' +
        (isMastered ? '★' : '☆') + '</button>';

    if (!isRule) {
        backHtml += '<div class="answer-jp">' + escHtml(card.back) + '</div>';
        backHtml += '<div class="answer-romaji" style="color:' + accent + ';">' + escHtml(card.back_romaji) + '</div>';
    } else {
        backHtml += '<div class="rule-back" style="margin-bottom:' + (card.back_romaji ? '6' : '18') + 'px;">' + escHtml(card.back) + '</div>';
        if (card.back_romaji) {
            backHtml += '<div class="rule-backromaji" style="color:' + accent + ';">' + escHtml(card.back_romaji) + '</div>';
        }
    }

    backHtml += '<div class="divider">';
    backHtml += '<div class="ex-jp">' + escHtml(card.example_jp) + '</div>';
    backHtml += '<div class="ex-romaji">' + escHtml(card.example_romaji) + '</div>';
    backHtml += '<div class="ex-en">' + escHtml(card.example_en) + '</div>';
    backHtml += '</div>';

    back.innerHTML = backHtml;

    // Buttons
    if (state.flipped) {
        document.getElementById('btn-row').style.display = 'flex';
        document.getElementById('btn-row-nav').style.display = 'none';

        // Update mastered button in button row
        const mBtn = document.getElementById('mastered-btn');
        const im = state.masteredHashes.has(card.hash);
        mBtn.textContent = im ? '★' : '☆';
        mBtn.style.color = im ? '#f5a623' : '#aaa';
        mBtn.onclick = () => toggleMastered(card.hash);

        // Explain row
        document.getElementById('explain-row').style.display = HAS_API_KEY ? 'flex' : 'none';
    } else {
        document.getElementById('btn-row').style.display = 'none';
        document.getElementById('explain-row').style.display = 'none';
        document.getElementById('btn-row-nav').style.display = 'flex';

        const prevBtn = document.getElementById('prev-btn');
        const disabled = state.index === 0;
        prevBtn.disabled = disabled;
        prevBtn.style.color = disabled ? '#3a3a5a' : '#ccc';
        prevBtn.style.border = '1px solid ' + (disabled ? '#2a2a45' : '#4a4a70');

        document.getElementById('flip-btn').style.background = accent;
    }

    // Legend
    const legend = document.getElementById('legend');
    legend.style.display = 'flex';
    legend.innerHTML = '';
    deckNames.forEach(name => {
        if (!state.selectedDecks.has(name)) return;
        const color = deckColorMap[name];
        const count = getDeckCardCount(name);
        const short = getDeckShortName(name).replace('Causative-Passive', 'C-Pass');
        const div = document.createElement('div');
        div.className = 'item';
        div.innerHTML = '<span class="deck-dot" style="background:' + color + ';"></span>' + short + ' ' + count;
        legend.appendChild(div);
    });
}

function escHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function renderMarkdown(str) {
    if (!str) return '';
    let h = escHtml(str);
    // Headers (###, ##, #)
    h = h.replace(/^### (.+)$/gm, '<strong style="font-size:14px;">$1</strong>');
    h = h.replace(/^## (.+)$/gm, '<strong style="font-size:15px;">$1</strong>');
    h = h.replace(/^# (.+)$/gm, '<strong style="font-size:16px;">$1</strong>');
    // Bold
    h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    // Italic
    h = h.replace(/\*(.+?)\*/g, '<em>$1</em>');
    // Inline code
    h = h.replace(/`(.+?)`/g, '<code style="background:#1c1c32;padding:1px 5px;border-radius:4px;font-size:12px;">$1</code>');
    // Unordered lists
    h = h.replace(/^[\-\*] (.+)$/gm, '<li style="margin-left:16px;list-style:disc;">$1</li>');
    // Ordered lists
    h = h.replace(/^\d+\. (.+)$/gm, '<li style="margin-left:16px;list-style:decimal;">$1</li>');
    // Double newlines → paragraphs, single → br
    h = h.replace(/\n\n/g, '</p><p style="margin-top:8px;">');
    h = h.replace(/\n/g, '<br>');
    return '<p>' + h + '</p>';
}

// ─── Init ───
resetSession();
</script>
</body>
</html>
