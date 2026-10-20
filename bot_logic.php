<?php
/** FU-Lab: диалог, кнопки, приём голосовых. */
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/vk_api.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/ui.php';

function bot_handle_message(array $msg): void
{
    $fromId = (int)($msg['from_id'] ?? 0);
    $peerId = (int)($msg['peer_id'] ?? $fromId);
    if ($fromId <= 0 || $fromId >= 2000000000 || $peerId !== $fromId) return;
    $lock = 'fulab_user_' . substr(hash('sha256', DB_NAME . ':' . $fromId), 0, 32);
    if ((int)db_val('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) throw new RuntimeException('Speaker busy');
    try {
        bot_process_message($msg, $fromId);
    } finally {
        db_val('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

function bot_process_message(array $msg, int $peerId): void
{
    $eventKey = (string)($msg['_event_key'] ?? '');
    if ($eventKey === '') throw new RuntimeException('Missing message identity');
    if (db_val('SELECT 1 FROM bot_events WHERE event_key = ?', [$eventKey])) return;
    $speaker = speaker_get_by_vk($peerId);
    if (!$speaker) {
        speaker_create($peerId);
        $speaker = speaker_get_by_vk($peerId);
    }
    if (!$speaker) throw new RuntimeException('Cannot create speaker');

    // Если процесс завершился после сохранения аудио, повтор Callback не пишет
    // его к следующей фразе. Можно восстановить выдачу следующей фразы.
    if (db_val('SELECT 1 FROM recordings WHERE event_key = ?', [$eventKey])) {
        assign_next_phrase((int)$speaker['id'], $peerId, false);
    } else {
        bot_dialog($speaker, $msg, $eventKey);
    }
    db()->prepare('INSERT IGNORE INTO bot_events (event_key) VALUES (?)')->execute([$eventKey]);
}

function bot_dialog(array $speaker, array $msg, string $eventKey): void
{
    $id = (int)$speaker['id'];
    $peerId = (int)$speaker['vk_id'];
    $text = trim((string)($msg['text'] ?? ''));
    $state = $speaker['state'];
    if ($text === 'Заводитны') $text = '/start';
    if (in_array($text, ['Сет мӧд фраза', 'Водзӧ'], true)) $text = '/skip';
    if (in_array($text, ['Чышкыны', 'Лыддьыны выльысь', 'Выльысь'], true)) $text = '/undo';
    if ($text === 'Бӧр' && in_array($state, ['active', 'waiting'], true)) {
        $text = '/undo';
    }
    if ($text === 'Вежны подув юӧр') $text = '/region';

    if (in_array($text, ['/region', '/dialect'], true)) {
        $editing = (int)$speaker['consent'] === 1;
        // При изменении профиля сохраняем текущую фразу и прежний район.
        if (!$editing) phrase_release($id);
        speaker_update($id, ['state' => $editing ? 'region_edit' : 'region']);
        ask_region($peerId, $editing);
        return;
    }
    if ($text === 'Бӧр' && in_array($state, ['region', 'region_edit'], true)
        && (int)$speaker['consent'] === 1) {
        speaker_update($id, ['state' => 'active']);
        assign_next_phrase($id, $peerId, false);
        return;
    }
    if ($text === '/help') {
        vk_send($peerId, "/start — заводитны / водзӧ\n/skip — мӧд сёрникузя\n/region — чужан район\n\n" .
            "Лыддьы сёрникузясӧ да ысты аудиоюӧрӧн.",
            (int)$speaker['consent'] === 1 ? active_keyboard($peerId) : keyboard([['Заводитны']]));
        return;
    }
    if ($state === 'new' || ($state === 'declined' && $text === '/start')) {
        speaker_update($id, ['state' => 'region']);
        vk_send($peerId, 'Чолӧм! Тайӧ коми сёрни чукӧртысь бот.');
        ask_region($peerId);
        return;
    }
    if ($state === 'dialect') {
        speaker_update($id, ['state' => 'region']);
        ask_region($peerId);
        return;
    }
    if (in_array($state, ['region', 'region_edit'], true)) {
        $region = normalize_region($text);
        if ($region === null) {
            ask_region($peerId, (int)$speaker['consent'] === 1);
            return;
        }
        speaker_update($id, ['region' => $region]);
        if ((int)$speaker['consent'] === 1) {
            speaker_update($id, ['state' => 'active']);
            assign_next_phrase($id, $peerId, false);
        } else {
            speaker_update($id, ['state' => 'consent']);
            ask_consent($peerId);
        }
        return;
    }
    if ($state === 'consent') {
        $answer = mb_strtolower($text);
        if (in_array($answer, ['лэдза', 'да', 'yes', '1'], true)) {
            enrich_speaker_from_vk($speaker);
            speaker_update($id, ['consent' => 1, 'state' => 'active']);
            assign_next_phrase($id, $peerId, true);
        } elseif (in_array($answer, ['ог лэдз', 'нет', 'no', '2'], true)) {
            phrase_release($id);
            speaker_update($id, ['consent' => 0, 'state' => 'declined']);
            vk_send($peerId, 'Бур. Нинӧм ог гижӧй. Кӧсъян кӧ заводитны выльысь — гиж /start.', keyboard([['Заводитны']]));
        } else {
            ask_consent($peerId);
        }
        return;
    }
    if ((int)$speaker['consent'] !== 1 || $state === 'declined') {
        vk_send($peerId, 'Заводитӧм могысь гиж /start.', keyboard([['Заводитны']]));
        return;
    }
    if ($text === '/undo') {
        $expectedId = null;
        if (isset($msg['payload'])) {
            $payload = is_string($msg['payload']) ? json_decode($msg['payload'], true) : null;
            // Невалидная/устаревшая кнопка не должна отменять другую запись.
            $expectedId = is_array($payload) && ($payload['action'] ?? '') === 'undo_last'
                && is_int($payload['recording_id'] ?? null) && $payload['recording_id'] > 0
                ? $payload['recording_id'] : 0;
        }
        $result = recording_undo_last($id, $expectedId, $eventKey);
        if ($result === 'duplicate') return;
        if ($result === 'retry') {
            $phrase = pending_phrase_for($id);
            if ($phrase) {
                vk_send(
                    $peerId,
                    "«" . $phrase['text'] . "»\n\u{2800}\n\u{2800}\n\u{2800}\n☼ ☼ ☼",
                    active_keyboard($peerId)
                );
                return;
            }
            $result = 'unavailable';
        }
        vk_send($peerId, $result === 'unavailable'
            ? 'Аудиоюӧрыс бракын. Тайӧ сёрникузясӧ ӧні оз позь гижны выльысь. Видзӧдлы сёрӧнджык: /undo.'
            : 'Чышкан аудиоюӧр абу.', active_keyboard($peerId));
        return;
    }
    foreach ($msg['attachments'] ?? [] as $att) {
        if (($att['type'] ?? '') === 'audio_message') {
            handle_voice($speaker, $att['audio_message'], $eventKey);
            return;
        }
    }
    if ($text === '/skip') phrase_release($id, true);
    assign_next_phrase($id, $peerId, false);
}

function assign_next_phrase(int $speakerId, int $peerId, bool $firstTime): void
{
    $phrase = phrase_next($speakerId);
    if (!$phrase) {
        vk_send($peerId, 'Ӧні выль сёрникузяяс абуӧсь. Аттьӧ! Гиж /start сёрӧнджык.', active_keyboard($peerId));
        return;
    }
    show_phrase($peerId, $phrase);
}

function handle_voice(array $speaker, array $audio, string $eventKey): void
{
    $id = (int)$speaker['id'];
    $peerId = (int)$speaker['vk_id'];
    $phrase = pending_phrase_for($id);
    if (!$phrase) {
        // Не прикрепляем старое аудио к фразе, которую человек ещё не видел.
        vk_send($peerId, 'Аудиоюӧрыс эз видзсьы. Лыддьы выль сёрникузясӧ да ысты выль аудиоюӧр.');
        assign_next_phrase($id, $peerId, false);
        return;
    }
    $duration = (float)($audio['duration'] ?? 0);
    if (!duration_ok($duration)) {
        vk_send($peerId, 'Аудиоюӧрлӧн кузьтаыс мед лоӧ ' . MIN_DURATION_SEC . ' секундсянь ' . MAX_DURATION_SEC .
            ' секундӧдз. Видзӧдлы нӧшта ӧтчыд.', active_keyboard($peerId));
        return;
    }
    $link = (string)($audio['link_ogg'] ?? '');
    if ($link === '') {
        vk_send($peerId, 'Аудиоюӧр чӧвтігӧн лоис техника кузя торксьӧм. Ысты нӧшта ӧтчыд.', active_keyboard($peerId));
        return;
    }
    $file = save_audio($id, (int)$phrase['id'], $link);
    if ($file === null) {
        vk_send($peerId, 'Эз артмы видзны аудиоюӧртӧ. Ысты нӧшта ӧтчыд.', active_keyboard($peerId));
        return;
    }
    $isFirstRecording = !db_val('SELECT 1 FROM recordings WHERE speaker_id = ? LIMIT 1', [$id]);
    if (!recording_commit($id, (int)$phrase['id'], $file, $duration, $eventKey)) {
        unlink(UPLOAD_DIR . '/' . $file);
        vk_send($peerId, 'Аудиоюӧрыс эз видзсьы. Лыддьы выль сёрникузясӧ да ысты выль аудиоюӧр.');
    } elseif ($isFirstRecording) {
        vk_send($peerId, 'Бур! Мунам водзӧ:');
    }
    assign_next_phrase($id, $peerId, false);
}
/** Пол и возраст из VK-профиля после согласия. */
function enrich_speaker_from_vk(array $speaker): void
{
    $u = vk_user_info((int)$speaker['vk_id']);

    if (!$u) {
        return;
    }

    $fields = [];
    $sex = (int)($u['sex'] ?? 0);

    if ($sex === 1) {
        $fields['gender'] = 'female';
    }

    if ($sex === 2) {
        $fields['gender'] = 'male';
    }

    $bdate = (string)($u['bdate'] ?? '');

    if (preg_match('/^\d+\.\d+\.(\d{4})$/', $bdate, $m)) {
        $age = (int)date('Y') - (int)$m[1];

        if ($age >= 5 && $age <= 100) {
            $fields['age'] = $age;
        }
    }

    if ($fields) {
        speaker_update((int)$speaker['id'], $fields);
    }
}
