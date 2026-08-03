<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_login();
if(!super_admin_role()){http_response_code(403);exit('Only a Super Admin may run database upgrades.');}
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_check((string)($_POST['csrf_token']??'')))$error='Your session expired. Refresh and try again.';
    else try{
        if(!db()->query("SHOW TABLES LIKE 'management_month_end_submissions'")->fetchColumn())throw new RuntimeException('Install the Management Properties database upgrade first.');
        if(!db()->query("SHOW COLUMNS FROM management_month_end_submissions LIKE 'state_tax_adjustments'")->fetchColumn())db()->exec("ALTER TABLE management_month_end_submissions ADD COLUMN state_tax_adjustments DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER state_tax");
        if(!db()->query("SHOW COLUMNS FROM management_month_end_submissions LIKE 'city_tax_adjustments'")->fetchColumn())db()->exec("ALTER TABLE management_month_end_submissions ADD COLUMN city_tax_adjustments DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER city_tax");
        $message='Month End tax adjustments are installed.';
    }catch(Throwable $exception){$error=$exception->getMessage();error_log('Month End tax adjustments upgrade failed: '.$exception->getMessage());}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Month End Tax Adjustments Upgrade</title><link rel="stylesheet" href="<?=e(app_url('/assets/design-system.css'))?>"></head><body class="znp-workspace-shell"><main style="width:min(720px,92%);margin:48px auto"><section class="card"><h1>Month End Tax Adjustments Upgrade</h1><p>Adds State Tax Adjustments and City Tax Adjustments to Month End reporting.</p><?php if($message):?><div class="notice-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice-error"><?=e($error)?></div><?php endif;?><?php if(!$message):?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="btn btn-primary" type="submit">Install Tax Adjustment Fields</button></form><?php endif;?><p><a href="<?=e(app_url('/manage/month_end.php'))?>">Return to Management Month End</a></p></section></main></body></html>
