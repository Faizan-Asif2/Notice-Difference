<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['student']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$tz  = new DateTimeZone(APP_TIMEZONE);

// Week navigation: ?w=0 is this week, -1 last week, +1 next week.
$offset = max(-52, min(52, (int)($_GET['w'] ?? 0)));
$monday = new DateTime('monday this week', $tz);
$monday->setTime(0, 0);
if ($offset !== 0) {
    $monday->modify(($offset > 0 ? '+' : '') . $offset . ' weeks');
}
$sunday = (clone $monday)->modify('+6 days');
$from = $monday->format('Y-m-d');
$to   = $sunday->format('Y-m-d');
$today = (new DateTime('now', $tz))->format('Y-m-d');

$typeLabels = [
    'cancellation' => 'Cancelled', 'reschedule' => 'Rescheduled', 'time_change' => 'Time changed',
    'room_change' => 'Room changed', 'mode_change' => 'Mode changed', 'additional' => 'Extra lecture',
];

function slotText($date, $start, $end, $room, $mode) {
    return fmtDate($date) . ', ' . fmtTime($start) . ' to ' . fmtTime($end) . ', ' . $room
         . ($mode === 'online' ? ' (online)' : '');
}

// Lectures of this student's courses and sections, with the latest APPROVED change (if any).
$st = $db->prepare(
    "SELECT l.original_date, l.original_start, l.original_end, l.original_room, l.original_mode,
            c.code, c.name, u.full_name AS instructor, s.name AS section,
            cr.change_type, cr.proposed_date, cr.proposed_start, cr.proposed_end, cr.proposed_room,
            cr.proposed_mode, cr.is_late
     FROM lectures l
     JOIN enrollments en ON en.course_id = l.course_id AND en.section_id = l.section_id AND en.student_id = ?
     JOIN courses c ON c.id = l.course_id
     JOIN users u ON u.id = l.instructor_id
     JOIN sections s ON s.id = l.section_id
     LEFT JOIN change_requests cr ON cr.id = (
         SELECT x.id FROM change_requests x
         WHERE x.lecture_id = l.id AND x.status = 'approved'
         ORDER BY x.reviewed_at DESC, x.id DESC LIMIT 1)
     WHERE (l.original_date BETWEEN ? AND ?)
        OR (cr.change_type = 'reschedule' AND cr.proposed_date BETWEEN ? AND ?)"
);
$st->execute([$uid, $from, $to, $from, $to]);
$rows = $st->fetchAll();

// Turn each row into one or two entries shown on specific days.
$entries = [];
foreach ($rows as $r) {
    $orig = slotText($r['original_date'], $r['original_start'], $r['original_end'], $r['original_room'], $r['original_mode']);
    $base = ['code' => $r['code'], 'name' => $r['name'], 'instructor' => $r['instructor'], 'section' => $r['section'],
             'late' => (int)$r['is_late'] === 1, 'orig' => $orig, 'now' => null, 'badge' => ['On schedule', 'ok']];

    $type = $r['change_type'];
    if (!$type) {
        $entries[] = $base + ['date' => $r['original_date'], 'sort' => $r['original_start'],
                              'plain' => fmtTime($r['original_start']) . ' to ' . fmtTime($r['original_end']) . ', ' . $r['original_room']
                                       . ($r['original_mode'] === 'online' ? ' (online)' : '')];
        continue;
    }
    $label = $typeLabels[$type] ?? 'Changed';
    $badge = [$label, $type === 'cancellation' ? 'bad' : 'warn'];

    if ($type === 'cancellation') {
        $entries[] = array_merge($base, ['date' => $r['original_date'], 'sort' => $r['original_start'],
                                         'now' => 'Lecture cancelled', 'badge' => $badge]);
        continue;
    }
    $newDate  = $r['proposed_date']  ?: $r['original_date'];
    $newStart = $r['proposed_start'] ?: $r['original_start'];
    $newEnd   = $r['proposed_end']   ?: $r['original_end'];
    $newRoom  = $r['proposed_room']  ?: $r['original_room'];
    $newMode  = $r['proposed_mode']  ?: $r['original_mode'];
    $now = slotText($newDate, $newStart, $newEnd, $newRoom, $newMode);

        if ($type === 'reschedule' && $newDate !== $r['original_date']) {
        $entries[] = array_merge($base, ['date' => $r['original_date'], 'sort' => $r['original_start'],
                                         'now' => 'Moved to ' . $now, 'badge' => $badge]);
        $entries[] = array_merge($base, ['date' => $newDate, 'sort' => $newStart,
                                         'now' => $now, 'badge' => $badge]);
    } else {
        $entries[] = array_merge($base, ['date' => $newDate, 'sort' => $newStart, 'now' => $now, 'badge' => $badge]);
    }
}

// Keep only the entries that fall inside this week, grouped by day.
$byDay = [];
foreach ($entries as $en) {
    if ($en['date'] >= $from && $en['date'] <= $to) {
        $byDay[$en['date']][] = $en;
    }
}
foreach ($byDay as &$list) {
    usort($list, function ($a, $b) { return strcmp($a['sort'], $b['sort']); });
}
unset($list);

$pageTitle = 'My timetable';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>My timetable</h1>
  <p class="date">Original schedule and current approved schedule, side by side.</p>
</div>

<div class="week-nav">
  <span class="range"><?= e($monday->format('j M') . ' to ' . $sunday->format('j M Y')) ?></span>
  <div class="week-btns">
    <a class="btn btn-outline" href="?w=<?= $offset - 1 ?>">Previous week</a>
    <a class="btn btn-outline" href="?w=0">This week</a>
    <a class="btn btn-outline" href="?w=<?= $offset + 1 ?>">Next week</a>
  </div>
</div>

<?php for ($i = 0; $i < 7; $i++):
  $d   = (clone $monday)->modify("+$i days");
  $key = $d->format('Y-m-d');
  $list = $byDay[$key] ?? [];
?>
  <section class="card day<?= $key === $today ? ' today' : '' ?>">
    <h2><?= e($d->format('l, j F')) ?><?php if ($key === $today): ?> <span class="badge ok">Today</span><?php endif; ?></h2>
    <?php if (!$list): ?>
      <p class="hint">No lectures.</p>
    <?php endif; ?>
    <?php foreach ($list as $en): ?>
      <article class="notice<?= $en['late'] ? ' late' : '' ?>">
        <div class="notice-head">
          <div>
            <h3><span class="code"><?= e($en['code']) ?></span><?= e($en['name']) ?></h3>
            <p class="meta"><?= e($en['instructor']) ?>, section <?= e($en['section']) ?></p>
          </div>
          <div class="badges">
            <span class="badge <?= e($en['badge'][1]) ?>"><?= e($en['badge'][0]) ?></span>
            <?php if ($en['late']): ?><span class="badge bad">! Late notice</span><?php endif; ?>
          </div>
        </div>
        <?php if ($en['now'] === null): ?>
          <p><?= e($en['plain']) ?></p>
        <?php else: ?>
          <div class="diff-row del"><span class="sign">&minus;</span><span class="text"><?= e($en['orig']) ?></span></div>
          <div class="diff-row add"><span class="sign">+</span><span><?= e($en['now']) ?></span></div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
<?php endfor; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>