<?php
require __DIR__ . '/_header.php';

$current = admin_user();

if (!super_admin_role($current)) {
    http_response_code(403);
    exit('Only a super administrator may manage users.');
}

$message = '';
$error = '';
$investmentAccessReady=investment_user_access_ready();
$managedRoleCondition="LOWER(REPLACE(role,'_',' ')) IN ('super admin','super administrator','investments only','investment only','investment','investor','partner')";
if($investmentAccessReady)$managedRoleCondition.=" OR (COALESCE(role,'')='' AND EXISTS (SELECT 1 FROM investment_user_access iua WHERE iua.admin_user_id=admin_users.id))";

if (isset($_GET['saved'])) {
    $message = 'Administrator account saved.';
}
if (isset($_GET['deleted'])) {
    $message = 'Administrator account deleted.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete') {
    if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Refresh the page and try again.';
    }
    $deleteId = (int) ($_POST['user_id'] ?? 0);

    if (!$error && $deleteId === (int)($current['id'] ?? 0)) {
        $error = 'You cannot delete the account currently signed in.';
    } elseif (!$error && $deleteId > 0) {
        try {
            $check = db()->prepare("SELECT id FROM admin_users WHERE id=? AND ($managedRoleCondition)");
            $check->execute([$deleteId]);
            if (!$check->fetchColumn()) {
                $error = 'That account is managed through the Construction Portal.';
            } else {
                if(investment_user_access_ready())db()->prepare('DELETE FROM investment_user_access WHERE admin_user_id=?')->execute([$deleteId]);
                if(partner_access_ready())db()->prepare('DELETE FROM admin_partner_permissions WHERE admin_user_id=?')->execute([$deleteId]);
                db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$deleteId]);
                header('Location: users.php?deleted=1');
                exit;
            }
        } catch (Throwable $exception) {
            error_log('Administrator deletion failed for user '.$deleteId.': '.$exception->getMessage());
            $error = 'The administrator account could not be deleted. Please try again.';
        }
    }
}

$users = db()->query(
    "SELECT id,email,full_name,role,is_active,last_login_at,created_at
     FROM admin_users
     WHERE $managedRoleCondition
     ORDER BY
       CASE
         WHEN LOWER(REPLACE(role,'_',' ')) IN ('super admin','super administrator') THEN 1
         WHEN LOWER(REPLACE(role,'_',' ')) = 'partner' THEN 2
         WHEN LOWER(REPLACE(role,'_',' ')) IN ('investments only','investment only','investment','investor') THEN 3
         WHEN COALESCE(role,'')='' THEN 3
         ELSE 4
       END,
       is_active DESC,
       full_name ASC"
)->fetchAll();

$investmentAccessByUser=[];
if($investmentAccessReady){
    $accessRows=db()->query(
        "SELECT iua.admin_user_id,io.project_name,io.status
         FROM investment_user_access iua
         JOIN investment_opportunities io ON io.id=iua.investment_opportunity_id
         ORDER BY io.project_name ASC"
    )->fetchAll();
    foreach($accessRows as $accessRow)$investmentAccessByUser[(int)$accessRow['admin_user_id']][]=$accessRow;
}
$partnerAccessByUser=[];
if(partner_access_ready()){
    $accessRows=db()->query('SELECT admin_user_id,permission_key FROM admin_partner_permissions ORDER BY permission_key')->fetchAll();
    $permissionLabels=partner_permission_definitions();
    foreach($accessRows as $accessRow){$key=(string)$accessRow['permission_key'];$partnerAccessByUser[(int)$accessRow['admin_user_id']][]=$permissionLabels[$key]??ucwords(str_replace('_',' ',$key));}
}

$roleLabels = [
    'super_admin' => 'Super Admin',
    'investments_only' => 'Investor',
    'partner' => 'Partner',
];
?>
<div class="admin-page-head">
  <div>
    <h1>Users</h1>
  </div>
  <div class="admin-row-actions"><a class="secondary" href="view_as_user.php">View As User</a><a class="primary" href="user_edit.php">Add User</a></div>
</div>

<?php if ($message): ?>
  <div class="status success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="status error"><?= e($error) ?></div>
<?php endif; ?>

<div class="admin-card-grid admin-user-grid">
  <?php foreach ($users as $admin): ?>
    <article class="admin-record-card">
      <div class="admin-record-card__head">
        <div class="admin-user-avatar">
          <?= e(strtoupper(substr((string)$admin['full_name'], 0, 1))) ?>
        </div>
        <div>
          <h2><?= e($admin['full_name']) ?></h2>
          <p><?= email_link($admin['email']) ?></p>
        </div>
        <span class="admin-status-pill <?= $admin['is_active'] ? 'is-active' : 'is-inactive' ?>">
          <?= $admin['is_active'] ? 'Active' : 'Disabled' ?>
        </span>
      </div>

      <dl class="admin-record-details">
        <div>
          <dt>Role</dt>
          <dd><?= e(investments_only_role($admin)?'Investor':($roleLabels[$admin['role']] ?? ucwords(str_replace('_', ' ', $admin['role'])))) ?></dd>
        </div>
        <div>
          <dt>Last Login</dt>
          <dd><?= e($admin['last_login_at'] ?: 'Never') ?></dd>
        </div>
        <div>
          <dt>Created</dt>
          <dd><?= e($admin['created_at']) ?></dd>
        </div>
        <?php if(investments_only_role($admin)):?>
        <div class="admin-user-project-access">
          <dt><span class="admin-project-count-badge">Projects</span></dt>
          <dd>
            <?php $assignedInvestments=$investmentAccessByUser[(int)$admin['id']]??[];?>
            <?php if($assignedInvestments):?>
              <span class="admin-project-badges"><?php foreach($assignedInvestments as $assignedInvestment):?><span><?=e((string)$assignedInvestment['project_name'])?><?=strtolower((string)$assignedInvestment['status'])==='archived'?' - ARCHIVED':''?></span><?php endforeach;?></span>
            <?php else:?>
              <span class="admin-user-no-projects">No projects assigned</span>
            <?php endif;?>
          </dd>
        </div>
        <?php endif;?>
        <?php if(partner_role($admin)):?>
        <div class="admin-user-project-access">
          <dt><span class="admin-project-count-badge">Access</span></dt>
          <dd><?php $assignedAreas=$partnerAccessByUser[(int)$admin['id']]??[];?><?php if($assignedAreas):?><span class="admin-project-badges"><?php foreach($assignedAreas as $assignedArea):?><span><?=e($assignedArea)?></span><?php endforeach;?></span><?php else:?><span class="admin-user-no-projects">No admin areas assigned</span><?php endif;?></dd>
        </div>
        <?php endif;?>
      </dl>

      <div class="admin-record-actions">
        <?php if(!super_admin_role($admin)):?><form method="post" action="impersonate.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="user_id" value="<?=(int)$admin['id']?>"><button class="primary admin-small-button" type="submit">View As</button></form><?php endif;?>
        <a class="secondary admin-small-button" href="user_edit.php?id=<?= (int)$admin['id'] ?>">
          Edit / Set Password
        </a>

        <?php if ((int)$admin['id'] !== (int)($current['id'] ?? 0)): ?>
          <form method="post" onsubmit="return confirm('Delete this user?')">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" value="<?= (int)$admin['id'] ?>">
            <button class="admin-danger-link" type="submit">Delete</button>
          </form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
