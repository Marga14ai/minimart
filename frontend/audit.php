<?php if(page_allowed('audit')): ?>
<section class="page" id="audit">
    <article class="panel">
        <div class="panel-head"><div><h2>Security activity</h2><p>The latest 200 sensitive system events.</p></div></div>
        <div class="filters"><label class="search wide">⌕<input class="table-search" data-table="audit-table" placeholder="Search user, action, IP or details…"></label></div>
        <div class="table-wrap"><table id="audit-table"><thead><tr><th>Date & time</th><th>User</th><th>Action</th><th>Record</th><th>IP address</th><th>Details</th></tr></thead><tbody>
        <?php if(!$auditLogs): ?><tr><td colspan="6" class="empty-row">No security activity recorded yet.</td></tr><?php endif; ?>
        <?php foreach($auditLogs as $entry): ?><tr><td><?= date('M j, Y · g:i A',strtotime($entry['created_at'])) ?></td><td><strong><?= e($entry['user_name']) ?></strong><small><?= e($entry['role']??'') ?></small></td><td><span class="badge"><?= e(str_replace('.',' · ',$entry['action'])) ?></span></td><td><?= e($entry['entity_type']) ?> #<?= (int)$entry['entity_id'] ?></td><td class="mono"><?= e($entry['ip_address']?:'Local') ?></td><td><small><?= e($entry['details']?:'—') ?></small></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>
    <article class="panel"><div class="panel-head"><div><h2>Remembered devices</h2><p>Revoke a device if it is lost, shared, or no longer trusted.</p></div></div><div class="table-wrap"><table><thead><tr><th>User</th><th>Remembered</th><th>Last used</th><th>Expires</th><th>Action</th></tr></thead><tbody>
    <?php if(!$rememberedDevices): ?><tr><td colspan="5" class="empty-row">No remembered devices.</td></tr><?php endif; ?>
    <?php foreach($rememberedDevices as $device): ?><tr><td><strong><?= e($device['full_name']) ?></strong><small><?= e($device['username']) ?></small></td><td><?= date('M j, Y · g:i A',strtotime($device['created_at'])) ?></td><td><?= $device['last_used_at']?date('M j, Y · g:i A',strtotime($device['last_used_at'])):'Not reused yet' ?></td><td><?= date('M j, Y',strtotime($device['expires_at'])) ?></td><td><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="revoke_remember_token"><input type="hidden" name="token_id" value="<?= (int)$device['token_id'] ?>"><button class="row-action danger">Revoke</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div></article>
</section>
<?php endif; ?>
