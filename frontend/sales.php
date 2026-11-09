<?php if(page_allowed('sales')): ?>
                <section class="page" id="sales">
                    <div class="section-tools actions-only">
                        <button class="primary" data-open="sale-modal">
                            ＋ New sale
                        </button>
                    </div>
                    <article class="panel">
                        <?= renderSales(rows("SELECT s.sale_id,s.receipt_number,s.sale_date,s.total_amount,s.payment_method,s.status,COALESCE(c.customer_name,'Walk-in Customer') customer_name FROM sales s LEFT JOIN customers c ON c.customer_id=s.customer_id ORDER BY s.sale_date DESC LIMIT 100")) ?>
                    </article>
                </section>
                <?php endif; ?>
<?php require __DIR__ . '/sales_dialogs.php'; ?>
<?php if(role_can('void_sale')): ?><dialog id="void-sale-modal"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="void_sale"><input type="hidden" name="sale_id"><div class="modal-head"><div><h2>Void sale</h2><p id="void-sale-receipt">Receipt</p></div><button type="button" data-close-modal>×</button></div><div class="form-grid"><label class="span-2">Reason<textarea required maxlength="255" name="reason" placeholder="Explain why this sale must be voided"></textarea></label></div><div class="modal-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button class="primary danger">Void and restore stock</button></div></form></dialog><?php endif; ?>
