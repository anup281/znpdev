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
$investmentAccessReady=investment_user_access_ready();
$managedRoleCondition="LOWER(REPLACE(role,'_',' ')) IN ('super admin','super administrator','admin','administrator','investments only','investment only','investment','investor')";
if($investmentAccessReady)$managedRoleCondition.=" OR (COALESCE(role,'')='' AND EXISTS (SELECT 1 FROM investment_user_access iua WHERE iua.admin_user_id=admin_users.id))";
$investments=db()->query('SELECT id,project_name,status FROM investment_opportunities ORDER BY project_name')->fetchAll();
$selectedInvestmentIds=[];

$account = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'role' => 'admin',
    'is_active' => 1,
];

if ($id > 0) {
    $statement = db()->prepare(
        "SELECT id,full_name,username,email,role,is_active FROM admin_users WHERE id = ? AND ($managedRoleCondition)"
    );
    $statement->execute([$id]);
    $foundAccount = $statement->fetch();
    if (!$foundAccount) {
        http_response_code(404);
        exit('Administrator account not found. Construction users are managed in the Construction Portal.');
    }
    $account = $foundAccount;
    if(investments_only_role($account))$account['role']='investments_only';
    if($investmentAccessReady){
        $accessStatement=db()->prepare('SELECT investment_opportunity_id FROM investment_user_access WHERE admin_user_id=?');
        $accessStatement->execute([$id]);
        $selectedInvestmentIds=array_map('intval',$accessStatement->fetchAll(PDO::FETCH_COLUMN));
    }
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
        $selectedInvestmentIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['investment_ids']??[])),static fn($value)=>$value>0)));

        if ($name === '' || !preg_match('/^[a-z0-9._]{3,40}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid name, username, and email address. Usernames may use letters, numbers, periods, and underscores.';
        } elseif (!in_array($role, ['super_admin','admin','investments_only'], true)) {
            $error = 'Select a valid role.';
        } elseif ($role==='investments_only'&&!$investmentAccessReady) {
            $error = 'Install the Investment User Access upgrade before creating an Investments Only account.';
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
            db()->beginTransaction();
            $savedId=$id;
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
                $savedId=(int)db()->lastInsertId();
            }

            if($investmentAccessReady){
                db()->prepare('DELETE FROM investment_user_access WHERE admin_user_id=?')->execute([$savedId]);
                if($role==='investments_only'&&$selectedInvestmentIds){
                    $validMarks=implode(',',array_fill(0,count($selectedInvestmentIds),'?'));
                    $validStatement=db()->prepare("SELECT id FROM investment_opportunities WHERE id IN ($validMarks)");
                    $validStatement->execute($selectedInvestmentIds);
                    $validIds=array_map('intval',$validStatement->fetchAll(PDO::FETCH_COLUMN));
                    if(count($validIds)!==count($selectedInvestmentIds))throw new RuntimeException('One or more selected investments are invalid.');
                    $assign=db()->prepare('INSERT INTO investment_user_access(admin_user_id,investment_opportunity_id,assigned_by_admin_user_id) VALUES(?,?,?)');
                    foreach($validIds as $investmentId)$assign->execute([$savedId,$investmentId,(int)($current['id']??0)]);
                }
            }
            db()->commit();
            header('Location: users.php?saved=1');
            exit;
            } catch (Throwable $exception) {
                if(db()->inTransaction())db()->rollBack();
                error_log('Admin account save failed: '.$exception->getMessage());
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
          'investments_only' => 'Investments Only',
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

  <section class="admin-password-panel">
    <h2>Investment Access</h2>
    <?php if(!$investmentAccessReady):?>
      <p>The access table is not installed. <a href="upgrade_investment_user_access.php">Run the Investment User Access installer</a> before selecting Investments Only.</p>
    <?php elseif(!$investments):?>
      <p>No investments are available to assign.</p>
    <?php else:?>
      <p>Select every investment this account may open. These assignments apply when the role is Investments Only.</p>
      <div class="admin-form-grid">
        <?php foreach($investments as $investment):?>
          <label class="admin-checkbox-panel">
            <span class="admin-checkbox"><input type="checkbox" name="investment_ids[]" value="<?=(int)$investment['id']?>" <?=in_array((int)$investment['id'],$selectedInvestmentIds,true)?'checked':''?>> <?=e((string)$investment['project_name'])?></span>
            <small><?=e(ucwords(str_replace('_',' ',(string)$investment['status'])))?></small>
          </label>
        <?php endforeach;?>
      </div>
    <?php endif;?>
  </section>

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
