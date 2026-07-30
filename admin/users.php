<?php
require __DIR__ . '/_header.php';

$current = admin_user();

if (($current['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    exit('Only a super administrator may manage users.');
}

$message = '';
$error = '';
$investmentAccessReady=investment_user_access_ready();
$managedRoleCondition="LOWER(REPLACE(role,'_',' ')) IN ('super admin','super administrator','admin','administrator','investments only','investment only','investment','investor')";
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
    "SELECT id,username,email,full_name,role,is_active,last_login_at,created_at
     FROM admin_users
     WHERE $managedRoleCondition
     ORDER BY is_active DESC, full_name ASC"
)->fetchAll();

$roleLabels = [
    'super_admin' => 'Super Admin',
    'admin' => 'Admin',
    'investments_only' => 'Investments Only',
];
?>
<div class="admin-page-head">
  <div>
    <h1>Users</h1>
  </div>
  <a class="primary" href="user_edit.php">Add User</a>
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
          <p><strong>@<?= e((string)$admin['username']) ?></strong><br><?= email_link($admin['email']) ?></p>
        </div>
        <span class="admin-status-pill <?= $admin['is_active'] ? 'is-active' : 'is-inactive' ?>">
          <?= $admin['is_active'] ? 'Active' : 'Disabled' ?>
        </span>
      </div>

      <dl class="admin-record-details">
        <div>
          <dt>Role</dt>
          <dd><?= e(investments_only_role($admin)?'Investments Only':($roleLabels[$admin['role']] ?? ucwords(str_replace('_', ' ', $admin['role'])))) ?></dd>
        </div>
        <div>
          <dt>Last Login</dt>
          <dd><?= e($admin['last_login_at'] ?: 'Never') ?></dd>
        </div>
        <div>
          <dt>Created</dt>
          <dd><?= e($admin['created_at']) ?></dd>
        </div>
      </dl>

      <div class="admin-record-actions">
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
