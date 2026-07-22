<?php
require __DIR__.'/includes/bootstrap.php';
$message='';$error='';$u=admin_user();
if($_SERVER['REQUEST_METHOD']==='POST'&&csrf_check((string)($_POST['csrf']??''))){
 try{
  $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');
  if(strlen($new)<8)throw new RuntimeException('New password must be at least 8 characters.');
  if($new!==$confirm)throw new RuntimeException('Password confirmation does not match.');
  $s=db()->prepare('SELECT password_hash FROM admin_users WHERE id=? AND is_active=1');$s->execute([(int)$u['id']]);$hash=(string)$s->fetchColumn();
  if(!$hash||!password_verify($current,$hash))throw new RuntimeException('Current password is incorrect.');
  db()->prepare('UPDATE admin_users SET password_hash=?,must_change_password=0,failed_login_attempts=0,locked_until=NULL WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),(int)$u['id']]);
  $_SESSION['admin_user']['must_change_password']=0;$message='Your password has been updated.';
 }catch(Throwable $e){$error=$e->getMessage();}
}
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>Change Password</h1><p class="muted">Choose a private password for your account.</p></div></div>
<?php if($message):?><div class="card notice-success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<form method="post" class="card form-grid password-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="form-full"><label>Current Password</label><input type="password" name="current_password" autocomplete="current-password" required></div><div><label>New Password</label><input type="password" name="new_password" minlength="8" autocomplete="new-password" required></div><div><label>Confirm New Password</label><input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></div><div class="form-full"><button class="primary">Update Password</button></div></form>
<?php require __DIR__.'/includes/footer.php';?>
