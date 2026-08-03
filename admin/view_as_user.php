<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_admin();
if(!super_admin_role()){http_response_code(403);exit('Only a Super Admin may view as another user.');}
$users=db()->query("SELECT id,full_name,email,role,COALESCE(construction_only,0) construction_only,COALESCE(construction_role,'') construction_role FROM admin_users WHERE is_active=1 AND LOWER(REPLACE(role,'_',' ')) NOT IN ('super admin','super administrator') ORDER BY full_name")->fetchAll();
require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><div><h1>View As User</h1><p>Open any Admin, Construction, or Management account using its assigned access. The user’s password is not changed or shared.</p></div><a class="secondary" href="users.php">Back to Users</a></div>
<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Email</th><th>Portal Role</th><th></th></tr></thead><tbody><?php foreach($users as $account):?><tr><td><strong><?=e($account['full_name'])?></strong></td><td><?=email_link($account['email'])?></td><td><?=e((int)$account['construction_only']===1?('Construction — '.($account['construction_role']?:'User')):ucwords(str_replace('_',' ',(string)$account['role'])))?></td><td><form method="post" action="impersonate.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="user_id" value="<?=(int)$account['id']?>"><button class="primary admin-small-button" type="submit">View As</button></form></td></tr><?php endforeach;?><?php if(!$users):?><tr><td colspan="4">No active users are available.</td></tr><?php endif;?></tbody></table></div>
<?php require __DIR__.'/_footer.php';?>
