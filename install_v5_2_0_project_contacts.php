<?php
declare(strict_types=1);
require_once __DIR__.'/includes/auth.php';
require_login();
$installerUser=admin_user();
$installerRole=normalized_role((string)($installerUser['role']??''));
if(!in_array($installerRole,['super admin','super administrator'],true)){http_response_code(403);exit('Super Administrator access required.');}
$error='';$message='';
function znp_project_contacts_installed(): bool {
    $q=db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='construction_project_contacts'");$q->execute();return (int)$q->fetchColumn()>0;
}
try{$installed=znp_project_contacts_installed();}catch(Throwable $e){$installed=false;$error='Could not inspect the database: '.$e->getMessage();}
if($_SERVER['REQUEST_METHOD']==='POST'&&$error==='')try{
    if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Your session expired. Refresh the page and try again.');
    db()->exec("CREATE TABLE IF NOT EXISTS construction_project_contacts (id INT UNSIGNED NOT NULL AUTO_INCREMENT,construction_project_id INT NOT NULL,role_title VARCHAR(150) NOT NULL,organization_name VARCHAR(190) NOT NULL DEFAULT '',contact_name VARCHAR(190) NOT NULL DEFAULT '',phone VARCHAR(50) NOT NULL DEFAULT '',email VARCHAR(190) NOT NULL DEFAULT '',address TEXT NULL,notes TEXT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_by_admin_user_id INT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY (id),KEY idx_project_contact_active (construction_project_id,is_active),KEY idx_project_contact_role (role_title)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $installed=znp_project_contacts_installed();if(!$installed)throw new RuntimeException('The database upgrade did not finish successfully.');$message='Project contacts are installed.';
}catch(Throwable $e){$error=$e->getMessage()?:'The database upgrade could not be completed.';}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install Project Contacts | ZNP Development</title><link rel="stylesheet" href="assets/site.css"><style>body{margin:0;background:#f4f7f9;color:#172333;font-family:Arial,sans-serif}.wrap{width:min(92%,720px);margin:60px auto}.card{background:#fff;border:1px solid #dbe4eb;border-radius:14px;padding:28px}.status{padding:13px 15px;margin:16px 0;border-radius:8px}.success{background:#e4f6eb;color:#236b45}.error{background:#fde7e7;color:#9b2929}.actions{display:flex;gap:10px;margin-top:22px}.actions a,.actions button{padding:11px 16px;border:0;border-radius:7px;text-decoration:none;font-weight:700}.primary{background:#1e5c8f;color:#fff}.secondary{background:#e7edf2;color:#172333}</style></head><body><main class="wrap"><section class="card"><h1>Project Contacts Installer</h1><p>Adds project-specific non-vendor contacts such as architects, engineers, fire marshals, and city inspectors. Safe to run more than once.</p><?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?><?php if($installed&&!$message):?><div class="status success">This upgrade is already installed.</div><?php endif;?><div class="actions"><?php if(!$installed):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button class="primary">Run Installer</button></form><?php endif;?><a class="secondary" href="dev/projects.php">Open Construction Projects</a></div></section></main></body></html>
