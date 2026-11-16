<?php
/** Called only after authentication and CSRF validation in _init.php. */
function recording_save_transcript(int $id, $text, $version): void
{
    if (!is_string($text) || !is_string($version) || $id < 1) {
        throw new InvalidArgumentException('Некорректные данные записи.');
    }
    if (!preg_match('//u', $text)) {
        throw new InvalidArgumentException('Текст должен быть в UTF-8.');
    }
    $text = preg_replace('/^\s+|\s+$/u', '', $text);
    if ($text === '' || strlen($text) > 65535) {
        throw new InvalidArgumentException('Введите непустой текст длиной не более 65535 байт. Вернитесь назад, чтобы исправить его.');
    }
    queue_locked(function () use ($id, $text, $version) {
        $st = db()->prepare('SELECT r.transcript, p.text AS phrase_text
            FROM recordings r JOIN phrases p ON p.id = r.phrase_id
            WHERE r.id = ? FOR UPDATE');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) throw new DomainException('Запись больше не существует.');
        $current = $row['transcript'] ?? $row['phrase_text'];
        if (!hash_equals(hash('sha256', $current), $version)) {
            throw new DomainException('Текст уже изменён другим проверяющим. Откройте список в новой вкладке и сравните изменения перед повторным сохранением.');
        }
        // Do not change the shared prompt, review status, quota or validations.
        db()->prepare('UPDATE recordings SET transcript = ? WHERE id = ?')
            ->execute([$text, $id]);
    });
}
