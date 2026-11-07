<?php
// includes/helpers.php - small display helpers shared by pages.

function appNow() {
    return new DateTime('now', new DateTimeZone(APP_TIMEZONE));
}

// Convert a UTC database datetime to Pakistan time for display.
function toLocalTime($utc, $format) {
    if (!$utc) {
        return '';
    }
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone(APP_TIMEZONE));
    return $d->format($format);
}

// Timetable DATE and TIME columns hold local times already.
function fmtDate($date) {
    return (new DateTime($date))->format('D j M');
}

function fmtTime($time) {
    return (new DateTime($time))->format('g:i A');
}

// Hours between the request being submitted and the original lecture start.
function noticeHours($submittedUtc, $startUtc) {
    return (strtotime($startUtc . ' UTC') - strtotime($submittedUtc . ' UTC')) / 3600;
}

function icon($name) {
    $paths = [
        'home'     => '<path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'swap'     => '<path d="M7 7h12l-3-3M17 17H5l3 3"/>',
        'bell'     => '<path d="M6 9a6 6 0 0 1 12 0c0 6 2 7 2 7H4s2-1 2-7M10 20a2 2 0 0 0 4 0"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'history'  => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l3 2"/>',
        'bus'      => '<path d="M5 17h14M7 17V8a3 3 0 0 1 3-3h4a3 3 0 0 1 3 3v9M9 21v-4M15 21v-4M9 12h6"/>',
        'settings' => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        'help'     => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/>',
        'book'     => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2zM4 19V5M8 7h7"/>',
        'send'     => '<path d="M21 3L10 14M21 3l-7 18-4-7-7-4z"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-6 7-6s7 2.5 7 6M17 4.5a3.5 3.5 0 0 1 0 7M22 20c0-2.5-1.5-4.5-4-5.5"/>',
        'check'    => '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>',
        'file'     => '<path d="M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'chart'    => '<path d="M5 20V10M12 20V4M19 20v-7"/>',
        'logout'   => '<path d="M9 4H5v16h4M16 8l4 4-4 4M20 12H9"/>',
        'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
         . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($paths[$name] ?? '') . '</svg>';
}

// Logo: a calendar with two opposing arrows (change).
function logoMark() {
    return '<svg class="logo" viewBox="0 0 32 32" width="34" height="34" aria-hidden="true">'
         . '<defs>'
         . '<linearGradient id="ndLogoGrad" x1="0%" y1="0%" x2="100%" y2="100%">'
         . '<stop offset="0%" stop-color="#0F766E"/>'
         . '<stop offset="100%" stop-color="#047857"/>'
         . '</linearGradient>'
         . '</defs>'
         . '<rect width="32" height="32" rx="9" fill="url(#ndLogoGrad)"/>'
         . '<g fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
         . '<rect x="7" y="9" width="18" height="16" rx="3.5"/><path d="M7 14h18M12 7v4M20 7v4"/>'
         . '<path d="M11 18h9M18 16l2 2-2 2M21 22h-9M14 20l-2 2 2 2" stroke-width="1.5"/>'
         . '</g></svg>';
}


function isValidDate($d) {
    $x = DateTime::createFromFormat('Y-m-d', (string)$d);
    return $x && $x->format('Y-m-d') === $d;
}

function isValidTime($t) {
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$t);
}

function setFlash($msg) {
    $_SESSION['flash_ok'] = $msg;
}

function takeFlash() {
    $m = $_SESSION['flash_ok'] ?? '';
    unset($_SESSION['flash_ok']);
    return $m;
}

// Pakistan-time date + time -> UTC datetime string for the database.
function localToUtc($date, $time) {
    $d = new DateTime($date . ' ' . $time, new DateTimeZone(APP_TIMEZONE));
    $d->setTimezone(new DateTimeZone('UTC'));
    return $d->format('Y-m-d H:i:s');
}
?>