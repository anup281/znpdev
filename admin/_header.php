<?php
require_once __DIR__ . '/../includes/workspace_bootstrap.php';
$workspaceContext = znp_workspace_bootstrap('admin');
$user = $workspaceContext['user'];
$currentAdminPage = $workspaceContext['current_page'];
$investmentsOnly = investments_only_role($user);
$partnerUser = partner_role($user);
$partnerCan = static fn(string $permission): bool => !$partnerUser||partner_has_permission($permission,$user);
function admin_nav_active(array $pages): string
{
    global $currentAdminPage;
    return in_array($currentAdminPage, $pages, true) ? 'active' : '';
}
?><?php znp_workspace_document_start('ZNP Admin', 'admin-body'.($partnerUser?' admin-partner-role':''), '../assets/site.css', '20260730-605'); ?>
<header class="admin-top znp-workspace-top"><?php znp_render_workspace_brand('Admin', $investmentsOnly ? 'opportunities.php' : 'index.php', $investmentsOnly ? 'ZNP Investments' : 'ZNP Admin Dashboard', 'admin-brand'); ?><div class="admin-mobile-header-icons"><?php znp_render_workspace_icons('admin'); ?></div>
<details class="admin-native-menu">
  <summary aria-label="Open admin menu"><span class="admin-native-menu-bars" aria-hidden="true"><i></i><i></i><i></i></span></summary>
  <div class="admin-native-menu-panel">
    <?php if($investmentsOnly): ?>
    <span class="admin-native-main">Website</span>
    <a class="admin-native-sub <?=admin_nav_active(['opportunities.php','opportunity_edit.php','opportunity.php'])?>" href="opportunities.php">Investments</a>
    <?php else: ?>
    <a class="admin-native-main <?=admin_nav_active(['index.php'])?>" href="index.php">Dashboard</a>
    <span class="admin-native-main">Website</span>
    <?php if($partnerCan('projects')):?><a class="admin-native-sub <?=admin_nav_active(['projects.php','project_edit.php'])?>" href="projects.php">Projects</a><?php endif;?>
    <?php if($partnerCan('team')):?><a class="admin-native-sub <?=admin_nav_active(['team.php','team_edit.php'])?>" href="team.php">Team</a><?php endif;?>
    <?php if($partnerCan('investments')):?><a class="admin-native-sub <?=admin_nav_active(['opportunities.php','opportunity_edit.php','opportunity.php'])?>" href="opportunities.php">Investments</a><?php endif;?>
    <?php if($partnerCan('crm')):?><a class="admin-native-main <?=admin_nav_active(['contacts.php','leads.php','inquiries.php'])?>" href="contacts.php">CRM</a><?php endif;?>
    <?php if($partnerCan('subscribers')||$partnerCan('campaigns')):?><span class="admin-native-main">Marketing</span><?php endif;?>
    <?php if($partnerCan('subscribers')):?><a class="admin-native-sub <?=admin_nav_active(['newsletter_subscribers.php'])?>" href="newsletter_subscribers.php">Subscribers</a><?php endif;?>
    <?php if($partnerCan('campaigns')):?><a class="admin-native-sub <?=admin_nav_active(['newsletter_campaigns.php','newsletter_campaign_edit.php'])?>" href="newsletter_campaigns.php">Campaigns</a><?php endif;?>
    <?php if(!$partnerUser):?>
    <span class="admin-native-main">Administration</span>
    <a class="admin-native-sub <?=admin_nav_active(['users.php','user_edit.php'])?>" href="users.php">Users</a>
    <a class="admin-native-sub <?=admin_nav_active(['settings.php'])?>" href="settings.php">Settings</a>
    <a class="admin-native-sub <?=admin_nav_active(['legal_pages.php','legal_page_edit.php'])?>" href="legal_pages.php">Legal Pages</a>
    <?php if(in_array(normalized_role((string)($user['role']??'')),['super admin','super administrator'],true)):?>
    <a class="admin-native-sub <?=admin_nav_active(['database_export.php'])?>" href="database_export.php">Database Export</a>
    <a class="admin-native-sub <?=admin_nav_active(['deployment.php'])?>" href="deployment.php">Deployment</a>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</details>
<button class="admin-menu-toggle" type="button" aria-label="Open admin menu" aria-expanded="false" aria-controls="admin-nav"><span></span><span></span><span></span></button><nav id="admin-nav" class="znp-workspace-nav" aria-label="Admin navigation">
<?php if($investmentsOnly): ?>
<div class="admin-hover-group"><button type="button">Website</button><div class="admin-hover-menu"><a class="<?=admin_nav_active(['opportunities.php','opportunity_edit.php','opportunity.php'])?>" href="opportunities.php">Investments</a><a href="logout.php">Logout</a></div></div>
<?php else: ?>
<div class="admin-hover-group"><button type="button">Website</button><div class="admin-hover-menu"><?php if($partnerCan('projects')):?><a class="<?=admin_nav_active(['projects.php','project_edit.php'])?>" href="projects.php">Projects</a><?php endif;?><?php if($partnerCan('team')):?><a class="<?=admin_nav_active(['team.php','team_edit.php'])?>" href="team.php">Team</a><?php endif;?><?php if($partnerCan('investments')):?><a class="<?=admin_nav_active(['opportunities.php','opportunity_edit.php','opportunity.php'])?>" href="opportunities.php">Investments</a><?php endif;?></div></div>
<?php if($partnerCan('crm')):?><a class="admin-top-link <?=admin_nav_active(['contacts.php','leads.php','inquiries.php'])?>" href="contacts.php">CRM</a><?php endif;?>
<?php if($partnerCan('subscribers')||$partnerCan('campaigns')):?><div class="admin-hover-group"><button type="button">Marketing</button><div class="admin-hover-menu"><?php if($partnerCan('subscribers')):?><a class="<?=admin_nav_active(['newsletter_subscribers.php'])?>" href="newsletter_subscribers.php">Subscribers</a><?php endif;?><?php if($partnerCan('campaigns')):?><a class="<?=admin_nav_active(['newsletter_campaigns.php','newsletter_campaign_edit.php'])?>" href="newsletter_campaigns.php">Campaigns</a><?php endif;?></div></div><?php endif;?>
<?php if(!$partnerUser):?>
<div class="admin-hover-group"><button type="button">Administration</button><div class="admin-hover-menu"><a class="<?=admin_nav_active(['users.php','user_edit.php'])?>" href="users.php">Users</a><a class="<?=admin_nav_active(['settings.php'])?>" href="settings.php">Settings</a><a class="<?=admin_nav_active(['legal_pages.php','legal_page_edit.php'])?>" href="legal_pages.php">Legal Pages</a><?php if(in_array(normalized_role((string)($user['role']??'')),['super admin','super administrator'],true)):?><a class="<?=admin_nav_active(['database_export.php'])?>" href="database_export.php">Database Export</a><a class="<?=admin_nav_active(['deployment.php'])?>" href="deployment.php">Deployment</a><?php endif;?><a href="logout.php">Logout</a></div></div>
<?php else:?><a class="admin-top-link" href="logout.php">Logout</a><?php endif;?>
<?php znp_render_workspace_icons('admin'); ?>
<?php endif; ?>
</nav></header><main class="admin-main">
