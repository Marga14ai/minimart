                <?php if(page_allowed('customers')): ?>
                <section class="page" id="customers">
                    <div class="section-tools actions-only">
                        <button class="primary" data-open="customer-modal">＋ Add customer</button>
                    </div>
                    <div class="card-grid">
                        <?php foreach($customers as $c): ?>
                        <button type="button" class="person-card customer-card <?= (int)$c['overdue_days']>0?'customer-overdue':'' ?>" data-customer-id="<?= (int)$c['customer_id'] ?>" data-customer-name="<?= e($c['customer_name']) ?>" data-balance="<?= e($c['balance']) ?>" data-overdue-days="<?= (int)$c['overdue_days'] ?>" data-due-date="<?= e($c['oldest_due_date'] ?? '') ?>">
                            <div class="avatar light"><?= e(strtoupper(substr($c['customer_name'],0,2))) ?></div>
                            <div><h3><?= e($c['customer_name']) ?></h3><p><?= e($c['phone_number'] ?: 'No phone number') ?></p><small><?= e($c['address'] ?: 'No address provided') ?></small></div>
                            <div class="balance"><span>Balance</span><strong class="<?= $c['balance']>0?'danger-text':'' ?>"><?= peso($c['balance']) ?></strong><?php if((int)$c['overdue_days']>0): ?><small class="overdue-label">Overdue by <?= (int)$c['overdue_days'] ?> day<?= (int)$c['overdue_days']===1?'':'s' ?></small><?php elseif($c['oldest_due_date'] && $c['balance']>0): ?><small class="due-label">Due <?= date('M j, Y',strtotime($c['oldest_due_date'])) ?></small><?php endif; ?></div>
                            <span class="card-arrow">›</span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>
<?php require __DIR__ . '/customers_dialogs.php'; ?>
