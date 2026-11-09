                <?php if(page_allowed('suppliers')): ?>
                <section class="page" id="suppliers">
                    <div class="section-tools actions-only">
                        <button class="primary" data-open="supplier-modal">＋ Add supplier</button>
                    </div>
                    <div class="card-grid">
                        <?php foreach($suppliers as $s): ?>
                        <button type="button" class="person-card supplier-card" data-supplier-id="<?= (int)$s['supplier_id'] ?>" data-supplier-name="<?= e($s['supplier_name']) ?>" data-supplier-email="<?= e($s['email'] ?? '') ?>">
                            <div class="avatar supplier">▣</div>
                            <div><h3><?= e($s['supplier_name']) ?></h3><p><?= e($s['contact_person'] ?: 'No contact person') ?></p><small><?= e($s['phone_number'] ?: $s['email'] ?: 'No contact details') ?></small></div>
                            <div class="balance"><span>Products</span><strong><?= (int)$s['product_count'] ?></strong></div>
                            <span class="card-arrow">›</span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <article class="panel" style="margin-top:20px"><div class="panel-head"><div><h2>Purchase orders</h2><p>Receive deliveries and update stock by batch.</p></div></div><div class="table-wrap"><table><thead><tr><th>Order</th><th>Supplier</th><th>Date</th><th>Status</th><th>Items</th><th>Action</th></tr></thead><tbody>
                    <?php if(!$supplierOrders): ?><tr><td colspan="6" class="empty-row">No purchase orders yet.</td></tr><?php endif; ?>
                    <?php foreach($supplierOrders as $order): $items=$supplierOrderItems[(int)$order['order_id']]??[]; ?><tr><td class="mono"><?= e($order['order_number']) ?></td><td><?= e($order['supplier_name']) ?></td><td><?= date('M j, Y',strtotime($order['created_at'])) ?></td><td><span class="badge"><?= e($order['status']) ?></span></td><td><?= count($items) ?> product(s)</td><td><?php if(role_can('receive_supplier_order')&&!in_array($order['status'],['Received','Cancelled'],true)): ?><button type="button" class="row-action receive-order-button" data-order='<?= e(json_encode(['id'=>(int)$order['order_id'],'number'=>$order['order_number'],'items'=>$items],JSON_UNESCAPED_UNICODE)) ?>'>Receive</button><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
                    </tbody></table></div></article>
                </section>
                <?php endif; ?>
<?php require __DIR__ . '/suppliers_dialogs.php'; ?>
