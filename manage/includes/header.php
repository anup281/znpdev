<?php
require_once __DIR__ . '/bootstrap.php';
?><?php znp_workspace_document_start('ZNP Management', 'management-body', '/manage/assets/manage.css', znp_asset_version()); ?>
<header class="management-top znp-workspace-top">
  <?php znp_render_workspace_brand('Management', '/manage/', 'ZNP Management Dashboard', 'management-brand'); ?>
  <button class="management-menu-toggle" type="button" aria-label="Open management menu" aria-expanded="false" aria-controls="management-nav"><span></span><span></span><span></span></button>
  <nav id="management-nav" class="znp-workspace-nav" aria-label="Management navigation">
    <a class="<?=manage_nav_active(['index.php'])?>" href="/manage/">Dashboard</a>
    <a class="<?=manage_nav_active(['month_end.php'])?>" href="/manage/month_end.php">Month End</a>
    <a class="<?=manage_nav_active(['receipts.php'])?>" href="/manage/receipts.php">Receipts</a>
    <a class="<?=manage_nav_active(['management_fees.php'])?>" href="/manage/management_fees.php">Management Fees</a>
    <a class="<?=manage_nav_active(['franchise_fees.php'])?>" href="/manage/franchise_fees.php">Franchise Fees</a>
    <a class="<?=manage_nav_active(['users.php'])?>" href="/manage/users.php">Users</a>
    <?php znp_render_workspace_icons('management'); ?>
  </nav>
</header>
<main class="management-main">
