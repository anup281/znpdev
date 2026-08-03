<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/storage.php';
require_admin();
foreach(['smtp_password','mega_s4_access_key','mega_s4_secret_key'] as $secretSetting){
 try{migrate_secret_setting($secretSetting);}catch(Throwable $migrationError){error_log('Secret setting migration failed for '.$secretSetting.': '.$migrationError->getMessage());}
}
$message='';$error='';$activeTab=$_GET['tab']??'general';
$allowedTabs=['general','pages','seo','email','storage','newsletter','branding','security','system'];if(!in_array($activeTab,$allowedTabs,true))$activeTab='general';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!csrf_check($_POST['csrf_token']??'')){$error='Your session expired. Please try again.';}
 else{
  $action=$_POST['action']??'save';$activeTab=$_POST['tab']??$activeTab;
  if($action==='test_email'){
   $recipient=trim((string)($_POST['test_email']??''));
   if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))$error='Enter a valid test email address.';
   else{$result=app_send_mail_detailed($recipient,'ZNP Development SMTP Test','<p>Your ZNP Development SMTP settings are working.</p><p>Sent '.e(date('F j, Y g:i A T')).'.</p>');if($result['ok'])$message='Test email accepted by the SMTP server.';else$error='Test email failed: '.$result['error'];}
  }elseif($action==='test_storage'){
   $activeTab='storage';
   $result=znp_mega_s4_test_connection();
   if($result['ok'])$message=$result['message'];else$error=$result['message'];
  }else{
   $messageInfoEmail=trim((string)($_POST['message_info_email_address']??''));
   if(array_key_exists('message_info_email_address',$_POST)&&$messageInfoEmail!==''&&!filter_var($messageInfoEmail,FILTER_VALIDATE_EMAIL)){
    $error='Enter a valid Message Info Email address.';
   }
   if($activeTab==='storage'){
    $storageDriver=(string)($_POST['storage_driver']??'local');
    $s4Endpoint=trim((string)($_POST['mega_s4_endpoint']??''));
    $s4Region=trim((string)($_POST['mega_s4_region']??''));
    $s4Bucket=trim((string)($_POST['mega_s4_bucket']??''));
    $hasAccessKey=trim((string)($_POST['mega_s4_access_key']??''))!==''||decrypt_setting(setting('mega_s4_access_key'))!=='';
    $hasSecretKey=trim((string)($_POST['mega_s4_secret_key']??''))!==''||decrypt_setting(setting('mega_s4_secret_key'))!=='';
    if(!in_array($storageDriver,['local','mega_s4'],true))$error='Choose a valid storage driver.';
    elseif($storageDriver==='mega_s4'&&(!filter_var($s4Endpoint,FILTER_VALIDATE_URL)||strtolower((string)parse_url($s4Endpoint,PHP_URL_SCHEME))!=='https'))$error='Enter a valid HTTPS MEGA S4 endpoint.';
    elseif($storageDriver==='mega_s4'&&!preg_match('/^[a-z0-9-]+$/',$s4Region))$error='Enter a valid MEGA S4 region.';
    elseif($storageDriver==='mega_s4'&&!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/',$s4Bucket))$error='Enter a valid MEGA S4 bucket name.';
    elseif($storageDriver==='mega_s4'&&(!$hasAccessKey||!$hasSecretKey))$error='Enter both MEGA S4 access and secret keys.';
   }
   $fields=[
    'company_name','website_url','admin_url','app_timezone','application_version',
    'page_projects_title','page_projects_subtitle','page_team_title','page_team_subtitle','page_invest_title','page_invest_subtitle','page_contact_title','page_contact_subtitle',
    'seo_default_title','seo_default_description','seo_social_title','seo_social_description','seo_social_image','google_analytics_id','google_tag_manager_id',
    'seo_home_title','seo_home_description','seo_home_og_title','seo_home_og_description','seo_projects_title','seo_projects_description','seo_projects_og_title','seo_projects_og_description','seo_team_title','seo_team_description','seo_team_og_title','seo_team_og_description','seo_invest_title','seo_invest_description','seo_invest_og_title','seo_invest_og_description','seo_contact_title','seo_contact_description','seo_contact_og_title','seo_contact_og_description',
    'smtp_host','smtp_port','smtp_encryption','smtp_username','mail_from_name','mail_from_email','mail_reply_to','message_info_email_address','storage_driver','mega_s4_endpoint','mega_s4_region','mega_s4_bucket','newsletter_enabled','newsletter_sender_name','newsletter_reply_to','newsletter_physical_address','newsletter_footer_text',
    'primary_color','secondary_color','session_timeout','max_login_attempts','password_reset_enabled'
   ];
   if($error===''){
    foreach($fields as $key){if(array_key_exists($key,$_POST))setting_save($key,trim((string)$_POST[$key]));}
    if(isset($_POST['smtp_password'])&&trim((string)$_POST['smtp_password'])!=='')setting_save('smtp_password',encrypt_setting(trim((string)$_POST['smtp_password'])),'secret');
    if(isset($_POST['mega_s4_access_key'])&&trim((string)$_POST['mega_s4_access_key'])!=='')setting_save('mega_s4_access_key',encrypt_setting(trim((string)$_POST['mega_s4_access_key'])),'secret');
    if(isset($_POST['mega_s4_secret_key'])&&trim((string)$_POST['mega_s4_secret_key'])!=='')setting_save('mega_s4_secret_key',encrypt_setting(trim((string)$_POST['mega_s4_secret_key'])),'secret');
    $message='Settings saved successfully.';
   }
  }
 }
}
require __DIR__.'/_header.php';
$diagnostics=znp_system_diagnostics();
?>
<div class="admin-page-head"><h1>Settings</h1></div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<div class="settings-layout">
 <nav class="settings-tabs" aria-label="Settings sections">
  <?php foreach(['general'=>'General','pages'=>'Page Titles','seo'=>'SEO','email'=>'Email','storage'=>'Storage','newsletter'=>'Newsletter','branding'=>'Branding','security'=>'Security','system'=>'System'] as $key=>$label):?>
   <a class="<?=$activeTab===$key?'active':''?>" href="settings.php?tab=<?=$key?>"><?=e($label)?></a>
  <?php endforeach;?>
 </nav>
 <section class="settings-content">
 <?php if($activeTab==='general'):?>
  <form method="post" class="settings-card admin-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="general">
   <h2>General</h2><div class="settings-grid">
    <label>Company Name<input name="company_name" value="<?=e(setting('company_name','ZNP Development'))?>"></label>
    <label>Application Version<input name="application_version" pattern="[0-9]+(?:\.[0-9A-Za-z_-]+)*" value="<?=e(setting('application_version','5.1.31'))?>" required><small class="admin-help-text">Used globally in every platform footer.</small></label>
    <label>Time Zone<select name="app_timezone"><option value="America/Chicago" selected>America/Chicago (Central Time)</option></select></label>
    <label>Public Website URL<input type="url" name="website_url" value="<?=e(setting('website_url',defined('BASE_URL')?BASE_URL:''))?>"></label>
    <label>Admin Portal URL<input type="url" name="admin_url" value="<?=e(setting('admin_url',(defined('BASE_URL')?BASE_URL:'').'/admin'))?>"></label>
   </div><button class="primary">Save General Settings</button>
  </form>
 <?php elseif($activeTab==='pages'):?>
  <form method="post" class="settings-card admin-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="pages">
   <h2>Public Page Titles</h2><p class="admin-help-text">Edit the title and supporting subtitle shown at the top of each public page.</p>
   <div class="public-page-settings">
    <?php foreach([
      'projects'=>['Projects','Developing Projects That Create Long-Term Value'],
      'team'=>['Team','Leadership Built on Complementary Expertise'],
      'invest'=>['Invest','Building Long-Term Partnerships'],
      'contact'=>['Contact','Let’s Build Something Together.'],
    ] as $pageKey=>$defaults):?>
     <fieldset class="page-title-settings-group">
      <legend><?=e($defaults[0])?> Page</legend>
      <div class="settings-grid">
       <label>Page Name<input maxlength="80" name="page_<?=e($pageKey)?>_title" value="<?=e(setting('page_'.$pageKey.'_title',$defaults[0]))?>"></label>
       <label>Subtitle<input maxlength="160" name="page_<?=e($pageKey)?>_subtitle" value="<?=e(setting('page_'.$pageKey.'_subtitle',$defaults[1]))?>"></label>
      </div>
     </fieldset>
    <?php endforeach;?>
   </div>
   <button class="primary">Save Page Titles</button>
  </form>

 <?php elseif($activeTab==='seo'):?>
  <form method="post" class="settings-card admin-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="seo">
   <h2>Search Engine Optimization</h2><p class="admin-help-text">Control search-result titles, descriptions, social sharing, and analytics without editing code.</p>
   <div class="settings-grid"><label>Default Website Title<input maxlength="70" name="seo_default_title" value="<?=e(setting('seo_default_title','ZNP Development | Texas Real Estate Development & Investment'))?>"></label><label>Social Share Image<input name="seo_social_image" placeholder="assets/images/social-share.jpg or https://..." value="<?=e(setting('seo_social_image','assets/images/home-slide-1.jpeg'))?>"></label><label>Google Analytics ID<input name="google_analytics_id" placeholder="G-XXXXXXXXXX" value="<?=e(setting('google_analytics_id'))?>"></label><label>Google Tag Manager ID<input name="google_tag_manager_id" placeholder="GTM-XXXXXXX" value="<?=e(setting('google_tag_manager_id'))?>"></label></div>
   <label>Default Meta Description<textarea maxlength="320" name="seo_default_description"><?=e(setting('seo_default_description','ZNP Development is a Texas-based real estate development and investment company specializing in multifamily, townhome, hospitality, commercial, and residential projects.'))?></textarea></label>
   <div class="public-page-settings"><?php foreach(seo_defaults() as $pageKey=>$defaults):?><fieldset class="page-title-settings-group"><legend><?=e(ucfirst($pageKey))?> SEO</legend><div class="settings-grid"><label>Search Page Title<input maxlength="70" name="seo_<?=e($pageKey)?>_title" value="<?=e(setting('seo_'.$pageKey.'_title',$defaults[0]))?>"></label><label>Open Graph Title<input maxlength="95" name="seo_<?=e($pageKey)?>_og_title" value="<?=e(setting('seo_'.$pageKey.'_og_title',$defaults[2]))?>"></label></div><label>Meta Description<textarea maxlength="320" name="seo_<?=e($pageKey)?>_description"><?=e(setting('seo_'.$pageKey.'_description',$defaults[1]))?></textarea></label><label>Open Graph Description<textarea maxlength="320" name="seo_<?=e($pageKey)?>_og_description"><?=e(setting('seo_'.$pageKey.'_og_description',$defaults[3]))?></textarea></label></fieldset><?php endforeach;?></div>
   <button class="primary">Save SEO Settings</button>
  </form>
 <?php elseif($activeTab==='email'):?>
  <form method="post" class="settings-card admin-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="email">
   <h2>Email</h2><p class="admin-help-text">Use authenticated SMTP for NDA and password-reset delivery.</p><div class="settings-grid">
    <label>SMTP Server<input name="smtp_host" placeholder="smtp.office365.com" value="<?=e(setting('smtp_host'))?>"></label>
    <label>Port<input type="number" min="1" max="65535" name="smtp_port" value="<?=e(setting('smtp_port','587'))?>"></label>
    <label>Encryption<select name="smtp_encryption"><option value="tls" <?=setting('smtp_encryption','tls')==='tls'?'selected':''?>>TLS</option><option value="ssl" <?=setting('smtp_encryption')==='ssl'?'selected':''?>>SSL</option><option value="none" <?=setting('smtp_encryption')==='none'?'selected':''?>>None</option></select></label>
    <label>SMTP Username<input name="smtp_username" autocomplete="username" value="<?=e(setting('smtp_username'))?>"></label>
    <label>SMTP Password<input type="password" name="smtp_password" autocomplete="new-password" placeholder="Leave blank to keep current password"></label>
    <label>Sender Name<input name="mail_from_name" value="<?=e(setting('mail_from_name','ZNP Development'))?>"></label>
    <label>Sender Email<input type="email" name="mail_from_email" value="<?=e(setting('mail_from_email'))?>"></label>
    <label>Reply-To Email<input type="email" name="mail_reply_to" value="<?=e(setting('mail_reply_to',setting('mail_from_email')))?>"></label>
    <label>Message Info Email address<input type="email" name="message_info_email_address" value="<?=e(setting('message_info_email_address'))?>" placeholder="admin@example.com"><small class="admin-help-text">New public contact inquiries will be sent to this address. Leave blank to disable notifications.</small></label>
   </div><button class="primary">Save Email Settings</button>
  </form>
  <form method="post" class="settings-card admin-form settings-test-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="email"><input type="hidden" name="action" value="test_email"><h2>Send Test Email</h2><label>Recipient Email<input type="email" required name="test_email" value="<?=e((string)($user['email']??''))?>"></label><button class="secondary">Send Test Email</button></form>
 <?php elseif($activeTab==='storage'):?>
  <?php $s4AccessKeySaved=decrypt_setting(setting('mega_s4_access_key'))!=='';$s4SecretKeySaved=decrypt_setting(setting('mega_s4_secret_key'))!=='';?>
  <form method="post" class="settings-card admin-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="storage">
   <h2>Upload Storage</h2><p class="admin-help-text">Configure MEGA S4 credentials for S3-compatible upload storage. Saved keys are encrypted and are never displayed again.</p>
   <div class="settings-grid">
    <label>Storage Driver<select name="storage_driver"><option value="local" <?=setting('storage_driver','local')==='local'?'selected':''?>>Local Server</option><option value="mega_s4" <?=setting('storage_driver')==='mega_s4'?'selected':''?>>MEGA S4</option></select></label>
    <label>MEGA S4 Endpoint<input type="url" name="mega_s4_endpoint" placeholder="https://s3.eu-central-1.s4.mega.io" value="<?=e(setting('mega_s4_endpoint'))?>"><small class="admin-help-text">Use the exact endpoint shown in MEGA Object Storage settings.</small></label>
    <label>Region<input name="mega_s4_region" placeholder="eu-central-1" pattern="[a-z0-9-]+" value="<?=e(setting('mega_s4_region'))?>"></label>
    <label>Bucket Name<input name="mega_s4_bucket" value="<?=e(setting('mega_s4_bucket'))?>"></label>
    <label>Access Key<input type="password" name="mega_s4_access_key" autocomplete="new-password" placeholder="<?=$s4AccessKeySaved?'Saved — leave blank to keep current key':'Enter access key'?>"></label>
    <label>Secret Key<input type="password" name="mega_s4_secret_key" autocomplete="new-password" placeholder="<?=$s4SecretKeySaved?'Saved — leave blank to keep current key':'Enter secret key'?>"></label>
   </div>
   <p class="admin-help-text">Leaving a key field blank preserves the currently saved key. Choose Local Server before changing or removing an active S4 configuration.</p>
   <button class="primary">Save Storage Settings</button>
  </form>
  <form method="post" class="settings-card admin-form settings-test-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="storage"><input type="hidden" name="action" value="test_storage"><h2>Test MEGA S4 Connection</h2><p class="admin-help-text">Performs a read-only bucket access check using the saved endpoint and credentials.</p><button class="secondary">Test Connection</button></form>
 <?php elseif($activeTab==='newsletter'):?>
  <form method="post" class="settings-card admin-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="newsletter"><h2>Newsletter</h2><p class="admin-help-text">Configure public signup language, sender information, and the compliance address included in every campaign.</p><div class="settings-grid"><label>Newsletter Status<select name="newsletter_enabled"><option value="1" <?=setting('newsletter_enabled','1')==='1'?'selected':''?>>Enabled</option><option value="0" <?=setting('newsletter_enabled')==='0'?'selected':''?>>Disabled</option></select></label><label>Sender Name<input name="newsletter_sender_name" value="<?=e(setting('newsletter_sender_name','ZNP Development'))?>"></label><label>Reply-To Email<input type="email" name="newsletter_reply_to" value="<?=e(setting('newsletter_reply_to',setting('mail_reply_to')))?>"></label><label>Physical Mailing Address<input name="newsletter_physical_address" value="<?=e(setting('newsletter_physical_address','Dallas, Texas'))?>"></label></div><label>Footer Signup Text<textarea name="newsletter_footer_text"><?=e(setting('newsletter_footer_text','Receive project updates, company news, and investment opportunity announcements.'))?></textarea></label><button class="primary">Save Newsletter Settings</button></form>
 <?php elseif($activeTab==='branding'):?>
  <form method="post" class="settings-card admin-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="branding"><h2>Branding</h2><div class="settings-grid"><label>Primary Color<input name="primary_color" value="<?=e(setting('primary_color','#0b1f3a'))?>"></label><label>Secondary Color<input name="secondary_color" value="<?=e(setting('secondary_color','#c9a96e'))?>"></label></div><p class="admin-help-text">Existing public-site logo, favicon, and design remain unchanged.</p><button class="primary">Save Branding Settings</button></form>
 <?php elseif($activeTab==='security'):?>
  <form method="post" class="settings-card admin-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="security"><h2>Security</h2><div class="settings-grid"><label>Password Reset<select name="password_reset_enabled"><option value="1" <?=setting('password_reset_enabled','1')==='1'?'selected':''?>>Enabled</option><option value="0" <?=setting('password_reset_enabled')==='0'?'selected':''?>>Disabled</option></select></label><label>Session Timeout (minutes)<input type="number" min="15" max="1440" name="session_timeout" value="<?=e(setting('session_timeout','120'))?>"></label><label>Maximum Login Attempts<input type="number" min="3" max="20" name="max_login_attempts" value="<?=e(setting('max_login_attempts','5'))?>"></label></div><button class="primary">Save Security Settings</button></form>
 <?php else:?>
  <div class="settings-card"><h2>System Information</h2><dl class="system-info"><div><dt>Website Version</dt><dd><?=e(znp_application_version_label())?></dd></div><div><dt>PHP Version</dt><dd><?=e(PHP_VERSION)?></dd></div><div><dt>Time Zone</dt><dd><?=e(setting('app_timezone','America/Chicago'))?></dd></div><div><dt>Server Time</dt><dd><?=e(date('F j, Y g:i A T'))?></dd></div></dl></div>
  <div class="settings-card"><h2>System Health</h2><div class="diagnostic-list"><?php foreach($diagnostics as $item):?><div class="diagnostic-row"><span class="diagnostic-dot <?=$item['ok']?'ok':'bad'?>"></span><strong><?=e($item['label'])?></strong><span><?=e($item['detail'])?></span></div><?php endforeach;?></div></div>
 <?php endif;?>
 </section>
</div>
<?php require __DIR__.'/_footer.php';?>
