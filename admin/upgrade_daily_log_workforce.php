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
            $pdo=db();
            $table=$pdo->query("SHOW TABLES LIKE 'construction_daily_log_workforce'")->fetchColumn();
            if(!$table)throw new RuntimeException('The construction_daily_log_workforce table is not installed.');
            $column=$pdo->query("SHOW COLUMNS FROM construction_daily_log_workforce LIKE 'source_key'")->fetch();
            if(!$column)$pdo->exec("ALTER TABLE construction_daily_log_workforce ADD COLUMN source_key varchar(100) COLLATE utf8mb4_unicode_ci NULL AFTER source_id");
            $pdo->exec("UPDATE construction_daily_log_workforce SET source_key=CONCAT(source_type,'_',source_id) WHERE source_key IS NULL OR source_key=''");
            $column=$pdo->query("SHOW COLUMNS FROM construction_daily_log_workforce LIKE 'source_key'")->fetch();
            if(strtoupper((string)($column['Null']??'YES'))!=='NO')$pdo->exec("ALTER TABLE construction_daily_log_workforce MODIFY source_key varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL");
            $index=$pdo->query("SHOW INDEX FROM construction_daily_log_workforce WHERE Key_name='uq_daily_log_workforce_source'")->fetchAll();
            usort($index,static fn(array $a,array $b): int=>((int)($a['Seq_in_index']??0))<=>((int)($b['Seq_in_index']??0)));
            $columns=array_map(static fn(array $row):string=>(string)$row['Column_name'],$index);
            $expected=['daily_log_id','source_type','source_key'];
            if($columns!==$expected){if($index)$pdo->exec('ALTER TABLE construction_daily_log_workforce DROP INDEX uq_daily_log_workforce_source');$pdo->exec('ALTER TABLE construction_daily_log_workforce ADD UNIQUE KEY uq_daily_log_workforce_source (daily_log_id,source_type,source_key)');}
            $message='Company + Trade Daily Log upgrade completed successfully.';
        }catch(Throwable $exception){error_log('Daily Log workforce upgrade failed: '.$exception->getMessage());$error='Upgrade failed: '.$exception->getMessage();}
    }
}
require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><div><h1>Daily Log Workforce Upgrade</h1><p>Allow separate workforce records for every Company + Trade relationship.</p></div></div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel"><h2>Company + Trade Source Keys</h2><p>This rerunnable upgrade preserves existing workforce history, adds a stable trade-level source key, and replaces the legacy company-level unique index.</p><form method="post" onsubmit="return confirm('Run the Daily Log Company + Trade database upgrade?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="primary" type="submit">Run Upgrade</button></form></section>
<?php require __DIR__.'/_footer.php';?>
