                <section class="page active" id="dashboard">
                    <?php if(($loggedInUser['role'] ?? '') === 'Owner' && $nearExpiryCount > 0): ?>
                    <button type="button" class="expiry-owner-alert" data-page-jump="inventory" data-stock-target="expiring">
                        <span>!</span><div><strong><?= $nearExpiryCount ?> stock batch<?= $nearExpiryCount===1?'':'es' ?> expired or near expiration</strong><small>Review products expiring within 30 days.</small></div><b>View inventory →</b>
                    </button>
                    <?php endif; ?>
                    <div class="stats">
                        <article <?= page_allowed('sales') ? 'class="stat-link" data-page-jump="sales" role="button" tabindex="0"' : '' ?>>
                            <div class="stat-icon green">
                                ₱
                            </div>
                            <div>
                                <span>
                                    Today's sales
                                </span>
                                <strong>
                                    <?= peso($todaySales) ?>
                                </strong>
                                <small>
                                    <?= $transactions ?> completed transactions
                                </small>
                            </div>
                        </article>
                        <article <?= page_allowed('inventory') ? 'class="stat-link" data-page-jump="inventory" role="button" tabindex="0"' : '' ?>>
                            <div class="stat-icon blue">
                                ▦
                            </div>
                            <div>
                                <span>
                                    Available products
                                </span>
                                <strong>
                                    <?= number_format($productsCount) ?>
                                </strong>
                                <small>
                                    Across <?= count($categories) ?> categories
                                </small>
                            </div>
                        </article>
                        <article <?= page_allowed('inventory') ? 'class="stat-link" data-page-jump="inventory" data-stock-target="low" role="button" tabindex="0"' : '' ?>>
                            <div class="stat-icon orange">
                                !
                            </div>
                            <div>
                                <span>
                                    Low stock items
                                </span>
                                <strong>
                                    <?= $lowStock ?>
                                </strong>
                                <small>
                                    Needs your attention
                                </small>
                            </div>
                        </article>
                        <article <?= page_allowed('customers') ? 'class="stat-link" data-page-jump="customers" role="button" tabindex="0"' : '' ?>>
                            <div class="stat-icon purple">
                                ↗
                            </div>
                            <div>
                                <span>
                                    Outstanding credit
                                </span>
                                <strong>
                                    <?= peso($debtTotal) ?>
                                </strong>
                                <small>
                                    Customer balances
                                </small>
                            </div>
                        </article>
                    </div>
                    <div class="dashboard-grid">
                        <article class="panel sales-panel">
                            <div class="panel-head">
                                <div>
                                    <h2>
                                        Sales overview
                                    </h2>
                                    <p id="sales-chart-description">Revenue performance this week</p>
                                </div>
                                <select id="sales-chart-period" aria-label="Sales chart period">
                                    <option value="day">This day</option>
                                    <option value="week" selected>This week</option>
                                    <option value="month">This month</option>
                                </select>
                            </div>
                            <div class="chart" id="chart" data-dynamic-chart>
                                <div class="empty-chart">
                                    <span>
                                        ▥
                                    </span>
                                    <strong>
                                        Sales chart ready
                                    </strong>
                                    <small>
                                        Transactions will appear here as sales are recorded.
                                    </small>
                                </div>
                            </div>
                        </article>
                        <article class="panel quick">
                            <div class="panel-head">
                                <div>
                                    <h2>
                                        Quick actions
                                    </h2>
                                    <p>
                                        Common store tasks
                                    </p>
                                </div>
                            </div>
                            <div class="quick-grid">
                                <?php if(role_can('add_product')): ?>
                                <button data-open-modal>
                                    <b>
                                        ＋
                                    </b>
                                    <span>
                                        Add product
                                        <small>
                                            Create new item
                                        </small>
                                    </span>
                                </button>
                                <?php endif; ?><?php if(role_can('stock_in')): ?>
                                <button data-open="stock-modal">
                                    <b>
                                        ⇩
                                    </b>
                                    <span>
                                        Stock in
                                        <small>
                                            Receive inventory
                                        </small>
                                    </span>
                                </button>
                                <?php endif; ?><?php if(role_can('new_sale')): ?>
                                <button data-open="sale-modal">
                                    <b>
                                        ₱
                                    </b>
                                    <span>
                                        New sale
                                        <small>
                                            Open register
                                        </small>
                                    </span>
                                </button>
                                <?php endif; ?><?php if(role_can('add_customer')): ?>
                                <button data-open="customer-modal">
                                    <b>
                                        ♙
                                    </b>
                                    <span>
                                        Customer
                                        <small>
                                            Add customer
                                        </small>
                                    </span>
                                </button>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                    <article class="panel">
                        <div class="panel-head">
                            <div>
                                <h2>
                                    Recent transactions
                                </h2>
                                <p>
                                    Latest activity from your store
                                </p>
                            </div>
                            <button class="text-button" data-page-jump="sales">
                                View all →
                            </button>
                        </div>
                        <?= renderSales($recentSales) ?>
                    </article>
                </section>
