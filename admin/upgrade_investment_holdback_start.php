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
            if(!$pdo->query("SHOW TABLES LIKE 'investment_model_settings'")->fetchColumn()){
                throw new RuntimeException('The investment_model_settings table is not installed.');
            }
            if(!$pdo->query("SHOW COLUMNS FROM investment_model_settings LIKE 'holdback_start_month'")->fetch()){
                $pdo->exec(
                    'ALTER TABLE investment_model_settings
                     ADD COLUMN holdback_start_month SMALLINT UNSIGNED NOT NULL DEFAULT 1
                     AFTER first_distribution_month'
                );
                $message='Holdback Start Month was added successfully.';
            }else{
                $message='Holdback Start Month is already installed. No database changes were required.';
            }
        }catch(Throwable $exception){
            error_log('Investment holdback start upgrade failed: '.$exception->getMessage());
            $error='Upgrade failed: '.$exception->getMessage();
        }
    }
}

require __DIR__.'/_header.php';
?>
<div class="admin-page-head">
  <div>
    <h1>Investment Holdback Start Upgrade</h1>
    <p>Add a project-specific month for activating the forward-looking cash holdback.</p>
  </div>
</div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel">
  <h2>Holdback Start Month</h2>
  <p>This safe, rerunnable installer adds the Holdback Start Month setting. The default is Month 1, preserving the model’s current behavior.</p>
  <form method="post" onsubmit="return confirm('Install or verify the Holdback Start Month field?')">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <button class="primary" type="submit">Install / Verify Upgrade</button>
  </form>
</section>
<?php require __DIR__.'/_footer.php';?>
