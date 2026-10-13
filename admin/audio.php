<?php
/**
 * Админка: отдача аудиофайла (доступ только после Basic Auth через _init.php)
 */
require_once __DIR__ . '/_init.php';

$f = $_GET['f'] ?? '';
if ($f === '') { http_response_code(400); exit('Файл абу индӧма.'); }

// Защита от path traversal
$f = str_replace('\\', '/', $f);
if (strpos($f, '..') !== false || strpos($f, "\0") !== false) {
    http_response_code(400); exit('Файлӧдз туйыс абу стӧч.');
}

$abs = UPLOAD_DIR . '/' . $f;
$real = realpath($abs);
$root = realpath(UPLOAD_DIR);
if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404); exit('Файлыс эз сюр.');
}
if (!is_file($real)) { http_response_code(404); exit('Файлыс эз сюр.'); }

$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$mime = $ext === 'mp3' ? 'audio/mpeg' : 'audio/ogg';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Accept-Ranges: none');
readfile($real);
exit;
