<?php
require_once __DIR__.'/includes/bootstrap.php';
$error='';$propertyId=manage_active_property_id();$property=$propertyId?manage_property($propertyId):null;
$years=range(2026,2045);$systemNow=new DateTimeImmutable('now',new DateTimeZone('America/Chicago'));$defaultPeriod=$systemNow->modify('first day of previous month');$defaultYear=(int)$defaultPeriod->format('Y');$defaultMonth=(int)$defaultPeriod->format('n');
if($_SERVER['REQUEST_METHOD']==='GET'&&!isset($_GET['year'])&&!isset($_GET['month'])){
    header('Location: month_end.php?year='.$defaultYear.'&month='.$defaultMonth);exit;
}
$selectedYear=(int)($_POST['report_year']??$_GET['year']??$defaultYear);$selectedMonth=(int)($_POST['report_month']??$_GET['month']??$defaultMonth);
if(!in_array($selectedYear,$years,true))$selectedYear=2026;
if($selectedMonth<1||$selectedMonth>12||($selectedYear===2026&&$selectedMonth<7))$selectedMonth=7;
if($_SERVER['REQUEST_METHOD']==='POST'&&$property&&manage_month_end_schema_ready()){
    if(!hash_equals(csrf_token(),(string)($_POST['csrf_token']??'')))$error='Your session expired. Please try again.';
    else{
        $action=(string)($_POST['action']??'save');$uploads=[];
        try{
            $find=db()->prepare('SELECT * FROM management_month_end_submissions WHERE management_property_id=? AND report_year=? AND report_month=?');
            $find->execute([$propertyId,$selectedYear,$selectedMonth]);$savedSubmission=$find->fetch()?:null;
            if($action==='unfinalize'){
                if(!manage_is_admin())throw new RuntimeException('Only a Super Admin may unfinalize a month.');
                if(!$savedSubmission||$savedSubmission['status']!=='finalized')throw new RuntimeException('This month is not finalized.');
                db()->prepare("UPDATE management_month_end_submissions SET status='submitted',submitted_by_admin_user_id=?,submitted_at=NOW() WHERE id=?")->execute([(int)($managementUser['id']??0),(int)$savedSubmission['id']]);
                header('Location: month_end.php?year='.$selectedYear.'&month='.$selectedMonth.'&unfinalized=1');exit;
            }
            if($action==='finalize'){
                if(!manage_is_admin())throw new RuntimeException('Only a Super Admin may finalize a month.');
                if(!$savedSubmission||$savedSubmission['status']!=='submitted')throw new RuntimeException('Submit this Month End before finalizing it.');
                if($savedSubmission['status']==='finalized')throw new RuntimeException('This month is already finalized. Unfinalize it before making further changes.');
                $uploads=manage_store_month_end_files($_FILES['final_files']??[],$propertyId,$selectedYear,$selectedMonth);
                db()->beginTransaction();$submissionId=(int)$savedSubmission['id'];
                $insert=db()->prepare('INSERT INTO management_month_end_files(management_month_end_submission_id,file_path,original_name,mime_type,file_size) VALUES(?,?,?,?,?)');
                foreach($uploads as $file)$insert->execute([$submissionId,$file['path'],$file['name'],$file['mime'],$file['size']]);
                db()->prepare("UPDATE management_month_end_submissions SET status='finalized',submitted_by_admin_user_id=?,submitted_at=NOW() WHERE id=?")->execute([(int)($managementUser['id']??0),$submissionId]);
                db()->commit();header('Location: month_end.php?year='.$selectedYear.'&month='.$selectedMonth.'&finalized=1');exit;
            }
            if($savedSubmission&&$savedSubmission['status']==='finalized')throw new RuntimeException('Unfinalize this month before making changes.');
            $values=manage_month_end_values($_POST,!empty($property['has_restaurant']));
            $uploads=manage_store_month_end_files($_FILES['report_files']??[],$propertyId,$selectedYear,$selectedMonth);
            if(!$uploads){$fileCount=0;if($savedSubmission){$count=db()->prepare('SELECT COUNT(*) FROM management_month_end_files WHERE management_month_end_submission_id=?');$count->execute([(int)$savedSubmission['id']]);$fileCount=(int)$count->fetchColumn();}if($fileCount<1)throw new RuntimeException('Upload at least one Month End report file.');}
            db()->beginTransaction();
            manage_save_month_end_values($propertyId,$selectedYear,$selectedMonth,$values,'submitted',(int)($managementUser['id']??0));
            $find->execute([$propertyId,$selectedYear,$selectedMonth]);$submissionId=(int)($find->fetch()['id']??0);
            $insert=db()->prepare('INSERT INTO management_month_end_files(management_month_end_submission_id,file_path,original_name,mime_type,file_size) VALUES(?,?,?,?,?)');
            foreach($uploads as $file)$insert->execute([$submissionId,$file['path'],$file['name'],$file['mime'],$file['size']]);
            db()->commit();header('Location: month_end.php?year='.$selectedYear.'&month='.$selectedMonth.'&submitted=1');exit;
        }catch(Throwable $e){
            if(db()->inTransaction())db()->rollBack();
            foreach($uploads as $file)try{znp_storage_delete((string)$file['path']);}catch(Throwable $cleanup){}
            $error=$e->getMessage();
        }
    }
}
$periodStatuses=[];$existing=null;$existingFiles=[];$taxCardStatuses=[];
if($property&&manage_month_end_schema_ready()){
    $calendar=db()->prepare('SELECT report_month,status FROM management_month_end_submissions WHERE management_property_id=? AND report_year=?');$calendar->execute([$propertyId,$selectedYear]);foreach($calendar->fetchAll() as $period)$periodStatuses[(int)$period['report_month']]=(string)$period['status']==='draft'?'blank':(string)$period['status'];
    $find=db()->prepare('SELECT * FROM management_month_end_submissions WHERE management_property_id=? AND report_year=? AND report_month=?');$find->execute([$propertyId,$selectedYear,$selectedMonth]);$existing=$find->fetch()?:null;
    if($existing){$files=db()->prepare('SELECT * FROM management_month_end_files WHERE management_month_end_submission_id=? ORDER BY id');$files->execute([(int)$existing['id']]);$existingFiles=$files->fetchAll();}
    $taxStatuses=db()->prepare('SELECT tax_key,status FROM management_month_end_tax_statuses WHERE management_property_id=? AND report_year=? AND report_month=?');$taxStatuses->execute([$propertyId,$selectedYear,$selectedMonth]);foreach($taxStatuses->fetchAll() as $taxStatus)$taxCardStatuses[(string)$taxStatus['tax_key']]=(string)$taxStatus['status'];
}
$isFinalized=$existing&&$existing['status']==='finalized';
require_once __DIR__.'/includes/header.php';
$months=[1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
?>
<?php if(isset($_GET['submitted'])):?><div class="manage-alert success">Month End reports saved successfully.</div><?php endif;?><?php if(isset($_GET['finalized'])):?><div class="manage-alert success">Month End finalized successfully. All report values are now locked.</div><?php endif;?><?php if(isset($_GET['unfinalized'])):?><div class="manage-alert success">Month End returned to Submitted status and can now be edited.</div><?php endif;?><?php if($error):?><div class="manage-alert error"><?=manage_e($error)?></div><?php endif;?>
<?php if(!manage_month_end_schema_ready()):?><div class="manage-alert error">Run the <a href="<?=manage_e(app_url('/admin/upgrade_management_month_end_tax_adjustments.php'))?>">Month End Tax Adjustments database upgrade</a> to enable Month End reporting.</div>
<?php elseif($property):?>
<section class="manage-year-calendar manage-panel"><div class="manage-calendar-heading"><div><h2><?=$selectedYear?> Calendar</h2><p>Select a month to view, upload, or update its report.</p></div><div class="manage-calendar-actions"><form method="get"><label for="calendarYear">Year</label><select id="calendarYear" name="year" onchange="this.form.submit()"><?php foreach($years as $year):?><option value="<?=$year?>" <?=$selectedYear===$year?'selected':''?>><?=$year?></option><?php endforeach;?></select></form><?php if(manage_is_admin()&&($existing||$taxCardStatuses)):?><button type="button" class="manage-reset-month-button" data-reset-month-end data-month-label="<?=manage_e($months[$selectedMonth].' '.$selectedYear)?>"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset Month</button><?php endif;?></div></div><div class="manage-calendar-months"><?php foreach($months as $number=>$name):$available=!($selectedYear===2026&&$number<7);$status=$periodStatuses[$number]??'blank';?><?php if($available):?><a class="manage-calendar-month <?=$selectedMonth===$number?'selected':''?> <?=manage_e($status)?>" href="?year=<?=$selectedYear?>&amp;month=<?=$number?>"><span class="manage-calendar-status-icon" aria-hidden="true"><i class="fa-solid <?=$status==='finalized'?'fa-arrow-up':($status==='submitted'?'fa-check':'fa-circle')?>"></i></span><span><?=$name?><small><?=ucfirst(manage_e($status))?></small></span></a><?php else:?><span class="manage-calendar-month unavailable"><span class="manage-calendar-status-icon" aria-hidden="true"><i class="fa-regular fa-circle"></i></span><span><?=$name?><small>Unavailable</small></span></span><?php endif;?><?php endforeach;?></div></section>
<div class="manage-month-end-layout"><section class="manage-month-end-main">
 <form method="post" enctype="multipart/form-data" class="manage-panel manage-month-end-form" data-autosave-url="month_end_autosave.php"><input type="hidden" name="csrf_token" value="<?=manage_e(csrf_token())?>"><input type="hidden" name="property_id" value="<?=$propertyId?>"><input type="hidden" name="report_year" value="<?=$selectedYear?>"><input type="hidden" name="report_month" value="<?=$selectedMonth?>"><input type="hidden" name="sales_tax" value="<?=manage_e($existing['sales_tax']??'0.00')?>"><input type="hidden" name="total_revenue" value="<?=manage_e($existing['total_revenue']??'0.00')?>">
  <div class="manage-autosave-status" id="monthEndAutosaveStatus" role="status" aria-live="polite"><?=$isFinalized?'Finalized — values are locked':'Changes save automatically'?></div>
  <fieldset class="manage-month-end-values" <?=$isFinalized?'disabled':''?>>
  <section class="manage-office-number-fields"><h2>Office Numbers</h2><div class="manage-money-grid">
   <?php foreach(['rooms_occupied'=>'Rooms Occupied','rooms_available'=>'Rooms Available'] as $field=>$label):?><label><?=$label?><span class="manage-money-input manage-room-count-input"><input type="number" min="0" step="1" name="<?=$field?>" inputmode="numeric" required value="<?=manage_e($existing[$field]??'0')?>"></span></label><?php endforeach;?>
   <?php foreach(['room_revenue'=>'Room Revenue','suite_shop_revenue'=>'Suite Shop Revenue'] as $field=>$label):?><label><?=$label?><span class="manage-money-input"><span>$</span><input name="<?=$field?>" inputmode="decimal" required value="<?=manage_e($existing[$field]??'0.00')?>"></span></label><?php endforeach;?>
  </div></section>
  <section class="manage-tax-fields"><h2>Taxes</h2><div class="manage-tax-groups">
   <?php foreach(['state'=>'State','city'=>'City'] as $taxKey=>$taxLabel):$taxField=$taxKey.'_tax';$adjustmentField=$taxField.'_adjustments';$collected=(float)($existing[$taxField]??0)-(float)($existing[$adjustmentField]??0);?>
   <section class="manage-tax-group"><h3><?=$taxLabel?> Tax</h3><div class="manage-money-grid manage-tax-money-grid">
    <label><?=$taxLabel?> Tax<span class="manage-money-input"><span>$</span><input id="<?=$taxField?>" name="<?=$taxField?>" inputmode="decimal" required value="<?=manage_e($existing[$taxField]??'0.00')?>"></span></label>
    <label><?=$taxLabel?> Tax Adjustments<span class="manage-money-input"><span>$</span><input id="<?=$adjustmentField?>" name="<?=$adjustmentField?>" inputmode="decimal" required value="<?=manage_e($existing[$adjustmentField]??'0.00')?>"></span></label>
    <label><?=$taxLabel?> Tax Collected<span class="manage-money-input manage-calculated-money-input"><span>$</span><input id="<?=$taxField?>_collected" readonly aria-readonly="true" data-tax-collected data-tax-input-id="<?=$taxField?>" data-adjustment-input-id="<?=$adjustmentField?>" value="<?=number_format($collected,2,'.','')?>"></span></label>
   </div></section>
   <?php endforeach;?>
  </div></section>
  <?php if(!empty($property['has_restaurant'])):?><section class="manage-restaurant-tax-fields"><h2>Restaurant Taxes</h2><div class="manage-money-grid"><?php foreach(['liquor_net_sales'=>'Liquor Net Sales','beer_net_sales'=>'Beer Net Sales','wine_net_sales'=>'Wine Net Sales','taxable_sales_mix_bev_sales_tax'=>'Mix Bev Sales Tax Taxable Sales'] as $field=>$label):?><label><?=$label?><span class="manage-money-input"><span>$</span><input name="<?=$field?>" inputmode="decimal" required value="<?=manage_e($existing[$field]??'0.00')?>"></span></label><?php endforeach;?></div></section><?php endif;?>
  </fieldset>
  <?php if(!$isFinalized):?><label class="manage-file-upload manage-file-drop-zone" data-drop-upload><strong><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i> Report Files</strong><span>Drag and drop multiple files here, or click to browse. Files upload automatically. Up to 25 MB each.</span><span class="manage-upload-selection" aria-live="polite">No files selected</span><input type="file" name="report_files[]" multiple accept=".pdf,.xls,.xlsx,.csv,.doc,.docx,.jpg,.jpeg,.png,.webp"></label><?php endif;?>
  <div class="manage-existing-files" id="monthEndFileList"><strong>Files attached to this month</strong><?php foreach($existingFiles as $file):?><span class="manage-file-item" data-file-id="<?=(int)$file['id']?>"><a href="month_end_file.php?id=<?=(int)$file['id']?>"><i class="fa-solid fa-paperclip"></i> <?=manage_e($file['original_name'])?></a><?php if(!$isFinalized):?><button type="button" class="manage-file-delete" data-file-id="<?=(int)$file['id']?>" aria-label="Delete <?=manage_e($file['original_name'])?>"><i class="fa-solid fa-trash"></i></button><?php endif;?></span><?php endforeach;?></div>
  <?php if(!$isFinalized):?><button class="manage-button primary manage-submit-button" name="action" value="save"><?= $existing&&$existing['status']==='submitted'?'Save Changes':'Submit Month End'?></button><?php endif;?>
 </form>
 <?php if(manage_is_admin()):?>
 <section class="manage-panel manage-finalize-panel">
  <div><h2>Finalize Month</h2><p>Review the calculated revenue and property bank details, attach any final supporting reports, then finalize this period.</p></div>
  <div class="manage-finalize-calculations">
   <?php $calculationGroups=[
    'city_tax'=>['City Tax',[
     ['Room Revenue','room_revenue',1,'divide'],['Taxable Revenue','city_tax,-city_tax_adjustments',0.09,'divide'],['Difference','room_revenue,city_tax,-city_tax_adjustments',0.09,'difference'],['City Tax Due','city_tax,-city_tax_adjustments',1,'divide']
    ]],
    'state_tax'=>['State Tax',[
     ['Room Revenue','room_revenue',1,'divide'],['Taxable Revenue','state_tax,-state_tax_adjustments',0.06,'divide'],['Difference','room_revenue,state_tax,-state_tax_adjustments',0.06,'difference'],['State Tax Due','state_tax,-state_tax_adjustments',1,'divide']
    ]],
    'suite_shop_tax'=>['Suite Shop Tax ('.ucfirst((string)($property['suite_shop_tax_frequency']??'monthly')).')',[
     ['Taxable Revenue','suite_shop_revenue',1,'divide'],['Suite Shop Tax Due','suite_shop_revenue',0.0825,'multiply']
    ]]
   ];if(!empty($property['has_restaurant'])){$calculationGroups['mixed_beverage_gross_receipts']=['Mixed Beverage Gross Receipts',[['Gross Receipts','liquor_net_sales,beer_net_sales,wine_net_sales',1,'divide'],['Mixed Beverage Gross Receipts Tax Due','liquor_net_sales,beer_net_sales,wine_net_sales',0.0670,'multiply']]];$calculationGroups['mixed_beverage_sales_tax']=['Mixed Beverage Sales Tax',[['Taxable Sales','taxable_sales_mix_bev_sales_tax',1,'divide'],['Mixed Beverage Sales Tax Due','taxable_sales_mix_bev_sales_tax',0.0825,'multiply']]];}foreach($calculationGroups as $taxKey=>[$groupTitle,$calculations]):$taxStatus=$taxCardStatuses[$taxKey]??'unsubmitted';?><section class="manage-finalize-calculation-group" data-tax-card="<?=$taxKey?>"><div class="manage-finalize-calculation-heading"><h3><?=$groupTitle?></h3><button type="button" class="manage-tax-status-button <?=$taxStatus?>" data-tax-key="<?=$taxKey?>" aria-pressed="<?=$taxStatus==='submitted'?'true':'false'?>"><span class="manage-tax-status-dot"></span><span class="manage-tax-status-label"><?=ucfirst($taxStatus)?></span></button></div><div class="manage-finalize-calculation-grid"><?php foreach($calculations as [$label,$sources,$rate,$mode]):$sourceValues=[];foreach(explode(',',$sources) as $source){$negative=str_starts_with($source,'-');$sourceName=$negative?substr($source,1):$source;$sourceAmount=(float)($existing[$sourceName]??0);$sourceValues[]=$negative?-$sourceAmount:$sourceAmount;}$sourceTotal=array_sum($sourceValues);if($mode==='multiply')$calculated=$sourceTotal*$rate;elseif($mode==='difference')$calculated=($sourceValues[0]??0)-(array_sum(array_slice($sourceValues,1))/$rate);else $calculated=$sourceTotal/$rate;?><label><?=$label?><span class="manage-money-input"><span>$</span><input readonly data-calculation-sources="<?=$sources?>" data-calculation-rate="<?=$rate?>" data-calculation-mode="<?=$mode?>" value="<?=number_format($calculated,2,'.','')?>"></span></label><?php endforeach;?></div></section><?php endforeach;?>
  </div>
  <div class="manage-finalize-bank-grid"><label>Account Name<input readonly value="<?=manage_e($property['bank_account_name']??'')?>"></label><label>Bank Account Number<input readonly value="<?=manage_e(manage_plain_bank_value($property['bank_account_number']??''))?>"></label><label>Bank Routing Number<input readonly value="<?=manage_e(manage_plain_bank_value($property['bank_routing_number']??''))?>"></label></div>
  <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=manage_e(csrf_token())?>"><input type="hidden" name="property_id" value="<?=$propertyId?>"><input type="hidden" name="report_year" value="<?=$selectedYear?>"><input type="hidden" name="report_month" value="<?=$selectedMonth?>"><?php if($isFinalized):?><p class="manage-finalized-note"><i class="fa-solid fa-lock"></i> This report is finalized. Unfinalize it before changing any numbers or files.</p><button class="manage-button manage-unfinalize-button" name="action" value="unfinalize">Unfinalize Report</button><?php else:?><label class="manage-file-upload manage-file-drop-zone" data-drop-upload><strong><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i> Additional Final Files</strong><span>Optional — drag and drop multiple files here, or click to browse.</span><span class="manage-upload-selection" aria-live="polite">No files selected</span><input type="file" name="final_files[]" multiple accept=".pdf,.xls,.xlsx,.csv,.doc,.docx,.jpg,.jpeg,.png,.webp"></label><?php if(!$existing||$existing['status']!=='submitted'):?><p class="manage-finalized-note"><i class="fa-solid fa-circle-info"></i> Submit the Month End form before finalizing this month.</p><?php endif;?><button class="manage-button manage-finalize-button" name="action" value="finalize" <?=!$existing||$existing['status']!=='submitted'?'disabled':''?>>Finalize</button><?php endif;?></form>
 </section>
 <?php endif;?>
</section></div>
<?php endif;?>
<script src="assets/month-end-autosave.js?v=<?=manage_e(znp_asset_version())?>-tax-difference16" defer></script>
<?php require_once __DIR__.'/includes/footer.php';?>
