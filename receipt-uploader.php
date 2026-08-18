<?php
declare(strict_types=1);
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/receipt_uploader.php';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$pinError='';$lockedUntil=(int)($_SESSION['receipt_uploader_pin_locked_until']??0);
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='unlock_receipt_uploader'){
    if(!csrf_check((string)($_POST['csrf_token']??'')))$pinError='Your session expired. Refresh and try again.';
    elseif($lockedUntil>time())$pinError='Too many attempts. Try again in '.max(1,$lockedUntil-time()).' seconds.';
    else{$passcode=(string)($_POST['passcode']??'');if(znp_receipt_uploader_passcode_valid($passcode)){znp_receipt_uploader_unlock();header('Location: '.app_url('/uploader'));exit;}$failures=(int)($_SESSION['receipt_uploader_pin_failures']??0)+1;$_SESSION['receipt_uploader_pin_failures']=$failures;if($failures>=5){$_SESSION['receipt_uploader_pin_failures']=0;$_SESSION['receipt_uploader_pin_locked_until']=time()+60;$pinError='Too many attempts. Try again in 60 seconds.';}else $pinError='Incorrect passcode. Please try again.';}
}
$pinUnlocked=znp_receipt_uploader_is_unlocked();
$properties=$pinUnlocked?db()->query('SELECT id,property_name FROM management_properties WHERE is_active=1 ORDER BY property_name')->fetchAll():[];
$now=new DateTimeImmutable('now',new DateTimeZone('America/Chicago'));$currentMonth=$now->format('Y-m');
if($currentMonth<'2026-07')$currentMonth='2026-07';elseif($currentMonth>'2045-12')$currentMonth='2045-12';
$selectedPeriod=DateTimeImmutable::createFromFormat('!Y-m',$currentMonth)?:$now;
$monthOptions=[];foreach([-1,0,1] as $monthOffset){$period=$selectedPeriod->modify(($monthOffset<0?'':'+' ).$monthOffset.' month');$periodValue=$period->format('Y-m');if($periodValue>='2026-07'&&$periodValue<='2045-12')$monthOptions[$periodValue]=$period->format('F Y');}
$pageTitle='Receipt Uploader | ZNP Development';$activePage='receipt-uploader';$bodyClass='receipt-uploader-page receipt-uploader-standalone'.($pinUnlocked?'':' receipt-uploader-locked');
$pageSeo=['title'=>$pageTitle,'description'=>'Upload property receipts and ACH or check documentation.','og_title'=>'Receipt Uploader','og_description'=>'Upload property receipts and payment documentation.'];
$pageStylesheets=['/assets/receipt-uploader.css?v=4','/assets/receipt-uploader-compact.css?v=2'];$pageRobots='noindex,nofollow';$pageManifest='/receipt-uploader.webmanifest';$pageThemeColor='#173a57';$pageAppleMobileWebAppTitle='Receipt Uploader';$pageAppleTouchIcon='/assets/icons/receipt-uploader-180.png';
require __DIR__.'/includes/header.php';
?>
<?php if(!$pinUnlocked):?>
<main class="receipt-pin-shell" data-service-worker="<?=e(app_url('/receipt-uploader-sw.js'))?>"><section class="receipt-pin-card"><div class="receipt-pin-icon"><i class="fa-solid fa-lock" aria-hidden="true"></i></div><p class="receipt-uploader-eyebrow">ZNP Management</p><h1>Receipt Uploader</h1><p>Enter the four-digit passcode to continue.</p><?php if($pinError):?><div class="receipt-pin-error" role="alert"><?=e($pinError)?></div><?php endif;?><form method="post" data-receipt-pin-gate><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="unlock_receipt_uploader"><input type="hidden" name="passcode" value="" data-pin-value><div class="receipt-pin-dots" aria-label="Passcode entry" data-pin-dots><span></span><span></span><span></span><span></span></div><div class="receipt-pin-keypad" aria-label="Numeric keypad"><?php foreach([1,2,3,4,5] as $digit):?><button type="button" data-pin-digit="<?=$digit?>" aria-label="<?=$digit?>"><?=$digit?></button><?php endforeach;?><button type="button" class="receipt-pin-delete" data-pin-delete aria-label="Delete last digit"><i class="fa-solid fa-delete-left" aria-hidden="true"></i></button></div><p class="receipt-pin-status" data-pin-status aria-live="polite"></p></form></section></main>
<script src="<?=e(app_url('/assets/receipt-uploader.js'))?>?v=5" defer></script>
<?php require __DIR__.'/includes/footer.php';exit;?>
<?php endif;?>
<main class="receipt-uploader-main" data-upload-endpoint="<?=e(app_url('/helpers/receipt_uploader_upload.php'))?>" data-service-worker="<?=e(app_url('/receipt-uploader-sw.js'))?>">
 <div class="receipt-uploader-period"><i class="fa-regular fa-calendar" aria-hidden="true"></i><label for="receiptUploadMonth">Upload month</label><select id="receiptUploadMonth" aria-label="Upload month"><?php foreach($monthOptions as $monthValue=>$monthLabel):?><option value="<?=e($monthValue)?>"<?=$monthValue===$currentMonth?' selected':''?>><?=e($monthLabel)?></option><?php endforeach;?></select></div>
 <section class="receipt-property-list" aria-label="Properties">
 <?php foreach($properties as $property):?>
  <details class="receipt-property-card">
   <summary><h2><?=e($property['property_name'])?></h2><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
   <div class="receipt-property-upload-grid">
   <?php foreach(['owner'=>['Receipts','fa-receipt','receipts'],'owner_ach_check'=>['ACH/CHECK','fa-money-check-dollar','ACH or check documentation']] as $receiptGroup=>[$sectionLabel,$sectionIcon,$sectionDescription]):$formId='receiptUpload'.(int)$property['id'].str_replace('_','',ucwords($receiptGroup,'_'));?>
    <form class="receipt-public-upload" id="<?=e($formId)?>" enctype="multipart/form-data" data-property-name="<?=e($property['property_name'])?>" data-section-name="<?=e($sectionLabel)?>">
     <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="property_id" value="<?=(int)$property['id']?>"><input type="hidden" name="receipt_group" value="<?=e($receiptGroup)?>"><input type="hidden" name="report_year" value="<?=e(substr($currentMonth,0,4))?>"><input type="hidden" name="report_month" value="<?=e((string)(int)substr($currentMonth,5,2))?>"><input class="receipt-uploader-honeypot" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
     <div class="receipt-upload-heading"><span><i class="fa-solid <?=e($sectionIcon)?>" aria-hidden="true"></i></span><div><h3><?=e($sectionLabel)?></h3><p>Upload <?=e($sectionDescription)?>.</p></div></div>
     <label class="receipt-public-drop-zone"><input type="file" name="files[]" multiple accept=".pdf,application/pdf,image/jpeg,image/png,image/webp" data-file-picker><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i><strong>Drop files here</strong><span>or tap to choose PDFs or photos</span><small data-selection>No files selected</small></label>
     <div class="receipt-upload-actions"><label class="receipt-camera-button"><input type="file" name="files[]" accept="image/*" capture="environment" data-camera-picker><i class="fa-solid fa-camera" aria-hidden="true"></i> Take Photo</label><span>JPG, PNG, WebP, or PDF · 50 MB max</span></div>
     <div class="receipt-upload-status" role="status" aria-live="polite" data-upload-status></div>
    </form>
   <?php endforeach;?>
   </div>
  </details>
 <?php endforeach;?>
 <?php if(!$properties):?><div class="receipt-uploader-empty"><h2>No properties available</h2><p>There are no active management properties configured for uploads.</p></div><?php endif;?>
 </section>
</main>
<script src="<?=e(app_url('/assets/receipt-uploader.js'))?>?v=5" defer></script>
<?php require __DIR__.'/includes/footer.php';?>
