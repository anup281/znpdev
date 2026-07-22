<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$current = admin_user();

if (($current['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    exit('Only a super administrator may manage users.');
}

$id = (int) ($_GET['id'] ?? 0);
$error = '';

$account = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'role' => 'admin',
    'is_active' => 1,
];

if ($id > 0) {
    $statement = db()->prepare(
        "SELECT id,full_name,username,email,role,is_active FROM admin_users WHERE id = ? AND LOWER(REPLACE(role,'_',' ')) IN ('super admin','super administrator','admin','administrator')"
    );
    $statement->execute([$id]);
    $foundAccount = $statement->fetch();
    if (!$foundAccount) {
        http_response_code(404);
        exit('Administrator account not found. Construction users are managed in the Construction Portal.');
    }
    $account = $foundAccount;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $name = trim($_POST['full_name'] ?? '');
        $username = strtolower(trim($_POST['username'] ?? ''));
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['role'] ?? 'admin';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['password_confirmation'] ?? '');

        if ($name === '' || !preg_match('/^[a-z0-9._]{3,40}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid name, username, and email address. Usernames may use letters, numbers, periods, and underscores.';
        } elseif (!in_array($role, ['super_admin','admin'], true)) {
            $error = 'Select a valid role.';
        } elseif ($id === (int)($current['id'] ?? 0) && !$isActive) {
            $error = 'You cannot disable the account currently signed in.';
        } elseif (($id === 0 || $password !== '') && strlen($password) < 12) {
            $error = 'New passwords must contain at least 12 characters.';
        } elseif ($password !== $confirmPassword) {
            $error = 'The password confirmation does not match.';
        }

        if (!$error) {
            $duplicate = db()->prepare(
                'SELECT id FROM admin_users WHERE (email = ? OR LOWER(username) = ?) AND id <> ? LIMIT 1'
            );
            $duplicate->execute([$email, $username, $id]);

            if ($duplicate->fetch()) {
                $error = 'That email address or username is already assigned to another user.';
            }
        }

        if (!$error) {
            try {
            if ($id > 0) {
                if ($password !== '') {
                    $statement = db()->prepare(
                        'UPDATE admin_users
                         SET full_name=?, username=?, email=?, role=?, is_active=?, password_hash=?,
                             failed_login_attempts=0, locked_until=NULL
                         WHERE id=?'
                    );
                    $statement->execute([
                        $name,
                        $username,
                        $email,
                        $role,
                        $isActive,
                        password_hash($password, PASSWORD_DEFAULT),
                        $id,
                    ]);
                } else {
                    $statement = db()->prepare(
                        'UPDATE admin_users
                         SET full_name=?, username=?, email=?, role=?, is_active=?
                         WHERE id=?'
                    );
                    $statement->execute([$name, $username, $email, $role, $isActive, $id]);
                }
            } else {
                $statement = db()->prepare(
                    'INSERT INTO admin_users
                     (full_name,username,email,role,is_active,password_hash)
                     VALUES (?,?,?,?,?,?)'
                );
                $statement->execute([
                    $name,
                    $username,
                    $email,
                    $role,
                    $isActive,
                    password_hash($password, PASSWORD_DEFAULT),
                ]);
            }

            header('Location: users.php?saved=1');
            exit;
            } catch (Throwable $exception) {
                $error = 'The administrator account could not be saved. Please verify the information and try again.';
            }
        }

        $account = [
            'full_name' => $name,
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'is_active' => $isActive,
        ];
    }
}
require __DIR__ . '/_header.php';
?>
<div class="admin-page-head">
  <div>
    <h1><?= $id ? 'Edit User' : 'Add User' ?></h1>
    <p><?= $id ? 'Leave the password blank to keep the current password.' : 'Create a new administrator account.' ?></p>
  </div>
  <a class="secondary" href="users.php">Back to Users</a>
</div>

<?php if ($error): ?>
  <div class="status error"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" class="admin-form admin-form-wide">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

  <div class="admin-form-grid">
    <div>
      <label for="full_name">Full Name *</label>
      <input id="full_name" name="full_name" required value="<?= e((string)$account['full_name']) ?>">
    </div>
    <div>
      <label for="username">Username *</label>
      <input id="username" name="username" required minlength="3" maxlength="40" pattern="[A-Za-z0-9._]+" value="<?= e((string)$account['username']) ?>">
    </div>
  </div>

  <div class="admin-form-grid">
    <div>
      <label for="email">Email Address *</label>
      <input id="email" type="email" name="email" required value="<?= e((string)$account['email']) ?>">
    </div>
  </div>

  <div class="admin-form-grid">
    <div>
      <label for="role">Role *</label>
      <select id="role" name="role" required>
        <?php foreach ([
          'super_admin' => 'Super Admin',
          'admin' => 'Admin',
        ] as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $account['role'] === $value ? 'selected' : '' ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-checkbox-panel">
      <label class="admin-checkbox">
        <input type="checkbox" name="is_active" <?= $account['is_active'] ? 'checked' : '' ?>>
        Account is active
      </label>
    </div>
  </div>

  <div class="admin-password-panel">
    <h2><?= $id ? 'Set a New Password' : 'Password' ?></h2>
    <p><?= $id ? 'Leave both fields blank when the password should remain unchanged.' : 'Use at least 12 characters.' ?></p>

    <div class="admin-form-grid">
      <div>
        <label for="password"><?= $id ? 'New Password' : 'Password *' ?></label>
        <input id="password" type="password" name="password" minlength="12" <?= $id ? '' : 'required' ?>>
      </div>
      <div>
        <label for="password_confirmation">Confirm Password<?= $id ? '' : ' *' ?></label>
        <input id="password_confirmation" type="password" name="password_confirmation" minlength="12" <?= $id ? '' : 'required' ?>>
      </div>
    </div>
  </div>

  <button class="primary" type="submit">Save User</button>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
