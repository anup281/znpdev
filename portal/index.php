<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/workspace_bootstrap.php';
require_once __DIR__ . '/../includes/investor_portal.php';
$workspaceContext = znp_workspace_bootstrap('investor');
$portalUser = $workspaceContext['user'];
$staffUser = $workspaceContext['staff_user'];
if ($staffUser && !admin_portal_role($staffUser)) { http_response_code(403); exit('Investor Portal access requires an administrator account.'); }
$isAdmin = is_array($staffUser) && admin_portal_role($staffUser);
if ($isAdmin) $portalUser = $staffUser;
$name = (string)($portalUser['name'] ?? $portalUser['full_name'] ?? 'Investor');
$projects = investor_portal_accessible_projects($workspaceContext);
?><?php znp_workspace_document_start('ZNP Investors', 'investor-portal-page', '/assets/site.css', '20260722-521'); ?>
<header class="znp-investor-top znp-workspace-top">
<?php znp_render_workspace_brand('Investors', '/portal/', 'ZNP Investors', 'znp-investor-brand'); ?>
<div class="znp-investor-actions"><?php znp_render_workspace_icons('investor'); ?></div>
</header>
<main class="investor-dashboard"><section class="investor-dashboard-hero"><span class="investor-eyebrow">INVESTOR PORTAL</span><h1>Welcome, <?=e($name)?></h1><p><?=$isAdmin?'Administrator access · All active investment opportunities':'Select an investment opportunity to view your secure investor information.'?></p></section><section class="investor-project-section" aria-labelledby="investor-project-heading"><h2 id="investor-project-heading">INVESTMENT OPPORTUNITIES</h2><div class="investor-project-buttons"><?php foreach($projects as $project):?><?php $location=trim((string)($project['city']??'').((!empty($project['city'])&&!empty($project['state']))?', ':'').(string)($project['state']??''));?><a class="investor-project-button" href="<?=e(app_url('/portal/project.php?type='.rawurlencode($project['entity_type']).'&id='.(int)$project['id']))?>"><strong><?=e($project['project_name'])?></strong><?php if($location!==''):?><span><?=e($location)?></span><?php endif;?></a><?php endforeach;?><?php if(!$projects):?><div class="investor-project-empty"><strong>No investment opportunities are available yet.</strong><p><?= $isAdmin?'No active investment opportunities were found.':'An investment opportunity will appear here after its NDA has been signed and accepted.' ?></p></div><?php endif;?></div></section></main>
<?php
znp_workspace_footer_styles();
znp_render_workspace_footer('Investor Portal', znp_application_version_label(), 'Signed in as ' . $name, 'investor-footer');
znp_workspace_document_end();
?>
