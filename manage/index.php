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
$allDashboardProperties=[];$bankDashboardProperties=[];$bankDashboardRecords=[];$monthEndDashboardRecords=[];$receiptDashboardRecords=[];$franchiseDashboardRecords=[];
if($isManagementAdmin){
  $dashboardPropertyRows=manage_properties();$allDashboardProperties=array_map(static fn(array $property):array=>['id'=>(int)$property['id'],'name'=>(string)$property['property_name']],$dashboardPropertyRows);$bankDashboardProperties=array_map(static fn(array $property):array=>['id'=>(int)$property['id'],'name'=>(string)$property['property_name']],array_values(array_filter($dashboardPropertyRows,static fn(array $property):bool=>!array_key_exists('participates_bank_deposits',$property)||!empty($property['participates_bank_deposits']))));
  if($allDashboardProperties){
    $propertyIds=array_column($allDashboardProperties,'id');$placeholders=implode(',',array_fill(0,count($propertyIds),'?'));
    if($bankDashboardProperties&&manage_bank_deposits_schema_ready()){$bankPropertyIds=array_column($bankDashboardProperties,'id');$bankPlaceholders=implode(',',array_fill(0,count($bankPropertyIds),'?'));$query=db()->prepare("SELECT management_property_id,deposit_date FROM management_bank_deposit_records WHERE management_property_id IN ($bankPlaceholders) AND deposit_date>=? ORDER BY deposit_date,management_property_id");$query->execute(array_merge($bankPropertyIds,['2026-07-31']));foreach($query->fetchAll() as $record)$bankDashboardRecords[(string)$record['deposit_date']][]=(int)$record['management_property_id'];}
    if(manage_month_end_schema_ready()){$query=db()->prepare("SELECT management_property_id,report_year,report_month FROM management_month_end_submissions WHERE management_property_id IN ($placeholders) AND report_year>=2026 AND status IN ('submitted','finalized') ORDER BY report_year,report_month,management_property_id");$query->execute($propertyIds);foreach($query->fetchAll() as $record)$monthEndDashboardRecords[(int)$record['report_year'].'-'.str_pad((string)$record['report_month'],2,'0',STR_PAD_LEFT)][]=(int)$record['management_property_id'];}
    if(manage_receipts_schema_ready()){$query=db()->prepare("SELECT p.management_property_id,p.report_year,p.report_month,COUNT(t.id) item_count,COALESCE(SUM(CASE WHEN t.id IS NOT NULL AND (t.category_id IS NULL OR t.receipt_found=0) THEN 1 ELSE 0 END),0) incomplete_count FROM management_receipt_periods p LEFT JOIN management_receipt_transactions t ON t.management_receipt_period_id=p.id LEFT JOIN management_receipt_analysis_runs r ON r.id=t.analysis_run_id WHERE p.management_property_id IN ($placeholders) AND p.report_year>=2026 AND (t.id IS NULL OR r.is_current=1 OR t.analysis_run_id IS NULL) GROUP BY p.id,p.management_property_id,p.report_year,p.report_month ORDER BY p.report_year,p.report_month,p.management_property_id");$query->execute($propertyIds);foreach($query->fetchAll() as $record)if((int)$record['item_count']>0&&(int)$record['incomplete_count']===0)$receiptDashboardRecords[(int)$record['report_year'].'-'.str_pad((string)$record['report_month'],2,'0',STR_PAD_LEFT)][]=(int)$record['management_property_id'];}
    if(manage_fees_schema_ready()){$query=db()->prepare("SELECT management_property_id,report_year,report_month FROM management_fee_periods WHERE management_property_id IN ($placeholders) AND fee_type='franchise' AND report_year>=2026 AND status='submitted' ORDER BY report_year,report_month,management_property_id");$query->execute($propertyIds);foreach($query->fetchAll() as $record)$franchiseDashboardRecords[(int)$record['report_year'].'-'.str_pad((string)$record['report_month'],2,'0',STR_PAD_LEFT)][]=(int)$record['management_property_id'];}
  }
}
?>
<!-- ZNP MANAGEMENT DASHBOARD REDESIGN START -->
<?php if($isManagementAdmin):?>
<section class="manage-admin-dashboard" aria-labelledby="managementAdminDashboardTitle">
  <header class="manage-admin-dashboard-heading">
    <span>Administration</span>
    <h1 id="managementAdminDashboardTitle">Management Dashboard</h1>
    <p>Select a section to review and manage portal activity.</p>
  </header>

  <div class="manage-admin-dashboard-sections" aria-label="Administration sections">
    <details class="manage-admin-dashboard-section" data-dashboard-section="bank-deposits" open>
      <summary><span class="manage-admin-dashboard-icon"><i class="fa-solid fa-building-columns" aria-hidden="true"></i></span><div><h2>Bank Deposits</h2><p>Daily completion status across all accessible properties.</p></div><span class="manage-admin-section-toggle"><span class="manage-admin-section-hide">Hide</span><span class="manage-admin-section-show">Show</span><i class="fa-solid fa-chevron-up" aria-hidden="true"></i></span></summary>
      <div class="manage-admin-dashboard-section-body" data-dashboard-section-body>
       <?php if(!manage_bank_deposits_schema_ready()):?><div class="manage-alert error">Bank Deposit storage is unavailable.</div>
       <?php elseif(!$bankDashboardProperties):?><p class="manage-admin-dashboard-empty">No active properties participate in Bank Deposits.</p>
       <?php else:?>
       <div class="manage-admin-bank-calendar" data-admin-bank-calendar data-initial-year="<?=$dashboardYear?>" data-initial-month="<?=$dashboardMonth?>" data-today="<?=manage_e($dashboardToday)?>" data-start-date="2026-07-31" data-properties="<?=manage_e(json_encode($bankDashboardProperties,JSON_UNESCAPED_SLASHES))?>" data-records="<?=manage_e(json_encode($bankDashboardRecords,JSON_UNESCAPED_SLASHES))?>">
        <div class="manage-admin-bank-calendar-heading"><div><h3 id="adminBankCalendarTitle"><?=manage_e($dashboardMonths[$dashboardMonth].' '.$dashboardYear)?></h3><p><span class="manage-admin-bank-legend completed"><i class="fa-solid fa-check"></i> Completed</span><span class="manage-admin-bank-legend incomplete"><i class="fa-solid fa-xmark"></i> Incomplete</span><span class="manage-admin-bank-legend not-due"><i class="fa-regular fa-clock"></i> Not due</span></p></div><div class="manage-bank-calendar-controls"><label>Month<select data-admin-bank-month><?php foreach($dashboardMonths as $monthNumber=>$monthName):?><option value="<?=$monthNumber?>" <?=$monthNumber===$dashboardMonth?'selected':''?>><?=$monthName?></option><?php endforeach;?></select></label><label>Year<select data-admin-bank-year><?php foreach($dashboardYears as $optionYear):?><option value="<?=$optionYear?>" <?=$optionYear===$dashboardYear?'selected':''?>><?=$optionYear?></option><?php endforeach;?></select></label></div></div>
        <div class="manage-admin-bank-calendar-scroll"><div class="manage-bank-calendar-weekdays" aria-hidden="true"><?php foreach(['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $weekday):?><span><?=$weekday?></span><?php endforeach;?></div><div class="manage-admin-bank-calendar-days" role="grid" aria-labelledby="adminBankCalendarTitle" data-admin-bank-days></div></div>
       </div>
       <?php endif;?>
      </div>
    </details>
    <details class="manage-admin-dashboard-section" data-dashboard-section="month-end" open>
      <summary><span class="manage-admin-dashboard-icon"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span><div><h2>Month End</h2><p>Monthly completion status across all accessible properties.</p></div><span class="manage-admin-section-toggle"><span class="manage-admin-section-hide">Hide</span><span class="manage-admin-section-show">Show</span><i class="fa-solid fa-chevron-up" aria-hidden="true"></i></span></summary>
      <div class="manage-admin-dashboard-section-body" data-dashboard-section-body>
       <?php if(!manage_month_end_schema_ready()):?><div class="manage-alert error">Month End storage is unavailable.</div><?php elseif(!$allDashboardProperties):?><p class="manage-admin-dashboard-empty">No active properties are available.</p><?php else:?>
       <div class="manage-admin-month-calendar" data-admin-month-calendar data-calendar-title="Month End" data-initial-year="<?=$dashboardYear?>" data-current-year="<?=$dashboardYear?>" data-current-month="<?=$dashboardMonth?>" data-start-year="2026" data-start-month="6" data-properties="<?=manage_e(json_encode($allDashboardProperties,JSON_UNESCAPED_SLASHES))?>" data-records="<?=manage_e(json_encode($monthEndDashboardRecords,JSON_UNESCAPED_SLASHES))?>"><div class="manage-admin-month-calendar-heading"><div><h3 data-admin-month-title><?=$dashboardYear?> Month End</h3><p>Submitted and finalized reports count as completed.</p></div><label>Year<select data-admin-month-year><?php foreach($dashboardYears as $optionYear):?><option value="<?=$optionYear?>" <?=$optionYear===$dashboardYear?'selected':''?>><?=$optionYear?></option><?php endforeach;?></select></label></div><div class="manage-admin-month-grid" data-admin-month-grid></div></div>
       <?php endif;?>
      </div>
    </details>
    <details class="manage-admin-dashboard-section" data-dashboard-section="receipts" open>
      <summary><span class="manage-admin-dashboard-icon"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span><div><h2>Receipts</h2><p>Monthly completion status across all accessible properties.</p></div><span class="manage-admin-section-toggle"><span class="manage-admin-section-hide">Hide</span><span class="manage-admin-section-show">Show</span><i class="fa-solid fa-chevron-up" aria-hidden="true"></i></span></summary>
      <div class="manage-admin-dashboard-section-body" data-dashboard-section-body>
       <?php if(!manage_receipts_schema_ready()):?><div class="manage-alert error">Receipts storage is unavailable.</div><?php elseif(!$allDashboardProperties):?><p class="manage-admin-dashboard-empty">No active properties are available.</p><?php else:?>
       <div class="manage-admin-month-calendar" data-admin-month-calendar data-calendar-title="Receipts" data-initial-year="<?=$dashboardYear?>" data-current-year="<?=$dashboardYear?>" data-current-month="<?=$dashboardMonth?>" data-start-year="2026" data-start-month="7" data-properties="<?=manage_e(json_encode($allDashboardProperties,JSON_UNESCAPED_SLASHES))?>" data-records="<?=manage_e(json_encode($receiptDashboardRecords,JSON_UNESCAPED_SLASHES))?>"><div class="manage-admin-month-calendar-heading"><div><h3 data-admin-month-title><?=$dashboardYear?> Receipts</h3><p>Analyzed months with no uncategorized items count as completed.</p></div><label>Year<select data-admin-month-year><?php foreach($dashboardYears as $optionYear):?><option value="<?=$optionYear?>" <?=$optionYear===$dashboardYear?'selected':''?>><?=$optionYear?></option><?php endforeach;?></select></label></div><div class="manage-admin-month-grid" data-admin-month-grid></div></div>
       <?php endif;?>
      </div>
    </details>
    <details class="manage-admin-dashboard-section" data-dashboard-section="franchise-fees" open>
      <summary><span class="manage-admin-dashboard-icon"><i class="fa-solid fa-percent" aria-hidden="true"></i></span><div><h2>Franchise Fees</h2><p>Monthly completion status across all accessible properties.</p></div><span class="manage-admin-section-toggle"><span class="manage-admin-section-hide">Hide</span><span class="manage-admin-section-show">Show</span><i class="fa-solid fa-chevron-up" aria-hidden="true"></i></span></summary>
      <div class="manage-admin-dashboard-section-body" data-dashboard-section-body>
       <?php if(!manage_fees_schema_ready()):?><div class="manage-alert error">Franchise Fee storage is unavailable.</div><?php elseif(!$allDashboardProperties):?><p class="manage-admin-dashboard-empty">No active properties are available.</p><?php else:?>
       <div class="manage-admin-month-calendar" data-admin-month-calendar data-calendar-title="Franchise Fees" data-initial-year="<?=$dashboardYear?>" data-current-year="<?=$dashboardYear?>" data-current-month="<?=$dashboardMonth?>" data-start-year="2026" data-start-month="7" data-properties="<?=manage_e(json_encode($allDashboardProperties,JSON_UNESCAPED_SLASHES))?>" data-records="<?=manage_e(json_encode($franchiseDashboardRecords,JSON_UNESCAPED_SLASHES))?>"><div class="manage-admin-month-calendar-heading"><div><h3 data-admin-month-title><?=$dashboardYear?> Franchise Fees</h3><p>Submitted franchise-fee periods count as completed.</p></div><label>Year<select data-admin-month-year><?php foreach($dashboardYears as $optionYear):?><option value="<?=$optionYear?>" <?=$optionYear===$dashboardYear?'selected':''?>><?=$optionYear?></option><?php endforeach;?></select></label></div><div class="manage-admin-month-grid" data-admin-month-grid></div></div>
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
<?php if($isManagementAdmin):?><script src="assets/admin-dashboard.js?v=<?=manage_e(znp_asset_version())?>-annual-calendars2" defer></script><?php endif;?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
