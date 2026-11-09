<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/auth.php';
require_once __DIR__ . '/../../backend/services.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function api_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['success' => $status < 400, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $status, array $details = []): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => ['message' => $message] + $details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    try {
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        api_error('The request body must contain valid JSON.', 400);
    }
    if (!is_array($body)) api_error('The JSON body must be an object.', 400);
    return $body;
}

function api_user(): array
{
    $user = current_user();
    if (!$user) api_error('Authentication is required.', 401);
    return $user;
}

function api_allow(string $permission): array
{
    $user = api_user();
    if (!role_can($permission)) api_error('Your account does not have permission for this operation.', 403);
    return $user;
}

function api_customer_access(): array
{
    $user = api_user();
    if (!in_array($user['role'], ['Owner', 'Cashier'], true)) api_error('Your account cannot access customers.', 403);
    return $user;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    header('Allow: GET, POST, OPTIONS');
    http_response_code(204);
    exit;
}

$path = trim((string) ($_SERVER['PATH_INFO'] ?? $_GET['route'] ?? ''), '/');
$segments = $path === '' ? [] : array_map('rawurldecode', explode('/', $path));

try {
    if ($method === 'GET' && $segments === []) {
        api_response([
            'name' => 'MiniMart REST API',
            'version' => '1.0',
            'authenticated' => current_user() !== null,
            'routes' => ['/auth/login', '/auth/me', '/auth/logout', '/products', '/products/barcode/{barcode}', '/sales', '/sales/{id}', '/customers', '/customers/{id}/debts', '/customers/{id}/payments', '/inventory', '/stock-in'],
        ]);
    }

    if ($method === 'POST' && $segments === ['auth', 'login']) {
        $body = api_body();
        $loginUsername = trim((string) ($body['username'] ?? ''));
        if (!attempt_login($loginUsername, (string) ($body['password'] ?? ''))) {
            if (login_is_rate_limited($loginUsername)) api_error('Too many failed sign-in attempts. Try again in 15 minutes.', 429);
            api_error('The username or password is incorrect.', 401);
        }
        api_response(['user' => current_user(), 'csrf_token' => csrf_token()]);
    }

    if ($method === 'GET' && $segments === ['auth', 'me']) {
        api_response(['user' => api_user(), 'csrf_token' => csrf_token()]);
    }

    if (!in_array($method, ['GET', 'HEAD'], true)) {
        api_user();
        if (!csrf_token_is_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) api_error('The CSRF token is missing or invalid.', 419);
    }

    if ($method === 'POST' && $segments === ['auth', 'logout']) {
        security_audit((int) (current_user()['user_id'] ?? 0) ?: null, 'logout.succeeded');
        forget_remember_token();
        $_SESSION = [];
        session_destroy();
        api_response(['message' => 'Signed out successfully.']);
    }

    if ($method === 'GET' && $segments === ['products']) {
        $user = api_user();
        $managementFields = in_array($user['role'], ['Owner','Assistant'], true) ? ', p.cost_price, p.category_id, p.supplier_id, COALESCE(i.reorder_level,p.reorder_level,5) reorder_level' : '';
        $products = rows("SELECT p.product_id, p.barcode, p.product_name, p.description, p.selling_price, p.unit, p.status, COALESCE(c.category_name,'Uncategorized') category_name, COALESCE(i.quantity_in_stock,0) quantity_in_stock {$managementFields} FROM products p LEFT JOIN categories c ON c.category_id=p.category_id LEFT JOIN inventory i ON i.product_id=p.product_id ORDER BY p.product_name");
        api_response($products);
    }

    if ($method === 'GET' && count($segments) === 3 && $segments[0] === 'products' && $segments[1] === 'barcode') {
        api_user();
        $product = rows("SELECT p.product_id, p.barcode, p.product_name, p.selling_price, p.unit, p.status, COALESCE(i.quantity_in_stock,0) quantity_in_stock FROM products p LEFT JOIN inventory i ON i.product_id=p.product_id WHERE p.barcode=? LIMIT 1", [$segments[2]])[0] ?? null;
        if (!$product) api_error('No product was found for this barcode.', 404);
        api_response($product);
    }

    if ($method === 'GET' && $segments === ['sales']) {
        $user = api_user();
        if (!in_array($user['role'], ['Owner', 'Cashier'], true)) api_error('Your account cannot access sales.', 403);
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
        $sales = rows("SELECT s.sale_id, s.receipt_number, s.sale_date, s.total_amount, s.amount_paid, s.change_amount, s.payment_method, s.status, COALESCE(c.customer_name,'Walk-in Customer') customer_name, u.full_name cashier FROM sales s JOIN users u ON u.user_id=s.user_id LEFT JOIN customers c ON c.customer_id=s.customer_id ORDER BY s.sale_date DESC LIMIT $limit");
        api_response($sales);
    }

    if ($method === 'GET' && count($segments) === 2 && $segments[0] === 'sales') {
        $user = api_user();
        if (!in_array($user['role'], ['Owner', 'Cashier'], true)) api_error('Your account cannot access sales.', 403);
        $saleId = (int) $segments[1];
        $sale = rows("SELECT s.sale_id, s.receipt_number, s.sale_date, s.total_amount, s.amount_paid, s.change_amount, s.payment_method, s.status, COALESCE(c.customer_name,'Walk-in Customer') customer_name, u.full_name cashier, d.due_date FROM sales s JOIN users u ON u.user_id=s.user_id LEFT JOIN customers c ON c.customer_id=s.customer_id LEFT JOIN customer_debts d ON d.sale_id=s.sale_id WHERE s.sale_id=?", [$saleId])[0] ?? null;
        if (!$sale) api_error('Sale not found.', 404);
        $sale['items'] = rows("SELECT si.sale_item_id, si.product_id, p.product_name, p.barcode, si.quantity, si.unit_price, si.discount, si.subtotal FROM sale_items si JOIN products p ON p.product_id=si.product_id WHERE si.sale_id=? ORDER BY si.sale_item_id", [$saleId]);
        api_response($sale);
    }

    if ($method === 'POST' && $segments === ['sales']) {
        $user = api_allow('new_sale');
        api_response(create_sale(api_body(), (int) $user['user_id']), 201);
    }

    if ($method === 'GET' && $segments === ['customers']) {
        api_customer_access();
        $customers = rows("SELECT c.customer_id, c.customer_name, c.phone_number, c.address, c.created_at, COALESCE(SUM(CASE WHEN d.status <> 'Paid' THEN d.remaining_balance ELSE 0 END),0) outstanding_balance FROM customers c LEFT JOIN customer_debts d ON d.customer_id=c.customer_id GROUP BY c.customer_id ORDER BY c.customer_name");
        api_response($customers);
    }

    if ($method === 'GET' && count($segments) === 3 && $segments[0] === 'customers' && $segments[2] === 'debts') {
        api_customer_access();
        $customerId = (int) $segments[1];
        if (!(int) value('SELECT COUNT(*) FROM customers WHERE customer_id=?', [$customerId])) api_error('Customer not found.', 404);
        $debts = rows("SELECT d.debt_id, d.sale_id, d.total_debt, d.remaining_balance, d.due_date, d.status, d.created_at, s.receipt_number FROM customer_debts d JOIN sales s ON s.sale_id=d.sale_id WHERE d.customer_id=? ORDER BY d.created_at DESC", [$customerId]);
        $payments = rows("SELECT dp.payment_id, dp.debt_id, dp.amount_paid, dp.payment_date, dp.notes, u.full_name recorded_by FROM debt_payments dp JOIN customer_debts d ON d.debt_id=dp.debt_id JOIN users u ON u.user_id=dp.user_id WHERE d.customer_id=? ORDER BY dp.payment_date DESC", [$customerId]);
        api_response(['debts' => $debts, 'payments' => $payments]);
    }

    if ($method === 'POST' && count($segments) === 3 && $segments[0] === 'customers' && $segments[2] === 'payments') {
        $user = api_allow('pay_debt');
        $body = api_body();
        api_response(pay_customer_debt((int) $segments[1], (float) ($body['amount_received'] ?? 0), (int) $user['user_id'], trim((string) ($body['notes'] ?? '')) ?: null), 201);
    }

    if ($method === 'GET' && $segments === ['inventory']) {
        $user = api_user();
        if (!in_array($user['role'], ['Owner', 'Assistant'], true)) api_error('Your account cannot access inventory management.', 403);
        api_response(rows("SELECT i.inventory_id, i.product_id, p.product_name, p.barcode, i.quantity_in_stock, i.reorder_level, i.last_updated FROM inventory i JOIN products p ON p.product_id=i.product_id ORDER BY p.product_name"));
    }

    if ($method === 'POST' && $segments === ['stock-in']) {
        api_allow('stock_in');
        $body = api_body();
        $productId = (int) ($body['product_id'] ?? 0);
        $quantity = (int) ($body['quantity'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) throw new InvalidArgumentException('A valid product and positive quantity are required.');
        if (!(int) value("SELECT COUNT(*) FROM products WHERE product_id=? AND status='Available'", [$productId])) api_error('Product not found or unavailable.', 404);
        $expirationDate = trim((string) ($body['expiration_date'] ?? '')) ?: null;
        $manufacturedDate = trim((string) ($body['manufactured_date'] ?? '')) ?: null;
        if ($manufacturedDate && $expirationDate && $manufacturedDate > $expirationDate) throw new InvalidArgumentException('Expiration date cannot be earlier than the manufactured date.');
        db()->beginTransaction();
        try {
            db()->prepare('INSERT INTO inventory (product_id, quantity_in_stock, reorder_level) VALUES (?, ?, 5) ON DUPLICATE KEY UPDATE quantity_in_stock=quantity_in_stock+VALUES(quantity_in_stock)')->execute([$productId, $quantity]);
            db()->prepare('INSERT INTO product_batches (product_id, batch_number, quantity, manufactured_date, expiration_date, date_received) VALUES (?, ?, ?, ?, ?, CURDATE())')->execute([$productId, trim((string) ($body['batch_number'] ?? '')) ?: null, $quantity, $manufacturedDate, $expirationDate]);
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            throw $error;
        }
        api_response(['product_id' => $productId, 'quantity_added' => $quantity, 'quantity_in_stock' => (int) value('SELECT quantity_in_stock FROM inventory WHERE product_id=?', [$productId]), 'batch_number' => trim((string) ($body['batch_number'] ?? '')) ?: null, 'expiration_date' => $expirationDate], 201);
    }

    api_error('API endpoint not found.', 404, ['path' => '/' . $path]);
} catch (InvalidArgumentException $error) {
    api_error($error->getMessage(), 422);
} catch (PDOException $error) {
    error_log($error->getMessage());
    api_error('A database error prevented the request from completing.', 500);
} catch (Throwable $error) {
    error_log($error->getMessage());
    api_error('The request could not be completed.', 500);
}
