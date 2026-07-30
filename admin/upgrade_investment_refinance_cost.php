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

            $column=$pdo->query("SHOW COLUMNS FROM investment_model_settings LIKE 'refinance_cost'")->fetch();
            if(!$column){
                $pdo->exec(
                    'ALTER TABLE investment_model_settings
                     ADD COLUMN refinance_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00
                     AFTER refinance_loan_amount'
                );
                $message='Refinance Cost was added to Investment Model Settings successfully.';
            }else{
                $message='Refinance Cost is already installed. No database changes were required.';
            }
        }catch(Throwable $exception){
            error_log('Investment refinance cost upgrade failed: '.$exception->getMessage());
            $error='Upgrade failed: '.$exception->getMessage();
        }
    }
}

require __DIR__.'/_header.php';
?>
<div class="admin-page-head">
  <div>
    <h1>Investment Refinance Cost Upgrade</h1>
    <p>Add a fixed, project-specific refinance cost to Investment Model Settings.</p>
  </div>
</div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel">
  <h2>Refinance Cost</h2>
  <p>This safe, rerunnable installer adds the refinance cost field if it is missing. Existing investment settings and calculations are preserved.</p>
  <form method="post" onsubmit="return confirm('Install or verify the Investment Refinance Cost field?')">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <button class="primary" type="submit">Install / Verify Upgrade</button>
  </form>
</section>
<?php require __DIR__.'/_footer.php';?>
