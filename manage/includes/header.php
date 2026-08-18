<?php
require_once __DIR__ . '/bootstrap.php';
$managementProperties=manage_properties();
$managementActivePropertyId=manage_active_property_id();
$managementActiveProperty=null;foreach($managementProperties as $managementPropertyOption)if((int)$managementPropertyOption['id']===$managementActivePropertyId){$managementActiveProperty=$managementPropertyOption;break;}
$managementShowsBankDeposits=!$managementActiveProperty||!array_key_exists('participates_bank_deposits',$managementActiveProperty)||!empty($managementActiveProperty['participates_bank_deposits']);
$managementReturnParams=$_GET;unset($managementReturnParams['property_id']);
$managementReturnTo=basename((string)($_SERVER['PHP_SELF']??'index.php')).($managementReturnParams?'?'.http_build_query($managementReturnParams):'');
$managementStylesheetPath=__DIR__.'/../assets/manage.css';
$managementStylesheetModified=is_file($managementStylesheetPath)?filemtime($managementStylesheetPath):false;
$managementStylesheetVersion=znp_asset_version().'-manage'.($managementStylesheetModified!==false?'-'.$managementStylesheetModified:'');
?><?php znp_workspace_document_start('ZNP Management', 'management-body', '/manage/assets/manage.css', $managementStylesheetVersion); ?>
<header class="management-top admin-top znp-workspace-top">
  <?php znp_render_workspace_brand('Management', '/manage/', 'ZNP Management Dashboard', 'management-brand admin-brand'); ?>
  <div class="management-mobile-header-icons"><?php znp_render_workspace_icons('management'); ?></div>
  <details class="management-native-menu admin-native-menu">
    <summary aria-label="Open management menu"><span class="management-native-menu-bars admin-native-menu-bars" aria-hidden="true"><i></i><i></i><i></i></span></summary>
    <div class="management-native-menu-panel admin-native-menu-panel">
      <span class="management-native-main admin-native-main">Management</span>
      <?php if($managementShowsBankDeposits):?><a class="management-native-sub admin-native-sub <?=manage_nav_active(['bank_deposits.php'])?>" href="<?=manage_e(app_url('/manage/bank_deposits.php'))?>">Bank Deposits</a><?php endif;?>
      <a class="management-native-sub admin-native-sub <?=manage_nav_active(['month_end.php'])?>" href="<?=manage_e(app_url('/manage/month_end.php'))?>">Month End</a>
      <a class="management-native-sub admin-native-sub <?=manage_nav_active(['receipts.php'])?>" href="<?=manage_e(app_url('/manage/receipts.php'))?>">Receipts</a>
      <?php if(manage_is_admin()):?>
      <a class="management-native-sub admin-native-sub <?=manage_nav_active(['franchise_fees.php'])?>" href="<?=manage_e(app_url('/manage/franchise_fees.php'))?>">Franchise Fees</a>
      <a class="management-native-sub admin-native-sub <?=manage_nav_active(['management_fees.php'])?>" href="<?=manage_e(app_url('/manage/management_fees.php'))?>">Management Fees</a>
      <a class="management-native-sub admin-native-sub <?=manage_nav_active(['properties.php','users.php','settings.php'])?>" href="<?=manage_e(app_url('/manage/properties.php'))?>">Settings</a>
      <?php endif;?>
      <?php if(count($managementProperties)>1):?><form method="post" action="<?=manage_e(app_url('/manage/select_property.php'))?>" class="management-header-property management-header-property-mobile"><input type="hidden" name="csrf_token" value="<?=manage_e(csrf_token())?>"><input type="hidden" name="return_to" value="<?=manage_e($managementReturnTo)?>"><label for="managementPropertyMobile"><i class="fa-solid fa-hotel" aria-hidden="true"></i><span>Property</span></label><select id="managementPropertyMobile" name="property_id" onchange="this.form.submit()"><?php foreach($managementProperties as $managementPropertyOption):?><option value="<?=(int)$managementPropertyOption['id']?>" <?=$managementActivePropertyId===(int)$managementPropertyOption['id']?'selected':''?>><?=manage_e($managementPropertyOption['property_name'])?></option><?php endforeach;?></select></form><?php endif;?>
      <?php znp_render_workspace_icons('management'); ?>
    </div>
  </details>
  <button class="management-menu-toggle" type="button" aria-label="Open management menu" aria-expanded="false" aria-controls="management-nav"><span></span><span></span><span></span></button>
  <nav id="management-nav" class="znp-workspace-nav" aria-label="Management navigation">
    <?php if($managementShowsBankDeposits):?><a class="<?=manage_nav_active(['bank_deposits.php'])?>" href="<?=manage_e(app_url('/manage/bank_deposits.php'))?>">Bank Deposits</a><?php endif;?>
    <a class="<?=manage_nav_active(['month_end.php'])?>" href="<?=manage_e(app_url('/manage/month_end.php'))?>">Month End</a>
    <a class="<?=manage_nav_active(['receipts.php'])?>" href="<?=manage_e(app_url('/manage/receipts.php'))?>">Receipts</a>
    <?php if(manage_is_admin()):?>
    <a class="<?=manage_nav_active(['franchise_fees.php'])?>" href="<?=manage_e(app_url('/manage/franchise_fees.php'))?>">Franchise Fees</a>
    <a class="<?=manage_nav_active(['management_fees.php'])?>" href="<?=manage_e(app_url('/manage/management_fees.php'))?>">Management Fees</a>
    <a class="<?=manage_nav_active(['properties.php','users.php','settings.php'])?>" href="<?=manage_e(app_url('/manage/properties.php'))?>">Settings</a>
    <?php endif;?>
    <?php if(count($managementProperties)>1):?><form method="post" action="<?=manage_e(app_url('/manage/select_property.php'))?>" class="management-header-property management-header-property-desktop"><input type="hidden" name="csrf_token" value="<?=manage_e(csrf_token())?>"><input type="hidden" name="return_to" value="<?=manage_e($managementReturnTo)?>"><label for="managementProperty"><i class="fa-solid fa-hotel" aria-hidden="true"></i><span>Property</span></label><select id="managementProperty" name="property_id" onchange="this.form.submit()" aria-label="Select property"><?php foreach($managementProperties as $managementPropertyOption):?><option value="<?=(int)$managementPropertyOption['id']?>" <?=$managementActivePropertyId===(int)$managementPropertyOption['id']?'selected':''?>><?=manage_e($managementPropertyOption['property_name'])?></option><?php endforeach;?></select></form><?php endif;?>
    <?php znp_render_workspace_icons('management'); ?>
  </nav>
</header>
<main class="management-main<?=!empty($managementDashboardPage)?' management-main-dashboard':''?>">
