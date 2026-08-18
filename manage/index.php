<?php
$managementDashboardPage=true;
require_once __DIR__ . '/includes/header.php';
$userName=trim((string)($managementUser['name']??$managementUser['full_name']??'Administrator'));
$isManagementAdmin=manage_is_admin();
$dashboardNow=new DateTimeImmutable('now',new DateTimeZone('America/Chicago'));
$dashboardToday=$dashboardNow->format('Y-m-d');
$dashboardYear=(int)$dashboardNow->format('Y');
$dashboardMonth=(int)$dashboardNow->format('n');
$dashboardMonths=[1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$dashboardYears=range(2026,2045);
$monthlyDashboardYears=range(2026,max(2026,$dashboardYear));
$allDashboardProperties=[];$bankDashboardProperties=[];$bankDashboardRecords=[];$monthEndDashboardRecords=[];$receiptDashboardRecords=[];$receiptManagerFileRecords=[];$franchiseDashboardRecords=[];
if($isManagementAdmin){
  $dashboardPropertyRows=manage_properties();$allDashboardProperties=array_map(static fn(array $property):array=>['id'=>(int)$property['id'],'name'=>(string)$property['property_name']],$dashboardPropertyRows);$bankDashboardProperties=array_map(static fn(array $property):array=>['id'=>(int)$property['id'],'name'=>(string)$property['property_name']],array_values(array_filter($dashboardPropertyRows,static fn(array $property):bool=>!array_key_exists('participates_bank_deposits',$property)||!empty($property['participates_bank_deposits']))));
  if($allDashboardProperties){
    $propertyIds=array_column($allDashboardProperties,'id');$placeholders=implode(',',array_fill(0,count($propertyIds),'?'));
    if($bankDashboardProperties&&manage_bank_deposits_schema_ready()){$bankPropertyIds=array_column($bankDashboardProperties,'id');$bankPlaceholders=implode(',',array_fill(0,count($bankPropertyIds),'?'));$query=db()->prepare("SELECT management_property_id,deposit_date,status FROM management_bank_deposit_records WHERE management_property_id IN ($bankPlaceholders) AND deposit_date>=? ORDER BY deposit_date,management_property_id");$query->execute(array_merge($bankPropertyIds,['2026-07-31']));foreach($query->fetchAll() as $record)$bankDashboardRecords[(string)$record['deposit_date']][(int)$record['management_property_id']]=(string)$record['status'];}
    if(manage_month_end_schema_ready()){$query=db()->prepare("SELECT management_property_id,report_year,report_month,status FROM management_month_end_submissions WHERE management_property_id IN ($placeholders) AND report_year>=2026 AND status IN ('submitted','finalized') ORDER BY report_year,report_month,management_property_id");$query->execute($propertyIds);foreach($query->fetchAll() as $record)$monthEndDashboardRecords[(int)$record['report_year'].'-'.str_pad((string)$record['report_month'],2,'0',STR_PAD_LEFT)][(int)$record['management_property_id']]=(string)$record['status'];}
    if(manage_receipts_schema_ready()){$query=db()->prepare("SELECT p.management_property_id,p.report_year,p.report_month,p.status period_status,COUNT(t.id) item_count,COALESCE(SUM(CASE WHEN t.id IS NOT NULL AND t.category_id IS NULL THEN 1 ELSE 0 END),0) incomplete_count FROM management_receipt_periods p LEFT JOIN management_receipt_transactions t ON t.management_receipt_period_id=p.id LEFT JOIN management_receipt_analysis_runs r ON r.id=t.analysis_run_id WHERE p.management_property_id IN ($placeholders) AND p.report_year>=2026 AND (t.id IS NULL OR r.is_current=1 OR t.analysis_run_id IS NULL) GROUP BY p.id,p.management_property_id,p.report_year,p.report_month,p.status ORDER BY p.report_year,p.report_month,p.management_property_id");$query->execute($propertyIds);foreach($query->fetchAll() as $record){$receiptState=(string)$record['period_status']==='finalized'?'finalized':((int)$record['item_count']===0?'not-ready':((int)$record['incomplete_count']===0?'ready':'incomplete'));$receiptDashboardRecords[(int)$record['report_year'].'-'.str_pad((string)$record['report_month'],2,'0',STR_PAD_LEFT)][(int)$record['management_property_id']]=$receiptState;}$query=db()->prepare("SELECT DISTINCT p.management_property_id,p.report_year,p.report_month FROM management_receipt_periods p JOIN management_receipt_files f ON f.management_receipt_period_id=p.id WHERE p.management_property_id IN ($placeholders) AND p.report_year>=2026 AND f.file_category='backup_invoices' AND f.receipt_group='manager' ORDER BY p.report_year,p.report_month,p.management_property_id");$query->execute($propertyIds);foreach($query->fetchAll() as $record)$receiptManagerFileRecords[(int)$record['report_year'].'-'.str_pad((string)$record['report_month'],2,'0',STR_PAD_LEFT)][]=(int)$record['management_property_id'];}
    if(manage_fees_schema_ready()){$query=db()->prepare("SELECT p.management_property_id,p.report_year,p.report_month,p.status,COUNT(f.id) uploaded_files FROM management_fee_periods p LEFT JOIN management_fee_files f ON f.management_fee_period_id=p.id WHERE p.management_property_id IN ($placeholders) AND p.fee_type='franchise' AND p.report_year>=2026 GROUP BY p.id,p.management_property_id,p.report_year,p.report_month,p.status ORDER BY p.report_year,p.report_month,p.management_property_id");$query->execute($propertyIds);foreach($query->fetchAll() as $record)if((string)$record['status']==='skipped'||(int)$record['uploaded_files']>0)$franchiseDashboardRecords[(int)$record['report_year'].'-'.str_pad((string)$record['report_month'],2,'0',STR_PAD_LEFT)][(int)$record['management_property_id']]=(string)$record['status']==='skipped'?'skipped':'completed';}
  }
}
?>
<!-- ZNP MANAGEMENT DASHBOARD REDESIGN START -->
<?php if($isManagementAdmin):?>
<section class="manage-admin-dashboard" aria-label="Management Dashboard">
  <form method="post" action="<?=manage_e(app_url('/manage/select_property.php'))?>" data-dashboard-property-nav hidden><input type="hidden" name="csrf_token" value="<?=manage_e(csrf_token())?>"><input type="hidden" name="property_id" value=""><input type="hidden" name="return_to" value=""></form>
  <div class="manage-admin-dashboard-sections" aria-label="Administration sections">
    <details class="manage-admin-dashboard-section" data-dashboard-section="bank-deposits" open>
      <summary><span class="manage-admin-dashboard-icon"><i class="fa-solid fa-building-columns" aria-hidden="true"></i></span><div><h2>Bank Deposits</h2></div><div class="manage-dashboard-header-tools" onclick="event.stopPropagation()"><div class="manage-dashboard-header-legend"><span class="manage-admin-bank-legend not-ready"><i class="fa-regular fa-circle"></i> Not Ready</span><span class="manage-admin-bank-legend submitted"><i class="fa-solid fa-clock"></i> Submitted</span><span class="manage-admin-bank-legend finalized"><i class="fa-solid fa-lock"></i> Finalized</span></div><div class="manage-bank-calendar-controls"><label><span class="znp-visually-hidden">Month</span><select aria-label="Bank Deposit month" data-admin-bank-month><?php foreach($dashboardMonths as $monthNumber=>$monthName):?><option value="<?=$monthNumber?>" <?=$monthNumber===$dashboardMonth?'selected':''?>><?=$monthName?></option><?php endforeach;?></select></label><label><span class="znp-visually-hidden">Year</span><select aria-label="Bank Deposit year" data-admin-bank-year><?php foreach($dashboardYears as $optionYear):?><option value="<?=$optionYear?>" <?=$optionYear===$dashboardYear?'selected':''?>><?=$optionYear?></option><?php endforeach;?></select></label></div></div><span class="manage-admin-section-toggle"><span class="manage-admin-section-hide">Hide</span><span class="manage-admin-section-show">Show</span><i class="fa-solid fa-chevron-up" aria-hidden="true"></i></span></summary>
      <div class="manage-admin-dashboard-section-body" data-dashboard-section-body>
       <?php if(!manage_bank_deposits_schema_ready()):?><div class="manage-alert error">Bank Deposit storage is unavailable.</div>
       <?php elseif(!$bankDashboardProperties):?><p class="manage-admin-dashboard-empty">No active properties participate in Bank Deposits.</p>
       <?php else:?>
       <div class="manage-admin-bank-calendar" data-admin-bank-calendar data-initial-year="<?=$dashboardYear?>" data-initial-month="<?=$dashboardMonth?>" data-today="<?=manage_e($dashboardToday)?>" data-start-date="2026-07-31" data-properties="<?=manage_e(json_encode($bankDashboardProperties,JSON_UNESCAPED_SLASHES))?>" data-records="<?=manage_e(json_encode($bankDashboardRecords,JSON_UNESCAPED_SLASHES))?>">
        <h3 id="adminBankCalendarTitle" class="znp-visually-hidden"><?=manage_e($dashboardMonths[$dashboardMonth].' '.$dashboardYear)?></h3>
        <div class="manage-admin-bank-calendar-scroll"><div class="manage-bank-calendar-weekdays" aria-hidden="true"><?php foreach(['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $weekday):?><span><?=$weekday?></span><?php endforeach;?></div><div class="manage-admin-bank-calendar-days" role="grid" aria-labelledby="adminBankCalendarTitle" data-admin-bank-days></div></div>
       </div>
       <?php endif;?>
      </div>
    </details>
    <details class="manage-admin-dashboard-section" data-dashboard-section="monthly-reporting" open>
      <summary><span class="manage-admin-dashboard-icon"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span><div><h2>Monthly Reporting</h2></div><div class="manage-dashboard-header-tools" onclick="event.stopPropagation()"><div class="manage-admin-combined-legend" aria-label="Monthly reporting legend"><span class="not-ready"><i class="fa-regular fa-circle"></i> Not Ready</span><span class="incomplete"><i class="fa-solid fa-triangle-exclamation"></i> Needs Work</span><span class="submitted"><i class="fa-solid fa-clock"></i> Ready</span><span class="finalized"><i class="fa-solid fa-lock"></i> Finalized</span></div><label class="manage-dashboard-header-year"><span class="znp-visually-hidden">Year</span><select aria-label="Monthly Reporting year" data-admin-combined-year><?php foreach($monthlyDashboardYears as $optionYear):?><option value="<?=$optionYear?>" <?=$optionYear===$dashboardYear?'selected':''?>><?=$optionYear?></option><?php endforeach;?></select></label></div><span class="manage-admin-section-toggle"><span class="manage-admin-section-hide">Hide</span><span class="manage-admin-section-show">Show</span><i class="fa-solid fa-chevron-up" aria-hidden="true"></i></span></summary>
      <div class="manage-admin-dashboard-section-body" data-dashboard-section-body>
       <?php $monthlySchemasReady=manage_month_end_schema_ready()&&manage_receipts_schema_ready()&&manage_fees_schema_ready();if(!$monthlySchemasReady):?><div class="manage-alert error">One or more monthly-reporting storage areas are unavailable.</div><?php elseif(!$allDashboardProperties):?><p class="manage-admin-dashboard-empty">No active properties are available.</p><?php else:?>
       <div class="manage-admin-month-calendar manage-admin-combined-calendar" data-admin-combined-calendar data-initial-year="<?=$dashboardYear?>" data-current-year="<?=$dashboardYear?>" data-current-month="<?=$dashboardMonth?>" data-properties="<?=manage_e(json_encode($allDashboardProperties,JSON_UNESCAPED_SLASHES))?>" data-month-end-records="<?=manage_e(json_encode($monthEndDashboardRecords,JSON_UNESCAPED_SLASHES))?>" data-receipt-records="<?=manage_e(json_encode($receiptDashboardRecords,JSON_UNESCAPED_SLASHES))?>" data-receipt-manager-files="<?=manage_e(json_encode($receiptManagerFileRecords,JSON_UNESCAPED_SLASHES))?>" data-franchise-records="<?=manage_e(json_encode($franchiseDashboardRecords,JSON_UNESCAPED_SLASHES))?>">
        <div class="manage-admin-month-grid manage-admin-combined-grid" data-admin-combined-grid></div>
       </div>
       <?php endif;?>
      </div>
    </details>
  </div>
</section>
<?php else:?>
<link rel="stylesheet" href="../assets/portal-dashboard.css?v=20260803-bank-participation2">

<section class="admin-portal-home management-portal-home" aria-labelledby="managementDashboardGreeting">
  <?php znp_render_dashboard_greeting($userName, 'managementDashboardGreeting'); ?>

  <div class="admin-portal-actions admin-portal-title-only <?=$managementShowsBankDeposits?'admin-portal-actions-three':'admin-portal-actions-two'?>" aria-label="Management dashboard destinations">
    <?php if($managementShowsBankDeposits):?><a class="admin-portal-button" href="bank_deposits.php">
      <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
      <span>Bank Deposits</span>
    </a><?php endif;?>
    <a class="admin-portal-button" href="month_end.php">
      <i class="fa-solid fa-calendar-check" aria-hidden="true"></i>
      <span>Month End</span>
    </a>
    <a class="admin-portal-button" href="receipts.php">
      <i class="fa-solid fa-receipt" aria-hidden="true"></i>
      <span>Receipts</span>
    </a>
  </div>
</section>

<script src="../assets/dashboard-greeting.js?v=20260730-1" defer></script>
<?php endif;?>
<!-- ZNP MANAGEMENT DASHBOARD REDESIGN END -->
<?php if($isManagementAdmin):?><script src="assets/admin-dashboard.js?v=<?=manage_e(znp_asset_version())?>-franchise-skip16" defer></script><?php endif;?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
