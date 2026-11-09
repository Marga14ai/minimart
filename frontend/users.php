                <?php if(page_allowed('users')): ?>
                <section class="page" id="users">
                    <article class="panel">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_user_name">
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>Display name</th><th>Username</th><th>Role</th><th>Status</th><th>Action</th></tr></thead>
                                    <tbody>
                                        <?php foreach($users as $account): ?>
                                        <tr>
                                            <td><input class="user-name-input" required maxlength="100" name="full_name[<?= (int)$account['user_id'] ?>]" value="<?= e($account['full_name']) ?>"></td>
                                            <td class="mono"><?= e($account['username']) ?></td>
                                            <td><?= e($account['role']) ?></td>
                                            <td><span class="badge <?= strtolower($account['status']) ?>"><?= e($account['status']) ?></span></td>
                                            <td><button class="secondary user-save" name="user_id" value="<?= (int)$account['user_id'] ?>">Save name</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    </article>
                </section>
                <?php endif; ?>
