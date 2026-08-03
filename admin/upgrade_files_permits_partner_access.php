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
            $pdo=db();
            $roleColumn=$pdo->query("SHOW COLUMNS FROM admin_users LIKE 'role'")->fetch();
            if(!$roleColumn)throw new RuntimeException('The admin_users.role column was not found.');
            $roleType=(string)($roleColumn['Type']??'');
            $converted=$pdo->exec("UPDATE admin_users SET role='super_admin' WHERE LOWER(REPLACE(REPLACE(TRIM(role),'_',' '),'-',' ')) IN ('admin','administrator')");
            if(str_starts_with(strtolower($roleType),'enum(')){
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/",$roleType,$roleMatches);
                $roleValues=array_map('stripcslashes',$roleMatches[1]??[]);
                $roleValues=array_values(array_filter($roleValues,static fn(string $value):bool=>!in_array(normalized_role($value),['admin','administrator'],true)));
                $roleValues=array_merge($roleValues,['super_admin','partner','investments_only']);
                $roleValues=array_values(array_unique($roleValues));
                $enumValues=implode(',',array_map(static fn(string $value):string=>$pdo->quote($value),$roleValues));
                $definition='ENUM('.$enumValues.')';
                $definition.=strtoupper((string)($roleColumn['Null']??'NO'))==='NO'?' NOT NULL':' NULL';
                if(array_key_exists('Default',$roleColumn)&&$roleColumn['Default']!==null){
                    $roleDefault=(string)$roleColumn['Default'];
                    if(in_array(normalized_role($roleDefault),['admin','administrator'],true))$roleDefault='super_admin';
                    if(in_array($roleDefault,$roleValues,true))$definition.=' DEFAULT '.$pdo->quote($roleDefault);
                }
                $pdo->exec('ALTER TABLE admin_users MODIFY role '.$definition);
            }
            $usernameColumn=$pdo->query("SHOW COLUMNS FROM admin_users LIKE 'username'")->fetch();
            if($usernameColumn&&strtoupper((string)($usernameColumn['Null']??'NO'))!=='YES'){
                $usernameType=(string)($usernameColumn['Type']??'varchar(190)');
                $pdo->exec('ALTER TABLE admin_users MODIFY username '.$usernameType.' NULL DEFAULT NULL');
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS construction_project_permits (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                construction_project_id BIGINT UNSIGNED NOT NULL,
                permit_number VARCHAR(190) NOT NULL,
                permit_type VARCHAR(190) NOT NULL,
                created_by_admin_user_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_construction_project_permits_project (construction_project_id),
                KEY idx_construction_project_permits_user (created_by_admin_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_partner_permissions (
                admin_user_id BIGINT UNSIGNED NOT NULL,
                permission_key VARCHAR(50) NOT NULL,
                assigned_by_admin_user_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (admin_user_id,permission_key),
                KEY idx_admin_partner_permissions_assigner (assigned_by_admin_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $requiredTables=[
                'construction_project_permits'=>['id','construction_project_id','permit_number','permit_type','created_by_admin_user_id','created_at','updated_at'],
                'admin_partner_permissions'=>['admin_user_id','permission_key','assigned_by_admin_user_id','created_at'],
            ];
            foreach($requiredTables as $table=>$requiredColumns){
                $existing=$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
                $missing=array_diff($requiredColumns,$existing);
                if($missing)throw new RuntimeException($table.' is incomplete. Missing columns: '.implode(', ',$missing).'.');
            }
            $message='Files & Permits and Partner Access were installed and verified successfully. '.number_format((int)$converted).' former Admin account'.((int)$converted===1?' was':'s were').' converted to Super Admin.';
        }catch(Throwable $exception){
            error_log('Files, permits, and Partner Access upgrade failed: '.$exception->getMessage());
            $error='Upgrade failed: '.$exception->getMessage();
        }
    }
}

require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><div><h1>Files, Permits &amp; Partner Access Upgrade</h1><p>Enable job permits and assignable Admin Portal access for Partner accounts.</p></div><a class="secondary" href="users.php">Back to Users</a></div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel">
  <h2>Database Upgrade</h2>
  <p>This rerunnable installer keeps Super Admin, Partner, and Investor as the Admin Portal roles, converts former Admin accounts to Super Admin, enables email-only user accounts, and creates the project permits and Partner permissions tables. Construction and Management user roles are preserved.</p>
  <form method="post" onsubmit="return confirm('Install or verify Files, Permits, and Partner Access?')">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <button class="primary" type="submit">Install / Verify Upgrade</button>
  </form>
</section>
<?php require __DIR__.'/_footer.php';?>
