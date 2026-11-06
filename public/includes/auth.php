<?php
// includes/auth.php - sessions, access control, CSRF helpers.
require_once __DIR__ . '/../../config/database.php';

if (!defined('BASE_URL')) {
    $env = loadEnv(SECRETS_FILE);
    if (!empty($env['BASE_URL'])) {
        define('BASE_URL', rtrim($env['BASE_URL'], '/'));
    } else {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = str_replace('\\', '/', dirname($script));
        if ($scriptDir === '.' || $scriptDir === '/') {
            $parts = [];
        } else {
            $parts = array_values(array_filter(explode('/', $scriptDir)));
            if (!empty($parts) && in_array(end($parts), ['auth', 'includes', 'database', 'admin', 'instructor', 'student', 'timetable', 'change_requests', 'history', 'notifications', 'settings'], true)) {
                array_pop($parts);
            }
        }
        define('BASE_URL', !empty($parts) ? '/' . implode('/', $parts) : '');
    }
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,   // JavaScript cannot read the cookie
        'samesite' => 'Lax',  // limits cross-site request abuse
    ]);
    session_start();
}

// Escape text before printing it in HTML (prevents XSS).
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
}

// Enforced on the server: call at the top of every protected page.
function requireRole(array $roles) {
    requireLogin();
    if (!in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        die('Access denied.');
    }
}

function csrfToken() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfCheck() {
    $sent = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        die('Invalid request.');
    }
}
