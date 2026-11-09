<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';
require_login();
$loggedInUser = current_user();

function peso(float|int|string|null $amount): string { return '₱' . number_format((float) $amount, 2); }
function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/../backend/actions.php';

require_once __DIR__ . '/../backend/dashboard_data.php';
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>
            MiniMart — Store Management
        </title>
        <link rel="stylesheet" href="assets/style.css">
        <link rel="stylesheet" href="assets/alerts.css">
        <link rel="stylesheet" href="assets/reports.css">
        <link rel="stylesheet" href="assets/customers.css">
        <link rel="stylesheet" href="assets/chart.css">
        <link rel="stylesheet" href="assets/users.css">
        <link rel="stylesheet" href="assets/sales.css?v=<?= filemtime(__DIR__ . '/assets/sales.css') ?>">
        <link rel="stylesheet" href="assets/vendor/sweetalert2/sweetalert2.min.css">
    </head>
    <body>
        <div class="app">
            <aside class="sidebar">
                <div class="brand">
                    <span class="brand-mark">
                        M
                    </span>
                    <div>
                        <strong>
                            MiniMart
                        </strong>
                        <small>
                            Store Management
                        </small>
                    </div>
                </div>
                <nav>
                    <?php foreach ([['dashboard','⌂','Overview'],['inventory','▦','Inventory'],['sales','₱','Sales'],['customers','♙','Utang'],['suppliers','▣','Suppliers'],['reports','▤','Reports']] as [$id,$icon,$label]): if(!page_allowed($id)) continue; ?>
                    <button class="nav-item <?= $id==='dashboard'?'active':'' ?>" data-page="<?= $id ?>">
                        <span>
                            <?= $icon ?>
                        </span>
                        <?= $label ?>
                    </button>
                    <?php endforeach; ?>
                    <?php if(page_allowed('users')): ?>
                    <button class="nav-item" data-page="users"><span>U</span>Users</button>
                    <?php endif; ?>
                    <?php if(page_allowed('audit')): ?><button class="nav-item" data-page="audit"><span>◎</span>Security log</button><?php endif; ?>
                </nav>
                <div class="store-card">
                    <span class="dot">
                    </span>
                    <div>
                        <strong>
                            Store online
                        </strong>
                        <small>
                            Database connected
                        </small>
                    </div>
                </div>
                <div class="profile">
                    <div class="avatar">
                        <?= e(strtoupper(substr($loggedInUser['full_name'],0,2))) ?>
                    </div>
                    <div>
                        <strong>
                            <?= e($loggedInUser['full_name']) ?>
                        </strong>
                        <small>
                            <?= e($loggedInUser['role']) ?>
                        </small>
                    </div>
                    <button type="button" id="open-password-modal" title="Change password" aria-label="Change password">⚿</button>
                    <form method="post" action="logout.php" class="logout-form">
                        <?= csrf_field() ?>
                        <button class="logout-link" title="Sign out" aria-label="Sign out">↪</button>
                    </form>
                </div>
            </aside>
            <main>
                <header>
                    <button class="menu" id="menu">
                        ☰
                    </button>
                    <div>
                        <h1 id="page-title">
                            Good day, <?= e(explode(' ', trim($loggedInUser['full_name']))[0]) ?>!
                        </h1>
                        <p id="page-subtitle">
                            Here’s what’s happening in your store today.
                        </p>
                    </div>
                    <div class="header-actions">
                        <label class="search">
                            ⌕
                            <input id="global-search" placeholder="Search products…">
                        </label>
                        <button class="icon-btn" id="notifications" title="Notifications">
                            ♢
                            <span class="alert-dot">
                            </span>
                        </button>
                        <?php if(role_can('add_product')): ?>
                        <button class="primary" id="header-add-product" data-open-modal>
                            ＋ Add product
                        </button>
                        <?php endif; ?>
                    </div>
                </header>
                <?php require __DIR__ . '/dashboard.php'; ?>
                <?php require __DIR__ . '/inventory.php'; ?>
                <?php require __DIR__ . '/sales.php'; ?>
                <?php require __DIR__ . '/customers.php'; ?>
                <?php require __DIR__ . '/suppliers.php'; ?>
                <?php require __DIR__ . '/reports.php'; ?>
                <?php require __DIR__ . '/users.php'; ?>
                <?php require __DIR__ . '/audit.php'; ?>
            </main>
        </div>

        <dialog id="password-modal">
            <form method="post" id="password-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="modal-head">
                    <div><h2>Change password</h2><p>Use a unique password containing at least 12 characters.</p></div>
                    <button type="button" data-close-modal>×</button>
                </div>
                <div class="form-grid">
                    <label class="span-2">Current password<input required autocomplete="current-password" type="password" name="current_password"></label>
                    <label>New password<input required minlength="12" autocomplete="new-password" type="password" name="new_password"></label>
                    <label>Confirm new password<input required minlength="12" autocomplete="new-password" type="password" name="confirm_password"></label>
                </div>
                <div class="modal-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button class="primary">Change password</button></div>
            </form>
        </dialog>

        <script nonce="<?= csp_nonce() ?>">
            window.MINIMART_DATA = <?= json_encode([
    'notice' => $notice,
    'userName' => $loggedInUser['full_name'],
    'userRole' => $loggedInUser['role'],
    'csrfToken' => csrf_token(),
    'customerPayments' => $customerPaymentHistory,
    'lowStock' => $lowStock,
    'nearExpiryCount' => $nearExpiryCount,
    'debtTotal' => peso($debtTotal),
    'salesChart' => $salesChart,
    'products' => array_values(array_map(fn($p) => [
        'id' => (int) $p['product_id'], 'barcode' => (string) $p['barcode'],
        'name' => $p['product_name'], 'price' => (float) $p['selling_price'],
        'stock' => (int) $p['stock'], 'status' => $p['status'], 'supplierId' => (int) $p['supplier_id']
    ], $inventory))
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <script src="assets/vendor/sweetalert2/sweetalert2.all.min.js">
        </script>
        <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>">
        </script>
    </body>
</html>
<?php function renderSales(array $sales): string { ob_start(); ?><div class="table-wrap"><table><thead><tr><th>Receipt</th><th>Customer</th><th>Date & time</th><th>Payment</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if(!$sales): ?><tr><td colspan="7" class="empty-row">No sales recorded yet.</td></tr><?php endif; ?><?php foreach($sales as $sale): ?><tr><td class="mono"><button type="button" class="receipt-link" data-view-receipt="<?= (int)($sale['sale_id'] ?? 0) ?>"><?= e($sale['receipt_number']) ?></button></td><td><?= e($sale['customer_name']) ?></td><td><?= date('M j, Y · g:i A', strtotime($sale['sale_date'])) ?></td><td><?= e($sale['payment_method']) ?></td><td><strong><?= peso($sale['total_amount']) ?></strong></td><td><span class="badge <?= strtolower($sale['status']) ?>"><?= e($sale['status']) ?></span></td><td><?php if(role_can('void_sale') && $sale['status']==='Completed'): ?><button type="button" class="row-action danger void-sale-button" data-sale-id="<?= (int)$sale['sale_id'] ?>" data-receipt="<?= e($sale['receipt_number']) ?>">Void</button><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php return (string)ob_get_clean(); } ?>
