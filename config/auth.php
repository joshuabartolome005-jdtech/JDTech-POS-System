<?php
// Secure Session Cookie Settings (Dapat bago mag-session_start)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1); // Iwas Cross-Site Scripting (XSS) session theft
    ini_set('session.use_only_cookies', 1); // Pinipilit na sa HTTP Cookie lang itatabi ang Session ID
    
    // Automatic na magse-set ng Secure flag kung naka-HTTPS (katulad ng sa Hostinger SSL)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }

    session_start();
}

// Auto-logout after this many seconds of inactivity (10 minutes)
define('SESSION_TIMEOUT_SECONDS', 600);

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Ends the current session and sends the user to the login page with a
 * reason code the login page can show a message for.
 */
function forceLogout(string $reason = '') {
    session_unset();
    session_destroy();
    $suffix = $reason ? ('?' . $reason . '=1') : '';
    header('Location: login' . $suffix);
    exit;
}

/**
 * Confirms this session's token still matches what's on record for the
 * user in the database. If another login has happened elsewhere (which
 * overwrites the token in the database), this session is no longer
 * valid and the user is signed out here.
 */
function checkSingleSession() {
    global $pdo;

    if (!isset($_SESSION['session_token'])) {
        // Session predates single-session enforcement - require a fresh login.
        forceLogout();
    }

    $stmt = $pdo->prepare("SELECT current_session_token FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $dbToken = $stmt->fetchColumn();

    if ($dbToken === false || $dbToken !== $_SESSION['session_token']) {
        forceLogout('kicked');
    }
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login');
        exit;
    }

    // Idle timeout: if too long since the last authenticated request,
    // sign the user out.
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
        forceLogout('timeout');
    }
    $_SESSION['last_activity'] = time();

    // Single-session enforcement.
    checkSingleSession();
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        die('Access denied. This page is for admins only.');
    }
}

function currentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['full_name'] ?? '',
        'role' => $_SESSION['role'] ?? '',
    ];
}