<?php
require __DIR__ . '/_header.php';

$current = admin_user();

if (($current['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    exit('Only a super administrator may manage users.');
}

$message = '';
$error = '';

if (isset($_GET['saved'])) {
    $message = 'Administrator account saved.';
}
if (isset($_GET['deleted'])) {
    $message = 'Administrator account deleted.';
}

if (isset($_GET['delete'])) {
    $deleteId = (int) $_GET['delete'];

    if ($deleteId === (int)($current['id'] ?? 0)) {
        $error = 'You cannot delete the account currently signed in.';
    } elseif ($deleteId > 0) {
        try {
            $check = db()->prepare("SELECT id FROM admin_users WHERE id=? AND LOWER(REPLACE(role,'_',' ')) IN ('super admin','super administrator','admin','administrator')");
            $check->execute([$deleteId]);
            if (!$check->fetchColumn()) {
                $error = 'That account is managed through the Construction Portal.';
            } else {
                db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$deleteId]);
                header('Location: users.php?deleted=1');
                exit;
            }
        } catch (Throwable $exception) {
            $error = 'The administrator account could not be deleted. Please try again.';
        }
    }
}

$users = db()->query(
    "SELECT id,username,email,full_name,role,is_active,last_login_at,created_at
     FROM admin_users
     WHERE LOWER(REPLACE(role,'_',' ')) IN ('super admin','super administrator','admin','administrator')
     ORDER BY is_active DESC, full_name ASC"
)->fetchAll();

$roleLabels = [
    'super_admin' => 'Super Admin',
    'admin' => 'Admin',
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
          <dd><?= e($roleLabels[$admin['role']] ?? ucwords(str_replace('_', ' ', $admin['role']))) ?></dd>
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
          <a
            class="admin-danger-link"
            href="?delete=<?= (int)$admin['id'] ?>"
            onclick="return confirm('Delete this user?')"
          >Delete</a>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
