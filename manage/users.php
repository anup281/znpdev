<?php
require_once __DIR__ . '/includes/header.php';
$message='';$error='';
try{
  $tableExists=(bool)db()->query("SHOW TABLES LIKE 'management_users'")->fetchColumn();
}catch(Throwable $e){$tableExists=false;}
if($_SERVER['REQUEST_METHOD']==='POST' && $tableExists){
  if(!hash_equals(csrf_token(),(string)($_POST['csrf_token']??''))){$error='Your session expired. Please try again.';}
  else{
    $action=(string)($_POST['action']??'');
    try{
      if($action==='create'){
        $name=trim((string)($_POST['full_name']??''));$email=strtolower(trim((string)($_POST['email']??'')));$username=strtolower(trim((string)($_POST['username']??'')));$password=(string)($_POST['password']??'');
        if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||$username===''||strlen($password)<8)throw new RuntimeException('Complete all fields and use a password of at least 8 characters.');
        $s=db()->prepare('INSERT INTO management_users(full_name,email,username,password_hash,role,is_active,must_change_password,created_at,updated_at) VALUES(?,?,?,?,\'management_user\',1,1,NOW(),NOW())');
        $s->execute([$name,$email,$username,password_hash($password,PASSWORD_DEFAULT)]);$message='Management user created.';
      }elseif($action==='toggle'){
        $s=db()->prepare('UPDATE management_users SET is_active=?,updated_at=NOW() WHERE id=?');$s->execute([(int)($_POST['is_active']??0),(int)($_POST['id']??0)]);$message='User status updated.';
      }
    }catch(Throwable $e){$error=$e->getMessage();}
  }
}
$users=$tableExists?db()->query('SELECT id,full_name,email,username,role,is_active,last_login_at,created_at FROM management_users ORDER BY full_name')->fetchAll():[];
?>
<section class="manage-page-heading"><h1>Management Users</h1><p>Separate accounts reserved for future ZNP Management access.</p></section>
<?php if($message):?><div class="manage-alert success"><?=manage_e($message)?></div><?php endif;?><?php if($error):?><div class="manage-alert error"><?=manage_e($error)?></div><?php endif;?>
<?php if(!$tableExists):?><div class="manage-alert error">Run <strong>/install_management_portal.php</strong> before creating management users.</div><?php else:?>
<section class="manage-panel" style="margin-bottom:20px"><form method="post" class="manage-form-grid"><input type="hidden" name="csrf_token" value="<?=manage_e(csrf_token())?>"><input type="hidden" name="action" value="create"><label>Full Name<input name="full_name" required></label><label>Email<input type="email" name="email" required></label><label>Username<input name="username" required></label><label>Temporary Password<input type="password" name="password" minlength="8" required></label><div class="manage-form-full"><button class="manage-button primary">Create Management User</button></div></form></section>
<section class="manage-panel"><div style="overflow:auto"><table class="manage-table"><thead><tr><th>User</th><th>Username</th><th>Status</th><th>Last Login</th><th>Action</th></tr></thead><tbody><?php if(!$users):?><tr><td colspan="5">No management users created.</td></tr><?php endif;?><?php foreach($users as $u):?><tr><td><strong><?=manage_e($u['full_name'])?></strong><br><small><?=manage_e($u['email'])?></small></td><td><?=manage_e($u['username'])?></td><td><span class="manage-status <?=$u['is_active']?'active':'inactive'?>"><?=$u['is_active']?'Active':'Disabled'?></span></td><td><?=manage_e($u['last_login_at']?:'Never')?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?=manage_e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$u['id']?>"><input type="hidden" name="is_active" value="<?=$u['is_active']?0:1?>"><button class="manage-button <?=$u['is_active']?'danger':'primary'?>"><?=$u['is_active']?'Disable':'Enable'?></button></form></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
