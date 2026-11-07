<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

if (empty($layout)) {
    if (($bodyClass ?? '') === 'auth-page') {
        $layout = 'auth';
    } elseif (isLoggedIn()) {
        $layout = 'app';
    } else {
        $layout = 'public';
    }
}

$sidebar = [];
$unreadCount = 0;
$current = basename($_SERVER['SCRIPT_NAME']);

if ($layout === 'app') {
    // [label, page file (null = not built yet), icon]
    
        $menus = [
        'student' => [
            ['Overview', 'home.php', 'home'],
            ['My timetable', 'student/timetable.php', 'calendar'],
            ['Notifications', 'student/notifications.php', 'bell'],
            ['Change history', 'student/history.php', 'history'],
            ['Commute preferences', 'student/commute.php', 'bus'],
        ],
        'instructor' => [
            ['Overview', 'home.php', 'home'],
            ['My courses', 'instructor/courses.php', 'book'],
            ['My lectures', 'instructor/lectures.php', 'calendar'],
            ['Submit change request', 'instructor/request.php', 'send'],
            ['Pending requests', 'instructor/requests.php?f=pending', 'clock'],
            ['Request history', 'instructor/requests.php', 'history'],
            ['Notifications', 'instructor/notifications.php', 'bell'],
        ],
        'admin' => [
            ['Overview', 'home.php', 'home'],
            ['User management', 'admin/users.php', 'users'],
            ['Courses & enrollments', 'admin/setup.php', 'book'],
            ['Timetable management', 'admin/lectures.php', 'calendar'],
            ['Change approvals', 'admin/approvals.php', 'check'],
            ['Audit history', 'admin/audit.php', 'shield'],
        ],
    ];
    
    $sidebar = $menus[$_SESSION['role']] ?? [];
    $s = getDB()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $s->execute([(int)$_SESSION['user_id']]);
    $unreadCount = (int)$s->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? 'NoticeDiff') ?> - NoticeDiff</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: 1 ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/layout.css?v=<?= @filemtime(__DIR__ . '/../assets/css/layout.css') ?: 1 ?>">
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?php if ($layout === 'public'): ?>
<header class="pub-header">
  <div class="pub-bar">
    <a class="brand" href="<?= BASE_URL ?>/"><?= logoMark() ?><span>NoticeDiff</span></a>
    <button type="button" class="menu-btn" id="menu-btn" aria-expanded="false" aria-controls="pub-menu" aria-label="Open menu"><?= icon('menu') ?></button>
    <div class="pub-menu" id="pub-menu">
      <nav class="pub-nav" aria-label="Main">
        <a href="<?= BASE_URL ?>/" aria-current="page">Home</a>
        <a href="<?= BASE_URL ?>/#features">Features</a>
        <a href="<?= BASE_URL ?>/#how-it-works">How it works</a>
        <a href="<?= BASE_URL ?>/#about">About</a>
      </nav>
      <div class="pub-actions">
        <a class="btn btn-outline" href="<?= BASE_URL ?>/auth/login.php">Log in</a>
        <a class="btn" href="<?= BASE_URL ?>/auth/register.php">Sign up</a>
      </div>
    </div>
  </div>
</header>
<main class="public-main">
<?php elseif ($layout === 'app'): ?>
<div class="app-shell">
  <aside class="sidebar" id="sidebar" aria-label="Sidebar">
    <a class="brand" href="<?= BASE_URL ?>/home.php"><?= logoMark() ?><span>NoticeDiff</span></a>
    <ul class="side-nav">
      <?php foreach ($sidebar as [$label, $href, $ic]): $active = ($href && substr($_SERVER['SCRIPT_NAME'], -strlen($href)) === $href); ?>
        <li>
          <?php if ($href): ?>
            <a class="side-link<?= $active ? ' active' : '' ?>" title="<?= e($label) ?>" href="<?= BASE_URL . '/' . $href ?>"<?= $active ? ' aria-current="page"' : '' ?>>
              <?= icon($ic) ?><span class="side-label"><?= e($label) ?></span>
            </a>
          <?php else: ?>
            <span class="side-link soon" title="<?= e($label) ?> (coming soon)" aria-disabled="true">
              <?= icon($ic) ?><span class="side-label"><?= e($label) ?></span><span class="tag-soon">Soon</span>
            </span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="side-foot">
      <form method="post" action="<?= BASE_URL ?>/auth/logout.php">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <button type="submit" class="side-link side-btn" title="Log out"><?= icon('logout') ?><span class="side-label">Log out</span></button>
      </form>
    </div>
  </aside>
  <div class="scrim" id="scrim"></div>
  <div class="app-body">
    <header class="app-topbar">
      <button type="button" class="icon-btn" id="side-toggle" aria-label="Toggle sidebar" aria-expanded="true" aria-controls="sidebar"><?= icon('menu') ?></button>
      <span class="grow"></span>
            <?php if (in_array($_SESSION['role'], ['student', 'instructor'], true)): ?>
        <a class="bell" href="<?= BASE_URL ?>/<?= $_SESSION['role'] ?>/notifications.php" aria-label="Notifications, <?= $unreadCount ?> unread">
          <?= icon('bell') ?>
          <?php if ($unreadCount > 0): ?><span class="badge-count"><?= min($unreadCount, 99) ?></span><?php endif; ?>
        </a>
      <?php else: ?>
        <span class="bell" role="img" aria-label="<?= $unreadCount ?> unread notifications">
          <?= icon('bell') ?>
          <?php if ($unreadCount > 0): ?><span class="badge-count"><?= min($unreadCount, 99) ?></span><?php endif; ?>
        </span>
      <?php endif; ?>
      <details class="menu">
        <summary>
          <span class="avatar"><?= e(mb_strtoupper(mb_substr($_SESSION['full_name'], 0, 1))) ?></span>
          <span class="user-name"><?= e($_SESSION['full_name']) ?></span>
        </summary>
        <div class="menu-pop">
          <p class="menu-name"><?= e($_SESSION['full_name']) ?></p>
          <p class="menu-role"><?= e(ucfirst($_SESSION['role'])) ?></p>
          <form method="post" action="<?= BASE_URL ?>/auth/logout.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <button type="submit" class="btn btn-outline btn-block">Log out</button>
          </form>
        </div>
      </details>
    </header>
    <main class="app-content">
<?php else: ?>
<main class="container">
<?php endif; ?>