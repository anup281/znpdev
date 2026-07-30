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

            $added=[];
            if(!$pdo->query("SHOW COLUMNS FROM investment_model_settings LIKE 'starting_cash_month'")->fetch()){
                $pdo->exec(
                    'ALTER TABLE investment_model_settings
                     ADD COLUMN starting_cash_month SMALLINT UNSIGNED NOT NULL DEFAULT 6
                     AFTER targeted_operating_expense_percent'
                );
                $added[]='Starting Cash Month';
            }
            if(!$pdo->query("SHOW COLUMNS FROM investment_model_settings LIKE 'starting_cash_amount'")->fetch()){
                $pdo->exec(
                    'ALTER TABLE investment_model_settings
                     ADD COLUMN starting_cash_amount DECIMAL(15,2) NOT NULL DEFAULT 300000.00
                     AFTER starting_cash_month'
                );
                $added[]='Starting Cash Amount';
            }

            $message=$added
                ? implode(' and ',$added).' added successfully.'
                : 'Starting Cash settings are already installed. No database changes were required.';
        }catch(Throwable $exception){
            error_log('Investment starting cash upgrade failed: '.$exception->getMessage());
            $error='Upgrade failed: '.$exception->getMessage();
        }
    }
}

require __DIR__.'/_header.php';
?>
<div class="admin-page-head">
  <div>
    <h1>Investment Starting Cash Upgrade</h1>
    <p>Add a project-specific operating cash funding month and amount.</p>
  </div>
</div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel">
  <h2>Starting Cash Settings</h2>
  <p>This safe, rerunnable installer adds Starting Cash Month and Starting Cash Amount. Existing model settings are preserved. Defaults are Month 6 and $300,000.</p>
  <form method="post" onsubmit="return confirm('Install or verify the Investment Starting Cash fields?')">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <button class="primary" type="submit">Install / Verify Upgrade</button>
  </form>
</section>
<?php require __DIR__.'/_footer.php';?>
