<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_admin();

if(!in_array(normalized_role((string)(admin_user()['role']??'')),['super admin','super administrator'],true)){
    http_response_code(403);
    exit('Only a Super Admin may run database upgrades.');
}

$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_check((string)($_POST['csrf_token']??''))){
        $error='Your session expired. Refresh and try again.';
    }else{
        try{
            $pdo=db();
            if(!$pdo->query("SHOW TABLES LIKE 'investment_model_settings'")->fetchColumn())throw new RuntimeException('The investment_model_settings table is not installed.');
            $columns=[
                'number_of_units'=>'INT UNSIGNED NULL',
                'market_rent_monthly'=>'DECIMAL(15,2) NULL',
                'other_income_monthly'=>'DECIMAL(15,2) NULL',
                'vacancy_loss_percent'=>'DECIMAL(7,4) NULL',
            ];
            $added=[];
            foreach($columns as $column=>$definition){
                $check=$pdo->query('SHOW COLUMNS FROM investment_model_settings LIKE '.$pdo->quote($column));
                if(!$check->fetch()){
                    $pdo->exec("ALTER TABLE investment_model_settings ADD COLUMN `$column` $definition");
                    $added[]=$column;
                }
            }
            $message=$added
                ?'Monthly income assumptions installed successfully: '.implode(', ',$added).'. Existing gross-income values remain available as a fallback.'
                :'Monthly income assumptions are already installed. No database changes were required.';
        }catch(Throwable $exception){
            error_log('Investment income assumptions upgrade failed: '.$exception->getMessage());
            $error='Upgrade failed: '.$exception->getMessage();
        }
    }
}

require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><div><h1>Investment Income Assumptions Upgrade</h1><p>Add unit-based monthly income assumptions to each investment model.</p></div><a class="secondary" href="opportunities.php">Back to Investments</a></div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel"><h2>Monthly Gross Operating Income</h2><p>This safe, rerunnable installer adds Number of Units, Monthly Market Rent, Monthly Other Income, and Vacancy Loss. Existing settings and projections are preserved until the new fields are entered.</p><form method="post" onsubmit="return confirm('Install or verify the monthly income assumptions?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="primary" type="submit">Install / Verify Upgrade</button></form></section>
<?php require __DIR__.'/_footer.php';?>
