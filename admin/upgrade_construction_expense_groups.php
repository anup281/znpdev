<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_admin();
if(!in_array(normalized_role((string)(admin_user()['role']??'')),['super admin','super administrator'],true)){http_response_code(403);exit('Only a Super Admin may run database upgrades.');}
require_once __DIR__.'/../dev/includes/expense_tracker.php';
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_check((string)($_POST['csrf_token']??'')))$error='Your session expired. Refresh and try again.';
    else try{
        dev_ensure_expense_tracker_schema();$projects=db()->query('SELECT id FROM construction_projects ORDER BY id')->fetchAll();$created=0;$linked=0;$defaults=['EQUITY','DRAW 1','DRAW 2','DRAW 3','DRAW 4','UNGROUPED'];
        foreach($projects as $project){$projectId=(int)$project['id'];foreach($defaults as $name){$before=db()->prepare('SELECT id FROM construction_expense_groups WHERE construction_project_id=? AND group_name=?');$before->execute([$projectId,$name]);if(!$before->fetchColumn())$created++;dev_ensure_expense_group($projectId,$name,(int)(admin_user()['id']??0));}dev_sync_expense_groups($projectId,(int)(admin_user()['id']??0));$linked+=dev_sync_expense_budget_items($projectId);}
        $message='Expense groups installed and verified. '.number_format($created).' group records created and '.number_format($linked).' expenses cross-referenced.';
    }catch(Throwable $exception){error_log('Construction expense group upgrade failed: '.$exception->getMessage());$error='Upgrade failed: '.$exception->getMessage();}
}
require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><div><h1>Construction Expense Groups Upgrade</h1><p>Create Bank Draw groups and cross-reference existing project expenses.</p></div><a class="secondary" href="../dev/projects.php">Back to Construction</a></div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel"><h2>Database Upgrade</h2><p>This rerunnable installer creates project-scoped expense groups, seeds EQUITY, DRAW 1 through DRAW 4, and UNGROUPED, then links existing expenses to both their matching group records and individual budget line items.</p><form method="post" onsubmit="return confirm('Install and cross-reference Construction expense groups?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="primary" type="submit">Install / Verify Expense Groups</button></form></section>
<?php require __DIR__.'/_footer.php';?>
