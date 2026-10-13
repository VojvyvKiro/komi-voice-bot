<?php
/**
 * Утилиты: логирование, сохранение аудио, мелочи
 */
require_once __DIR__ . '/config.php';

function log_line(string $msg): void
{
    $line = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/bot.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Сохранить ogg-файл: uploads/recordings/{speaker_id}/{phrase_id}_{uniqid}.ogg
 * Возвращает относительный путь (для БД) или null при ошибке.
 */
function save_audio(int $speakerId, int $phraseId, string $url): ?string
{
    $dir = UPLOAD_DIR . '/' . $speakerId;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        log_line("ERROR: cannot mkdir $dir");
        return null;
    }
    $fname = $phraseId . '_' . bin2hex(random_bytes(6)) . '.ogg';
    $path  = $dir . '/' . $fname;

    if (!vk_download($url, $path)) {
        log_line("ERROR: download failed sp=$speakerId ph=$phraseId");
        return null;
    }
    // Базовая проверка, что это OggS-контейнер
    $head = @file_get_contents($path, false, null, 0, 4);
    if ($head !== 'OggS') {
        unlink($path);
        log_line("ERROR: not an ogg file sp=$speakerId ph=$phraseId");
        return null;
    }
    return $speakerId . '/' . $fname;
}

/** Валидация длительности голосового */
function duration_ok(float $duration): bool
{
    return $duration >= MIN_DURATION_SEC && $duration <= MAX_DURATION_SEC;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}