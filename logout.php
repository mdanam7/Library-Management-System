<?php
require_once 'config/db.php';
require_once 'includes/auth.php';

if (is_logged_in()) {
    log_activity($pdo, (int) $_SESSION['user_id'], 'LOGOUT', 'User logged out');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: login.php');
exit;
?>