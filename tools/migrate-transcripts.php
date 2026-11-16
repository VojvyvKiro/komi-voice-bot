<?php
/** CLI only. Add per-recording transcripts; safe to run more than once. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../db.php';
db()->exec('ALTER TABLE recordings ADD COLUMN IF NOT EXISTS transcript TEXT NULL');
echo "Поле текста аудиозаписи готово.\n";
