<?php

declare(strict_types=1);

function audit_event(PDO $pdo, int $userId, string $action, string $entityType, int $entityId, array $details = []): void
{
    $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $action, $entityType, $entityId, $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null]);
}

function move_batch_stock(PDO $pdo, int $productId, int $quantity, string $movementType, ?int $referenceId, int $userId): array
{
    if ($quantity <= 0) throw new InvalidArgumentException('Stock quantity must be greater than zero.');
    $remaining = $quantity;
    $moves = [];
    $batches = rows("SELECT batch_id, quantity FROM product_batches WHERE product_id=? AND quantity>0 ORDER BY (expiration_date IS NULL), expiration_date, date_received, batch_id FOR UPDATE", [$productId]);
    foreach ($batches as $batch) {
        if ($remaining <= 0) break;
        $used = min($remaining, (int) $batch['quantity']);
        $pdo->prepare('UPDATE product_batches SET quantity=quantity-? WHERE batch_id=?')->execute([$used, (int) $batch['batch_id']]);
        $pdo->prepare('INSERT INTO batch_movements (batch_id, product_id, quantity_change, movement_type, reference_id, user_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int) $batch['batch_id'], $productId, -$used, $movementType, $referenceId, $userId]);
        $moves[] = ['batch_id' => (int) $batch['batch_id'], 'quantity' => $used];
        $remaining -= $used;
    }
    if ($remaining > 0) throw new RuntimeException('Batch quantities do not match the available inventory. Receive or adjust the product batches first.');
    return $moves;
}

function restore_sale_batches(PDO $pdo, int $saleId, int $userId): void
{
    $movements = rows("SELECT batch_id, product_id, -SUM(quantity_change) quantity FROM batch_movements WHERE movement_type='Sale' AND reference_id=? GROUP BY batch_id, product_id FOR UPDATE", [$saleId]);
    if (!$movements) {
        // Sales recorded before batch tracking are restored into a clearly marked legacy batch.
        foreach (rows('SELECT product_id, quantity FROM sale_items WHERE sale_id=?', [$saleId]) as $item) {
            $pdo->prepare("INSERT INTO product_batches (product_id,batch_number,quantity,date_received) VALUES (?,'LEGACY-VOID',?,CURDATE())")->execute([(int) $item['product_id'], (int) $item['quantity']]);
            $batchId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO batch_movements (batch_id, product_id, quantity_change, movement_type, reference_id, user_id) VALUES (?, ?, ?, ?, ?, ?)')->execute([$batchId, (int) $item['product_id'], (int) $item['quantity'], 'Sale Void', $saleId, $userId]);
        }
        return;
    }
    foreach ($movements as $movement) {
        $quantity = (int) $movement['quantity'];
        if ($quantity <= 0) continue;
        $pdo->prepare('UPDATE product_batches SET quantity=quantity+? WHERE batch_id=?')->execute([$quantity, (int) $movement['batch_id']]);
        $pdo->prepare('INSERT INTO batch_movements (batch_id, product_id, quantity_change, movement_type, reference_id, user_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int) $movement['batch_id'], (int) $movement['product_id'], $quantity, 'Sale Void', $saleId, $userId]);
    }
}

function create_sale(array $data, int $userId): array
{
    $items = $data['items'] ?? [];
    if (!is_array($items) || !$items) {
        throw new InvalidArgumentException('Add at least one product before continuing.');
    }

    $quantities = [];
    foreach ($items as $item) {
        if (!is_array($item)) throw new InvalidArgumentException('The sale contains an invalid product.');
        $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);
        $quantity = (int) ($item['quantity'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) throw new InvalidArgumentException('Every sale item needs a valid product and quantity.');
        $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
    }

    $paymentType = trim((string) ($data['payment_method'] ?? $data['payment_type'] ?? 'Cash'));
    if (!in_array($paymentType, ['Cash', 'GCash', 'Credit'], true)) {
        throw new InvalidArgumentException('Payment method must be Cash, GCash, or Credit.');
    }
    $customerId = (int) ($data['customer_id'] ?? 0);
    $newCustomerName = trim((string) ($data['new_customer_name'] ?? ''));
    $newCustomerPhone = trim((string) ($data['new_customer_phone'] ?? ''));
    $createCustomer = $paymentType === 'Credit' && $customerId <= 0 && ($newCustomerName !== '' || $newCustomerPhone !== '');
    if ($paymentType === 'Credit' && $customerId <= 0 && !$createCustomer) {
        throw new InvalidArgumentException('Select an existing customer or enter a new customer.');
    }
    if ($createCustomer && ($newCustomerName === '' || $newCustomerPhone === '')) {
        throw new InvalidArgumentException('The new credit customer needs a name and contact number.');
    }
    if (strlen($newCustomerName) > 150 || strlen($newCustomerPhone) > 20) {
        throw new InvalidArgumentException('The new customer details are too long.');
    }
    $customerName = 'Walk-in Customer';
    if ($customerId > 0) {
        $customerName = (string) value('SELECT customer_name FROM customers WHERE customer_id=?', [$customerId]);
        if ($customerName === '') throw new InvalidArgumentException('The selected customer does not exist.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $customerCreated = false;
        if ($createCustomer) {
            $pdo->prepare('INSERT INTO customers (customer_name, phone_number, address) VALUES (?, ?, NULL)')->execute([$newCustomerName, $newCustomerPhone]);
            $customerId = (int) $pdo->lastInsertId();
            $customerName = $newCustomerName;
            $customerCreated = true;
        }
        $verifiedItems = [];
        $total = 0.0;
        foreach ($quantities as $productId => $quantity) {
            $product = rows("SELECT p.product_name, p.selling_price, p.status, COALESCE(i.quantity_in_stock,0) stock FROM products p LEFT JOIN inventory i ON i.product_id=p.product_id WHERE p.product_id=? FOR UPDATE", [$productId])[0] ?? null;
            if (!$product || $product['status'] !== 'Available') throw new RuntimeException('One product in the cart is unavailable.');
            if ((int) $product['stock'] < $quantity) throw new RuntimeException($product['product_name'] . ' does not have enough stock.');
            $subtotal = round((float) $product['selling_price'] * $quantity, 2);
            $total = round($total + $subtotal, 2);
            $verifiedItems[] = ['product_id' => $productId, 'product_name' => $product['product_name'], 'quantity' => $quantity, 'unit_price' => (float) $product['selling_price'], 'subtotal' => $subtotal];
        }

        $paid = $paymentType === 'Cash'
            ? round(max(0, (float) ($data['amount_paid'] ?? 0)), 2)
            : ($paymentType === 'GCash' ? $total : 0.0);
        if ($paymentType === 'Cash' && $paid < $total) throw new InvalidArgumentException('Cash received is less than the sale total.');
        $change = $paymentType === 'Cash' ? round($paid - $total, 2) : 0.0;
        $dueDate = trim((string) ($data['due_date'] ?? '')) ?: null;
        if ($dueDate !== null && !DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate)) throw new InvalidArgumentException('The credit due date is invalid.');

        $receipt = 'MM-' . date('Ymd-His') . '-' . random_int(10, 99);
        $pdo->prepare('INSERT INTO sales (user_id, customer_id, receipt_number, total_amount, amount_paid, change_amount, payment_method, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$userId, $customerId ?: null, $receipt, $total, $paid, $change, $paymentType, 'Completed']);
        $saleId = (int) $pdo->lastInsertId();
        foreach ($verifiedItems as $item) {
            $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, discount, subtotal) VALUES (?, ?, ?, ?, 0, ?)')->execute([$saleId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']]);
            move_batch_stock($pdo, $item['product_id'], $item['quantity'], 'Sale', $saleId, $userId);
            $pdo->prepare('UPDATE inventory SET quantity_in_stock=quantity_in_stock-? WHERE product_id=?')->execute([$item['quantity'], $item['product_id']]);
        }
        if ($paymentType === 'Credit') {
            $pdo->prepare("INSERT INTO customer_debts (customer_id, sale_id, total_debt, remaining_balance, due_date, status) VALUES (?, ?, ?, ?, ?, 'Unpaid')")->execute([$customerId, $saleId, $total, $total, $dueDate]);
        }
        audit_event($pdo, $userId, 'sale.completed', 'sale', $saleId, ['receipt_number' => $receipt, 'total' => $total, 'payment_method' => $paymentType]);
        $pdo->commit();
        return ['sale_id' => $saleId, 'receipt_number' => $receipt, 'sale_date' => date('Y-m-d H:i:s'), 'total_amount' => $total, 'amount_paid' => $paid, 'change_amount' => $change, 'payment_method' => $paymentType, 'due_date' => $paymentType === 'Credit' ? $dueDate : null, 'customer_id' => $customerId ?: null, 'customer_name' => $customerName, 'customer_created' => $customerCreated, 'items' => $verifiedItems];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function adjust_inventory(int $productId, int $newQuantity, string $reason, int $userId): array
{
    $reason = trim($reason);
    if ($productId <= 0 || $newQuantity < 0 || $reason === '') throw new InvalidArgumentException('Choose a product, enter a valid quantity, and provide a reason.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $current = rows('SELECT quantity_in_stock FROM inventory WHERE product_id=? FOR UPDATE', [$productId])[0] ?? null;
        if (!$current) throw new RuntimeException('The selected product has no inventory record.');
        $oldQuantity = (int) $current['quantity_in_stock'];
        $difference = $newQuantity - $oldQuantity;
        if ($difference < 0) {
            move_batch_stock($pdo, $productId, abs($difference), 'Adjustment', null, $userId);
        } elseif ($difference > 0) {
            $pdo->prepare('INSERT INTO product_batches (product_id, batch_number, quantity, date_received) VALUES (?, ?, ?, CURDATE())')->execute([$productId, 'ADJ-' . date('Ymd-His'), $difference]);
            $batchId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO batch_movements (batch_id, product_id, quantity_change, movement_type, user_id) VALUES (?, ?, ?, ?, ?)')->execute([$batchId, $productId, $difference, 'Adjustment', $userId]);
        }
        $pdo->prepare('UPDATE inventory SET quantity_in_stock=? WHERE product_id=?')->execute([$newQuantity, $productId]);
        $pdo->prepare('INSERT INTO stock_adjustments (product_id, user_id, old_quantity, new_quantity, reason) VALUES (?, ?, ?, ?, ?)')->execute([$productId, $userId, $oldQuantity, $newQuantity, $reason]);
        audit_event($pdo, $userId, 'inventory.adjusted', 'product', $productId, ['old_quantity' => $oldQuantity, 'new_quantity' => $newQuantity, 'reason' => $reason]);
        $pdo->commit();
        return ['old_quantity' => $oldQuantity, 'new_quantity' => $newQuantity];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function void_sale(int $saleId, string $reason, int $userId): void
{
    $reason = trim($reason);
    if ($saleId <= 0 || $reason === '') throw new InvalidArgumentException('Select a sale and provide a void reason.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sale = rows('SELECT * FROM sales WHERE sale_id=? FOR UPDATE', [$saleId])[0] ?? null;
        if (!$sale) throw new RuntimeException('Sale not found.');
        if ($sale['status'] === 'Voided') throw new RuntimeException('This sale is already voided.');
        if ((float) value('SELECT COALESCE(SUM(dp.amount_paid),0) FROM debt_payments dp JOIN customer_debts d ON d.debt_id=dp.debt_id WHERE d.sale_id=?', [$saleId]) > 0) throw new RuntimeException('A credit sale with recorded payments cannot be voided. Refund the payment first.');
        $items = rows('SELECT product_id, quantity FROM sale_items WHERE sale_id=? FOR UPDATE', [$saleId]);
        restore_sale_batches($pdo, $saleId, $userId);
        foreach ($items as $item) $pdo->prepare('UPDATE inventory SET quantity_in_stock=quantity_in_stock+? WHERE product_id=?')->execute([(int) $item['quantity'], (int) $item['product_id']]);
        $pdo->prepare("UPDATE customer_debts SET remaining_balance=0, status='Paid' WHERE sale_id=?")->execute([$saleId]);
        $pdo->prepare("UPDATE sales SET status='Voided' WHERE sale_id=?")->execute([$saleId]);
        $pdo->prepare('INSERT INTO sale_voids (sale_id, user_id, reason) VALUES (?, ?, ?)')->execute([$saleId, $userId, $reason]);
        audit_event($pdo, $userId, 'sale.voided', 'sale', $saleId, ['receipt_number' => $sale['receipt_number'], 'reason' => $reason]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function receive_supplier_order(int $orderId, array $received, int $userId): void
{
    if ($orderId <= 0 || !$received) throw new InvalidArgumentException('Select an order and enter delivered quantities.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $order = rows('SELECT * FROM supplier_orders WHERE order_id=? FOR UPDATE', [$orderId])[0] ?? null;
        if (!$order) throw new RuntimeException('Supplier order not found.');
        if (in_array($order['status'], ['Received', 'Cancelled'], true)) throw new RuntimeException('This order can no longer receive stock.');
        $items = rows('SELECT * FROM supplier_order_items WHERE order_id=? FOR UPDATE', [$orderId]);
        $receivedAny = false;
        foreach ($items as $item) {
            $itemId = (int) $item['order_item_id'];
            $quantity = max(0, (int) ($received[$itemId]['quantity'] ?? 0));
            if ($quantity === 0) continue;
            $remaining = (int) $item['total_units'] - (int) $item['received_quantity'];
            if ($quantity > $remaining) throw new RuntimeException('Received quantity cannot exceed the outstanding order quantity.');
            $expiration = trim((string) ($received[$itemId]['expiration_date'] ?? '')) ?: null;
            $batchNumber = trim((string) ($received[$itemId]['batch_number'] ?? '')) ?: null;
            $pdo->prepare('INSERT INTO product_batches (product_id, batch_number, quantity, expiration_date, date_received) VALUES (?, ?, ?, ?, CURDATE())')->execute([(int) $item['product_id'], $batchNumber, $quantity, $expiration]);
            $batchId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO batch_movements (batch_id, product_id, quantity_change, movement_type, reference_id, user_id) VALUES (?, ?, ?, ?, ?, ?)')->execute([$batchId, (int) $item['product_id'], $quantity, 'Supplier Receipt', $orderId, $userId]);
            $pdo->prepare('INSERT INTO inventory (product_id, quantity_in_stock, reorder_level) VALUES (?, ?, 5) ON DUPLICATE KEY UPDATE quantity_in_stock=quantity_in_stock+VALUES(quantity_in_stock)')->execute([(int) $item['product_id'], $quantity]);
            $pdo->prepare('UPDATE supplier_order_items SET received_quantity=received_quantity+? WHERE order_item_id=?')->execute([$quantity, $itemId]);
            $receivedAny = true;
        }
        if (!$receivedAny) throw new InvalidArgumentException('Enter at least one delivered quantity.');
        $outstanding = (int) value('SELECT COUNT(*) FROM supplier_order_items WHERE order_id=? AND received_quantity<total_units', [$orderId]);
        $status = $outstanding === 0 ? 'Received' : 'Partially Received';
        $pdo->prepare('UPDATE supplier_orders SET status=?, received_at=IF(?="Received",NOW(),received_at) WHERE order_id=?')->execute([$status, $status, $orderId]);
        audit_event($pdo, $userId, 'supplier_order.received', 'supplier_order', $orderId, ['status' => $status]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function pay_customer_debt(int $customerId, float $received, int $userId, ?string $notes = null): array
{
    if ($customerId <= 0) throw new InvalidArgumentException('Select a valid customer.');
    $received = round(max(0, $received), 2);
    if ($received <= 0) throw new InvalidArgumentException('Enter the amount received from the customer.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $debts = rows("SELECT debt_id, remaining_balance FROM customer_debts WHERE customer_id=? AND status <> 'Paid' AND remaining_balance > 0 ORDER BY COALESCE(due_date, DATE(created_at)), debt_id FOR UPDATE", [$customerId]);
        $balance = round(array_sum(array_map('floatval', array_column($debts, 'remaining_balance'))), 2);
        if ($balance <= 0) throw new RuntimeException('This customer has no outstanding debt.');
        $appliedTotal = min($received, $balance);
        $remainingPayment = $appliedTotal;
        foreach ($debts as $debt) {
            if ($remainingPayment <= 0) break;
            $applied = min($remainingPayment, (float) $debt['remaining_balance']);
            $newBalance = round((float) $debt['remaining_balance'] - $applied, 2);
            $status = $newBalance <= 0 ? 'Paid' : 'Partially Paid';
            $pdo->prepare('INSERT INTO debt_payments (debt_id, user_id, amount_paid, notes) VALUES (?, ?, ?, ?)')->execute([(int) $debt['debt_id'], $userId, $applied, $notes ?: null]);
            $pdo->prepare('UPDATE customer_debts SET remaining_balance=?, status=? WHERE debt_id=?')->execute([$newBalance, $status, (int) $debt['debt_id']]);
            $remainingPayment = round($remainingPayment - $applied, 2);
        }
        audit_event($pdo, $userId, 'debt.payment_recorded', 'customer', $customerId, ['amount_received' => $received, 'amount_applied' => $appliedTotal]);
        $pdo->commit();
        return ['amount_received' => $received, 'amount_applied' => $appliedTotal, 'change_amount' => round(max(0, $received - $balance), 2), 'remaining_balance' => round(max(0, $balance - $appliedTotal), 2)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
