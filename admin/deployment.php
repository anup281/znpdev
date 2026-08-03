<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/deployment.php';
require_admin();

$currentUser=admin_user();
if(!is_array($currentUser)||!in_array(normalized_role((string)($currentUser['role']??'')),['super admin','super administrator'],true)){
    http_response_code(403);
    exit('Only a Super Admin may deploy the test website.');
}

$error='';
$success='';
$result=null;
$preflight=null;

try{
    $preflight=znp_deployment_preflight();
}catch(Throwable $exception){
    $error=$exception->getMessage();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!csrf_check((string)($_POST['csrf_token']??'')))throw new RuntimeException('Your session expired. Refresh the page and try again.');
        if((string)($_POST['confirmation']??'')!=='DEPLOY TEST TO PRODUCTION'){
            throw new RuntimeException('Enter the confirmation phrase exactly as shown.');
        }
        if(empty($_POST['understand_backup'])){
            throw new RuntimeException('Confirm that you understand the production replacement and backup process.');
        }
        $result=znp_deployment_run();
        $success='Deployment completed. Production was backed up and /test was promoted to the document root.';
        $preflight=znp_deployment_preflight();
    }catch(Throwable $exception){
        error_log('Production deployment failed: '.$exception->getMessage());
        $error=$exception->getMessage();
    }
}

require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><div><h1>Production Deployment</h1><p>Back up the production document root and promote the current <strong>/test</strong> website.</p></div></div>
<?php if($success):?><div class="status success"><?=e($success)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>

<?php if($result):?>
<section class="settings-card">
 <h2>Deployment Complete</h2>
 <p><strong>Backup:</strong> /backup/<?=e((string)$result['backup'])?></p>
 <p><strong>Previous production:</strong> <?=number_format((int)$result['production']['files'])?> files, <?=e(znp_deployment_format_bytes((int)$result['production']['bytes']))?></p>
 <p><strong>Promoted from /test:</strong> <?=number_format((int)$result['source']['files'])?> files, <?=e(znp_deployment_format_bytes((int)$result['source']['bytes']))?></p>
</section>
<?php endif;?>

<section class="settings-card">
 <h2>Deployment Safety</h2>
 <ul>
  <li>Only the server document root is treated as production.</li>
  <li>The <strong>/test</strong> and <strong>/backup</strong> folders are always preserved.</li>
  <li>Current production files are archived before any production file is removed.</li>
  <li>If promotion fails, the new backup is restored automatically.</li>
  <li>Symbolic links or ambiguous directory layouts stop the deployment.</li>
 </ul>
</section>

<?php if($preflight):?>
<section class="settings-card">
 <h2>Preflight</h2>
 <div class="settings-grid">
  <div><strong>Production root</strong><p><?=e((string)$preflight['paths']['production'])?></p></div>
  <div><strong>Deployment source</strong><p><?=e((string)$preflight['paths']['source'])?></p></div>
  <div><strong>Backup folder</strong><p><?=e((string)$preflight['paths']['backup'])?></p></div>
  <div><strong>Composer dependencies</strong><p><?=$preflight['vendor_ready']?'Ready — /vendor/autoload.php found':'Missing'?></p></div>
  <div><strong>Production backup size</strong><p><?=number_format((int)$preflight['production']['files'])?> files · <?=e(znp_deployment_format_bytes((int)$preflight['production']['bytes']))?></p></div>
  <div><strong>Test deployment size</strong><p><?=number_format((int)$preflight['source']['files'])?> files · <?=e(znp_deployment_format_bytes((int)$preflight['source']['bytes']))?></p></div>
 </div>
</section>

<form method="post" class="settings-card admin-form" onsubmit="return confirm('Deploy /test to production now? The current production files will be replaced after the backup is verified.');">
 <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
 <h2>Deploy /test to Production</h2>
 <p>This operation replaces every production-root file and folder except <strong>/test</strong> and <strong>/backup</strong>.</p>
 <label>Confirmation Phrase
  <input name="confirmation" autocomplete="off" placeholder="DEPLOY TEST TO PRODUCTION" required>
  <small class="admin-help-text">Enter: DEPLOY TEST TO PRODUCTION</small>
 </label>
 <label class="admin-inline-check"><input type="checkbox" name="understand_backup" value="1" required> I understand that production will be replaced after a verified ZIP backup is created.</label>
 <button class="admin-danger-button" type="submit">Create Backup and Deploy</button>
</form>
<?php endif;?>
<?php require __DIR__.'/_footer.php';?>
