<?php if(role_can('add_supplier')): ?>
        <?php if(role_can('receive_supplier_order')): ?><dialog id="receive-order-modal"><form method="post" id="receive-order-form"><?= csrf_field() ?><input type="hidden" name="action" value="receive_supplier_order"><input type="hidden" name="order_id"><div class="modal-head"><div><h2>Receive supplier delivery</h2><p id="receive-order-number">Purchase order</p></div><button type="button" data-close-modal>×</button></div><div id="receive-order-items"></div><div class="modal-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button class="primary">Receive selected stock</button></div></form></dialog><?php endif; ?>
        <dialog id="supplier-modal">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_supplier">
                <div class="modal-head">
                    <div>
                        <h2>
                            Add supplier
                        </h2>
                        <p>
                            Save a new supply partner.
                        </p>
                    </div>
                    <button type="button" data-close-modal>
                        ×
                    </button>
                </div>
                <div class="form-grid">
                    <label class="span-2">
                        Supplier name
                        <input required name="supplier_name" placeholder="Business name">
                    </label>
                    <label>
                        Contact person
                        <input name="contact_person" placeholder="Contact name">
                    </label>
                    <label>
                        Phone number
                        <input name="phone_number" placeholder="Phone">
                    </label>
                    <label class="span-2">
                        Email
                        <input type="email" name="email" placeholder="supplier@example.com">
                    </label>
                    <label class="span-2">
                        Address
                        <textarea name="address" placeholder="Business address">
                        </textarea>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="secondary" data-close-modal>
                        Cancel
                    </button>
                    <button class="primary">
                        Save supplier
                    </button>
                </div>
            </form>
        </dialog>
        <?php endif; ?>
<?php if(role_can('order_supplier')): ?>
        <dialog id="supplier-order-modal">
            <form method="post" id="supplier-order-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="order_supplier">
                <input type="hidden" name="supplier_id">
                <div class="modal-head">
                    <div>
                        <h2>
                            Create purchase order
                        </h2>
                        <p>
                            <span id="order-supplier-name">
                                Supplier
                            </span>
                            ·
                            <span id="order-supplier-email">
                                No email
                            </span>
                        </p>
                    </div>
                    <button type="button" data-close-modal>
                        ×
                    </button>
                </div>
                <div class="form-grid supplier-email-field">
                    <label class="span-2">
                        Supplier email
                        <input required type="email" name="supplier_email" id="order-supplier-email-input" placeholder="supplier@example.com">
                    </label>
                </div>
                <div id="order-items" class="order-items">
                </div>
                <button type="button" class="secondary add-order-row" id="add-order-item">
                    ＋ Add another product
                </button>
                <div class="form-grid order-notes">
                    <label class="span-2">
                        Order notes
                        <textarea name="notes" placeholder="Delivery instructions or other notes">
                        </textarea>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="secondary" data-close-modal>
                        Cancel
                    </button>
                    <button class="primary">
                        Save & email order
                    </button>
                </div>
            </form>
        </dialog>
        <?php endif; ?>
