<?php if(role_can('stock_in')): ?>
        <?php if(role_can('adjust_inventory')): ?>
        <dialog id="adjust-stock-modal">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="adjust_inventory"><input type="hidden" name="product_id">
                <div class="modal-head"><div><h2>Adjust inventory</h2><p id="adjust-stock-product">Product</p></div><button type="button" data-close-modal>×</button></div>
                <div class="form-grid"><label>Current quantity<input id="adjust-current-stock" disabled></label><label>Correct quantity<input required min="0" type="number" name="new_quantity"></label><label class="span-2">Reason<textarea required maxlength="255" name="reason" placeholder="Example: damaged items or physical count correction"></textarea></label></div>
                <div class="modal-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button class="primary">Save adjustment</button></div>
            </form>
        </dialog>
        <?php endif; ?>
        <dialog id="stock-modal">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="stock_in">
                <div class="modal-head">
                    <div>
                        <h2>
                            Receive stock
                        </h2>
                        <p>
                            Add delivered quantity to an existing product.
                        </p>
                    </div>
                    <button type="button" data-close-modal>
                        ×
                    </button>
                </div>
                <div class="form-grid">
                    <label class="span-2">
                        Product
                        <select required name="product_id">
                            <option value="">
                                Select product
                            </option>
                            <?php foreach($inventory as $p): ?>
                            <option value="<?= (int)$p['product_id'] ?>">
                                <?= e($p['product_name']) ?> — current stock: <?= (int)$p['stock'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="span-2">
                        Quantity received
                        <input required min="1" type="number" name="quantity" value="1">
                    </label>
                    <label>
                        Batch number
                        <input name="batch_number" placeholder="Optional batch code">
                    </label>
                    <label>
                        Date received
                        <input type="date" value="<?= date('Y-m-d') ?>" disabled>
                    </label>
                    <label>
                        Manufactured date
                        <input type="date" name="manufactured_date">
                    </label>
                    <label>
                        Expiration date
                        <input type="date" name="expiration_date">
                        <small>Leave blank only when the product does not expire.</small>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="secondary" data-close-modal>
                        Cancel
                    </button>
                    <button class="primary">
                        Add stock
                    </button>
                </div>
            </form>
        </dialog>
        <?php endif; ?>
<?php if(role_can('add_product')): ?>
        <dialog id="product-modal">
            <form method="post" id="product-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_product">
                <input type="hidden" name="product_id" value="">
                <div class="modal-head">
                    <div>
                        <h2 id="product-modal-title">
                            Add new product
                        </h2>
                        <p>
                            Enter the product and product and stock details.
                        </p>
                    </div>
                    <button type="button" data-close-modal>
                        ×
                    </button>
                </div>
                <div class="form-grid">
                    <label class="span-2">
                        Product name
                        <input required name="product_name" placeholder="e.g. Sardines 155g">
                    </label>
                    <label>
                        Barcode
                        <div class="barcode-entry">
                            <input id="product-barcode" name="barcode" autocomplete="off" inputmode="numeric" placeholder="Scan or enter code">
                            <button type="button" class="secondary" id="scan-product-camera">Camera scan</button>
                        </div>
                        <small id="product-barcode-result">Use the camera or enter the barcode manually.</small>
                    </label>
                    <label>
                        Unit
                        <select name="unit">
                            <option value="">Select unit</option>
                            <option value="piece">Piece</option>
                            <option value="pack">Pack</option>
                            <option value="box">Box</option>
                            <option value="bottle">Bottle</option>
                            <option value="can">Can</option>
                            <option value="sachet">Sachet</option>
                            <option value="kilogram">Kilogram</option>
                            <option value="gram">Gram</option>
                            <option value="liter">Liter</option>
                            <option value="milliliter">Milliliter</option>
                        </select>
                    </label>
                    <label>
                        Category
                        <select name="category_id">
                            <option value="">
                                Uncategorized
                            </option>
                            <?php foreach($categories as $c): ?>
                            <option value="<?= $c['category_id'] ?>">
                                <?= e($c['category_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Supplier
                        <select name="supplier_id">
                            <option value="">
                                No supplier
                            </option>
                            <?php foreach($suppliers as $s): ?>
                            <option value="<?= $s['supplier_id'] ?>">
                                <?= e($s['supplier_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Selling price
                        <input required min="0" step="0.01" type="number" name="selling_price" placeholder="0.00">
                    </label>
                    <label>
                        Cost price
                        <input min="0" step="0.01" type="number" name="cost_price" placeholder="0.00">
                    </label>
                    <label>
                        Opening stock
                        <input min="0" type="number" name="quantity" value="0">
                    </label>
                    <label>
                        Reorder level
                        <input min="0" type="number" name="reorder_level" value="5">
                    </label>
                    <label>
                        Manufactured date
                        <input type="date" name="manufactured_date">
                    </label>
                    <label class="span-2">
                        Opening stock expiration date
                        <input type="date" name="expiration_date">
                        <small>Used for opening stock; leave blank for non-expiring products.</small>
                    </label>
                    <label class="span-2">
                        Description
                        <textarea name="description" placeholder="Optional product notes">
                        </textarea>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="secondary" data-close-modal>
                        Cancel
                    </button>
                    <button class="primary">
                        Save product
                    </button>
                </div>
            </form>
        </dialog>
        <?php endif; ?>
