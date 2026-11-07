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


// Where each lecture really is: its original slot, or the slot an APPROVED reschedule or time change
// moved it to. Cancelled lectures are left out. Dates are Y-m-d and times are H:i.
function effectiveSlots(PDO $db, $from, $to, $sectionId = null) {
    $sql = "SELECT l.id AS lecture_id, l.section_id, l.original_date, l.original_start, l.original_end, l.original_room,
                   c.code, c.name, u.full_name AS instructor, s.name AS section,
                   cr.change_type, cr.proposed_date, cr.proposed_start, cr.proposed_end, cr.proposed_room
            FROM lectures l
            JOIN courses c ON c.id = l.course_id
            JOIN sections s ON s.id = l.section_id
            JOIN users u ON u.id = l.instructor_id
            LEFT JOIN change_requests cr ON cr.id = (
                SELECT x.id FROM change_requests x
                WHERE x.lecture_id = l.id AND x.status = 'approved'
                ORDER BY x.reviewed_at DESC, x.id DESC LIMIT 1)
            WHERE l.status <> 'cancelled'
              AND (l.original_date BETWEEN ? AND ? OR cr.proposed_date BETWEEN ? AND ?)";
    $params = [$from, $to, $from, $to];
    if ($sectionId !== null) {
        $sql .= ' AND l.section_id = ?';
        $params[] = (int)$sectionId;
    }
    $st = $db->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $moved = in_array($r['change_type'], ['reschedule', 'time_change'], true);
        $date  = $moved ? ($r['proposed_date'] ?: $r['original_date']) : $r['original_date'];
        $start = $moved ? $r['proposed_start'] : $r['original_start'];
        $end   = $moved ? $r['proposed_end'] : $r['original_end'];
        if ($date < $from || $date > $to) {
            continue;
        }
        $out[] = [
            'lecture_id' => (int)$r['lecture_id'], 'section_id' => (int)$r['section_id'], 'section' => $r['section'],
            'code' => $r['code'], 'name' => $r['name'], 'instructor' => $r['instructor'],
            'date' => $date, 'start' => substr($start, 0, 5), 'end' => substr($end, 0, 5),
            'room' => ($r['change_type'] === 'room_change' && $r['proposed_room']) ? $r['proposed_room'] : $r['original_room'],
            'changed' => $r['change_type'] !== null && $r['change_type'] !== 'cancellation',
        ];
    }
    return $out;
}

// Lectures of the same section that overlap the given slot (the lecture being changed is skipped).
function slotConflicts(PDO $db, $sectionId, $date, $start, $end, $excludeLectureId = 0) {
    $start = substr($start, 0, 5);
    $end   = substr($end, 0, 5);
    $out = [];
    foreach (effectiveSlots($db, $date, $date, (int)$sectionId) as $s) {
        if ($s['lecture_id'] === (int)$excludeLectureId) {
            continue;
        }
        if ($start < $s['end'] && $end > $s['start']) {
            $out[] = $s;
        }
    }
    return $out;
}

function conflictLines(array $conflicts) {
    $out = [];
    foreach ($conflicts as $c) {
        $out[] = $c['code'] . ' ' . $c['name'] . ' (' . fmtTime($c['start']) . ' to ' . fmtTime($c['end'])
               . ', ' . fmtDate($c['date']) . ', ' . $c['instructor'] . ')';
    }
    return $out;
}

// The new time slot a reschedule or time-change request asks for, or null for other types.
function proposedSlot(array $r) {
    if (!in_array($r['change_type'], ['reschedule', 'time_change'], true)) {
        return null;
    }
    return [$r['proposed_date'] ?: $r['original_date'], $r['proposed_start'], $r['proposed_end']];
}
?>