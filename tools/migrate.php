<?php
/** CLI only. Идемпотентная миграция; web должен быть остановлен. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../db.php';
$pdo = db();
$pdo->exec('CREATE TABLE IF NOT EXISTS bot_migrations (
    name VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');
$name = '20261001_feedback_v1';
if (db_val('SELECT 1 FROM bot_migrations WHERE name = ?', [$name])) {
    echo "Миграция уже применена.\n";
    exit;
}
// MariaDB DDL делает implicit commit. Все DDL допускают повторный запуск.
$pdo->exec('ALTER TABLE speakers ADD COLUMN IF NOT EXISTS region VARCHAR(200) NULL');
$pdo->exec('ALTER TABLE recordings ADD COLUMN IF NOT EXISTS event_key VARCHAR(100) NULL');
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_recording_event ON recordings(event_key)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_rec_speaker_phrase ON recordings(speaker_id, phrase_id, status)');
$pdo->exec('CREATE TABLE IF NOT EXISTS phrase_reservations (
    speaker_id INT PRIMARY KEY, phrase_id INT NOT NULL, expires_at DATETIME NOT NULL,
    INDEX idx_res_phrase (phrase_id), INDEX idx_res_expiry (expires_at),
    FOREIGN KEY (speaker_id) REFERENCES speakers(id) ON DELETE CASCADE,
    FOREIGN KEY (phrase_id) REFERENCES phrases(id) ON DELETE CASCADE
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS phrase_skips (
    speaker_id INT NOT NULL, phrase_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (speaker_id, phrase_id),
    FOREIGN KEY (speaker_id) REFERENCES speakers(id) ON DELETE CASCADE,
    FOREIGN KEY (phrase_id) REFERENCES phrases(id) ON DELETE CASCADE
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS bot_events (
    event_key VARCHAR(100) PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS phrase_quota_before_feedback (
    phrase_id INT PRIMARY KEY, required_times INT NOT NULL
) ENGINE=InnoDB');
$pdo->exec('ALTER TABLE phrases ALTER COLUMN required_times SET DEFAULT 1');
$pdo->beginTransaction();
try {
    $pdo->exec('INSERT IGNORE INTO phrase_quota_before_feedback SELECT id, required_times FROM phrases');
    $pdo->exec('UPDATE phrases SET required_times = 1 WHERE required_times > 0');
    // Сохраняем уже выданные задания там, где это совместимо с квотой.
    $pending = $pdo->query("SELECT s.id, s.last_phrase_id FROM speakers s
        JOIN phrases p ON p.id = s.last_phrase_id
        WHERE s.state = 'active' AND s.consent = 1 AND p.required_times > 0
          AND NOT EXISTS (SELECT 1 FROM recordings r WHERE r.phrase_id = p.id AND r.status <> 'rejected')
        ORDER BY s.id")->fetchAll();
    $seen = [];
    $insert = $pdo->prepare('INSERT INTO phrase_reservations (speaker_id, phrase_id, expires_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))');
    foreach ($pending as $r) {
        if (isset($seen[$r['last_phrase_id']])) continue;
        $seen[$r['last_phrase_id']] = true;
        $insert->execute([$r['id'], $r['last_phrase_id']]);
    }
    $pdo->exec('UPDATE speakers s LEFT JOIN phrase_reservations q ON q.speaker_id = s.id
        SET s.last_phrase_id = q.phrase_id');
    $pdo->prepare('INSERT INTO bot_migrations (name) VALUES (?)')->execute([$name]);
    if ($pdo->inTransaction()) $pdo->commit();
    echo "Миграция завершена. Активная квота: 1. Записи, тексты и диалекты сохранены.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
