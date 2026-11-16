<?php
// Isolated regression test: SQLite substitutes for MariaDB; only FOR UPDATE is removed.
require_once __DIR__ . '/../admin/_transcripts.php';
class TestDatabase extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new TestDatabase('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    return $pdo;
}
function queue_locked(callable $work) {
    db()->beginTransaction();
    try { $result = $work(); db()->commit(); return $result; }
    catch (Throwable $e) { db()->rollBack(); throw $e; }
}
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function fails(callable $work, string $class): void {
    try { $work(); } catch (Throwable $e) { check($e instanceof $class, get_class($e)); return; }
    throw new RuntimeException('Expected ' . $class);
}
db()->exec('CREATE TABLE phrases (id INTEGER PRIMARY KEY, text TEXT, done_count INTEGER)');
db()->exec('CREATE TABLE recordings (id INTEGER PRIMARY KEY, phrase_id INTEGER, transcript TEXT NULL, status TEXT)');
$original = 'Коми сёрни';
db()->prepare('INSERT INTO phrases VALUES (1, ?, 2)')->execute([$original]);
db()->exec("INSERT INTO recordings VALUES (1, 1, NULL, 'uploaded'), (2, 1, NULL, 'approved'), (3, 1, NULL, 'rejected')");
$hash = hash('sha256', $original);
recording_save_transcript(1, '  Мӧд сёрни  ', $hash);
check(db()->query('SELECT transcript FROM recordings WHERE id = 1')->fetchColumn() === 'Мӧд сёрни', 'Komi text saved');
check(db()->query('SELECT text FROM phrases')->fetchColumn() === $original, 'Shared phrase unchanged');
check(db()->query('SELECT done_count FROM phrases')->fetchColumn() == 2, 'Quota unchanged');
check(db()->query('SELECT transcript FROM recordings WHERE id = 2')->fetchColumn() === null, 'Other audio unchanged');
fails(fn() => recording_save_transcript(1, 'stale', $hash), DomainException::class);
fails(fn() => recording_save_transcript(9, 'missing', $hash), DomainException::class);
foreach (['', '  ', "\u{00a0}", str_repeat('ӧ', 32768), "\xff", []] as $bad) {
    fails(fn() => recording_save_transcript(2, $bad, $hash), InvalidArgumentException::class);
}
recording_save_transcript(2, 'Текст "quoted" <tag>', $hash);
recording_save_transcript(3, 'Текст', $hash);
check(db()->query('SELECT status FROM recordings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['uploaded', 'approved', 'rejected'], 'Statuses unchanged');
$export = db()->query('SELECT COALESCE(r.transcript,p.text) AS text FROM recordings r JOIN phrases p ON p.id=r.phrase_id ORDER BY r.id')->fetchAll(PDO::FETCH_COLUMN);
check($export === ['Мӧд сёрни', 'Текст "quoted" <tag>', 'Текст'], 'Export uses corrected text');
echo "PASS: per-recording edits, shared phrase/quota/status preservation, stale edits, missing record, validation, export.\n";
