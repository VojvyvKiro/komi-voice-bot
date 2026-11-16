<?php
/**
 * Админка: список записей + модерация
 */
require_once __DIR__ . '/_init.php';

// ── Действия (POST) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_action_review();
    $returnStatus = $_POST['return_status'] ?? 'uploaded';
    if (!in_array($returnStatus, ['uploaded', 'approved', 'rejected', 'all'], true)) $returnStatus = 'uploaded';
    header('Location: index.php?status=' . $returnStatus . '#recording-' . (int)($_POST['recording_id'] ?? 0));
    exit;
}

// ── Данные ──────────────────────────────────────────────────
$onlyStatus = $_GET['status'] ?? 'uploaded';
$allowed = ['uploaded', 'approved', 'rejected', 'all'];
if (!in_array($onlyStatus, $allowed, true)) $onlyStatus = 'uploaded';

$where  = $onlyStatus === 'all' ? '' : 'WHERE r.status = ' . db()->quote($onlyStatus);
$sql = "SELECT r.id, r.file, r.duration, r.status, r.created_at,
               p.text AS phrase_text, p.id AS phrase_id, r.transcript,
               s.vk_id, s.age, s.gender, s.dialect, s.region, s.consent
        FROM recordings r
        JOIN phrases p ON p.id = r.phrase_id
        JOIN speakers s ON s.id = r.speaker_id
        $where
        ORDER BY r.id DESC
        LIMIT 300";
$rows = db()->query($sql)->fetchAll();

$stats = db()->query(
    "SELECT status, COUNT(*) c FROM recordings GROUP BY status"
)->fetchAll();
$cnt = [];
foreach ($stats as $st) $cnt[$st['status']] = (int)$st['c'];
$cntAll = array_sum($cnt);

$statusLabels = [
    'uploaded' => 'Видлалӧм вылӧ',
    'approved' => 'Лӧсялӧ',
    'rejected' => 'Оз лӧсяв',
];
$genderLabels = [
    'male' => 'Ай',
    'female' => 'Ань',
    'other' => 'Мӧд',
    'undeclared' => 'Абу индӧма',
];
$dialectLabels = [
    'сысольский' => 'Сыктывса',
    'ижемский' => 'Изьваса',
    'верхневычегодский' => 'Вылыс эжваса',
    'другой' => 'Мӧд',
];

?>
<!doctype html>
<html lang="kv">
<head>
<meta charset="utf-8">
<title>FU-Lab — аудиоюӧръяс видлалӧм</title>
<style>
  body { font-family: -apple-system, 'Segoe UI', sans-serif; margin: 24px; background: #f6f7f9; color: #1d2733; }
  h1 { font-size: 20px; }
  .tabs a { display: inline-block; padding: 6px 12px; margin-right: 6px; border-radius: 6px; text-decoration: none; color: #244; background: #e2e7ee; }
  .tabs a.active { background: #2b5f9e; color: #fff; }
  table { border-collapse: collapse; width: 100%; margin-top: 14px; background: #fff; }
  th, td { padding: 8px 10px; border-bottom: 1px solid #e3e7ec; font-size: 14px; text-align: left; vertical-align: top; }
  th { background: #eef1f5; font-weight: 600; }
  .badge { padding: 2px 8px; border-radius: 10px; font-size: 12px; }
  .uploaded { background: #fff3cd; color: #664d03; }
  .approved { background: #d1e7dd; color: #0f5132; }
  .rejected { background: #f8d7da; color: #842029; }
  audio { height: 32px; }
  .btn { border: 0; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 13px; }
  .ok { background: #198754; color: #fff; }
  .rej { background: #dc3545; color: #fff; }
  form { display: inline; }
  .edit-text { margin: 0; }
  .edit-text summary { cursor: text; list-style: none; white-space: pre-wrap; border-bottom: 1px dashed #aab5c2; }
  .edit-text summary::-webkit-details-marker { display: none; }
  .edit-text[open] summary { display: none; }
  .edit-text form { display: block; margin-top: 8px; }
  .edit-text textarea { display: block; box-sizing: border-box; width: 100%; min-width: 220px; min-height: 100px; margin: 6px 0; font: inherit; }
  .original-text { display: block; margin-top: 8px; color: #667; }
  .save { background: #2b5f9e; color: #fff; }
</style>
</head>
<body>
<h1>Аудиоюӧръяс видлалӧм</h1>
<p>
  Став аудиоюӧрыс: <b><?= $cntAll ?></b> —
  Видлалӧм вылӧ: <b><?= $cnt['uploaded'] ?? 0 ?></b>,
  Лӧсяланаӧсь: <b><?= $cnt['approved'] ?? 0 ?></b>,
  Оз лӧсявны: <b><?= $cnt['rejected'] ?? 0 ?></b>
</p>

<div class="tabs">
  <a href="phrases.php">Сёрникузяяс</a>
  <a class="<?= $onlyStatus === 'uploaded' ? 'active' : '' ?>" href="?status=uploaded">Видлалӧм вылӧ</a>
  <a class="<?= $onlyStatus === 'approved' ? 'active' : '' ?>" href="?status=approved">Лӧсяланаӧсь</a>
  <a class="<?= $onlyStatus === 'rejected' ? 'active' : '' ?>" href="?status=rejected">Оз лӧсявны</a>
  <a class="<?= $onlyStatus === 'all' ? 'active' : '' ?>" href="?status=all">Ставыс</a>
  <a href="export.php">Сӧвтны CSV файл</a>
</div>

<table>
  <tr>
    <th>ID</th><th>Аудио</th><th>Сёрникузя</th><th>Лыддьысь</th><th>Чужан район / сёрнисикас</th><th>Видлалӧм</th><th>Кад</th><th>Вӧчӧмъяс</th>
  </tr>
<?php if (!$rows): ?>
  <tr><td colspan="8">Аудиоюӧръяс абуӧсь.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
  <?php $recordingText = $r['transcript'] ?? $r['phrase_text']; ?>
  <tr id="recording-<?= (int)$r['id'] ?>">
    <td><?= (int)$r['id'] ?></td>
    <td>
      <audio controls preload="none" src="audio.php?f=<?= rawurlencode($r['file']) ?>"></audio>
      <div style="font-size:11px;color:#888"><?= e($r['file']) ?> (<?= (float)$r['duration'] ?> сек.)</div>
    </td>
    <td style="max-width:320px">
      <details class="edit-text">
        <summary title="Нажмите, чтобы исправить текст"><?= e($recordingText) ?></summary>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($adminCsrf) ?>">
          <input type="hidden" name="recording_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="return_status" value="<?= e($onlyStatus) ?>">
          <input type="hidden" name="text_version" value="<?= e(hash('sha256', $recordingText)) ?>">
          <label for="transcript-<?= (int)$r['id'] ?>">Аудиоюӧрлӧн текст</label>
          <textarea id="transcript-<?= (int)$r['id'] ?>" name="transcript" rows="4" required><?= e($recordingText) ?></textarea>
          <button class="btn save" name="action" value="save_transcript">Видзны</button>
          <button class="btn" type="button" onclick="this.form.reset(); this.closest('details').open = false;">Отмена</button>
        </form>
      </details>
      <?php if ($r['transcript'] !== null && $r['transcript'] !== $r['phrase_text']): ?>
        <small class="original-text">Медводдза текст: <?= e($r['phrase_text']) ?></small>
      <?php endif; ?>
    </td>
    <td>
      vk <?= (int)$r['vk_id'] ?><br>
      <?= e($genderLabels[$r['gender']] ?? $r['gender']) ?>, <?= $r['age'] !== null ? (int)$r['age'] . ' арӧс' : '—' ?>
      <?= ((int)$r['consent'] === 1) ? '' : '<br><b style="color:#b02a37">Лэдзӧм абу!</b>' ?>
    </td>
    <td><?= e($r['region'] ?? '—') ?><br><small><?= e($dialectLabels[$r['dialect'] ?? ''] ?? (string)$r['dialect']) ?></small></td>
    <td><span class="badge <?= e($r['status']) ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
    <td><?= e($r['created_at']) ?></td>
    <td>
      <?php if ($r['status'] === 'uploaded'): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($adminCsrf) ?>">
          <input type="hidden" name="recording_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="return_status" value="<?= e($onlyStatus) ?>">
          <button class="btn ok" name="action" value="approve">Лӧсялӧ</button>
          <button class="btn rej" name="action" value="reject">Оз лӧсяв</button>
        </form>
      <?php endif; ?>

    </td>
  </tr>
<?php endforeach; ?>
</table>
<script>
document.querySelectorAll('.edit-text').forEach(editor => {
  editor.addEventListener('toggle', () => {
    if (editor.open) editor.querySelector('textarea').focus();
  });
});
</script>
</body>
</html>

