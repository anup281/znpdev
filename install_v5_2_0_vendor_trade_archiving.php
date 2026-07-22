<?php
declare(strict_types=1);
require_once __DIR__.'/includes/auth.php';

require_login();
$installerUser=admin_user();
$installerRole=normalized_role((string)($installerUser['role']??''));
if(!in_array($installerRole,['super admin','super administrator'],true)){
    http_response_code(403);
    exit('Super Administrator access required.');
}

$error='';
$message='';
$columnExists=false;
$indexExists=false;

function znp_vendor_trade_archive_status(): array
{
    $column=db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='construction_company_trades' AND COLUMN_NAME='archived_at'");
    $column->execute();
    $index=db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='construction_company_trades' AND INDEX_NAME='idx_company_trade_archived'");
    $index->execute();
    return [(int)$column->fetchColumn()>0,(int)$index->fetchColumn()>0];
}

try{[$columnExists,$indexExists]=znp_vendor_trade_archive_status();}
catch(Throwable $exception){$error='Could not inspect the database: '.$exception->getMessage();}

if($_SERVER['REQUEST_METHOD']==='POST'&&$error===''){
    try{
        if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Your session expired. Refresh the page and try again.');
        if(!$columnExists){db()->exec('ALTER TABLE construction_company_trades ADD COLUMN archived_at DATETIME NULL AFTER updated_at');}
        [$columnExists,$indexExists]=znp_vendor_trade_archive_status();
        if(!$indexExists){db()->exec('ALTER TABLE construction_company_trades ADD INDEX idx_company_trade_archived (archived_at)');}
        [$columnExists,$indexExists]=znp_vendor_trade_archive_status();
        if(!$columnExists||!$indexExists)throw new RuntimeException('The database upgrade did not finish successfully.');
        $message='Version 5.2.0 vendor trade archiving is installed. Company + Trade relationships can now be archived independently.';
    }catch(Throwable $exception){$error=$exception->getMessage()?:'The database upgrade could not be completed.';}
}

$installed=$columnExists&&$indexExists;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install Vendor Trade Archiving | ZNP Development</title>
<link rel="stylesheet" href="assets/site.css">
<style>
body{margin:0;background:#f4f7f9;color:#172333;font-family:Arial,sans-serif}.installer-wrap{width:min(92%,720px);margin:60px auto}.installer-card{background:#fff;border:1px solid #dbe4eb;border-radius:14px;padding:28px;box-shadow:0 10px 32px rgba(20,45,70,.08)}h1{margin-top:0;color:#0d294b}.status{padding:13px 15px;margin:16px 0;border-radius:8px}.status.success{background:#e4f6eb;color:#236b45}.status.error{background:#fde7e7;color:#9b2929}.installer-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}.installer-actions a,.installer-actions button{display:inline-flex;align-items:center;justify-content:center;padding:11px 16px;border:0;border-radius:7px;text-decoration:none;font-weight:700;cursor:pointer}.primary{background:#1e5c8f;color:#fff}.secondary{background:#e7edf2;color:#172333}.checklist{padding-left:20px}.checklist li{margin:7px 0}
</style>
</head>
<body><main class="installer-wrap"><section class="installer-card">
<h1>Vendor Trade Archiving Installer</h1>
<p>This Version 5.2.0 upgrade allows each Company + Trade relationship to be archived and restored independently without deleting vendor or project history.</p>
<ul class="checklist"><li>Archive one trade while leaving the vendor's other trades active.</li><li>Preserve Project Team and Daily Log history.</li><li>Safe to run more than once.</li></ul>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<?php if($installed&&!$message):?><div class="status success">This upgrade is already installed. No database changes are required.</div><?php endif;?>
<div class="installer-actions">
<?php if(!$installed):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button class="primary" type="submit">Run Installer</button></form><?php endif;?>
<a class="secondary" href="dev/companies.php">Open Vendors</a>
</div>
</section></main></body></html>
