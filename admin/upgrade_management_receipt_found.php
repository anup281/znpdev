<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_login();
if(!super_admin_role()){http_response_code(403);exit('Only a Super Admin may run database upgrades.');}
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_check((string)($_POST['csrf_token']??'')))$error='Your session expired. Refresh and try again.';
    else try{
        if(!db()->query("SHOW TABLES LIKE 'management_receipt_transactions'")->fetchColumn())throw new RuntimeException('Install Management Receipts before running this upgrade.');
        if(!db()->query("SHOW COLUMNS FROM management_receipt_transactions LIKE 'receipt_found'")->fetchColumn())db()->exec("ALTER TABLE management_receipt_transactions ADD COLUMN receipt_found TINYINT(1) NOT NULL DEFAULT 0 AFTER category_id");
        $message='Receipt Found tracking is installed.';
    }catch(Throwable $exception){$error=$exception->getMessage();error_log('Receipt Found upgrade failed: '.$exception->getMessage());}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Receipt Found Upgrade</title><link rel="stylesheet" href="<?=e(app_url('/assets/design-system.css'))?>"></head><body class="znp-workspace-shell"><main style="width:min(720px,92%);margin:48px auto"><section class="card"><h1>Receipt Found Upgrade</h1><p>Adds the saved Receipt Found checkbox used to complete receipt line items.</p><?php if($message):?><div class="notice-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice-error"><?=e($error)?></div><?php endif;?><?php if(!$message):?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="btn btn-primary" type="submit">Install Receipt Found Tracking</button></form><?php endif;?><p><a href="<?=e(app_url('/manage/receipts.php'))?>">Return to Management Receipts</a></p></section></main></body></html>
