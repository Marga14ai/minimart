<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';

if (current_user()) { header('Location: index.php'); exit; }
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'This sign-in form expired. Refresh the page and try again.';
    } elseif (login_is_rate_limited($username)) {
        http_response_code(429);
        $error = 'Too many failed sign-in attempts. Try again in 15 minutes.';
    } elseif (attempt_login($username, (string) ($_POST['password'] ?? ''), isset($_POST['remember_me']))) {
        header('Location: index.php'); exit;
    } elseif (login_is_rate_limited($username)) {
        http_response_code(429);
        $error = 'Too many failed sign-in attempts. Try again in 15 minutes.';
    } else {
        $error = 'Incorrect username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>
            Sign in — MiniMart
        </title>
        <link rel="stylesheet" href="assets/style.css">
        <link rel="stylesheet" href="assets/login.css?v=<?= filemtime(__DIR__ . '/assets/login.css') ?>">
        <link rel="stylesheet" href="assets/vendor/sweetalert2/sweetalert2.min.css">
    </head>
    <body class="login-page">
        <main class="login-shell">
            <section class="login-brand">
                <div class="login-logo">
                    M
                </div>
                <p>
                    MiniMart Management
                </p>
                <h1>
                    Run your store with confidence.
                </h1>
                <span>
                    Sales, inventory, customers, and suppliers—all in one place.
                </span>
            </section>
            <section class="login-card">
                <div>
                    <small>
                        WELCOME BACK
                    </small>
                    <h2>
                        Sign in to MiniMart
                    </h2>
                    <p>
                        Use your assigned owner, cashier, or assistant account.
                    </p>
                </div>
                <form method="post">
                    <?= csrf_field() ?>
                    <label>
                        Username
                        <input required autofocus autocomplete="username" name="username" placeholder="Enter username">
                    </label>
                    <label>
                        Password
                        <span class="password-field">
                            <input required autocomplete="current-password" type="password" name="password" id="login-password" placeholder="Enter password">
                            <button type="button" id="toggle-password" aria-label="Show password" aria-pressed="false" title="Show password">
                                <svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20">
                                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </span>
                    </label>
                    <label class="remember-login">
                        <input type="checkbox" name="remember_me" value="1">
                        <span>Remember me on this device for 7 days</span>
                    </label>
                    <button class="primary">
                        Sign in
                    </button>
                </form>
                <div class="role-hints">
                    <span>
                        Owner
                    </span>
                    <span>
                        Cashier
                    </span>
                    <span>
                        Inventory Assistant
                    </span>
                </div>
            </section>
        </main>
        <?php if($error): ?>
        <script src="assets/vendor/sweetalert2/sweetalert2.all.min.js">
        </script>
        <script nonce="<?= csp_nonce() ?>">
            Swal.fire({icon:'error',title:'Sign in failed',text:<?= json_encode($error) ?>,confirmButtonColor:'#1b754a'});
        </script>
        <?php endif; ?>
        <script nonce="<?= csp_nonce() ?>">
            const passwordInput = document.getElementById('login-password');
            const passwordToggle = document.getElementById('toggle-password');
            passwordToggle.addEventListener('click', () => {
                const showing = passwordInput.type === 'text';
                passwordInput.type = showing ? 'password' : 'text';
                passwordToggle.setAttribute('aria-pressed', String(!showing));
                passwordToggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
                passwordToggle.title = showing ? 'Show password' : 'Hide password';
                passwordToggle.classList.toggle('showing', !showing);
            });
        </script>
    </body>
</html>
