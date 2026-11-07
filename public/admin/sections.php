<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin']);

$db = getDB();
$tz = new DateTimeZone(APP_TIMEZONE);

$sections = $db->query(
    'SELECT s.id, s.name, m.name AS semester
     FROM sections s JOIN semesters m ON m.id = s.semester_id
     ORDER BY m.start_date DESC, s.name'
)->fetchAll();
$sectionIds = array_map('intval', array_column($sections, 'id'));
$sectionName = [];
foreach ($sections as $s) {
    $sectionName[(int)$s['id']] = $s['name'] . ' (' . $s['semester'] . ')';
}

// Everything comes from the whitelist or is validated before use.
$secId = (int)($_GET['section'] ?? 0);
if (!in_array($secId, $sectionIds, true)) {
    $secId = $sectionIds[0] ?? 0;
}
$baseDate = isValidDate($_GET['d'] ?? '') ? $_GET['d'] : (new DateTime('now', $tz))->format('Y-m-d');
$monday = (new DateTime($baseDate, $tz))->modify('monday this week');
$sunday = (clone $monday)->modify('+6 days');
$from = $monday->format('Y-m-d');
$to   = $sunday->format('Y-m-d');
$prev = (clone $monday)->modify('-7 days')->format('Y-m-d');
$next = (clone $monday)->modify('+7 days')->format('Y-m-d');
$today = (new DateTime('now', $tz))->format('Y-m-d');

$grid = [];
foreach (effectiveSlots($db, $from, $to) as $s) {
    $grid[$s['section_id']][$s['date']][] = $s;
}
foreach ($grid as $sid => $days) {
    foreach ($days as $d => $list) {
        usort($list, function ($a, $b) { return strcmp($a['start'], $b['start']); });
        $grid[$sid][$d] = $list;
    }
}

// Free-or-busy checker.
$check = null;
if (isset($_GET['cdate'])) {
    $cs  = (int)($_GET['csection'] ?? 0);
    $cd  = $_GET['cdate'] ?? '';
    $cst = $_GET['cstart'] ?? '';
    $cen = $_GET['cend'] ?? '';
    if (!in_array($cs, $sectionIds, true) || !isValidDate($cd) || !isValidTime($cst) || !isValidTime($cen) || $cen <= $cst) {
        $check = ['error' => 'Choose a section, a date, and a start and end time (end after start).'];
    } else {
        $check = ['section' => $cs, 'date' => $cd, 'start' => $cst, 'end' => $cen,
                  'conflicts' => slotConflicts($db, $cs, $cd, $cst, $cen)];
    }
}

$pageTitle = 'Section schedules';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Section schedules</h1>
  <p class="date">See how many lectures each section has on each day, and whether a section is free at a given time.</p>
</div>

<?php if (!$sections): ?>
  <section class="card mt"><div class="empty">No sections yet. Add a semester and sections on Courses &amp; enrollments.</div></section>
<?php else: ?>

<section class="card form-card mt">
  <h2>Is a section free?</h2>
  <form method="get">
    <input type="hidden" name="section" value="<?= (int)$secId ?>">
    <input type="hidden" name="d" value="<?= e($baseDate) ?>">
    <div class="row2">
      <div>
        <label for="csection">Section</label>
        <select id="csection" name="csection">
          <?php foreach ($sections as $s): ?>
            <option value="<?= (int)$s['id'] ?>"<?= (int)($check['section'] ?? $secId) === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['name'] . ' (' . $s['semester'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label for="cdate">Date</label><input type="date" id="cdate" name="cdate" value="<?= e($check['date'] ?? '') ?>" required></div>
    </div>
    <div class="row2">
      <div><label for="cstart">From</label><input type="time" id="cstart" name="cstart" value="<?= e($check['start'] ?? '') ?>" required></div>
      <div><label for="cend">To</label><input type="time" id="cend" name="cend" value="<?= e($check['end'] ?? '') ?>" required></div>
    </div>
    <button type="submit" class="btn btn-sm">Check</button>
  </form>

  <?php if ($check): ?>
    <?php if (isset($check['error'])): ?>
      <div class="error mt" role="alert"><?= e($check['error']) ?></div>
    <?php elseif (!$check['conflicts']): ?>
      <div class="success mt" role="status">Section <?= e($sectionName[$check['section']]) ?> is <strong>free</strong> on
        <?= e(fmtDate($check['date'])) ?> from <?= e(fmtTime($check['start'])) ?> to <?= e(fmtTime($check['end'])) ?>.</div>
    <?php else: ?>
      <div class="error mt" role="alert">Section <?= e($sectionName[$check['section']]) ?> is <strong>busy</strong>:
        <ul><?php foreach (conflictLines($check['conflicts']) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<div class="week-nav">
  <span class="range"><?= e($monday->format('j M') . ' to ' . $sunday->format('j M Y')) ?></span>
  <div class="week-btns">
    <a class="btn btn-outline" href="?section=<?= (int)$secId ?>&amp;d=<?= e($prev) ?>">Previous week</a>
    <a class="btn btn-outline" href="?section=<?= (int)$secId ?>">This week</a>
    <a class="btn btn-outline" href="?section=<?= (int)$secId ?>&amp;d=<?= e($next) ?>">Next week</a>
  </div>
</div>

<section class="card mt">
  <h2>Lectures per day, all sections</h2>
  <p class="hint">Each number is how many lectures that section has that day. Open a section to see the times.</p>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Section</th>
          <?php for ($i = 0; $i < 7; $i++): $d = (clone $monday)->modify("+$i days"); ?>
            <th><?= e($d->format('D j')) ?></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sections as $s): $sid = (int)$s['id']; ?>
          <tr>
            <td><a href="?section=<?= $sid ?>&amp;d=<?= e($from) ?>"><?= e($s['name'] . ' (' . $s['semester'] . ')') ?></a><?= $sid === $secId ? ' <span class="badge ok">Open</span>' : '' ?></td>
            <?php for ($i = 0; $i < 7; $i++):
              $key = (clone $monday)->modify("+$i days")->format('Y-m-d');
              $n = count($grid[$sid][$key] ?? []);
            ?>
              <td><?= $n > 0 ? '<span class="badge warn">' . $n . '</span>' : '<span class="meta">Free</span>' ?></td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<h2 class="mt">Section <?= e($sectionName[$secId] ?? '') ?></h2>
<?php for ($i = 0; $i < 7; $i++):
  $d = (clone $monday)->modify("+$i days");
  $key = $d->format('Y-m-d');
  $list = $grid[$secId][$key] ?? [];
?>
  <section class="card day<?= $key === $today ? ' today' : '' ?>">
    <h2><?= e($d->format('l, j F')) ?>
      <?php if ($key === $today): ?><span class="badge ok">Today</span><?php endif; ?>
      <span class="badge <?= $list ? 'warn' : 'ok' ?>"><?= count($list) ?> lecture(s)</span>
    </h2>
    <?php if (!$list): ?><p class="hint">Free all day.</p><?php endif; ?>
    <?php foreach ($list as $en): ?>
      <article class="notice">
        <div class="notice-head">
          <div>
            <h3><span class="code"><?= e($en['code']) ?></span><?= e($en['name']) ?></h3>
            <p class="meta"><?= e($en['instructor']) ?>, <?= e($en['room']) ?></p>
          </div>
          <div class="badges">
            <span class="badge neutral"><?= e(fmtTime($en['start']) . ' to ' . fmtTime($en['end'])) ?></span>
            <?php if ($en['changed']): ?><span class="badge warn">Changed</span><?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
<?php endfor; ?>

<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>