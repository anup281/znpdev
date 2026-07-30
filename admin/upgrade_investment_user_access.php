<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_admin();

if(!in_array(normalized_role((string)(admin_user()['role']??'')),['super admin','super administrator'],true)){
    http_response_code(403);
    exit('Only a Super Admin may run database upgrades.');
}

$message='';
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_check((string)($_POST['csrf_token']??''))){
        $error='Your session expired. Refresh and try again.';
    }else{
        try{
            $roleColumn=db()->query("SHOW COLUMNS FROM admin_users LIKE 'role'")->fetch();
            if(!$roleColumn)throw new RuntimeException('The admin_users.role column was not found.');
            $roleType=(string)($roleColumn['Type']??'');
            if(str_starts_with(strtolower($roleType),'enum(')&&stripos($roleType,"'investments_only'")===false){
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/",$roleType,$roleMatches);
                $roleValues=array_map('stripcslashes',$roleMatches[1]??[]);
                $roleValues[]='investments_only';
                $roleValues=array_values(array_unique($roleValues));
                $enumValues=implode(',',array_map(static fn(string $value):string=>db()->quote($value),$roleValues));
                $roleDefinition='ENUM('.$enumValues.')';
                $roleDefinition.=strtoupper((string)($roleColumn['Null']??'NO'))==='NO'?' NOT NULL':' NULL';
                if(array_key_exists('Default',$roleColumn)&&$roleColumn['Default']!==null)$roleDefinition.=' DEFAULT '.db()->quote((string)$roleColumn['Default']);
                db()->exec('ALTER TABLE admin_users MODIFY role '.$roleDefinition);
            }

            db()->exec(
                "CREATE TABLE IF NOT EXISTS investment_user_access (
                    admin_user_id BIGINT UNSIGNED NOT NULL,
                    investment_opportunity_id BIGINT UNSIGNED NOT NULL,
                    assigned_by_admin_user_id BIGINT UNSIGNED NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (admin_user_id,investment_opportunity_id),
                    KEY idx_investment_user_access_opportunity (investment_opportunity_id),
                    KEY idx_investment_user_access_assigned_by (assigned_by_admin_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $required=['admin_user_id','investment_opportunity_id','assigned_by_admin_user_id','created_at'];
            $existing=db()->query('SHOW COLUMNS FROM investment_user_access')->fetchAll(PDO::FETCH_COLUMN);
            $missing=array_diff($required,$existing);
            if($missing)throw new RuntimeException('The access table is incomplete. Missing columns: '.implode(', ',$missing).'.');
            $repair=db()->exec(
                "UPDATE admin_users au
                 SET au.role='investments_only'
                 WHERE COALESCE(au.role,'')=''
                   AND EXISTS (
                       SELECT 1 FROM investment_user_access iua
                       WHERE iua.admin_user_id=au.id
                   )"
            );
            $message='Investment User Access was installed and verified successfully. '
                .number_format((int)$repair).' assigned account role'.((int)$repair===1?' was':'s were').' repaired.';
        }catch(Throwable $exception){
            error_log('Investment user access upgrade failed: '.$exception->getMessage());
            $error='Upgrade failed: '.$exception->getMessage();
        }
    }
}

require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><div><h1>Investment User Access Upgrade</h1><p>Enable project-specific access for Investments Only accounts.</p></div><a class="secondary" href="users.php">Back to Users</a></div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel">
  <h2>Investment Assignments</h2>
  <p>This rerunnable installer creates the user-to-investment access table. Existing accounts, investments, and assignments are preserved.</p>
  <form method="post" onsubmit="return confirm('Install or verify Investment User Access?')">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <button class="primary" type="submit">Install / Verify Upgrade</button>
  </form>
</section>
<?php require __DIR__.'/_footer.php';?>
