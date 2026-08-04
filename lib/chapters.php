<?php
// Reads chapters/g<book>/l<NN>.md into structures the app and the prompt
// builders can use. The format is fixed by chapters/SCHEMA.md, so this parses
// only the subset that schema allows rather than being a general YAML or
// markdown parser.

/**
 * Parse the fixed-shape frontmatter block. Handles scalars, the `grammar` list
 * of id/name pairs, and the `sources` string list. Nothing else appears there.
 */
function parseFrontmatter(string $yaml): array {
    $meta = ['grammar' => [], 'sources' => []];
    $list = null;

    foreach (explode("\n", $yaml) as $line) {
        if (trim($line) === '') continue;

        // Nested list items are indented; top-level keys are not.
        $indented = $line[0] === ' ' || $line[0] === "\t";
        $trimmed = trim($line);

        if (!$indented && substr($trimmed, -1) === ':') {
            $list = rtrim($trimmed, ':');
            continue;
        }

        if (!$indented && strpos($trimmed, ': ') !== false) {
            [$key, $value] = explode(': ', $trimmed, 2);
            $meta[trim($key)] = is_numeric(trim($value)) ? (int) trim($value) : trim($value);
            $list = null;
            continue;
        }

        if ($indented && $list === 'sources' && strpos($trimmed, '- ') === 0) {
            $meta['sources'][] = substr($trimmed, 2);
            continue;
        }

        if ($indented && $list === 'grammar') {
            if (strpos($trimmed, '- id: ') === 0) {
                $meta['grammar'][] = ['id' => substr($trimmed, 6), 'name' => ''];
            } elseif (strpos($trimmed, 'name: ') === 0 && $meta['grammar']) {
                $meta['grammar'][count($meta['grammar']) - 1]['name'] = substr($trimmed, 6);
            }
        }
    }

    return $meta;
}

/**
 * Pull the one-line summaries out of the Overview quick-reference table, keyed
 * by grammar id. These are what the chapter tabs show as bullets.
 */
function parseOverviewSummaries(string $body): array {
    if (!preg_match('/^## Overview$(.*?)^## /ms', $body, $m)) return [];

    $summaries = [];
    foreach (explode("\n", $m[1]) as $line) {
        if (strpos($line, '|') !== 0) continue;
        $cells = array_map('trim', explode('|', trim($line, "| \t")));
        // Skip the header and the |---| separator.
        if (count($cells) !== 3 || $cells[1] === 'id' || strpos($cells[0], '---') === 0) continue;
        $summaries[$cells[1]] = $cells[2];
    }
    return $summaries;
}

/**
 * Split the Grammar section into per-point bodies, keyed by the id carried in
 * each heading's HTML comment. The body is the full markdown for that point,
 * which is exactly what gets sent to the model as context.
 */
function parseGrammarBodies(string $body): array {
    if (!preg_match('/^## Grammar$(.*?)^## Vocabulary$/ms', $body, $m)) return [];

    $bodies = [];
    // Split on ### headings, keeping the heading with its content.
    $parts = preg_split('/^(?=### )/m', $m[1]);
    foreach ($parts as $part) {
        if (!preg_match('/<!-- id: (\S+) -->/', $part, $idMatch)) continue;
        $bodies[$idMatch[1]] = trim($part);
    }
    return $bodies;
}

function parseChapterFile(string $path): ?array {
    $text = @file_get_contents($path);
    if ($text === false) return null;
    if (!preg_match('/^---\n(.*?)\n---\n(.*)$/s', $text, $m)) return null;

    $meta = parseFrontmatter($m[1]);
    $body = $m[2];
    $summaries = parseOverviewSummaries($body);
    $bodies = parseGrammarBodies($body);

    $grammar = [];
    foreach ($meta['grammar'] as $point) {
        $grammar[] = [
            'id' => $point['id'],
            'name' => $point['name'],
            'summary' => $summaries[$point['id']] ?? '',
            'body' => $bodies[$point['id']] ?? '',
        ];
    }

    return [
        'book' => $meta['book'] ?? 0,
        'lesson' => $meta['lesson'] ?? 0,
        'slug' => $meta['slug'] ?? '',
        'title' => $meta['title'] ?? '',
        'title_jp' => $meta['title_jp'] ?? '',
        'vocab_count' => $meta['vocab_count'] ?? 0,
        'kanji_count' => $meta['kanji_count'] ?? 0,
        'grammar' => $grammar,
    ];
}

/** All chapters, ordered by book then lesson. */
function loadChapters(): array {
    $chapters = [];
    // Not l*.md, which would also match the generated l<NN>.vocab.md companions.
    foreach (glob(__DIR__ . '/../chapters/g*/l[0-9][0-9].md') as $path) {
        $chapter = parseChapterFile($path);
        if ($chapter) $chapters[] = $chapter;
    }

    usort($chapters, function ($a, $b) {
        return [$a['book'], $a['lesson']] <=> [$b['book'], $b['lesson']];
    });
    return $chapters;
}

/**
 * The grammar bodies for a set of ids, in the order the chapters define them
 * rather than the order they were clicked. Unknown ids are dropped.
 */
function collectGrammar(array $chapters, array $ids): array {
    $wanted = array_flip($ids);
    $found = [];
    foreach ($chapters as $chapter) {
        foreach ($chapter['grammar'] as $point) {
            if (!isset($wanted[$point['id']])) continue;
            $point['lesson'] = $chapter['lesson'];
            $point['book'] = $chapter['book'];
            $point['chapter_title'] = $chapter['title'];
            $found[] = $point;
        }
    }
    return $found;
}

/** The word lists, when they have been generated locally. */
function loadVocabForLessons(array $chapters, array $lessons): string {
    $out = [];
    foreach ($chapters as $chapter) {
        if (!in_array($chapter['lesson'], $lessons, true)) continue;
        $path = sprintf(
            '%s/../chapters/g%d/l%02d.vocab.md',
            __DIR__, $chapter['book'], $chapter['lesson']
        );
        $text = @file_get_contents($path);
        if ($text !== false) $out[] = $text;
    }
    return implode("\n\n", $out);
}
