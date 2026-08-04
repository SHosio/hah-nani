<?php
// SQLite schema and queries. Everything the app persists lives here.

function initDb(string $dbDir): SQLite3 {
    if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
    $db = new SQLite3($dbDir . '/flashcards.db');

    $db->exec('CREATE TABLE IF NOT EXISTS mastered_cards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        card_hash TEXT UNIQUE NOT NULL,
        mastered_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    // Every generated study artifact, with the inputs that produced it so it
    // can be reopened or regenerated later.
    $db->exec('CREATE TABLE IF NOT EXISTS generations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL,
        title TEXT NOT NULL,
        prompt TEXT NOT NULL DEFAULT "",
        grammar_ids TEXT NOT NULL DEFAULT "[]",
        model TEXT NOT NULL DEFAULT "",
        content TEXT NOT NULL DEFAULT "",
        deck_file TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_generations_created
               ON generations (created_at DESC)');

    return $db;
}

function getMasteredHashes(SQLite3 $db): array {
    $hashes = [];
    $result = $db->query('SELECT card_hash FROM mastered_cards');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $hashes[] = $row['card_hash'];
    }
    return $hashes;
}

function toggleMastered(SQLite3 $db, string $hash): bool {
    $stmt = $db->prepare('SELECT id FROM mastered_cards WHERE card_hash = :hash');
    $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
    $exists = $stmt->execute()->fetchArray();

    $sql = $exists
        ? 'DELETE FROM mastered_cards WHERE card_hash = :hash'
        : 'INSERT INTO mastered_cards (card_hash) VALUES (:hash)';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
    $stmt->execute();

    return !$exists;
}

function saveGeneration(SQLite3 $db, array $gen): int {
    $stmt = $db->prepare('INSERT INTO generations
        (kind, title, prompt, grammar_ids, model, content, deck_file)
        VALUES (:kind, :title, :prompt, :ids, :model, :content, :deck)');
    $stmt->bindValue(':kind', $gen['kind'], SQLITE3_TEXT);
    $stmt->bindValue(':title', $gen['title'], SQLITE3_TEXT);
    $stmt->bindValue(':prompt', $gen['prompt'], SQLITE3_TEXT);
    $stmt->bindValue(':ids', json_encode($gen['grammar_ids']), SQLITE3_TEXT);
    $stmt->bindValue(':model', $gen['model'], SQLITE3_TEXT);
    $stmt->bindValue(':content', $gen['content'], SQLITE3_TEXT);
    $stmt->bindValue(':deck', $gen['deck_file'] ?? null, SQLITE3_TEXT);
    $stmt->execute();
    return $db->lastInsertRowID();
}

/** Library listing. Content is omitted, since it can be large. */
function listGenerations(SQLite3 $db): array {
    $rows = [];
    $result = $db->query('SELECT id, kind, title, grammar_ids, model, deck_file, created_at
                          FROM generations ORDER BY created_at DESC, id DESC');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['grammar_ids'] = json_decode($row['grammar_ids'], true) ?: [];
        $rows[] = $row;
    }
    return $rows;
}

function getGeneration(SQLite3 $db, int $id): ?array {
    $stmt = $db->prepare('SELECT * FROM generations WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    $row['grammar_ids'] = json_decode($row['grammar_ids'], true) ?: [];
    return $row;
}

function deleteGeneration(SQLite3 $db, int $id): bool {
    $stmt = $db->prepare('SELECT deck_file FROM generations WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) return false;

    // A generated deck is a real CSV in cards/. Remove it with its record so
    // the deck picker does not keep offering a deck the library no longer has.
    if (!empty($row['deck_file'])) {
        $path = __DIR__ . '/../cards/' . basename($row['deck_file']);
        if (is_file($path)) unlink($path);
    }

    $stmt = $db->prepare('DELETE FROM generations WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    return true;
}
