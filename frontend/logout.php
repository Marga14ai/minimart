<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    header('Location: index.php');
    exit;
}
security_audit((int) (current_user()['user_id'] ?? 0) ?: null, 'logout.succeeded');
$_SESSION = [];
forget_remember_token();
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Clear-Site-Data: "cache", "storage"');
header('Location: login.php');
exit;
