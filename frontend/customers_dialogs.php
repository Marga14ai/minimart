<?php if(role_can('add_customer')): ?>
        <dialog id="customer-modal">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_customer">
                <div class="modal-head">
                    <div>
                        <h2>
                            Add customer
                        </h2>
                        <p>
                            Save customer contact information.
                        </p>
                    </div>
                    <button type="button" data-close-modal>
                        ×
                    </button>
                </div>
                <div class="form-grid">
                    <label class="span-2">
                        Customer name
                        <input required name="customer_name" placeholder="Full name">
                    </label>
                    <label class="span-2">
                        Phone number
                        <input name="phone_number" placeholder="09xx xxx xxxx">
                    </label>
                    <label class="span-2">
                        Address
                        <textarea name="address" placeholder="Customer address">
                        </textarea>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="secondary" data-close-modal>
                        Cancel
                    </button>
                    <button class="primary">
                        Save customer
                    </button>
                </div>
            </form>
        </dialog>
        <?php endif; ?>
<?php if(role_can('pay_debt')): ?>
        <dialog id="debt-modal">
            <form method="post" id="debt-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay_debt">
                <input type="hidden" name="customer_id">
                <div class="modal-head">
                    <div>
                        <h2>
                            Receive debt payment
                        </h2>
                        <p id="debt-customer-name">
                            Customer account
                        </p>
                    </div>
                    <button type="button" data-close-modal>
                        ×
                    </button>
                </div>
                <div class="debt-summary">
                    <div>
                        <span>
                            Outstanding balance
                        </span>
                        <strong id="debt-balance">
                            ₱0.00
                        </strong>
                    </div>
                    <div>
                        <span>
                            Payment applied
                        </span>
                        <strong id="debt-applied">
                            ₱0.00
                        </strong>
                    </div>
                    <div>
                        <span>
                            Customer change
                        </span>
                        <strong id="debt-change">
                            ₱0.00
                        </strong>
                    </div>
                    <div>
                        <span>Due status</span>
                        <strong id="debt-due-status">No outstanding due date</strong>
                    </div>
                </div>
                <div class="form-grid">
                    <label class="span-2">
                        Amount received
                        <input required min="0.01" step="0.01" type="number" name="amount_received" id="debt-received" placeholder="0.00">
                    </label>
                    <label class="span-2">
                        Notes
                        <textarea name="notes" placeholder="Optional payment note">
                        </textarea>
                    </label>
                </div>
                <div class="debt-history">
                    <div class="debt-history-head"><strong>Payment history</strong><span id="debt-payment-count">0 payments</span></div>
                    <div id="debt-history-list" class="debt-history-list"><div class="empty-history">No debt payments recorded yet.</div></div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="secondary" data-close-modal>
                        Cancel
                    </button>
                    <button class="primary" id="record-debt-payment">
                        Record payment
                    </button>
                </div>
            </form>
        </dialog>
        <?php endif; ?>
