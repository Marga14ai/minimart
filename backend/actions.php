<?php

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/services.php';

$notice = null;
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '' && !csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    $notice = 'Your form expired or was invalid. Refresh the page and try again.';
    $action = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '' && !role_can($action)) {
    http_response_code(403);
    $notice = 'Your account does not have permission to perform that action.';
    $action = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'change_password') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['confirm_password'] ?? '');
    $userId = (int) (current_user()['user_id'] ?? 0);
    $storedHash = (string) value('SELECT password FROM users WHERE user_id=?', [$userId]);
    if (!password_verify($currentPassword, $storedHash)) {
        $notice = 'The current password is incorrect.';
    } elseif (strlen($newPassword) < 12) {
        $notice = 'The new password must contain at least 12 characters.';
    } elseif ($newPassword !== $confirmation) {
        $notice = 'The new password and confirmation do not match.';
    } elseif (password_verify($newPassword, $storedHash)) {
        $notice = 'Choose a new password that is different from the current password.';
    } else {
        db()->prepare('UPDATE users SET password=? WHERE user_id=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        db()->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$userId]);
        forget_remember_token();
        audit_event(db(), $userId, 'password.changed', 'user', $userId);
        session_regenerate_id(true);
        header('Location: index.php?password_changed=1#dashboard'); exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add_product', 'update_product'], true)) {
    try {
        db()->beginTransaction();
        $expirationDate = trim((string) ($_POST['expiration_date'] ?? '')) ?: null;
        $manufacturedDate = trim((string) ($_POST['manufactured_date'] ?? '')) ?: null;
        if ($manufacturedDate && $expirationDate && $manufacturedDate > $expirationDate) {
            throw new RuntimeException('Expiration date cannot be earlier than the manufactured date.');
        }
        $productData = [
            $_POST['category_id'] ?: null, $_POST['supplier_id'] ?: null, $_POST['barcode'] ?: null,
            trim($_POST['product_name']), trim($_POST['description'] ?? ''), (float) $_POST['selling_price'],
            (float) ($_POST['cost_price'] ?: 0), trim($_POST['unit'] ?? ''), (int) ($_POST['reorder_level'] ?: 5), 'Available'
        ];
        if ($action === 'add_product') {
            db()->prepare('INSERT INTO products (category_id, supplier_id, barcode, product_name, description, selling_price, cost_price, unit, reorder_level, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($productData);
            $productId = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO inventory (product_id, quantity_in_stock, reorder_level) VALUES (?, ?, ?)')->execute([$productId, (int) ($_POST['quantity'] ?: 0), (int) ($_POST['reorder_level'] ?: 5)]);
            $openingQuantity = max(0, (int) ($_POST['quantity'] ?? 0));
            $openingExpiry = $expirationDate;
            if ($openingQuantity > 0 && $openingExpiry) {
                db()->prepare('INSERT INTO product_batches (product_id, batch_number, quantity, manufactured_date, expiration_date, date_received) VALUES (?, ?, ?, ?, ?, CURDATE())')->execute([$productId, trim((string) ($_POST['batch_number'] ?? '')) ?: null, $openingQuantity, $manufacturedDate, $openingExpiry]);
            }
        } else {
            $productId = (int) $_POST['product_id'];
            $productData[] = $productId;
            db()->prepare('UPDATE products SET category_id=?, supplier_id=?, barcode=?, product_name=?, description=?, selling_price=?, cost_price=?, unit=?, reorder_level=?, status=? WHERE product_id=?')->execute($productData);
            $updatedQuantity = (int) value('SELECT COALESCE(quantity_in_stock,0) FROM inventory WHERE product_id=?', [$productId]);
            db()->prepare('UPDATE inventory SET reorder_level=? WHERE product_id=?')->execute([(int) ($_POST['reorder_level'] ?: 5), $productId]);
            if ($expirationDate) {
                $batchId = (int) (value('SELECT batch_id FROM product_batches WHERE product_id=? AND quantity>0 ORDER BY (expiration_date IS NULL) DESC, COALESCE(expiration_date,\'9999-12-31\'), batch_id DESC LIMIT 1', [$productId]) ?: 0);
                if ($batchId > 0) {
                    db()->prepare('UPDATE product_batches SET batch_number=COALESCE(?,batch_number), manufactured_date=COALESCE(?,manufactured_date), expiration_date=? WHERE batch_id=?')->execute([trim((string) ($_POST['batch_number'] ?? '')) ?: null, $manufacturedDate, $expirationDate, $batchId]);
                } else {
                    db()->prepare('INSERT INTO product_batches (product_id, batch_number, quantity, manufactured_date, expiration_date, date_received) VALUES (?, ?, ?, ?, ?, CURDATE())')->execute([$productId, trim((string) ($_POST['batch_number'] ?? '')) ?: null, $updatedQuantity, $manufacturedDate, $expirationDate]);
                }
            }
        }
        db()->commit();
        header('Location: index.php?saved=1#inventory'); exit;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        $notice = $error instanceof RuntimeException ? $error->getMessage() : 'Could not save the product. Check that the barcode is unique.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'archive_product') {
    db()->prepare("UPDATE products SET status='Unavailable' WHERE product_id=?")->execute([(int) $_POST['product_id']]);
    header('Location: index.php?archived=1#inventory'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_customer') {
    db()->prepare('INSERT INTO customers (customer_name, phone_number, address) VALUES (?, ?, ?)')->execute([trim($_POST['customer_name']), trim($_POST['phone_number'] ?? '') ?: null, trim($_POST['address'] ?? '') ?: null]);
    header('Location: index.php?customer_added=1#customers'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_supplier') {
    db()->prepare('INSERT INTO suppliers (supplier_name, contact_person, phone_number, email, address, status) VALUES (?, ?, ?, ?, ?, ?)')->execute([trim($_POST['supplier_name']), trim($_POST['contact_person'] ?? '') ?: null, trim($_POST['phone_number'] ?? '') ?: null, trim($_POST['email'] ?? '') ?: null, trim($_POST['address'] ?? '') ?: null, 'Active']);
    header('Location: index.php?supplier_added=1#suppliers'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_user_name') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $names = $_POST['full_name'] ?? [];
    $fullName = trim((string) ($names[$userId] ?? ''));
    if ((current_user()['role'] ?? '') !== 'Owner') {
        $notice = 'Only the owner can change user names.';
    } elseif ($userId <= 0 || $fullName === '' || mb_strlen($fullName) > 100) {
        $notice = 'Enter a valid name containing no more than 100 characters.';
    } elseif (!(int) value('SELECT COUNT(*) FROM users WHERE user_id=?', [$userId])) {
        $notice = 'The selected user account does not exist.';
    } else {
        db()->prepare('UPDATE users SET full_name=? WHERE user_id=?')->execute([$fullName, $userId]);
        if ((int) (current_user()['user_id'] ?? 0) === $userId) $_SESSION['user']['full_name'] = $fullName;
        header('Location: index.php?user_updated=1#users'); exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'revoke_remember_token') {
    $tokenId = (int) ($_POST['token_id'] ?? 0);
    if ($tokenId > 0) {
        db()->prepare('DELETE FROM remember_tokens WHERE token_id=?')->execute([$tokenId]);
        audit_event(db(), (int) current_user()['user_id'], 'session.revoked', 'remember_token', $tokenId);
    }
    header('Location: index.php?session_revoked=1#audit'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'stock_in') {
    $productId = (int) $_POST['product_id'];
    $quantity = max(1, (int) $_POST['quantity']);
    $expirationDate = trim((string) ($_POST['expiration_date'] ?? '')) ?: null;
    $manufacturedDate = trim((string) ($_POST['manufactured_date'] ?? '')) ?: null;
    if ($manufacturedDate && $expirationDate && $manufacturedDate > $expirationDate) {
        $notice = 'Expiration date cannot be earlier than the manufactured date.';
    } else {
        try {
            db()->beginTransaction();
            db()->prepare('INSERT INTO inventory (product_id, quantity_in_stock, reorder_level) VALUES (?, ?, 5) ON DUPLICATE KEY UPDATE quantity_in_stock=quantity_in_stock+VALUES(quantity_in_stock)')->execute([$productId, $quantity]);
            db()->prepare('INSERT INTO product_batches (product_id, batch_number, quantity, manufactured_date, expiration_date, date_received) VALUES (?, ?, ?, ?, ?, CURDATE())')->execute([$productId, trim((string) ($_POST['batch_number'] ?? '')) ?: null, $quantity, $manufacturedDate, $expirationDate]);
            $batchId = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO batch_movements (batch_id, product_id, quantity_change, movement_type, user_id) VALUES (?, ?, ?, ?, ?)')->execute([$batchId, $productId, $quantity, 'Stock In', (int) current_user()['user_id']]);
            audit_event(db(), (int) current_user()['user_id'], 'inventory.received', 'product', $productId, ['quantity' => $quantity, 'batch_id' => $batchId]);
            db()->commit();
            header('Location: index.php?stock_added=1#inventory'); exit;
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            $notice = 'Could not receive the stock batch.';
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'adjust_inventory') {
    try {
        adjust_inventory((int) ($_POST['product_id'] ?? 0), (int) ($_POST['new_quantity'] ?? -1), (string) ($_POST['reason'] ?? ''), (int) current_user()['user_id']);
        header('Location: index.php?inventory_adjusted=1#inventory'); exit;
    } catch (Throwable $error) { $notice = $error->getMessage(); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'void_sale') {
    try {
        void_sale((int) ($_POST['sale_id'] ?? 0), (string) ($_POST['reason'] ?? ''), (int) current_user()['user_id']);
        header('Location: index.php?sale_voided=1#sales'); exit;
    } catch (Throwable $error) { $notice = $error->getMessage(); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'receive_supplier_order') {
    try {
        receive_supplier_order((int) ($_POST['order_id'] ?? 0), (array) ($_POST['received'] ?? []), (int) current_user()['user_id']);
        header('Location: index.php?order_received=1#suppliers'); exit;
    } catch (Throwable $error) { $notice = $error->getMessage(); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'new_sale') {
    try {
        db()->beginTransaction();
        $cart = json_decode($_POST['cart_json'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        if (!$cart || !is_array($cart)) throw new RuntimeException('Add at least one product before continuing.');

        $verifiedItems = [];
        $total = 0.0;
        foreach ($cart as $item) {
            $productId = (int) ($item['id'] ?? 0);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $product = rows("SELECT p.product_name, p.selling_price, p.status, COALESCE(i.quantity_in_stock,0) stock FROM products p LEFT JOIN inventory i ON i.product_id=p.product_id WHERE p.product_id=? FOR UPDATE", [$productId])[0] ?? null;
            if (!$product || $product['status'] !== 'Available') throw new RuntimeException('One product in the cart is unavailable.');
            if ((int) $product['stock'] < $quantity) throw new RuntimeException($product['product_name'] . ' does not have enough stock.');
            $subtotal = round((float) $product['selling_price'] * $quantity, 2);
            $total += $subtotal;
            $verifiedItems[] = ['id' => $productId, 'quantity' => $quantity, 'price' => (float) $product['selling_price'], 'subtotal' => $subtotal];
        }
        $total = round($total, 2);
        $userId = (int) (current_user()['user_id'] ?? 0);
        if (!$userId) throw new RuntimeException('An active user is required to record a sale.');

        $paymentType = trim((string) ($_POST['payment_type'] ?? 'Cash'));
        if (!in_array($paymentType, ['Cash', 'GCash', 'Credit'], true)) $paymentType = 'Cash';
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        if ($paymentType === 'Credit' && !$customerId) throw new RuntimeException('Select a customer for a credit or utang sale.');
        $paid = $paymentType === 'Cash' ? round(max(0, (float) ($_POST['amount_paid'] ?? 0)), 2) : ($paymentType === 'GCash' ? $total : 0.0);
        if ($paymentType === 'Cash' && $paid < $total) throw new RuntimeException('Cash received is less than the sale total.');
        $change = $paymentType === 'Cash' ? round($paid - $total, 2) : 0.0;

        $receipt = 'MM-' . date('Ymd-His') . '-' . random_int(10, 99);
        db()->prepare('INSERT INTO sales (user_id, customer_id, receipt_number, total_amount, amount_paid, change_amount, payment_method, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$userId, $customerId ?: null, $receipt, $total, $paid, $change, $paymentType, 'Completed']);
        $saleId = (int) db()->lastInsertId();
        foreach ($verifiedItems as $item) {
            db()->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, discount, subtotal) VALUES (?, ?, ?, ?, 0, ?)')->execute([$saleId, $item['id'], $item['quantity'], $item['price'], $item['subtotal']]);
            move_batch_stock(db(), $item['id'], $item['quantity'], 'Sale', $saleId, $userId);
            db()->prepare('UPDATE inventory SET quantity_in_stock=quantity_in_stock-? WHERE product_id=?')->execute([$item['quantity'], $item['id']]);
        }
        if ($paymentType === 'Credit') {
            db()->prepare("INSERT INTO customer_debts (customer_id, sale_id, total_debt, remaining_balance, due_date, status) VALUES (?, ?, ?, ?, ?, 'Unpaid')")->execute([$customerId, $saleId, $total, $total, ($_POST['due_date'] ?? '') ?: null]);
        }
        audit_event(db(), $userId, 'sale.completed', 'sale', $saleId, ['receipt_number' => $receipt, 'total' => $total, 'payment_method' => $paymentType]);
        db()->commit();
        header('Location: index.php?sale_added=1&payment=' . strtolower($paymentType) . '#sales'); exit;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        $notice = $error->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pay_debt') {
    try {
        db()->beginTransaction();
        $customerId = (int) $_POST['customer_id'];
        $received = round(max(0, (float) $_POST['amount_received']), 2);
        $debts = rows("SELECT debt_id, remaining_balance FROM customer_debts WHERE customer_id=? AND status <> 'Paid' AND remaining_balance > 0 ORDER BY COALESCE(due_date, DATE(created_at)), debt_id FOR UPDATE", [$customerId]);
        $balance = round(array_sum(array_column($debts, 'remaining_balance')), 2);
        if ($balance <= 0) throw new RuntimeException('This customer has no outstanding debt.');
        if ($received <= 0) throw new RuntimeException('Enter the amount received from the customer.');
        $toApply = min($received, $balance);
        $remainingPayment = $toApply;
        foreach ($debts as $debt) {
            if ($remainingPayment <= 0) break;
            $applied = min($remainingPayment, (float) $debt['remaining_balance']);
            $newBalance = round((float) $debt['remaining_balance'] - $applied, 2);
            $status = $newBalance <= 0 ? 'Paid' : 'Partially Paid';
            db()->prepare('INSERT INTO debt_payments (debt_id, user_id, amount_paid, notes) VALUES (?, ?, ?, ?)')->execute([(int) $debt['debt_id'], (int) current_user()['user_id'], $applied, trim($_POST['notes'] ?? '') ?: null]);
            db()->prepare('UPDATE customer_debts SET remaining_balance=?, status=? WHERE debt_id=?')->execute([$newBalance, $status, (int) $debt['debt_id']]);
            $remainingPayment = round($remainingPayment - $applied, 2);
        }
        audit_event(db(), (int) current_user()['user_id'], 'debt.payment_recorded', 'customer', $customerId, ['amount_received' => $received, 'amount_applied' => $toApply]);
        db()->commit();
        $change = round(max(0, $received - $balance), 2);
        header('Location: index.php?debt_paid=1&applied=' . urlencode((string) $toApply) . '&change=' . urlencode((string) $change) . '#customers'); exit;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        $notice = $error->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'order_supplier') {
    try {
        $supplierId = (int) $_POST['supplier_id'];
        $supplier = rows("SELECT supplier_name, email FROM suppliers WHERE supplier_id=? AND status='Active'", [$supplierId])[0] ?? null;
        if (!$supplier) throw new RuntimeException('The selected supplier is unavailable.');
        $supplierEmail = trim($_POST['supplier_email'] ?? $supplier['email'] ?? '');
        if (!filter_var($supplierEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid supplier email address before placing the order.');
        if ($supplierEmail !== ($supplier['email'] ?? '')) db()->prepare('UPDATE suppliers SET email=? WHERE supplier_id=?')->execute([$supplierEmail, $supplierId]);
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $packages = $_POST['package_type'] ?? [];
        $unitsPerBox = $_POST['units_per_box'] ?? [];
        $newProductNames = $_POST['new_product_name'] ?? [];
        $newProductBarcodes = $_POST['new_product_barcode'] ?? [];
        $newProductUnits = $_POST['new_product_unit'] ?? [];
        $newProductCosts = $_POST['new_product_cost'] ?? [];
        $newProductPrices = $_POST['new_product_price'] ?? [];
        if (!$productIds) throw new RuntimeException('Add at least one product to the supplier order.');

        db()->beginTransaction();
        $orderNumber = 'PO-' . date('Ymd-His') . '-' . random_int(10, 99);
        db()->prepare("INSERT INTO supplier_orders (supplier_id,user_id,order_number,notes) VALUES (?,?,?,?)")->execute([$supplierId, (int) current_user()['user_id'], $orderNumber, trim($_POST['notes'] ?? '') ?: null]);
        $orderId = (int) db()->lastInsertId();
        $emailItems = [];
        foreach ($productIds as $index => $productId) {
            $quantity = max(1, (int) ($quantities[$index] ?? 1));
            $package = ($packages[$index] ?? 'Piece') === 'Box' ? 'Box' : 'Piece';
            $perBox = $package === 'Box' ? max(1, (int) ($unitsPerBox[$index] ?? 1)) : 1;
            if ((string) $productId === 'new') {
                $newName = trim((string) ($newProductNames[$index] ?? ''));
                $newBarcode = trim((string) ($newProductBarcodes[$index] ?? '')) ?: null;
                if ($newName === '') throw new RuntimeException('Enter the name of every new product.');
                if ($newBarcode && (int) value('SELECT COUNT(*) FROM products WHERE barcode=?', [$newBarcode])) throw new RuntimeException('The barcode for ' . $newName . ' is already used by another product.');
                $newPrice = max(0, (float) ($newProductPrices[$index] ?? 0));
                $newCost = max(0, (float) ($newProductCosts[$index] ?? 0));
                db()->prepare("INSERT INTO products (supplier_id,barcode,product_name,selling_price,cost_price,unit,reorder_level,status) VALUES (?,?,?,?,?,?,5,'Available')")->execute([$supplierId, $newBarcode, $newName, $newPrice, $newCost, trim((string) ($newProductUnits[$index] ?? '')) ?: null]);
                $productId = (int) db()->lastInsertId();
                db()->prepare('INSERT INTO inventory (product_id,quantity_in_stock,reorder_level) VALUES (?,0,5)')->execute([$productId]);
                $product = ['product_name' => $newName];
            } else {
                $product = rows('SELECT product_name FROM products WHERE product_id=? AND supplier_id=?', [(int) $productId, $supplierId])[0] ?? null;
                if (!$product) throw new RuntimeException('One selected product does not belong to this supplier.');
            }
            $totalUnits = $quantity * $perBox;
            db()->prepare('INSERT INTO supplier_order_items (order_id,product_id,quantity,package_type,units_per_box,total_units) VALUES (?,?,?,?,?,?)')->execute([$orderId, (int) $productId, $quantity, $package, $perBox, $totalUnits]);
            $emailItems[] = ['product_name' => $product['product_name'], 'quantity' => $quantity, 'package_type' => $package, 'units_per_box' => $perBox, 'total_units' => $totalUnits];
        }
        db()->commit();
        audit_event(db(), (int) current_user()['user_id'], 'supplier_order.created', 'supplier_order', $orderId, ['order_number' => $orderNumber]);
        $subject = "Purchase Order {$orderNumber} | SME MiniMart";
        $email = supplier_order_email_content(['order_number' => $orderNumber, 'supplier_name' => $supplier['supplier_name'], 'order_date' => date('F j, Y'), 'requested_by' => current_user()['full_name'], 'notes' => trim($_POST['notes'] ?? ''), 'items' => $emailItems]);
        $sent = send_supplier_order_email($supplierEmail, $subject, $email['html'], $email['plain']);
        if ($sent) db()->prepare("UPDATE supplier_orders SET status='Emailed', emailed_at=NOW() WHERE order_id=?")->execute([$orderId]);
        header('Location: index.php?order_saved=1&email=' . ($sent ? 'sent' : 'pending') . '&number=' . urlencode($orderNumber) . '#suppliers'); exit;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        $notice = $error->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generate_report') {
    $allowedReports = ['Sales', 'Inventory', 'Low Stock', 'Expiration', 'Debts'];
    $reportType = in_array($_POST['report_type'] ?? '', $allowedReports, true) ? $_POST['report_type'] : 'Sales';
    $startDate = $_POST['start_date'] ?: date('Y-m-01');
    $endDate = $_POST['end_date'] ?: date('Y-m-d');
    db()->prepare('INSERT INTO reports (report_type,start_date,end_date,generated_by,notes) VALUES (?,?,?,?,?)')->execute([$reportType, $startDate, $endDate, (int) current_user()['user_id'], trim($_POST['notes'] ?? '') ?: null]);
    header('Location: index.php?report=' . urlencode($reportType) . '&start=' . urlencode($startDate) . '&end=' . urlencode($endDate) . '#reports'); exit;
}
if (isset($_GET['saved'])) $notice = 'Product saved successfully.';
if (isset($_GET['archived'])) $notice = 'Product marked as unavailable.';
if (isset($_GET['customer_added'])) $notice = 'Customer added successfully.';
if (isset($_GET['supplier_added'])) $notice = 'Supplier added successfully.';
if (isset($_GET['stock_added'])) $notice = 'Stock quantity updated successfully.';
if (isset($_GET['inventory_adjusted'])) $notice = 'Inventory adjustment saved with its reason.';
if (isset($_GET['sale_voided'])) $notice = 'Sale voided and its stock was restored.';
if (isset($_GET['order_received'])) $notice = 'Supplier delivery received and inventory updated.';
if (isset($_GET['sale_added'])) $notice = 'Sale completed successfully.';
if (isset($_GET['user_updated'])) $notice = 'User name updated successfully.';
if (isset($_GET['debt_paid'])) $notice = 'Debt payment recorded. Applied: ₱' . number_format((float) ($_GET['applied'] ?? 0), 2) . ' · Change: ₱' . number_format((float) ($_GET['change'] ?? 0), 2);
if (isset($_GET['order_saved'])) $notice = ($_GET['email'] ?? '') === 'sent' ? 'Purchase order ' . ($_GET['number'] ?? '') . ' was saved and emailed through Gmail.' : 'Purchase order ' . ($_GET['number'] ?? '') . ' was saved, but Gmail SMTP is not configured or could not deliver it. The order is marked Pending Email.';
if (isset($_GET['password_changed'])) $notice = 'Your password was changed successfully.';
if (isset($_GET['session_revoked'])) $notice = 'The remembered device session was revoked.';
