<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_login();
if(!super_admin_role()){http_response_code(403);exit('Only a Super Admin may run database upgrades.');}
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_check((string)($_POST['csrf_token']??'')))$error='Your session expired. Refresh and try again.';
    else try{
        db()->exec("CREATE TABLE IF NOT EXISTS management_cpa_deliveries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            management_property_id BIGINT UNSIGNED NOT NULL,
            report_year SMALLINT UNSIGNED NOT NULL,
            report_month TINYINT UNSIGNED NOT NULL,
            recipient_email VARCHAR(190) NOT NULL,
            cc_email VARCHAR(190) NULL,
            recipient_name VARCHAR(150) NULL,
            sent_by_admin_user_id BIGINT UNSIGNED NULL,
            category_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            payment_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_management_cpa_period (management_property_id,report_year,report_month,sent_at),
            KEY idx_management_cpa_sender (sent_by_admin_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if(!db()->query("SHOW COLUMNS FROM management_cpa_deliveries LIKE 'cc_email'")->fetchColumn())db()->exec("ALTER TABLE management_cpa_deliveries ADD COLUMN cc_email VARCHAR(190) NULL AFTER recipient_email");
        $message='CPA delivery history is installed.';
    }catch(Throwable $exception){$error=$exception->getMessage();error_log('CPA delivery history upgrade failed: '.$exception->getMessage());}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CPA Delivery History Upgrade</title><link rel="stylesheet" href="<?=e(app_url('/assets/design-system.css'))?>"></head><body class="znp-workspace-shell"><main style="width:min(720px,92%);margin:48px auto"><section class="card"><h1>CPA Delivery History Upgrade</h1><p>Creates the database table used to record every successful Receipts email sent to the CPA.</p><?php if($message):?><div class="notice-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice-error"><?=e($error)?></div><?php endif;?><?php if(!$message):?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="btn btn-primary" type="submit">Install CPA Delivery History</button></form><?php endif;?><p><a href="<?=e(app_url('/manage/receipts.php'))?>">Return to Management Receipts</a></p></section></main></body></html>
