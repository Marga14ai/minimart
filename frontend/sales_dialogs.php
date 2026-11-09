        <?php if(role_can('new_sale')): ?>
        <dialog id="sale-modal" class="checkout-modal">
            <form method="post" id="sale-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="new_sale">
                <input type="hidden" name="cart_json" id="cart-json">
                <div class="modal-head">
                    <div><h2>New sale</h2><p>Scan products, review the cart, then choose payment.</p></div>
                    <button type="button" data-close-modal>×</button>
                </div>
                <div class="checkout-steps">
                    <span class="active">1 <b>Items</b></span><span>2 <b>Review</b></span><span>3 <b>Payment</b></span>
                </div>
                <section class="sale-step active" data-sale-step="1">
                    <div class="form-grid">
                        <input id="sale-barcode" type="hidden">
                        <label class="span-2">Search for a product
                            <div class="barcode-entry"><input id="sale-product-search" autocomplete="off" placeholder="Type product name or barcode"><button type="button" class="secondary" id="scan-camera">Camera scan</button></div>
                            <small id="barcode-result">Search by product name or barcode, or scan with the camera.</small>
                            <div id="sale-product-results" class="product-search-results" hidden></div>
                            <select id="sale-product" class="product-selection-value" hidden aria-hidden="true" tabindex="-1">
                                <option value="">Select product</option>
                                <?php foreach($inventory as $p): if($p['status']==='Available' && $p['stock']>0): ?>
                                <option value="<?= (int)$p['product_id'] ?>"><?= e($p['product_name']) ?> — <?= peso($p['selling_price']) ?> (<?= (int)$p['stock'] ?> available)</option>
                                <?php endif; endforeach; ?>
                            </select>
                        </label>
                        <label>Quantity<input min="1" type="number" id="sale-quantity" value="1"></label>
                        <div class="add-cart-wrap"><button type="button" class="primary" id="add-to-cart">＋ Add item</button></div>
                    </div>
                    <div id="scan-cart" class="scan-cart"></div>
                    <div class="modal-actions"><button type="button" class="primary next-sale-step" data-next="2">Review sale →</button></div>
                </section>
                <section class="sale-step" data-sale-step="2">
                    <div class="review-cart" id="review-cart"></div>
                    <div class="checkout-total"><span>Sale total</span><strong id="review-total">₱0.00</strong></div>
                    <div class="modal-actions split"><button type="button" class="secondary next-sale-step" data-next="1">← Back</button><button type="button" class="primary next-sale-step" data-next="3">Continue to payment →</button></div>
                </section>
                <section class="sale-step" data-sale-step="3">
                    <div class="payment-choice">
                        <label>
                            <input type="radio" name="payment_type" value="Cash" checked>
                            <span>
                                <b>
                                    Cash
                                </b>
                                <small>
                                    Receive cash and calculate change
                                </small>
                            </span>
                        </label>
                        <label>
                            <input type="radio" name="payment_type" value="Credit">
                            <span>
                                <b>
                                    Customer Credit
                                </b>
                                <small>
                                    Record as utang / unpaid debt
                                </small>
                            </span>
                        </label>
                        <label>
                            <input type="radio" name="payment_type" value="GCash">
                            <span>
                                <b>GCash</b>
                                <small>Receive a cashless GCash payment</small>
                            </span>
                        </label>
                    </div>
                    <div class="form-grid">
                        <label class="span-2">
                            Customer
                            <select name="customer_id" id="sale-customer">
                                <option value="">
                                    Walk-in Customer
                                </option>
                                <?php foreach($customers as $c): ?>
                                <option value="<?= (int)$c['customer_id'] ?>">
                                    <?= e($c['customer_name']) ?>
                                </option>
                                <?php endforeach; ?>
                                <option value="new">＋ New customer</option>
                            </select>
                        </label>
                        <div class="span-2 payment-panel active" id="cash-payment">
                            <label>
                                Cash received
                                <input min="0" step="0.01" type="number" name="amount_paid" id="amount-paid" placeholder="0.00">
                            </label>
                            <div class="sale-total">
                                <span>
                                    Change
                                </span>
                                <strong id="sale-change">
                                    ₱0.00
                                </strong>
                            </div>
                        </div>
                        <div class="span-2 payment-panel" id="credit-payment">
                            <div class="new-credit-customer" id="new-credit-customer" hidden>
                                <label>
                                    Customer name
                                    <input type="text" name="new_customer_name" id="new-credit-name" maxlength="150" placeholder="Enter full name">
                                </label>
                                <label>
                                    Contact number
                                    <input type="tel" name="new_customer_phone" id="new-credit-phone" maxlength="20" placeholder="09XXXXXXXXX">
                                </label>
                            </div>
                            <label>
                                Due date
                                <input type="date" name="due_date" id="credit-due-date">
                            </label>
                            <p>
                                The full sale total will be added to the selected customer's outstanding balance.
                            </p>
                        </div>
                        <div class="span-2 payment-panel" id="gcash-payment">
                            <p>Confirm that the customer's GCash payment was received before completing the sale.</p>
                        </div>
                    </div>
                    <div class="checkout-total">
                        <span>
                            Amount due
                        </span>
                        <strong id="sale-total">
                            ₱0.00
                        </strong>
                    </div>
                    <div class="modal-actions split">
                        <button type="button" class="secondary next-sale-step" data-next="2">
                            ← Back
                        </button>
                        <button class="primary">
                            Complete sale
                        </button>
                    </div>
                </section>
            </form>
        </dialog>
        <dialog id="receipt-modal" class="receipt-modal">
            <div class="receipt-paper" id="receipt-paper">
                <div class="receipt-store">
                    <div class="receipt-logo" aria-hidden="true"><i></i><i></i><i></i></div>
                    <strong>MiniMart</strong>
                    <span>NEIGHBORHOOD CONVENIENCE STORE</span>
                    <small>Official Sales Receipt</small>
                </div>
                <div class="receipt-meta">
                    <div><span>Receipt:</span><strong id="receipt-number"></strong></div>
                    <div><span>Date / Time:</span><strong id="receipt-date"></strong></div>
                    <div><span>Cashier:</span><strong id="receipt-cashier"></strong></div>
                    <div><span>Customer:</span><strong id="receipt-customer"></strong></div>
                    <div id="receipt-due-row" hidden><span>Due date:</span><strong id="receipt-due-date"></strong></div>
                </div>
                <table class="receipt-items">
                    <thead><tr><th>DESCRIPTION</th><th>QTY</th><th>PRICE</th><th>AMOUNT</th></tr></thead>
                    <tbody id="receipt-items"></tbody>
                </table>
                <div class="receipt-totals">
                    <div class="receipt-grand-total"><span>TOTAL</span><strong id="receipt-total"></strong></div>
                    <div><span>PAYMENT</span><strong id="receipt-payment"></strong></div>
                    <div><span>AMOUNT PAID</span><strong id="receipt-paid"></strong></div>
                    <div><span id="receipt-final-label">CHANGE</span><strong id="receipt-final-amount"></strong></div>
                </div>
                <div class="receipt-barcode" aria-hidden="true"></div>
                <div class="receipt-barcode-number" id="receipt-barcode-number"></div>
                <p class="receipt-thanks"><strong>THANK YOU!</strong><small>Please keep this receipt for your records.</small></p>
                <div class="modal-actions receipt-actions">
                    <button type="button" class="secondary" id="print-receipt">Print receipt</button>
                    <button type="button" class="primary" id="finish-receipt">Done</button>
                </div>
            </div>
        </dialog>
        <?php endif; ?>
<?php if(role_can('new_sale') || role_can('add_product')): ?>
        <dialog id="camera-modal">
            <div class="camera-box">
                <div class="modal-head">
                    <div>
                        <h2 id="camera-title">
                            Scan barcode
                        </h2>
                        <p>
                            Point the camera at the product barcode.
                        </p>
                    </div>
                    <button type="button" id="close-camera">
                        ×
                    </button>
                </div>
                <video id="barcode-video" playsinline autoplay>
                </video>
                <p id="camera-status">
                    Starting camera…
                </p>
                <div id="camera-scan-cart" class="camera-scan-cart" hidden></div>
                <div class="camera-actions">
                    <button type="button" class="primary" id="finish-camera">Done scanning</button>
                </div>
            </div>
        </dialog>
        <?php endif; ?>
