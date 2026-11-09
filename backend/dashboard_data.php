<?php

$todaySales = (float) value("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE status='Completed' AND DATE(sale_date)=CURDATE()");
$transactions = (int) value("SELECT COUNT(*) FROM sales WHERE status='Completed' AND DATE(sale_date)=CURDATE()");
$lowStock = (int) value('SELECT COUNT(*) FROM inventory WHERE quantity_in_stock <= reorder_level');
$nearExpiryCount = (int) value("SELECT COUNT(*) FROM product_batches WHERE quantity > 0 AND expiration_date IS NOT NULL AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$expiryAlerts = rows("SELECT b.batch_id, b.product_id, p.product_name, b.batch_number, b.quantity, b.expiration_date, DATEDIFF(b.expiration_date,CURDATE()) days_left FROM product_batches b JOIN products p ON p.product_id=b.product_id WHERE b.quantity > 0 AND b.expiration_date IS NOT NULL AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY b.expiration_date, p.product_name");
$productsCount = (int) value("SELECT COUNT(*) FROM products WHERE status='Available'");
$debtTotal = (float) value("SELECT COALESCE(SUM(remaining_balance),0) FROM customer_debts WHERE status <> 'Paid'");
$salesChart = ['day' => [], 'week' => [], 'month' => []];
$salesChart['day'][] = ['label' => 'Today', 'value' => $todaySales];
$weekSales = array_column(rows("SELECT DATE(sale_date) chart_key, SUM(total_amount) total FROM sales WHERE status='Completed' AND YEARWEEK(sale_date,1)=YEARWEEK(CURDATE(),1) GROUP BY DATE(sale_date)"), 'total', 'chart_key');
$weekStart = new DateTimeImmutable('monday this week');
for ($day = 0; $day < 7; $day++) {
    $date = $weekStart->modify("+$day days");
    $salesChart['week'][] = ['label' => $date->format('D'), 'value' => (float) ($weekSales[$date->format('Y-m-d')] ?? 0)];
}
$monthSales = array_column(rows("SELECT DATE(sale_date) chart_key, SUM(total_amount) total FROM sales WHERE status='Completed' AND YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE()) GROUP BY DATE(sale_date)"), 'total', 'chart_key');
$monthStart = new DateTimeImmutable('first day of this month');
for ($day = 0, $daysInMonth = (int) $monthStart->format('t'); $day < $daysInMonth; $day++) {
    $date = $monthStart->modify("+$day days");
    $salesChart['month'][] = ['label' => $date->format('j'), 'value' => (float) ($monthSales[$date->format('Y-m-d')] ?? 0)];
}
$inventory = rows("SELECT p.*, c.category_name, s.supplier_name, s.email supplier_email, COALESCE(i.quantity_in_stock,0) stock, COALESCE(i.reorder_level,p.reorder_level,5) reorder_level, (SELECT MIN(b.expiration_date) FROM product_batches b WHERE b.product_id=p.product_id AND b.quantity>0 AND b.expiration_date IS NOT NULL) nearest_expiration FROM products p LEFT JOIN categories c ON c.category_id=p.category_id LEFT JOIN suppliers s ON s.supplier_id=p.supplier_id LEFT JOIN inventory i ON i.product_id=p.product_id ORDER BY p.product_name");
$recentSales = rows("SELECT s.sale_id, s.receipt_number, s.sale_date, s.total_amount, s.payment_method, s.status, COALESCE(c.customer_name,'Walk-in Customer') customer_name FROM sales s LEFT JOIN customers c ON c.customer_id=s.customer_id ORDER BY s.sale_date DESC LIMIT 7");
$customers = rows("SELECT c.*, COALESCE(SUM(CASE WHEN d.status <> 'Paid' THEN d.remaining_balance ELSE 0 END),0) balance, MIN(CASE WHEN d.status <> 'Paid' AND d.remaining_balance>0 THEN d.due_date END) oldest_due_date, COALESCE(MAX(CASE WHEN d.status <> 'Paid' AND d.remaining_balance>0 AND d.due_date<CURDATE() THEN DATEDIFF(CURDATE(),d.due_date) ELSE 0 END),0) overdue_days FROM customers c LEFT JOIN customer_debts d ON d.customer_id=c.customer_id GROUP BY c.customer_id ORDER BY overdue_days DESC, c.customer_name");
$customerPaymentHistory = rows("SELECT d.customer_id, dp.payment_id, dp.amount_paid, dp.payment_date, dp.notes, COALESCE(u.full_name,'Unknown') recorded_by, s.receipt_number, COALESCE(s.sale_date,d.created_at) utang_date FROM debt_payments dp JOIN customer_debts d ON d.debt_id=dp.debt_id LEFT JOIN users u ON u.user_id=dp.user_id LEFT JOIN sales s ON s.sale_id=d.sale_id ORDER BY dp.payment_date DESC, dp.payment_id DESC");
$suppliers = rows("SELECT s.*, COUNT(p.product_id) product_count FROM suppliers s LEFT JOIN products p ON p.supplier_id=s.supplier_id GROUP BY s.supplier_id ORDER BY s.supplier_name");
$supplierOrders = page_allowed('suppliers') ? rows("SELECT so.*, s.supplier_name, u.full_name ordered_by FROM supplier_orders so JOIN suppliers s ON s.supplier_id=so.supplier_id JOIN users u ON u.user_id=so.user_id ORDER BY so.created_at DESC") : [];
$supplierOrderItems = [];
if ($supplierOrders) foreach (rows("SELECT soi.*, p.product_name FROM supplier_order_items soi JOIN products p ON p.product_id=soi.product_id ORDER BY soi.order_item_id") as $orderItem) $supplierOrderItems[(int) $orderItem['order_id']][] = $orderItem;
$categories = rows('SELECT * FROM categories ORDER BY category_name');
$users = (current_user()['role'] ?? '') === 'Owner'
    ? rows("SELECT user_id, full_name, username, role, status, created_at FROM users ORDER BY FIELD(role,'Owner','Cashier','Assistant'), full_name")
    : [];
$auditLogs = page_allowed('audit') ? rows("SELECT a.*,COALESCE(u.full_name,'Unknown user') user_name,u.role FROM audit_logs a LEFT JOIN users u ON u.user_id=a.user_id ORDER BY a.created_at DESC,a.audit_id DESC LIMIT 200") : [];
$rememberedDevices = page_allowed('audit') ? rows("SELECT rt.token_id,rt.created_at,rt.last_used_at,rt.expires_at,u.full_name,u.username FROM remember_tokens rt JOIN users u ON u.user_id=rt.user_id WHERE rt.expires_at>NOW() ORDER BY COALESCE(rt.last_used_at,rt.created_at) DESC") : [];

$reportType = $_GET['report'] ?? 'Sales';
$reportStart = $_GET['start'] ?? date('Y-m-01');
$reportEnd = $_GET['end'] ?? date('Y-m-d');
$reportRows = [];
$reportColumns = [];
$reportMetrics = [];
if (page_allowed('reports')) {
    switch ($reportType) {
        case 'Inventory':
            $reportColumns = ['Product', 'Category', 'Stock', 'Reorder Level', 'Value'];
            $reportRows = rows("SELECT p.product_name Product, COALESCE(c.category_name,'Uncategorized') Category, COALESCE(i.quantity_in_stock,0) Stock, COALESCE(i.reorder_level,p.reorder_level,5) 'Reorder Level', CONCAT('₱',FORMAT(COALESCE(i.quantity_in_stock,0)*p.cost_price,2)) Value FROM products p LEFT JOIN categories c ON c.category_id=p.category_id LEFT JOIN inventory i ON i.product_id=p.product_id ORDER BY p.product_name");
            $reportMetrics = [['label'=>'Total products','value'=>number_format(count($reportRows))],['label'=>'Total units','value'=>number_format((int)value('SELECT COALESCE(SUM(quantity_in_stock),0) FROM inventory'))],['label'=>'Inventory cost value','value'=>peso(value('SELECT COALESCE(SUM(i.quantity_in_stock*p.cost_price),0) FROM inventory i JOIN products p ON p.product_id=i.product_id'))]];
            break;
        case 'Low Stock':
            $reportColumns = ['Product', 'Stock', 'Reorder Level', 'Status'];
            $reportRows = rows("SELECT p.product_name Product, i.quantity_in_stock Stock, i.reorder_level 'Reorder Level', CASE WHEN i.quantity_in_stock=0 THEN 'Out of Stock' ELSE 'Low Stock' END Status FROM inventory i JOIN products p ON p.product_id=i.product_id WHERE i.quantity_in_stock<=i.reorder_level ORDER BY i.quantity_in_stock");
            $reportMetrics = [['label'=>'Low-stock products','value'=>number_format(count($reportRows))],['label'=>'Units remaining','value'=>number_format(array_sum(array_map(fn($row)=>(int)$row['Stock'],$reportRows)))]];
            break;
        case 'Expiration':
            $reportColumns = ['Product', 'Batch', 'Quantity', 'Expiration Date', 'Days Left'];
            $reportRows = rows("SELECT p.product_name Product, COALESCE(b.batch_number,'—') Batch, b.quantity Quantity, b.expiration_date 'Expiration Date', DATEDIFF(b.expiration_date,CURDATE()) 'Days Left' FROM product_batches b JOIN products p ON p.product_id=b.product_id WHERE b.expiration_date IS NOT NULL AND b.expiration_date BETWEEN ? AND ? ORDER BY b.expiration_date", [$reportStart, $reportEnd]);
            $reportMetrics = [['label'=>'Expiring batches','value'=>number_format(count($reportRows))],['label'=>'Affected units','value'=>number_format(array_sum(array_map(fn($row)=>(int)$row['Quantity'],$reportRows)))]];
            break;
        case 'Debts':
            $reportColumns = ['Customer', 'Receipt', 'Total Debt', 'Remaining', 'Due Date', 'Status'];
            $reportRows = rows("SELECT c.customer_name Customer, s.receipt_number Receipt, CONCAT('₱',FORMAT(d.total_debt,2)) 'Total Debt', CONCAT('₱',FORMAT(d.remaining_balance,2)) Remaining, COALESCE(d.due_date,'—') 'Due Date', d.status Status FROM customer_debts d JOIN customers c ON c.customer_id=d.customer_id JOIN sales s ON s.sale_id=d.sale_id WHERE DATE(d.created_at) BETWEEN ? AND ? ORDER BY d.created_at DESC", [$reportStart, $reportEnd]);
            $debtTotals = rows('SELECT COALESCE(SUM(total_debt),0) total_debt, COALESCE(SUM(remaining_balance),0) remaining FROM customer_debts WHERE DATE(created_at) BETWEEN ? AND ?', [$reportStart,$reportEnd])[0];
            $reportMetrics = [['label'=>'Debt accounts','value'=>number_format(count($reportRows))],['label'=>'Total credit issued','value'=>peso($debtTotals['total_debt'])],['label'=>'Outstanding balance','value'=>peso($debtTotals['remaining'])]];
            break;
        default:
            $reportType = 'Sales';
            $reportColumns = ['Receipt', 'Date', 'Customer', 'Payment', 'Amount'];
            $reportRows = rows("SELECT s.receipt_number Receipt, DATE_FORMAT(s.sale_date,'%b %e, %Y %h:%i %p') Date, COALESCE(c.customer_name,'Walk-in Customer') Customer, s.payment_method Payment, CONCAT('₱',FORMAT(s.total_amount,2)) Amount FROM sales s LEFT JOIN customers c ON c.customer_id=s.customer_id WHERE DATE(s.sale_date) BETWEEN ? AND ? ORDER BY s.sale_date DESC", [$reportStart, $reportEnd]);
            $salesTotals = rows("SELECT COUNT(*) transactions, COALESCE(SUM(total_amount),0) revenue, COALESCE(SUM(CASE WHEN payment_method='Credit' THEN total_amount ELSE 0 END),0) credit FROM sales WHERE status='Completed' AND DATE(sale_date) BETWEEN ? AND ?", [$reportStart,$reportEnd])[0];
            $reportMetrics = [['label'=>'Total sales','value'=>peso($salesTotals['revenue'])],['label'=>'Credit sales','value'=>peso($salesTotals['credit'])]];
    }
}
$reportHistory = page_allowed('reports') ? rows("SELECT r.report_type, r.start_date, r.end_date, r.generated_at, u.full_name generated_by FROM reports r LEFT JOIN users u ON u.user_id=r.generated_by ORDER BY r.generated_at DESC LIMIT 8") : [];
