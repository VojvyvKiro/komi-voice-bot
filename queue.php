<?php
/** Очередь: короткие операции сериализованы блокировкой MariaDB. */
require_once __DIR__ . '/db.php';

function queue_locked(callable $work)
{
    $name = 'fulab_queue_' . substr(hash('sha256', DB_NAME), 0, 24);
    if ((int)db_val('SELECT GET_LOCK(?, 10)', [$name]) !== 1) {
        throw new RuntimeException('Queue busy; retry callback');
    }
    try {
        db()->beginTransaction();
        $result = $work();
        db()->commit();
        return $result;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    } finally {
        db_val('SELECT RELEASE_LOCK(?)', [$name]);
    }
}

function pending_phrase_for(int $speakerId): ?array
{
    $st = db()->prepare(
        'SELECT p.* FROM phrase_reservations q JOIN phrases p ON p.id = q.phrase_id
         WHERE q.speaker_id = ? AND q.expires_at > NOW() AND p.required_times > 0'
    );
    $st->execute([$speakerId]);
    return $st->fetch() ?: null;
}

/** Выбор и закрепление — одна транзакция; квота учитывает и резервы. */
function phrase_next(int $speakerId): ?array
{
    return queue_locked(function () use ($speakerId) {
        db()->exec('DELETE FROM phrase_reservations WHERE expires_at <= NOW()');
        $pending = pending_phrase_for($speakerId);
        if ($pending) return $pending;
        db()->prepare('DELETE FROM phrase_reservations WHERE speaker_id = ?')->execute([$speakerId]);
        $st = db()->prepare(
            "SELECT p.* FROM phrases p
             WHERE p.required_times > 0
               AND NOT EXISTS (SELECT 1 FROM phrase_skips x
                   WHERE x.speaker_id = ? AND x.phrase_id = p.id)
               AND NOT EXISTS (SELECT 1 FROM recordings r
                   WHERE r.speaker_id = ? AND r.phrase_id = p.id AND r.status <> 'rejected')
               AND (SELECT COUNT(DISTINCT r.speaker_id) FROM recordings r
                   WHERE r.phrase_id = p.id AND r.status <> 'rejected')
                   + (SELECT COUNT(*) FROM phrase_reservations q WHERE q.phrase_id = p.id)
                   < p.required_times
             ORDER BY RAND() LIMIT 1"
        );
        $st->execute([$speakerId, $speakerId]);
        $phrase = $st->fetch() ?: null;
        if ($phrase) {
            db()->prepare('INSERT INTO phrase_reservations (speaker_id, phrase_id, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$speakerId, $phrase['id']]);
        }
        speaker_update($speakerId, [
            'last_phrase_id' => $phrase ? (int)$phrase['id'] : null,
            'state' => $phrase ? 'active' : 'waiting',
        ]);
        return $phrase;
    });
}

function phrase_release(int $speakerId, bool $skip = false): void
{
    queue_locked(function () use ($speakerId, $skip) {
        $p = pending_phrase_for($speakerId);
        if ($skip && $p) {
            db()->prepare('INSERT IGNORE INTO phrase_skips (speaker_id, phrase_id) VALUES (?, ?)')
                ->execute([$speakerId, $p['id']]);
        }
        db()->prepare('DELETE FROM phrase_reservations WHERE speaker_id = ?')->execute([$speakerId]);
        speaker_update($speakerId, ['last_phrase_id' => null]);
    });
}

/** Запись прикрепляется только к всё ещё закреплённой фразе. */
function recording_commit(int $speakerId, int $phraseId, string $file, float $duration, string $eventKey): bool
{
    return queue_locked(function () use ($speakerId, $phraseId, $file, $duration, $eventKey) {
        $p = pending_phrase_for($speakerId);
        if (!$p || (int)$p['id'] !== $phraseId) return false;
        $st = db()->prepare('INSERT INTO recordings
            (speaker_id, phrase_id, file, format, duration, status, created_at, event_key)
            VALUES (?, ?, ?, "ogg", ?, "uploaded", NOW(), ?)');
        $st->execute([$speakerId, $phraseId, $file, $duration, $eventKey]);
        phrase_bump($phraseId);
        db()->prepare('DELETE FROM phrase_reservations WHERE speaker_id = ?')->execute([$speakerId]);
        speaker_update($speakerId, ['last_phrase_id' => null]);
        return true;
    });
}

/** Повторное нажатие модератором не меняет счётчики повторно. */
function recording_review(int $id, string $action): void
{
    if (!in_array($action, ['approve', 'reject'], true)) return;
    queue_locked(function () use ($id, $action) {
        $st = db()->prepare('SELECT * FROM recordings WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r || $r['status'] !== 'uploaded') return;
        db()->prepare('UPDATE recordings SET status = ? WHERE id = ?')
            ->execute([$action === 'approve' ? 'approved' : 'rejected', $id]);
        if ($action === 'reject') phrase_bump((int)$r['phrase_id'], -1);
        db()->prepare('INSERT INTO validations (recording_id, validator_id, valid, created_at)
            VALUES (?, 0, ?, NOW())')->execute([$id, (int)($action === 'approve')]);
    });
}

/** Отмена только последней записи своего участника. Файл остаётся на диске. */
function recording_undo_last(int $speakerId, ?int $expectedId, string $eventKey): string
{
    return queue_locked(function () use ($speakerId, $expectedId, $eventKey) {
        if (db_val('SELECT 1 FROM bot_events WHERE event_key = ?', [$eventKey])) return 'duplicate';
        // Событие фиксируется вместе с отменой: повтор доставки не отменит новую запись.
        db()->prepare('INSERT INTO bot_events (event_key) VALUES (?)')->execute([$eventKey]);
        $st = db()->prepare('SELECT * FROM recordings WHERE speaker_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $st->execute([$speakerId]);
        $recording = $st->fetch();
        if (!$recording) return 'none';
        if ($expectedId !== null && (int)$recording['id'] !== $expectedId) return 'stale';
        $alreadyRejected = $recording['status'] === 'rejected';
        if (!$alreadyRejected) {
        db()->prepare("UPDATE recordings SET status = 'rejected' WHERE id = ?")
            ->execute([$recording['id']]);
        phrase_bump((int)$recording['phrase_id'], -1);
        db()->prepare('INSERT INTO validations (recording_id, validator_id, valid, created_at) VALUES (?, ?, 0, NOW())')
            ->execute([$recording['id'], $speakerId]);
        }

        // Старая фраза закрепляется обратно в той же транзакции, без гонки.
        db()->exec('DELETE FROM phrase_reservations WHERE expires_at <= NOW()');
        $st = db()->prepare('SELECT * FROM phrases WHERE id = ?');
        $st->execute([$recording['phrase_id']]);
        $phrase = $st->fetch();
        $used = (int)db_val("SELECT COUNT(DISTINCT speaker_id) FROM recordings
            WHERE phrase_id = ? AND status <> 'rejected'", [$recording['phrase_id']]);
        $reserved = (int)db_val('SELECT COUNT(*) FROM phrase_reservations
            WHERE phrase_id = ? AND speaker_id <> ?', [$recording['phrase_id'], $speakerId]);
        if ($phrase && (int)$phrase['required_times'] > $used + $reserved) {
            db()->prepare('DELETE FROM phrase_reservations WHERE speaker_id = ?')->execute([$speakerId]);
            db()->prepare('DELETE FROM phrase_skips WHERE speaker_id = ? AND phrase_id = ?')
                ->execute([$speakerId, $phrase['id']]);
            db()->prepare('INSERT INTO phrase_reservations (speaker_id, phrase_id, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')->execute([$speakerId, $phrase['id']]);
            speaker_update($speakerId, ['last_phrase_id' => (int)$phrase['id'], 'state' => 'active']);
            return 'retry';
        }
        // Не оставляем следующую фразу активной: голосовое для перезаписи
        // не должно случайно привязаться к другому тексту.
        db()->prepare('DELETE FROM phrase_reservations WHERE speaker_id = ?')->execute([$speakerId]);
        speaker_update($speakerId, ['last_phrase_id' => null, 'state' => 'waiting']);
        return 'unavailable';
    });
}
