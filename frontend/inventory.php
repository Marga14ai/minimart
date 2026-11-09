                <?php if(page_allowed('inventory')): ?>
                <section class="page" id="inventory">
                    <?php if($nearExpiryCount > 0): ?>
                    <button type="button" class="expiry-owner-alert" data-expiry-filter="expiring">
                        <span>!</span><div><strong><?= $nearExpiryCount ?> batch<?= $nearExpiryCount===1?'':'es' ?> need expiration attention</strong><small>Includes expired stock and stock expiring within the next 30 days.</small></div><b>Show affected products →</b>
                    </button>
                    <?php endif; ?>
                    <div class="section-tools actions-only">
                        <button class="primary" data-open-modal>
                            ＋ Add product
                        </button>
                    </div>
                    <article class="panel">
                        <div class="filters">
                            <label class="search wide">
                                ⌕
                                <input class="table-search" data-table="inventory-table" placeholder="Search name, barcode, category…">
                            </label>
                            <select id="stock-filter">
                                <option value="all">
                                    All stock levels
                                </option>
                                <option value="low">
                                    Low stock
                                </option>
                                <option value="out">
                                    Out of stock
                                </option>
                                <option value="expiring">Expiring within 30 days</option>
                                <option value="expired">Expired</option>
                            </select>
                        </div>
                        <div class="table-wrap">
                            <table id="inventory-table">
                                <thead>
                                    <tr>
                                        <th>
                                            Product
                                        </th>
                                        <th>
                                            Category
                                        </th>
                                        <th>
                                            Barcode
                                        </th>
                                        <th>
                                            Price
                                        </th>
                                        <th>
                                            Stock
                                        </th>
                                        <th>Nearest expiration</th>
                                        <th>
                                            Status
                                        </th>
                                        <th>
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($inventory as $p): $state=$p['stock']<=0?'out':($p['stock']<=$p['reorder_level']?'low':'ok'); $daysLeft=$p['nearest_expiration']!==null?(int)((strtotime($p['nearest_expiration'])-strtotime(date('Y-m-d')))/86400):null; $expiryState=$daysLeft===null?'':($daysLeft<0?'expired':($daysLeft<=30?'expiring':'fresh')); ?>
                                    <tr data-stock="<?= $state ?> <?= $expiryState === 'expired' ? 'expiring expired' : $expiryState ?>">
                                        <td>
                                            <strong>
                                                <?= e($p['product_name']) ?>
                                            </strong>
                                            <small>
                                                <?= e($p['unit'] ?: 'per item') ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?= e($p['category_name'] ?: 'Uncategorized') ?>
                                        </td>
                                        <td class="mono">
                                            <?= e($p['barcode'] ?: '—') ?>
                                        </td>
                                        <td>
                                            <strong>
                                                <?= peso($p['selling_price']) ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <strong>
                                                <?= (int)$p['stock'] ?>
                                            </strong>
                                            <small>
                                                Reorder at <?= (int)$p['reorder_level'] ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if($daysLeft === null): ?>
                                                <span class="expiry-none">No expiry recorded</span>
                                            <?php else: ?>
                                                <strong><?= date('M j, Y', strtotime($p['nearest_expiration'])) ?></strong>
                                                <small class="expiry-<?= e($expiryState) ?>"><?= $daysLeft < 0 ? abs($daysLeft).' day(s) expired' : ($daysLeft === 0 ? 'Expires today' : $daysLeft.' day(s) left') ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $p['status']==='Unavailable'?'out':$state ?>">
                                                <?= e($p['status']==='Unavailable'?'Unavailable':($state==='ok'?'In stock':($state==='low'?'Low stock':'Out of stock'))) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if(role_can('order_supplier') && $state !== 'ok' && !empty($p['supplier_id'])): ?>
                                            <button type="button" class="row-action order-low-stock" data-supplier-id="<?= (int)$p['supplier_id'] ?>" data-supplier-name="<?= e($p['supplier_name'] ?? 'Supplier') ?>" data-supplier-email="<?= e($p['supplier_email'] ?? '') ?>" data-product-id="<?= (int)$p['product_id'] ?>" data-suggested-quantity="<?= max(1, ((int)$p['reorder_level'] * 2) - (int)$p['stock']) ?>">
                                                Order
                                            </button>
                                            <?php endif; ?>
                                            <button type="button" class="row-action edit-product" data-product='<?= e(json_encode($p, JSON_UNESCAPED_UNICODE)) ?>'>
                                                Update
                                            </button>
                                            <?php if(role_can('adjust_inventory')): ?>
                                            <button type="button" class="row-action adjust-stock" data-product-id="<?= (int)$p['product_id'] ?>" data-product-name="<?= e($p['product_name']) ?>" data-current-stock="<?= (int)$p['stock'] ?>">Adjust stock</button>
                                            <?php endif; ?>
                                            <?php if($p['status'] !== 'Unavailable'): ?>
                                            <form method="post" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="archive_product">
                                                <input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>">
                                                <button class="row-action danger">
                                                    Archive
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                </section>
                <?php endif; ?>
<?php require __DIR__ . '/inventory_dialogs.php'; ?>
