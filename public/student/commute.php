<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['student']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$errors = [];

$types = [
    'university_bus' => 'University bus',
    'personal'       => 'Personal or family transport',
    'public'         => 'Public transport',
    'hostel_walking' => 'Hostel or walking distance',
    'other'          => 'Other',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $type = $_POST['transport_type'] ?? '';
    $dep  = trim($_POST['bus_departure'] ?? '');
    $ret  = trim($_POST['bus_return'] ?? '');

    if (!isset($types[$type])) {
        $errors[] = 'Choose a transport option.';
    }
    if ($type !== 'university_bus') {
        $dep = '';
        $ret = '';                       // bus times only make sense for the university bus
    }
    if ($dep !== '' && !isValidTime($dep)) $errors[] = 'Enter a valid morning bus time.';
    if ($ret !== '' && !isValidTime($ret)) $errors[] = 'Enter a valid return bus time.';
    if (!$errors && $dep !== '' && $ret !== '' && $ret <= $dep) {
        $errors[] = 'The return bus must leave after the morning bus.';
    }

    if (!$errors) {
        $db->prepare(
            'INSERT INTO transport_settings (user_id, transport_type, bus_departure, bus_return)
             VALUES (?, ?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE transport_type = new.transport_type,
                                     bus_departure = new.bus_departure,
                                     bus_return = new.bus_return'
        )->execute([$uid, $type, $dep !== '' ? $dep : null, $ret !== '' ? $ret : null]);
        setFlash('Commute preferences saved.');
        header('Location: ' . $_SERVER['SCRIPT_NAME']);
        exit;
    }
}

$st = $db->prepare('SELECT transport_type, bus_departure, bus_return FROM transport_settings WHERE user_id = ?');
$st->execute([$uid]);
$cur = $st->fetch() ?: ['transport_type' => 'other', 'bus_departure' => null, 'bus_return' => null];
$selType = $_POST['transport_type'] ?? $cur['transport_type'];
$selDep  = $_POST['bus_departure'] ?? ($cur['bus_departure'] ? substr($cur['bus_departure'], 0, 5) : '');
$selRet  = $_POST['bus_return']    ?? ($cur['bus_return'] ? substr($cur['bus_return'], 0, 5) : '');
$flash = takeFlash();

$pageTitle = 'Commute preferences';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Commute preferences</h1>
  <p class="date">Optional. Used only to warn you when an approved change may clash with your travel.</p>
</div>

<?php if ($flash): ?><div class="success mt" role="status"><?= e($flash) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="error mt" role="alert"><ul>
    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<section class="card form-card mt">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <label for="transport_type">How do you usually travel?</label>
    <select id="transport_type" name="transport_type" required>
      <?php foreach ($types as $k => $v): ?>
        <option value="<?= e($k) ?>"<?= $selType === $k ? ' selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select>

    <div id="bus-fields">
      <div class="row2">
        <div><label for="bus_departure">Morning bus departs at</label><input type="time" id="bus_departure" name="bus_departure" value="<?= e($selDep) ?>"></div>
        <div><label for="bus_return">Return bus departs at</label><input type="time" id="bus_return" name="bus_return" value="<?= e($selRet) ?>"></div>
      </div>
      <p class="hint">Times are in Pakistan time.</p>
    </div>

    <p class="hint">NoticeDiff only compares the times you enter with changed lectures. It does not know the bus route and does not coordinate or control university transport.</p>
    <button type="submit" class="btn btn-sm">Save preferences</button>
  </form>
</section>

<script>
(function () {
  var sel = document.getElementById('transport_type');
  var box = document.getElementById('bus-fields');
  function sync() { box.style.display = sel.value === 'university_bus' ? '' : 'none'; }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>