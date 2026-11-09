<?php

declare(strict_types=1);

function request_is_https(): bool
{
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    $trusted = array_filter(array_map('trim', explode(',', (string) app_config('TRUSTED_PROXIES', '127.0.0.1,::1'))));
    return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $trusted, true)
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function enforce_https_outside_localhost(): void
{
    if (PHP_SAPI === 'cli' || request_is_https()) return;
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test')) return;
    $appUrl = rtrim((string) app_config('APP_URL', ''), '/');
    if ($appUrl === '') { http_response_code(400); exit('Application URL is not configured.'); }
    header('Location: ' . $appUrl . ($_SERVER['REQUEST_URI'] ?? '/'), true, 302);
    exit;
}

enforce_https_outside_localhost();
$production = strtolower((string) app_config('APP_ENV', 'development')) === 'production';
ini_set('display_errors', $production ? '0' : '1');
ini_set('log_errors', '1');
if ($production) error_reporting(E_ALL);
set_exception_handler(static function (Throwable $error) use ($production): void {
    error_log(sprintf('[MiniMart] %s in %s:%d', $error->getMessage(), $error->getFile(), $error->getLine()));
    if (headers_sent()) return;
    http_response_code(500);
    if (str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => ['message' => 'The request could not be completed.']]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo $production ? '<!doctype html><title>MiniMart error</title><h1>MiniMart is temporarily unavailable</h1><p>Please try again or contact the administrator.</p>' : '<pre>' . htmlspecialchars((string) $error) . '</pre>';
    }
});
$GLOBALS['csp_nonce'] = base64_encode(random_bytes(18));
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$GLOBALS['csp_nonce']}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self' blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');
    if (request_is_https()) header('Strict-Transport-Security: max-age=31536000');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionPath = __DIR__ . '/../storage/sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0775, true);
    }
    if (!is_writable($sessionPath)) {
        throw new RuntimeException('The application session folder is not writable: ' . $sessionPath);
    }
    session_save_path($sessionPath);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) (7 * 24 * 60 * 60));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $sessionTimeout = !empty($_SESSION['remember_me']) ? 7 * 24 * 60 * 60 : 30 * 60;
    if (!empty($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $sessionTimeout) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
    if (empty($_SESSION['user'])) restore_remembered_login();
}

function csp_nonce(): string { return htmlspecialchars((string) ($GLOBALS['csp_nonce'] ?? ''), ENT_QUOTES, 'UTF-8'); }

function remember_cookie_options(int $expires): array
{
    return ['expires' => $expires, 'path' => '/', 'secure' => request_is_https(), 'httponly' => true, 'samesite' => 'Lax'];
}

function forget_remember_token(): void
{
    $cookie = (string) ($_COOKIE['minimart_remember'] ?? '');
    $selector = explode(':', $cookie, 2)[0] ?? '';
    if (preg_match('/^[a-f0-9]{24}$/', $selector)) db()->prepare('DELETE FROM remember_tokens WHERE selector=?')->execute([$selector]);
    setcookie('minimart_remember', '', remember_cookie_options(time() - 3600));
    unset($_COOKIE['minimart_remember']);
}

function issue_remember_token(int $userId): void
{
    forget_remember_token();
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + 7 * 24 * 60 * 60;
    db()->prepare('INSERT INTO remember_tokens (user_id,selector,token_hash,user_agent_hash,expires_at) VALUES (?,?,?,?,FROM_UNIXTIME(?))')->execute([$userId,$selector,hash('sha256',$validator),hash('sha256',(string)($_SERVER['HTTP_USER_AGENT']??'')),$expires]);
    setcookie('minimart_remember', $selector . ':' . $validator, remember_cookie_options($expires));
}

function restore_remembered_login(): void
{
    $parts = explode(':', (string) ($_COOKIE['minimart_remember'] ?? ''), 2);
    if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{24}$/', $parts[0]) || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) return;
    $token = rows("SELECT rt.*,u.user_id,u.full_name,u.username,u.role FROM remember_tokens rt JOIN users u ON u.user_id=rt.user_id WHERE rt.selector=? AND rt.expires_at>NOW() AND u.status='Active' LIMIT 1", [$parts[0]])[0] ?? null;
    if (!$token || !hash_equals((string)$token['token_hash'],hash('sha256',$parts[1])) || !hash_equals((string)$token['user_agent_hash'],hash('sha256',(string)($_SERVER['HTTP_USER_AGENT']??'')))) { forget_remember_token(); return; }
    session_regenerate_id(true);
    $_SESSION['user']=['user_id'=>(int)$token['user_id'],'full_name'=>$token['full_name'],'username'=>$token['username'],'role'=>$token['role']];
    $_SESSION['remember_me']=true; $_SESSION['last_activity']=time();
    db()->prepare('UPDATE remember_tokens SET last_used_at=NOW() WHERE token_id=?')->execute([(int)$token['token_id']]);
    issue_remember_token((int)$token['user_id']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrf_token_is_valid(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function ensure_login_attempts_table(): void
{
    static $ready = false;
    if ($ready) return;
    db()->exec("CREATE TABLE IF NOT EXISTS login_attempts (attempt_key CHAR(64) PRIMARY KEY, failed_attempts INT NOT NULL DEFAULT 0, first_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, locked_until DATETIME NULL) ENGINE=InnoDB");
    $ready = true;
}

function login_attempt_key(string $username): string
{
    return hash('sha256', strtolower(trim($username)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
}

function login_is_rate_limited(string $username): bool
{
    ensure_login_attempts_table();
    return (int) value('SELECT COUNT(*) FROM login_attempts WHERE attempt_key=? AND locked_until>NOW()', [login_attempt_key($username)]) > 0;
}

function record_failed_login(string $username): void
{
    ensure_login_attempts_table();
    $key = login_attempt_key($username);
    $attempt = rows('SELECT failed_attempts, last_attempt_at FROM login_attempts WHERE attempt_key=?', [$key])[0] ?? null;
    $failures = !$attempt || strtotime((string) $attempt['last_attempt_at']) < time() - 900 ? 1 : (int) $attempt['failed_attempts'] + 1;
    db()->prepare('INSERT INTO login_attempts (attempt_key,failed_attempts,first_attempt_at,last_attempt_at,locked_until) VALUES (?,?,NOW(),NOW(),IF(? >= 5,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NULL)) ON DUPLICATE KEY UPDATE failed_attempts=VALUES(failed_attempts), first_attempt_at=IF(VALUES(failed_attempts)=1,NOW(),first_attempt_at), last_attempt_at=NOW(), locked_until=VALUES(locked_until)')->execute([$key, $failures, $failures]);
}

function clear_failed_logins(string $username): void
{
    ensure_login_attempts_table();
    db()->prepare('DELETE FROM login_attempts WHERE attempt_key=?')->execute([login_attempt_key($username)]);
}

function security_audit(?int $userId, string $action, array $details = []): void
{
    try {
        db()->prepare('INSERT INTO audit_logs (user_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?)')->execute([$userId,$action,'authentication',$userId??0,$details?json_encode($details,JSON_UNESCAPED_SLASHES):null,substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)?:null]);
    } catch (Throwable $ignored) { error_log('[MiniMart] Could not write authentication audit event.'); }
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

function attempt_login(string $username, string $password, bool $rememberMe = false): bool
{
    if (login_is_rate_limited($username)) return false;
    $statement = db()->prepare("SELECT user_id, full_name, username, password, role FROM users WHERE username=? AND status='Active' LIMIT 1");
    $statement->execute([$username]);
    $user = $statement->fetch();
    if (!$user) { record_failed_login($username); security_audit(null,'login.failed',['username'=>$username]); return false; }

    $valid = password_verify($password, $user['password']);
    if (!$valid) { record_failed_login($username); security_audit((int)$user['user_id'],'login.failed',['username'=>$username]); return false; }

    clear_failed_logins($username);
    session_regenerate_id(true);
    unset($user['password']);
    $_SESSION['user'] = $user;
    $_SESSION['remember_me'] = $rememberMe;
    $_SESSION['last_activity'] = time();
    security_audit((int)$user['user_id'],'login.succeeded',['remembered'=>$rememberMe]);
    if ($rememberMe) {
        issue_remember_token((int) $user['user_id']);
    } else {
        forget_remember_token();
    }
    return true;
}

function role_can(string $action): bool
{
    $role = current_user()['role'] ?? '';
    if ($role === 'Owner') return true;
    $permissions = [
        'Cashier' => ['new_sale', 'add_customer', 'pay_debt', 'change_password'],
        'Assistant' => ['add_product', 'update_product', 'archive_product', 'stock_in', 'adjust_inventory', 'add_supplier', 'order_supplier', 'receive_supplier_order', 'change_password'],
    ];
    return in_array($action, $permissions[$role] ?? [], true);
}

function page_allowed(string $page): bool
{
    $role = current_user()['role'] ?? '';
    $pages = [
        'Owner' => ['dashboard', 'inventory', 'sales', 'customers', 'suppliers', 'reports', 'users', 'audit'],
        'Cashier' => ['dashboard', 'sales', 'customers'],
        'Assistant' => ['dashboard', 'inventory', 'suppliers'],
    ];
    return in_array($page, $pages[$role] ?? [], true);
}
