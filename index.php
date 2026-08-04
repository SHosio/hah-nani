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
$apiKey = $env['OPENROUTER_API_KEY'] ?? '';

// Cheap model for the one-shot explain button, stronger one for full lessons
// and decks. Both overridable in .env without touching code.
$explainModel = $env['EXPLAIN_MODEL'] ?? 'google/gemini-2.5-flash';
$lessonModel = $env['LESSON_MODEL'] ?? 'anthropic/claude-sonnet-5';

require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/chapters.php';
require __DIR__ . '/lib/openrouter.php';
require __DIR__ . '/lib/templates.php';

// ─── SQLite ───
$dbDir = __DIR__ . '/db';
$db = initDb($dbDir);

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
$masteredHashes = getMasteredHashes($db);

// ─── Chapters ───
$chapters = loadChapters();

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
        echo json_encode(['mastered' => toggleMastered($db, $hash)]);
        exit;
    }

    if ($action === 'chapters') {
        echo json_encode($chapters);
        exit;
    }

    if ($action === 'generations') {
        echo json_encode(listGenerations($db));
        exit;
    }

    if ($action === 'generation') {
        $gen = getGeneration($db, (int) ($_GET['id'] ?? 0));
        echo json_encode($gen ?: ['error' => 'Not found']);
        exit;
    }

    if ($action === 'delete_generation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $ok = deleteGeneration($db, (int) ($input['id'] ?? 0));
        echo json_encode($ok ? ['deleted' => true] : ['error' => 'Not found']);
        exit;
    }

    if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $kind = $input['kind'] ?? 'lesson';
        $ids = $input['grammar_ids'] ?? [];
        $request = trim($input['prompt'] ?? '');

        if (!isset(GENERATION_KINDS[$kind])) {
            echo json_encode(['error' => "Unknown kind: $kind"]); exit;
        }
        if (!is_array($ids) || !$ids) {
            echo json_encode(['error' => 'Select at least one grammar point.']); exit;
        }

        $points = collectGrammar($chapters, $ids);
        if (!$points) {
            echo json_encode(['error' => 'None of those grammar points exist.']); exit;
        }

        $result = openrouterChat(
            $apiKey, $lessonModel, buildMessages($kind, $points, $request)
        );
        if (isset($result['error'])) { echo json_encode($result); exit; }

        $content = $result['content'];
        $deckFile = null;
        $note = null;

        if ($kind === 'flashcards') {
            $parsed = parseGeneratedCards($content);
            if (isset($parsed['error'])) {
                echo json_encode(['error' => $parsed['error']]); exit;
            }
            $cardsDir = __DIR__ . '/cards';
            $deckName = uniqueDeckName(deriveTitle($request, $points), $cardsDir);
            try {
                $deckFile = writeDeckCsv($cardsDir, $deckName, $parsed['rows']);
            } catch (RuntimeException $e) {
                echo json_encode(['error' => $e->getMessage()]); exit;
            }
            $count = count($parsed['rows']);
            $note = "Deck \"$deckName\" written with $count cards.";
            if (!empty($parsed['skipped'])) {
                $note .= " {$parsed['skipped']} malformed row(s) were dropped.";
            }
        }

        $id = saveGeneration($db, [
            'kind' => $kind,
            'title' => deriveTitle($request, $points),
            'prompt' => $request,
            'grammar_ids' => array_column($points, 'id'),
            'model' => $lessonModel,
            'content' => $content,
            'deck_file' => $deckFile,
        ]);

        echo json_encode([
            'id' => $id,
            'kind' => $kind,
            'title' => deriveTitle($request, $points),
            'content' => $content,
            'deck_file' => $deckFile,
            'note' => $note,
            'model' => $lessonModel,
        ]);
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

        $result = openrouterChat($apiKey, $explainModel, [
            ['role' => 'system', 'content' => 'You are a Japanese grammar tutor. Explain the grammar point or sentence briefly and clearly. Break down the conjugation steps. Keep it concise (3-5 sentences). Use romaji alongside Japanese where helpful.'],
            ['role' => 'user', 'content' => "Explain this Japanese grammar/sentence:\n$sentence"],
        ], 30);

        echo json_encode(isset($result['error'])
            ? $result
            : ['explanation' => $result['content']]);
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

/* ─── View switcher ─── */
#view-nav{display:flex;gap:8px;margin-bottom:22px;}
#view-nav .pill{font-size:11px;padding:7px 20px;letter-spacing:2px;background:#12122a;color:#777;border:1px solid #23233f;}
#view-nav .pill.active{background:#1c1c3a;color:#fff;border-color:#4a4a70;}

/* ─── Study view ─── */
#view-study{width:100%;max-width:760px;display:flex;flex-direction:column;align-items:stretch;}

#chapter-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;}
#chapter-tabs .pill{font-size:11px;padding:7px 14px;background:#12122a;color:#888;border:1px solid #23233f;display:flex;gap:7px;align-items:center;}
#chapter-tabs .pill.active{background:#1c1c3a;color:#fff;border-color:#4a4a70;}
#chapter-tabs .tick{font-size:10px;color:#34d399;}

#chapter-panel{background:#0c0c1e;border:1px solid #23233f;border-radius:16px;padding:20px 22px;margin-bottom:18px;}
.chapter-head{display:flex;align-items:baseline;gap:10px;margin-bottom:4px;flex-wrap:wrap;}
.chapter-head .jp{font-size:19px;color:#fff;}
.chapter-head .en{font-family:'Space Mono',monospace;font-size:12px;color:#888;}
.chapter-meta{font-family:'Space Mono',monospace;font-size:10px;color:#555;letter-spacing:1px;margin-bottom:16px;}

.point{display:flex;gap:11px;align-items:flex-start;padding:9px 10px;border-radius:9px;cursor:pointer;transition:background .15s;}
.point:hover{background:#13132a;}
.point.on{background:#16162f;}
.point .box{width:15px;height:15px;border-radius:4px;border:1.5px solid #3a3a5e;flex-shrink:0;margin-top:2px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#080810;transition:all .15s;}
.point.on .box{background:#4fc3f7;border-color:#4fc3f7;}
.point .txt{flex:1;min-width:0;}
.point .name{color:#e8e8f0;font-size:14px;margin-bottom:2px;}
.point.on .name{color:#fff;}
.point .sum{font-family:'Space Mono',monospace;font-size:11px;color:#6a6a85;line-height:1.5;}

/* ─── Composer ─── */
#composer{background:#0c0c1e;border:1px solid #23233f;border-radius:16px;padding:18px 20px;margin-bottom:18px;}
#selection-tray{font-family:'Space Mono',monospace;font-size:11px;color:#6a6a85;line-height:1.6;margin-bottom:12px;}
#selection-tray .sel{color:#4fc3f7;}
#composer-prompt{width:100%;background:#080814;border:1px solid #23233f;border-radius:10px;color:#eee;font-family:'Space Mono',monospace;font-size:13px;padding:11px 13px;resize:vertical;line-height:1.6;}
#composer-prompt:focus{outline:none;border-color:#4a4a70;}
#composer-prompt::placeholder{color:#4a4a63;}
#composer-row{display:flex;gap:10px;margin-top:11px;align-items:center;flex-wrap:wrap;}
#composer-kind{background:#12122a;border:1px solid #23233f;color:#ccc;border-radius:10px;padding:9px 12px;font-size:12px;cursor:pointer;}
#composer-kind:focus{outline:none;border-color:#4a4a70;}
#generate-btn{background:#4fc3f7;color:#080810;font-weight:700;font-size:13px;padding:10px 26px;}
#clear-btn{background:#1c1c32;color:#888;border:1px solid #2e2e50;font-size:12px;padding:10px 16px;}
#composer-status{font-family:'Space Mono',monospace;font-size:11px;margin-top:11px;min-height:15px;color:#888;}
#composer-status.err{color:#f87191;}

/* ─── Output ─── */
#output-panel{display:none;background:#0c0c1e;border:1px solid #23233f;border-radius:16px;padding:22px;margin-bottom:18px;position:relative;}
#output-panel.visible{display:block;}
#output-panel .close-btn{position:absolute;top:12px;right:16px;background:none;border:none;color:#666;font-size:16px;cursor:pointer;font-family:'Space Mono',monospace;}
#output-panel .close-btn:hover{color:#aaa;}
#output-title{font-size:11px;color:#4fc3f7;letter-spacing:2px;margin-bottom:6px;padding-right:26px;}
#output-note{font-family:'Space Mono',monospace;font-size:11px;color:#34d399;margin-bottom:14px;}
#output-content{color:#d5d5e0;font-size:14px;line-height:1.75;}
#output-content h1,#output-content h2,#output-content h3{color:#fff;margin:22px 0 9px;line-height:1.35;}
#output-content h1{font-size:19px;}
#output-content h2{font-size:16px;}
#output-content h3{font-size:14px;font-family:'Space Mono',monospace;letter-spacing:1px;}
#output-content p{margin-bottom:11px;}
#output-content ul,#output-content ol{margin:0 0 11px 20px;}
#output-content li{margin-bottom:5px;}
#output-content strong{color:#fff;}
#output-content em{color:#aaa;}
#output-content code{font-family:'Space Mono',monospace;background:#16162e;padding:2px 6px;border-radius:5px;font-size:12px;color:#9fdcf7;}
#output-content hr{border:none;border-top:1px solid #23233f;margin:18px 0;}
.table-scroll{overflow-x:auto;margin-bottom:13px;}
#output-content table{border-collapse:collapse;font-size:12.5px;min-width:100%;}
#output-content th,#output-content td{border:1px solid #23233f;padding:7px 11px;text-align:left;vertical-align:top;}
#output-content th{background:#13132a;color:#fff;font-family:'Space Mono',monospace;font-size:11px;letter-spacing:1px;white-space:nowrap;}

/* ─── Library ─── */
#library-heading{font-size:11px;color:#555;letter-spacing:3px;margin-bottom:10px;}
.lib-item{display:flex;align-items:center;gap:11px;background:#0c0c1e;border:1px solid #1d1d36;border-radius:11px;padding:11px 14px;margin-bottom:7px;}
.lib-item .main{flex:1;min-width:0;cursor:pointer;}
.lib-item .t{color:#ddd;font-size:13px;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.lib-item:hover .t{color:#fff;}
.lib-item .m{font-family:'Space Mono',monospace;font-size:10px;color:#5a5a75;letter-spacing:1px;}
.lib-badge{font-family:'Space Mono',monospace;font-size:9px;padding:3px 8px;border-radius:6px;letter-spacing:1px;flex-shrink:0;}
.lib-badge.lesson{background:#152a3a;color:#4fc3f7;}
.lib-badge.flashcards{background:#152a1e;color:#34d399;}
.lib-del{background:none;border:none;color:#3a3a55;cursor:pointer;font-size:14px;padding:3px 5px;flex-shrink:0;}
.lib-del:hover{color:#f87191;}
#library-empty{font-family:'Space Mono',monospace;font-size:11px;color:#4a4a63;padding:6px 2px;}

@media (max-width:560px){
  #view-study{max-width:100%;}
  #chapter-panel,#composer,#output-panel{padding:15px;}
}
</style>
</head>
<body>

<!-- Header -->
<div style="text-align:center;margin-bottom:20px;">
  <div class="mono" style="color:#888;font-size:11px;letter-spacing:4px;margin-bottom:6px;">HÄH?何?</div>
  <h1 style="color:#fff;font-size:22px;font-weight:700;letter-spacing:2px;margin-bottom:4px;">日本語フラッシュカード</h1>
  <div class="mono" id="card-total" style="color:#888;font-size:11px;letter-spacing:3px;">GRAMMAR FLASHCARDS</div>
</div>

<!-- View switcher -->
<div id="view-nav">
  <button class="pill" data-view="cards" onclick="setView('cards')">CARDS</button>
  <button class="pill" data-view="study" onclick="setView('study')">STUDY</button>
</div>

<div id="view-cards">

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

</div><!-- /view-cards -->

<div id="view-study" style="display:none;">

  <!-- Chapter tabs -->
  <div id="chapter-tabs"></div>

  <!-- Grammar bullets for the active chapter -->
  <div id="chapter-panel"></div>

  <!-- Composer -->
  <div id="composer">
    <div id="selection-tray">Nothing selected yet. Tick grammar points above.</div>
    <textarea id="composer-prompt" rows="2"
      placeholder="What do you want? e.g. drill me on the difference between causative and causative-passive"></textarea>
    <div id="composer-row">
      <select id="composer-kind" class="mono"></select>
      <button class="btn" id="generate-btn" onclick="generate()">Generate</button>
      <button class="btn" id="clear-btn" onclick="clearSelection()">Clear</button>
    </div>
    <div id="composer-status"></div>
  </div>

  <!-- Output -->
  <div id="output-panel">
    <button class="close-btn" onclick="closeOutput()">✕</button>
    <div id="output-title" class="mono"></div>
    <div id="output-note"></div>
    <div id="output-content" class="content"></div>
  </div>

  <!-- Library -->
  <div id="library">
    <div id="library-heading" class="mono">LIBRARY</div>
    <div id="library-list"></div>
  </div>

</div><!-- /view-study -->

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

// Decks written by the study view are prefixed with ★.
function isGeneratedDeck(deckName) {
    return deckName.startsWith('★');
}

function getDeckFormName(deckName) {
    // Extract form name: "GENKI II L22 - Passive Verbs" -> "Passive"
    const m = deckName.match(/- (.+?) (Verbs|Rules)$/);
    if (m) return m[1];
    // A generated deck is named after the request that produced it, which is
    // far too long for the badge.
    if (isGeneratedDeck(deckName)) {
        const label = deckName.slice(1).trim();
        return label.length > 26 ? label.slice(0, 24) + '…' : label;
    }
    return deckName;
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
        // Hand-built decks hold a bare verb, so they need the "→ X FORM?"
        // prompt. A generated card already asks its own question.
        if (!isGeneratedDeck(card.deck)) {
            frontHtml += '<div class="form-prompt" style="color:' + accent + ';">→ ' + formName.toUpperCase() + ' FORM?</div>';
        }
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

// ═══════════════════════════════════════════════════════
// STUDY VIEW
// ═══════════════════════════════════════════════════════

const CHAPTERS = <?= json_encode(array_map(function ($c) {
    // The grammar bodies are large and only the server needs them, so the
    // browser gets everything except those.
    $c['grammar'] = array_map(function ($g) {
        unset($g['body']);
        return $g;
    }, $c['grammar']);
    return $c;
}, $chapters)) ?>;
const KINDS = <?= json_encode(GENERATION_KINDS) ?>;

const study = {
    view: 'cards',
    activeChapter: CHAPTERS.length ? CHAPTERS[0].slug : null,
    selected: new Set(),
    busy: false,
};

// Points keyed by id, so the tray can name things selected in other chapters.
const POINT_INDEX = {};
CHAPTERS.forEach(c => c.grammar.forEach(g => {
    POINT_INDEX[g.id] = { name: g.name, lesson: c.lesson, book: c.book };
}));

function setView(view) {
    study.view = view;
    document.getElementById('view-cards').style.display = view === 'cards' ? '' : 'none';
    document.getElementById('view-study').style.display = view === 'study' ? 'flex' : 'none';
    document.querySelectorAll('#view-nav .pill').forEach(b => {
        b.classList.toggle('active', b.dataset.view === view);
    });
    document.getElementById('card-total').textContent =
        view === 'cards' ? 'GRAMMAR FLASHCARDS' : 'ON-DEMAND LESSONS';
    if (view === 'study') renderStudy();
}

function setChapter(slug) {
    study.activeChapter = slug;
    renderStudy();
}

function togglePoint(id) {
    study.selected.has(id) ? study.selected.delete(id) : study.selected.add(id);
    renderStudy();
}

function clearSelection() {
    study.selected.clear();
    renderStudy();
}

function renderStudy() {
    renderChapterTabs();
    renderChapterPanel();
    renderTray();
}

function renderChapterTabs() {
    const el = document.getElementById('chapter-tabs');
    el.innerHTML = CHAPTERS.map(c => {
        const n = c.grammar.filter(g => study.selected.has(g.id)).length;
        const active = c.slug === study.activeChapter ? ' active' : '';
        const tick = n ? `<span class="tick">${n}</span>` : '';
        return `<button class="pill${active}" onclick="setChapter('${c.slug}')">`
             + `L${c.lesson}${tick}</button>`;
    }).join('');
}

function renderChapterPanel() {
    const c = CHAPTERS.find(x => x.slug === study.activeChapter);
    const el = document.getElementById('chapter-panel');
    if (!c) {
        el.innerHTML = '<div class="mono" style="color:#666;font-size:12px;">'
                     + 'No chapters found in chapters/.</div>';
        return;
    }

    const points = c.grammar.map(g => {
        const on = study.selected.has(g.id) ? ' on' : '';
        return `<div class="point${on}" onclick="togglePoint('${g.id}')">`
             + `<div class="box">${study.selected.has(g.id) ? '✓' : ''}</div>`
             + `<div class="txt"><div class="name">${escHtml(g.name)}</div>`
             + `<div class="sum">${escHtml(g.summary)}</div></div></div>`;
    }).join('');

    el.innerHTML =
        `<div class="chapter-head"><span class="jp">${escHtml(c.title_jp)}</span>`
      + `<span class="en">Genki ${c.book === 1 ? 'I' : 'II'} · Lesson ${c.lesson} · ${escHtml(c.title)}</span></div>`
      + `<div class="chapter-meta">${c.grammar.length} GRAMMAR POINTS · ${c.vocab_count} WORDS · ${c.kanji_count} KANJI</div>`
      + points;
}

function renderTray() {
    const el = document.getElementById('selection-tray');
    const ids = [...study.selected];
    if (!ids.length) {
        el.innerHTML = 'Nothing selected yet. Tick grammar points above.';
        return;
    }
    const names = ids.map(id => {
        const p = POINT_INDEX[id];
        return p ? `<span class="sel">${escHtml(p.name)}</span> <span>(L${p.lesson})</span>` : '';
    }).filter(Boolean);
    el.innerHTML = `Selected ${ids.length}: ` + names.join(', ');
}

async function generate() {
    if (study.busy) return;
    const status = document.getElementById('composer-status');
    const btn = document.getElementById('generate-btn');

    if (!study.selected.size) {
        status.className = 'err';
        status.textContent = 'Tick at least one grammar point first.';
        return;
    }
    if (!HAS_API_KEY) {
        status.className = 'err';
        status.textContent = 'No API key. Add OPENROUTER_API_KEY to .env';
        return;
    }

    const kind = document.getElementById('composer-kind').value;
    study.busy = true;
    btn.disabled = true;
    status.className = '';
    status.textContent = 'Generating from your chapter notes, this takes a while...';

    try {
        const res = await fetch('?action=generate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                kind,
                grammar_ids: [...study.selected],
                prompt: document.getElementById('composer-prompt').value,
            }),
        });
        const data = await res.json();

        if (data.error) {
            status.className = 'err';
            status.textContent = data.error;
        } else {
            status.className = '';
            status.textContent = '';
            showOutput(data);
            loadLibrary();
            // A generated deck is a real CSV, so the picker needs reloading.
            if (data.deck_file) await reloadDecks();
        }
    } catch (e) {
        status.className = 'err';
        status.textContent = 'Request failed: ' + e.message;
    } finally {
        study.busy = false;
        btn.disabled = false;
    }
}

function showOutput(data) {
    const panel = document.getElementById('output-panel');
    document.getElementById('output-title').textContent =
        (KINDS[data.kind] || data.kind).toUpperCase() + ' · ' + (data.model || '');
    document.getElementById('output-note').textContent = data.note || '';

    document.getElementById('output-content').innerHTML = data.kind === 'flashcards'
        ? '<p>Deck saved. Switch to <strong>CARDS</strong> and pick it in the deck list.</p>'
          + '<pre style="overflow-x:auto;font-size:11px;color:#7a7a95;white-space:pre-wrap;">'
          + escHtml(data.content.slice(0, 1200)) + '</pre>'
        : renderLessonMarkdown(data.content);

    panel.classList.add('visible');
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeOutput() {
    document.getElementById('output-panel').classList.remove('visible');
}

async function reloadDecks() {
    const res = await fetch('?action=cards');
    const decks = await res.json();
    Object.keys(decks).forEach(name => {
        if (!ALL_DECKS[name]) {
            ALL_DECKS[name] = decks[name];
            deckNames.push(name);
            deckColorMap[name] = DECK_COLORS[(deckNames.length - 1) % DECK_COLORS.length];
            state.selectedDecks.add(name);
        }
    });
    deckNames.sort();
    resetSession();
}

async function loadLibrary() {
    const res = await fetch('?action=generations');
    const rows = await res.json();
    const el = document.getElementById('library-list');

    if (!rows.length) {
        el.innerHTML = '<div id="library-empty">Nothing generated yet.</div>';
        return;
    }

    el.innerHTML = rows.map(r => {
        const when = (r.created_at || '').slice(0, 16);
        const points = (r.grammar_ids || [])
            .map(id => POINT_INDEX[id])
            .filter(Boolean).map(p => p.name).join(', ');
        return `<div class="lib-item">`
             + `<span class="lib-badge ${r.kind}">${(KINDS[r.kind] || r.kind).toUpperCase()}</span>`
             + `<div class="main" onclick="openGeneration(${r.id})">`
             + `<div class="t">${escHtml(r.title)}</div>`
             + `<div class="m">${when} · ${escHtml(points)}</div></div>`
             + `<button class="lib-del" onclick="removeGeneration(${r.id})" title="Delete">✕</button>`
             + `</div>`;
    }).join('');
}

async function openGeneration(id) {
    const res = await fetch('?action=generation&id=' + id);
    const data = await res.json();
    if (data.error) return;
    showOutput(data);
}

async function removeGeneration(id) {
    if (!confirm('Delete this? A generated deck is deleted with it.')) return;
    await fetch('?action=delete_generation', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
    });
    loadLibrary();
}

// Markdown renderer for generated lessons. Handles the pipe tables the prompt
// asks for, which the flashcard explain panel's renderer does not.
function renderLessonMarkdown(src) {
    if (!src) return '';
    const lines = escHtml(src).split('\n');
    const out = [];
    let i = 0;

    const inline = s => s
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*]+?)\*/g, '$1<em>$2</em>')
        .replace(/`(.+?)`/g, '<code>$1</code>');

    const cells = line => line.trim().replace(/^\||\|$/g, '').split('|').map(c => c.trim());

    while (i < lines.length) {
        const line = lines[i];

        if (line.trim() === '') { i++; continue; }

        // Table: a pipe row followed by a |---| separator.
        if (line.trim().startsWith('|') && i + 1 < lines.length
            && /^\s*\|[\s\-:|]+\|\s*$/.test(lines[i + 1])) {
            const head = cells(line);
            i += 2;
            const body = [];
            while (i < lines.length && lines[i].trim().startsWith('|')) {
                body.push(cells(lines[i]));
                i++;
            }
            out.push('<div class="table-scroll"><table><thead><tr>'
                + head.map(c => `<th>${inline(c)}</th>`).join('')
                + '</tr></thead><tbody>'
                + body.map(r => '<tr>' + r.map(c => `<td>${inline(c)}</td>`).join('') + '</tr>').join('')
                + '</tbody></table></div>');
            continue;
        }

        const heading = line.match(/^(#{1,4})\s+(.*)$/);
        if (heading) {
            const level = Math.min(heading[1].length, 3);
            out.push(`<h${level}>${inline(heading[2])}</h${level}>`);
            i++;
            continue;
        }

        if (/^\s*(---+|___+|\*\*\*+)\s*$/.test(line)) { out.push('<hr>'); i++; continue; }

        // Lists, gathering consecutive items of the same kind.
        const bullet = line.match(/^\s*[-*+]\s+(.*)$/);
        const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
        if (bullet || numbered) {
            const tag = bullet ? 'ul' : 'ol';
            const pattern = bullet ? /^\s*[-*+]\s+(.*)$/ : /^\s*\d+[.)]\s+(.*)$/;
            const items = [];
            while (i < lines.length) {
                const m = lines[i].match(pattern);
                if (!m) break;
                items.push(`<li>${inline(m[1])}</li>`);
                i++;
            }
            out.push(`<${tag}>${items.join('')}</${tag}>`);
            continue;
        }

        // Paragraph, running until a blank line or a block-level marker.
        const para = [];
        while (i < lines.length && lines[i].trim() !== ''
               && !/^\s*(#{1,4}\s|[-*+]\s|\d+[.)]\s|\|)/.test(lines[i])
               && !/^\s*(---+|___+|\*\*\*+)\s*$/.test(lines[i])) {
            para.push(lines[i].trim());
            i++;
        }
        if (para.length) out.push(`<p>${inline(para.join(' '))}</p>`);
        else i++;
    }

    return out.join('');
}

// ─── Init ───
resetSession();

document.getElementById('composer-kind').innerHTML = Object.entries(KINDS)
    .map(([k, label]) => `<option value="${k}">${label}</option>`).join('');
setView('cards');
loadLibrary();
</script>
</body>
</html>
