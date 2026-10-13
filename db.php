<?php
/**
 * Подключение к БД + вспомогательные запросы
 */
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** Одно значение или null */
function db_val(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_NUM);
    return $row ? $row[0] : null;
}

/* ─────────── speakers ─────────── */

function speaker_get_by_vk(int $vkId): ?array
{
    $st = db()->prepare('SELECT * FROM speakers WHERE vk_id = ? LIMIT 1');
    $st->execute([$vkId]);
    return $st->fetch() ?: null;
}

function speaker_create(int $vkId): int
{
    db()->prepare('INSERT INTO speakers (vk_id, state, created_at) VALUES (?, "new", NOW()) ON DUPLICATE KEY UPDATE vk_id = VALUES(vk_id)')
        ->execute([$vkId]);
    return (int)db()->lastInsertId();
}

function speaker_update(int $id, array $fields): void
{
    $sets = [];
    foreach (array_keys($fields) as $k) $sets[] = "`$k` = ?";
    $vals = array_values($fields);
    $vals[] = $id;
    db()->prepare('UPDATE speakers SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}

/* ─────────── phrases ─────────── */

function phrase_bump(int $phraseId, int $delta = 1): void
{
    db()->prepare('UPDATE phrases SET done_count = GREATEST(0, done_count + ?) WHERE id = ?')
        ->execute([$delta, $phraseId]);
}

/* ─────────── recordings ─────────── */

function recording_add(int $speakerId, int $phraseId, string $file, float $duration): int
{
    db()->prepare(
        'INSERT INTO recordings (speaker_id, phrase_id, file, format, duration, status, created_at)
         VALUES (?, ?, ?, "ogg", ?, "uploaded", NOW())'
    )->execute([$speakerId, $phraseId, $file, $duration]);
    return (int)db()->lastInsertId();
}

