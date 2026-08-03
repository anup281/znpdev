<?php
$managementSettingsPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<nav class="manage-settings-tabs" aria-label="Management settings sections">
  <a class="<?=$managementSettingsPage==='properties.php'?'active':''?>" href="<?=manage_e(app_url('/manage/properties.php'))?>"><i class="fa-solid fa-hotel" aria-hidden="true"></i> Properties</a>
  <a class="<?=$managementSettingsPage==='users.php'?'active':''?>" href="<?=manage_e(app_url('/manage/users.php'))?>"><i class="fa-solid fa-users" aria-hidden="true"></i> Users</a>
  <a class="<?=$managementSettingsPage==='settings.php'?'active':''?>" href="<?=manage_e(app_url('/manage/settings.php'))?>"><i class="fa-solid fa-gear" aria-hidden="true"></i> Global Settings</a>
</nav>
