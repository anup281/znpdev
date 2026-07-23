<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_admin();
if(!in_array(normalized_role((string)(admin_user()['role']??'')),['super admin','super administrator'],true)){http_response_code(403);exit('Only a Super Admin may run database upgrades.');}
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_check((string)($_POST['csrf_token']??'')))$error='Your session expired. Refresh and try again.';
    else{
        try{
            $pdo=db();$column=$pdo->query("SHOW COLUMNS FROM investment_opportunities LIKE 'status'")->fetch();
            if(!$column)throw new RuntimeException('The investment status column could not be found.');
            if(stripos((string)$column['Type'],"'archived'")===false){$sqlMode=(string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();try{$pdo->exec("SET SESSION sql_mode=''");$pdo->exec("ALTER TABLE investment_opportunities MODIFY status enum('draft','raising_capital','funded','closed','archived') NOT NULL DEFAULT 'draft'");}finally{$pdo->exec('SET SESSION sql_mode='.$pdo->quote($sqlMode));}}
            $repaired=$pdo->exec("UPDATE investment_opportunities SET status='archived' WHERE status='' AND is_visible=0 AND accepting_inquiries=0");
            $message='Investment Archive upgrade completed successfully'.($repaired?' and repaired '.number_format($repaired).' legacy project(s).':'.');
        }catch(Throwable $exception){$error='Upgrade failed: '.$exception->getMessage();}
    }
}
require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><div><h1>Investment Archive Upgrade</h1><p>Add supported archive status and repair legacy archived projects.</p></div></div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel"><h2>Archived Investment Status</h2><p>This rerunnable upgrade adds the archived status without removing existing values and moves legacy invalid archive records to the supported status.</p><form method="post" onsubmit="return confirm('Run the Investment Archive database upgrade?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="primary" type="submit">Run Upgrade</button></form></section>
<?php require __DIR__.'/_footer.php';?>
