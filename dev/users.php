<?php
require __DIR__.'/includes/header.php';
if(!dev_is_super()){http_response_code(403);exit('Administrator access required.');}
$message='';$error='';
function dev_admin_password_column_v3(): ?string {
    $cols=db()->query('SHOW COLUMNS FROM admin_users')->fetchAll();
    foreach($cols as $c){if(in_array($c['Field'],['password_hash','password','passwd'],true))return $c['Field'];}
    return null;
}
function dev_construction_user_exists(int $userId): bool {
    $check=db()->prepare("SELECT id FROM admin_users WHERE id=? AND COALESCE(construction_only,0)=1 AND LOWER(REPLACE(role,'_',' ')) NOT IN ('super admin','super administrator')");
    $check->execute([$userId]);
    return (bool)$check->fetchColumn();
}
if($_SERVER['REQUEST_METHOD']==='POST'&&csrf_check((string)($_POST['csrf']??''))){
 try{
  $action=(string)($_POST['action']??'');
  if($action==='create_user'){
    $name=trim((string)($_POST['full_name']??''));$email=strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');$role=(string)($_POST['construction_role']??'Field Staff / Helper');
    if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<8)throw new RuntimeException('Name, valid email, and a temporary password of at least 8 characters are required.');
    $exists=db()->prepare('SELECT id FROM admin_users WHERE email=? LIMIT 1');$exists->execute([$email]);if($exists->fetchColumn())throw new RuntimeException('A user with that email already exists.');
    $pc=dev_admin_password_column_v3();if(!$pc)throw new RuntimeException('Could not identify the password column in admin_users.');
    $sql="INSERT INTO admin_users(full_name,email,`$pc`,role,is_active,construction_only,construction_role,must_change_password) VALUES(?,?,?,?,1,1,?,1)";
    db()->prepare($sql)->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),'construction_user',$role]);
    $userId=(int)db()->lastInsertId();
    $projectIds=array_map('intval',(array)($_POST['project_ids']??[]));
    $ins=db()->prepare('INSERT INTO construction_project_users(construction_project_id,admin_user_id,project_role,is_active,created_at) VALUES(?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE project_role=VALUES(project_role),is_active=1');
    foreach(array_unique($projectIds) as $pid){if($pid>0)$ins->execute([$pid,$userId,$role]);}
    $message='Construction user created and project access assigned.';
  } elseif($action==='save_access'){
    $userId=(int)($_POST['admin_user_id']??0);if($userId<1)throw new RuntimeException('Select a user.');
    if(!dev_construction_user_exists($userId))throw new RuntimeException('Construction user not found or cannot be managed here.');
    $role=(string)($_POST['project_role']??'Field Staff / Helper');$selected=array_unique(array_filter(array_map('intval',(array)($_POST['project_ids']??[]))));
    db()->prepare('UPDATE construction_project_users SET is_active=0 WHERE admin_user_id=?')->execute([$userId]);
    $ins=db()->prepare('INSERT INTO construction_project_users(construction_project_id,admin_user_id,project_role,is_active,created_at) VALUES(?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE project_role=VALUES(project_role),is_active=1');
    foreach($selected as $pid){$ins->execute([$pid,$userId,$role]);}
    db()->prepare('UPDATE admin_users SET construction_role=? WHERE id=?')->execute([$role,$userId]);
    $message='Project access updated.';
  } elseif($action==='reset_password'){
    $userId=(int)($_POST['admin_user_id']??0);
    $password=(string)($_POST['new_password']??'');
    $confirm=(string)($_POST['confirm_password']??'');
    $requireChange=isset($_POST['require_change'])?1:0;
    if($userId<1)throw new RuntimeException('Select a user.');
    if(strlen($password)<8)throw new RuntimeException('Temporary password must be at least 8 characters.');
    if($password!==$confirm)throw new RuntimeException('The password confirmation does not match.');
    $pc=dev_admin_password_column_v3();if(!$pc)throw new RuntimeException('Could not identify the password column in admin_users.');
    if(!dev_construction_user_exists($userId))throw new RuntimeException('Construction user not found or cannot be managed here.');
    db()->prepare("UPDATE admin_users SET `$pc`=?,must_change_password=?,failed_login_attempts=0,locked_until=NULL WHERE id=?")->execute([password_hash($password,PASSWORD_DEFAULT),$requireChange,$userId]);
    $message='Password reset successfully.';
  } elseif($action==='toggle_user'){
    $userId=(int)($_POST['admin_user_id']??0);$active=(int)($_POST['is_active']??0);
    if(!dev_construction_user_exists($userId))throw new RuntimeException('Construction user not found or cannot be managed here.');
    db()->prepare('UPDATE admin_users SET is_active=? WHERE id=? AND COALESCE(construction_only,0)=1')->execute([$active,$userId]);$message=$active?'User activated.':'User deactivated.';
  }
 }catch(Throwable $e){$error=$e->getMessage();}
}
$projects=dev_projects();
$users=db()->query("SELECT id,full_name,email,role,is_active,COALESCE(construction_only,0) construction_only,COALESCE(construction_role,'') construction_role,last_login_at FROM admin_users WHERE COALESCE(construction_only,0)=1 AND LOWER(REPLACE(role,'_',' ')) NOT IN ('super admin','super administrator') ORDER BY full_name")->fetchAll();
$activeTestUsers=array_values(array_filter($users,static fn(array $account):bool=>(int)$account['is_active']===1));
$assignRows=db()->query('SELECT construction_project_id,admin_user_id,project_role FROM construction_project_users WHERE is_active=1')->fetchAll();$assign=[];foreach($assignRows as $r){$assign[(int)$r['admin_user_id']][(int)$r['construction_project_id']]=$r['project_role'];}
?>
<div class="page-head"><div><h1>Construction Users</h1><p class="muted">Create field users and give each person access to one or multiple projects.</p></div></div>
<?php if($message):?><div class="card notice-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<div class="grid grid-2">
<form method="post" class="card form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_user"><div class="form-full"><h2>Add Construction User</h2></div><div><label>Full Name</label><input name="full_name" required></div><div><label>Email</label><input type="email" name="email" required></div><div><label>Temporary Password</label><input type="password" name="password" minlength="8" required></div><div><label>Default Role</label><select name="construction_role"><option>Project Administrator</option><option>Project Manager</option><option>Superintendent</option><option>Field Staff / Helper</option><option>Contractor</option><option>Read Only</option></select></div><div class="form-full"><label>Project Access</label><div class="project-checks"><?php foreach($projects as $p):?><label class="check-card"><input type="checkbox" name="project_ids[]" value="<?=$p['id']?>"><span><?=e($p['project_name'])?></span></label><?php endforeach;?></div><small>Select every project this user should see.</small></div><div class="form-full"><button class="primary">Create User</button></div></form>
<form method="post" class="card form-grid" id="accessForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_access"><div class="form-full"><h2>Update Project Access</h2><p class="muted" id="editingUserText">Choose a user below or click Edit beside their name.</p></div><div class="form-full"><label>User</label><select name="admin_user_id" id="accessUser" required><option value="">Select user</option><?php foreach($users as $u):?><option value="<?=$u['id']?>"><?=e($u['full_name'].' · '.$u['email'])?></option><?php endforeach;?></select></div><div class="form-full"><label>Role for Selected Projects</label><select name="project_role"><option>Project Administrator</option><option>Project Manager</option><option>Superintendent</option><option>Field Staff / Helper</option><option>Contractor</option><option>Read Only</option></select></div><div class="form-full"><label>Project Access</label><div class="project-checks"><?php foreach($projects as $p):?><label class="check-card"><input class="access-project" type="checkbox" name="project_ids[]" value="<?=$p['id']?>"><span><?=e($p['project_name'])?></span></label><?php endforeach;?></div><small>Saving replaces this user's current project list.</small></div><div class="form-full"><button class="primary">Save Project Access</button></div></form>
</div>
<div class="card znp-mt-5"><h2>Current Users</h2><div class="table-wrap"><table><thead><tr><th>User</th><th>Type</th><th>Projects</th><th>Default Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead><tbody><?php foreach($users as $u):?><tr><td><strong><?=e($u['full_name'])?></strong><br><span class="muted"><?=e($u['email'])?></span></td><td><?=$u['construction_only']?'Construction Only':'Super Admin'?></td><td><?php $names=[];foreach($projects as $p){if(isset($assign[(int)$u['id']][(int)$p['id']]))$names[]=e($p['project_name']);}echo $names?implode('<br>',$names):($u['construction_only']?'<span class="muted">No projects assigned</span>':'All projects'); ?></td><td><?=e(ucwords(str_replace('_',' ',(string)($u['construction_role']?:$u['role']))))?></td><td><?=$u['is_active']?'Active':'Inactive'?></td><td><?=e(dev_datetime($u['last_login_at']??null))?></td><td><button type="button" class="btn edit-user-access" data-user-id="<?=$u['id']?>" data-user-name="<?=e($u['full_name'])?>" data-user-role="<?=e(ucwords(str_replace('_',' ',(string)($u['construction_role']?:$u['role']))))?>">Edit Access</button> <button type="button" class="btn reset-user-password" data-user-id="<?=$u['id']?>" data-user-name="<?=e($u['full_name'])?>">Reset Password</button><?php if($u['construction_only']):?><form method="post" class="znp-inline-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="admin_user_id" value="<?=$u['id']?>"><input type="hidden" name="is_active" value="<?=$u['is_active']?0:1?>"><button class="btn"><?=$u['is_active']?'Deactivate':'Activate'?></button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="dev-modal" id="resetPasswordModal" hidden><div class="dev-modal-panel reset-password-panel"><button type="button" class="modal-close" data-close-modal aria-label="Close">×</button><h2>Reset Password</h2><p class="muted" id="resetPasswordUserText"></p><form method="post" class="form-grid" id="resetPasswordForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="admin_user_id" id="resetPasswordUserId"><div class="form-full"><label>Temporary Password</label><div class="password-row"><input type="text" name="new_password" id="newTemporaryPassword" minlength="8" autocomplete="new-password" required><button type="button" class="btn btn-secondary" id="generateTemporaryPassword">Generate</button></div></div><div class="form-full"><label>Confirm Password</label><input type="text" name="confirm_password" id="confirmTemporaryPassword" minlength="8" autocomplete="new-password" required></div><div class="form-full"><label class="inline-check"><input type="checkbox" name="require_change" value="1" checked> Require password change at next login</label></div><div class="form-full modal-actions"><button type="button" class="btn btn-secondary" data-close-modal>Cancel</button><button class="primary">Save New Password</button></div></form></div></div>
<section class="card znp-mt-5"><div class="section-heading"><div><h2>Test User Access</h2><p class="muted">Open the Construction Portal with a user’s exact project assignments and permissions.</p></div></div><div class="actions"><?php foreach($activeTestUsers as $account):?><form method="post" action="<?=e(app_url('/admin/impersonate.php'))?>" class="znp-inline-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="user_id" value="<?=(int)$account['id']?>"><button class="btn btn-secondary" type="submit">View As <?=e($account['full_name'])?></button></form><?php endforeach;?><?php if(!$activeTestUsers):?><span class="muted">No active Construction users are available.</span><?php endif;?></div></section>
<script>
const assignments=<?=json_encode($assign,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const accessUser=document.getElementById('accessUser');
const accessForm=document.getElementById('accessForm');
const editingUserText=document.getElementById('editingUserText');
function loadUserAccess(userId,userName,userRole){
  if(!accessUser)return;
  accessUser.value=String(userId);
  const selected=assignments[String(userId)]||assignments[userId]||{};
  document.querySelectorAll('.access-project').forEach(cb=>{
    cb.checked=Object.prototype.hasOwnProperty.call(selected,String(cb.value)) || Object.prototype.hasOwnProperty.call(selected,Number(cb.value));
  });
  const roleSelect=accessForm?.querySelector('select[name="project_role"]');
  if(roleSelect && userRole){
    const matching=[...roleSelect.options].find(o=>o.value===userRole);
    if(matching)roleSelect.value=userRole;
  }
  if(editingUserText)editingUserText.textContent='Editing project access for '+(userName||accessUser.options[accessUser.selectedIndex]?.text||'selected user')+'.';
}
accessUser?.addEventListener('change',function(){
  const option=this.options[this.selectedIndex];
  loadUserAccess(this.value,option?.text||'', '');
});
document.querySelectorAll('.edit-user-access').forEach(btn=>btn.addEventListener('click',function(){
  loadUserAccess(this.dataset.userId,this.dataset.userName,this.dataset.userRole);
  accessForm?.scrollIntoView({behavior:'smooth',block:'start'});
}));

const resetModal=document.getElementById('resetPasswordModal');
document.querySelectorAll('.reset-user-password').forEach(btn=>btn.addEventListener('click',function(){
  document.getElementById('resetPasswordUserId').value=this.dataset.userId;
  document.getElementById('resetPasswordUserText').textContent='Create a temporary password for '+this.dataset.userName+'.';
  document.getElementById('newTemporaryPassword').value='';
  document.getElementById('confirmTemporaryPassword').value='';
  resetModal.hidden=false;document.body.classList.add('modal-open');
}));
function createTemporaryPassword(){
  const upper='ABCDEFGHJKLMNPQRSTUVWXYZ',lower='abcdefghijkmnopqrstuvwxyz',digits='23456789',symbols='!@#$%';
  const all=upper+lower+digits+symbols;
  let out=upper[Math.floor(Math.random()*upper.length)]+lower[Math.floor(Math.random()*lower.length)]+digits[Math.floor(Math.random()*digits.length)]+symbols[Math.floor(Math.random()*symbols.length)];
  while(out.length<12)out+=all[Math.floor(Math.random()*all.length)];
  return out.split('').sort(()=>Math.random()-.5).join('');
}
document.getElementById('generateTemporaryPassword')?.addEventListener('click',()=>{
  const value=createTemporaryPassword();
  document.getElementById('newTemporaryPassword').value=value;
  document.getElementById('confirmTemporaryPassword').value=value;
});
</script>
<?php require __DIR__.'/includes/footer.php';?>
