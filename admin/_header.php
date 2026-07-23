<?php
require_once __DIR__ . '/../includes/workspace_bootstrap.php';
$workspaceContext = znp_workspace_bootstrap('admin');
$user = $workspaceContext['user'];
$currentAdminPage = $workspaceContext['current_page'];
function admin_nav_active(array $pages): string
{
    global $currentAdminPage;
    return in_array($currentAdminPage, $pages, true) ? 'active' : '';
}
?><?php znp_workspace_document_start('ZNP Admin', 'admin-body', '../assets/site.css', '20260723-546'); ?>
<header class="admin-top znp-workspace-top"><?php znp_render_workspace_brand('Admin', 'index.php', 'ZNP Admin Dashboard', 'admin-brand'); ?><button class="admin-menu-toggle" type="button" aria-label="Open admin menu" aria-expanded="false" aria-controls="admin-nav"><span></span><span></span><span></span></button><nav id="admin-nav" aria-label="Admin navigation">
<div class="admin-hover-group"><button type="button">Website</button><div class="admin-hover-menu"><a class="<?=admin_nav_active(['projects.php','project_edit.php'])?>" href="projects.php">Projects</a><a class="<?=admin_nav_active(['team.php','team_edit.php'])?>" href="team.php">Team</a><a class="<?=admin_nav_active(['opportunities.php','opportunity_edit.php','opportunity.php'])?>" href="opportunities.php">Investments</a></div></div>
<a class="admin-top-link <?=admin_nav_active(['contacts.php','leads.php','inquiries.php'])?>" href="contacts.php">Contacts</a>
<div class="admin-hover-group"><button type="button">Marketing</button><div class="admin-hover-menu"><a class="<?=admin_nav_active(['newsletter_subscribers.php'])?>" href="newsletter_subscribers.php">Subscribers</a><a class="<?=admin_nav_active(['newsletter_campaigns.php','newsletter_campaign_edit.php'])?>" href="newsletter_campaigns.php">Campaigns</a></div></div>
<div class="admin-hover-group"><button type="button">Administration</button><div class="admin-hover-menu"><a class="<?=admin_nav_active(['users.php','user_edit.php'])?>" href="users.php">Users</a><a class="<?=admin_nav_active(['settings.php'])?>" href="settings.php">Settings</a><a class="<?=admin_nav_active(['legal_pages.php','legal_page_edit.php'])?>" href="legal_pages.php">Legal Pages</a><?php if(in_array(normalized_role((string)($user['role']??'')),['super admin','super administrator'],true)):?><a class="<?=admin_nav_active(['database_export.php'])?>" href="database_export.php">Database Export</a><a class="<?=admin_nav_active(['upgrade_daily_log_workforce.php'])?>" href="upgrade_daily_log_workforce.php">Daily Log Upgrade</a><a class="<?=admin_nav_active(['upgrade_investment_archiving.php'])?>" href="upgrade_investment_archiving.php">Investment Archive Upgrade</a><a class="<?=admin_nav_active(['upgrade_investment_model_settings.php'])?>" href="upgrade_investment_model_settings.php">Model Settings Upgrade</a><?php endif;?><a href="logout.php">Logout</a></div></div>
<?php znp_render_workspace_icons('admin'); ?>
</nav></header><main class="admin-main">
