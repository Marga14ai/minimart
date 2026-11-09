<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';
$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
    audit_id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(50) NOT NULL, entity_id INT NOT NULL, details JSON NULL,
    ip_address VARCHAR(45) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id), INDEX(entity_type, entity_id), INDEX(created_at)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS batch_movements (
    movement_id BIGINT AUTO_INCREMENT PRIMARY KEY, batch_id INT NOT NULL, product_id INT NOT NULL,
    quantity_change INT NOT NULL, movement_type VARCHAR(40) NOT NULL, reference_id INT NULL,
    user_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(batch_id), INDEX(product_id), INDEX(movement_type, reference_id), INDEX(user_id)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS stock_adjustments (
    adjustment_id BIGINT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, user_id INT NOT NULL,
    old_quantity INT NOT NULL, new_quantity INT NOT NULL, reason VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(product_id), INDEX(user_id)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS sale_voids (
    void_id BIGINT AUTO_INCREMENT PRIMARY KEY, sale_id INT NOT NULL UNIQUE, user_id INT NOT NULL,
    reason VARCHAR(255) NOT NULL, voided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
    token_id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, selector CHAR(24) NOT NULL UNIQUE,
    token_hash CHAR(64) NOT NULL, user_agent_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id), INDEX(expires_at)
) ENGINE=InnoDB");
$pdo->exec('ALTER TABLE audit_logs MODIFY user_id INT NULL');

function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $pdo->quote($table) . " AND COLUMN_NAME=" . $pdo->quote($column))->fetchColumn();
    if (!$exists) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}

add_column_if_missing($pdo, 'supplier_order_items', 'received_quantity', 'INT NOT NULL DEFAULT 0 AFTER total_units');
add_column_if_missing($pdo, 'supplier_orders', 'received_at', 'DATETIME NULL AFTER emailed_at');
$columnType = (string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='supplier_orders' AND COLUMN_NAME='status'")->fetchColumn();
if ($columnType !== '' && !str_contains($columnType, 'Partially Received')) {
    $pdo->exec("ALTER TABLE supplier_orders MODIFY status ENUM('Pending Email','Emailed','Partially Received','Received','Cancelled') NOT NULL DEFAULT 'Pending Email'");
}

// Bring legacy batch balances in line with the existing inventory summary once.
foreach ($pdo->query('SELECT product_id, quantity_in_stock FROM inventory')->fetchAll() as $inventory) {
    $productId = (int) $inventory['product_id'];
    $target = (int) $inventory['quantity_in_stock'];
    $batchTotalStatement = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM product_batches WHERE product_id=?');
    $batchTotalStatement->execute([$productId]);
    $difference = $target - (int) $batchTotalStatement->fetchColumn();
    if ($difference > 0) {
        $pdo->prepare("INSERT INTO product_batches (product_id,batch_number,quantity,date_received) VALUES (?,'LEGACY-OPENING',?,CURDATE())")->execute([$productId,$difference]);
    } elseif ($difference < 0) {
        $toRemove = abs($difference);
        $batches = $pdo->prepare("SELECT batch_id,quantity FROM product_batches WHERE product_id=? AND quantity>0 ORDER BY (expiration_date IS NULL),expiration_date,date_received,batch_id");
        $batches->execute([$productId]);
        foreach ($batches->fetchAll() as $batch) {
            if ($toRemove <= 0) break;
            $amount = min($toRemove,(int)$batch['quantity']);
            $pdo->prepare('UPDATE product_batches SET quantity=quantity-? WHERE batch_id=?')->execute([$amount,(int)$batch['batch_id']]);
            $toRemove -= $amount;
        }
    }
}

echo "MiniMart database migration completed.\n";
