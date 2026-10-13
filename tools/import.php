<?php
/** php tools/import.php /path/to/phrases.txt [source] */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../db.php';
$path = $argv[1] ?? '';
$source = $argv[2] ?? basename($path);
$raw = $path !== '' ? file_get_contents($path) : false;
if ($raw === false || !mb_check_encoding($raw, 'UTF-8')) {
    fwrite(STDERR, "Нужен текстовый файл UTF-8, одна фраза на строку.\n");
    exit(1);
}
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
$lines = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', $raw)),
    static fn($text) => $text !== '')));
if (!$lines) exit("Файл пуст.\n");
if (mb_strlen($source) > 100) exit("Название источника длиннее 100 знаков.\n");
foreach ($lines as $line) {
    if (strlen($line) > 65535) exit("Одна из строк не помещается в поле TEXT.\n");
}
$lock = 'fulab_import_' . substr(hash('sha256', DB_NAME), 0, 24);
if ((int)db_val('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) exit("Другой импорт уже идёт.\n");
try {
    db()->beginTransaction();
    $known = [];
    foreach (db()->query('SELECT text FROM phrases') as $row) $known[$row['text']] = true;
    $insert = db()->prepare('INSERT INTO phrases (text, source, required_times) VALUES (?, ?, 1)');
    $added = 0;
    foreach ($lines as $text) {
        if (isset($known[$text])) continue;
        $insert->execute([$text, $source]);
        $known[$text] = true;
        $added++;
    }
    db()->commit();
    echo 'Прочитано: ', count($lines), '. Добавлено: ', $added, ". Старые записи сохранены.\n";
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    throw $e;
} finally {
    db_val('SELECT RELEASE_LOCK(?)', [$lock]);
}
