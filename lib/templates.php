<?php
// Prompt builders, one per output kind, plus the CSV handling that turns a
// generated deck into a real file in cards/.
//
// Adding a kind means adding an entry to GENERATION_KINDS and a builder here.
// Nothing else in the app needs to know about it.

const GENERATION_KINDS = [
    'lesson' => 'Text lesson',
    'flashcards' => 'Flashcard deck',
];

const CSV_COLUMNS = [
    'front', 'front_sub', 'front_romaji', 'back', 'back_romaji',
    'example_jp', 'example_romaji', 'example_en',
];

/**
 * The selected grammar points, verbatim from the chapter notes. This is the
 * whole point of the notes: the model works from them rather than from its own
 * recollection of Genki.
 */
function buildContext(array $points): string {
    $blocks = [];
    foreach ($points as $point) {
        $blocks[] = sprintf(
            "=== Genki %s, Lesson %d (%s) ===\n\n%s",
            $point['book'] === 1 ? 'I' : 'II',
            $point['lesson'],
            $point['chapter_title'],
            $point['body']
        );
    }
    return implode("\n\n", $blocks);
}

function pointNames(array $points): string {
    return implode(', ', array_column($points, 'name'));
}

function buildLessonMessages(array $points, string $request): array {
    $system = <<<'TXT'
You are a Japanese tutor writing a self-contained study lesson for an adult
learner working through Genki II. The learner already knows everything up to
the lessons in the reference material.

Work from the reference material given below. It is the learner's own chapter
notes and is authoritative. Do not contradict it. You may add examples,
exercises and explanation beyond it, but formation rules and particle usage
must match it exactly.

Write in markdown. Use headings, short paragraphs and tables. For every
Japanese sentence give the Japanese, the romaji, and the English on separate
lines or in a table, never Japanese alone. Romaji is wapuro style, so "wo" for
を and doubled long vowels.

Be concrete and drill-oriented. A lesson the learner cannot practise from is a
failed lesson. End with a short practice set including an answer key.
TXT;

    $context = buildContext($points);
    $names = pointNames($points);
    $ask = trim($request) !== ''
        ? trim($request)
        : 'Write a focused lesson covering these points, with plenty of contrast between them.';

    $user = <<<TXT
Grammar points selected: $names

What I want:
$ask

Reference material (my chapter notes):

$context
TXT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}

function buildFlashcardMessages(array $points, string $request): array {
    // JSON rather than CSV on purpose. Models reliably forget to quote a field
    // containing a comma, which silently corrupts a CSV row. The app writes the
    // CSV itself from this, so escaping is never the model's problem.
    $system = <<<'TXT'
You generate flashcard decks for a Japanese study app. You output a JSON array
and nothing else.

Each element is an object with exactly these keys, all strings:
  front, front_sub, front_romaji, back, back_romaji,
  example_jp, example_romaji, example_en

Key meaning:
- front, front_sub, front_romaji: the prompt side.
- back, back_romaji: the answer side.
- example_jp, example_romaji, example_en: one example sentence using the answer.

There are two card shapes, and the app tells them apart by front_sub:
- Rule card: front_sub is NON-EMPTY. Use for conjugation rules and patterns.
  front is the rule name in English, front_sub is the Japanese label,
  front_romaji is "", back is the rule itself.
- Practice card: front_sub is "". Use for individual words or sentences.
  front is the Japanese prompt, front_romaji its romaji, back the answer.

Hard rules:
- Every object has all 8 keys. Use "" for a key that does not apply, but never
  leave front, back, example_jp, example_romaji or example_en empty.
- Romaji is wapuro style, so "wo" for を and doubled long vowels.
- No markdown, no code fences, no commentary. Your reply starts with [ and ends
  with ].

Work from the reference material. Its formation rules and particles are
authoritative.
TXT;

    $context = buildContext($points);
    $names = pointNames($points);
    $ask = trim($request) !== ''
        ? trim($request)
        : 'Build a deck that drills these points, mixing rule cards and practice cards.';

    $user = <<<TXT
Grammar points selected: $names

What I want:
$ask

Reference material (my chapter notes):

$context
TXT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}

function buildMessages(string $kind, array $points, string $request): array {
    return $kind === 'flashcards'
        ? buildFlashcardMessages($points, $request)
        : buildLessonMessages($points, $request);
}

/**
 * Parse a generated card list into CSV-ordered rows. Tolerates a wrapping code
 * fence and surrounding chatter, since models add both despite instructions.
 * Returns ['rows' => array, 'skipped' => int] or ['error' => string].
 */
function parseGeneratedCards(string $text): array {
    $text = trim($text);

    // Strip a wrapping code fence if one snuck in.
    if (strpos($text, '```') === 0) {
        $text = preg_replace('/^```[a-z]*\n/i', '', $text);
        $text = preg_replace('/\n```\s*$/', '', $text);
        $text = trim($text);
    }

    // Fall back to the outermost bracket pair if the model wrapped the array in
    // a sentence.
    if (strpos($text, '[') !== 0) {
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start === false || $end === false || $end < $start) {
            return ['error' => 'The model did not return a JSON array of cards.'];
        }
        $text = substr($text, $start, $end - $start + 1);
    }

    $cards = json_decode($text, true);
    if (!is_array($cards)) {
        return ['error' => 'The model returned invalid JSON: ' . json_last_error_msg()];
    }

    $rows = [];
    $skipped = 0;
    foreach ($cards as $card) {
        if (!is_array($card)) { $skipped++; continue; }
        // The app cannot render a card without these.
        if (trim((string) ($card['front'] ?? '')) === ''
            || trim((string) ($card['back'] ?? '')) === '') {
            $skipped++;
            continue;
        }
        $row = [];
        foreach (CSV_COLUMNS as $column) {
            // Newlines inside a cell would break the app's line-based CSV reader.
            $row[] = trim(str_replace(["\r", "\n"], ' ', (string) ($card[$column] ?? '')));
        }
        $rows[] = $row;
    }

    if (!$rows) return ['error' => 'The model returned no usable cards.'];
    return ['rows' => $rows, 'skipped' => $skipped];
}

/** A filename-safe deck name that will not collide with an existing deck. */
function uniqueDeckName(string $title, string $cardsDir): string {
    $base = preg_replace('/[^\p{L}\p{N} _-]+/u', '', $title);
    $base = trim(preg_replace('/\s+/', ' ', $base));
    if ($base === '') $base = 'Generated deck';
    // Trim after truncating, or a cut mid-phrase leaves a trailing space in the
    // filename.
    $base = rtrim(mb_substr($base, 0, 60));

    // Generated decks are prefixed so they read as distinct from the
    // hand-built GENKI decks in the picker.
    $name = "★ $base";
    $suffix = 2;
    while (file_exists("$cardsDir/$name.csv")) {
        $name = "★ $base ($suffix)";
        $suffix++;
    }
    return $name;
}

function writeDeckCsv(string $cardsDir, string $deckName, array $rows): string {
    $path = "$cardsDir/$deckName.csv";
    $handle = fopen($path, 'w');
    if (!$handle) throw new RuntimeException("Could not write $path");

    fputcsv($handle, CSV_COLUMNS, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    fclose($handle);
    return basename($path);
}

/** A short title for the library list. */
function deriveTitle(string $request, array $points): string {
    $request = trim(preg_replace('/\s+/', ' ', $request));
    if ($request !== '') {
        return mb_strlen($request) > 70 ? mb_substr($request, 0, 67) . '...' : $request;
    }
    $names = pointNames($points);
    return $names !== '' ? $names : 'Untitled';
}
