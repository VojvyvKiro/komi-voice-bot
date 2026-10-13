<?php
/**
 * Админка: экспорт датасета в CSV
 * Связка audio + текст + метаданные диктора — для TTS/STT
 */
require_once __DIR__ . '/_init.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="komi_dataset_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
// BOM для Excel
fwrite($out, "\xEF\xBB\xBF");

// Заголовок
fputcsv($out, [
    'recording_id', 'audio_path', 'phrase_id', 'text', 'duration_sec',
    'speaker_id', 'vk_id', 'age', 'gender', 'dialect', 'region',
], ';');

$sql = "SELECT r.id, r.file, r.duration, r.phrase_id,
               p.text, s.id AS speaker_id, s.vk_id, s.age, s.gender, s.dialect, s.region
        FROM recordings r
        JOIN phrases  p ON p.id = r.phrase_id
        JOIN speakers s ON s.id = r.speaker_id
        WHERE r.status = 'approved'
        ORDER BY r.id";
$st = db()->prepare($sql);
$st->execute();
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        $row['id'],
        $row['file'],
        $row['phrase_id'],
        $row['text'],
        $row['duration'],
        $row['speaker_id'],
        $row['vk_id'],
        $row['age'],
        $row['gender'],
        $row['dialect'],
        $row['region'],
    ], ';');
}
fclose($out);
exit;