<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/workspace_bootstrap.php';
require_once __DIR__ . '/../includes/investor_portal.php';
$workspaceContext = znp_workspace_bootstrap('investor');
$staffUser = $workspaceContext['staff_user'];
if ($staffUser && !admin_portal_role($staffUser)) { http_response_code(403); exit('Investor Portal access requires an administrator account.'); }
$entityType = (string)($_GET['type'] ?? '');
$entityId = (int)($_GET['id'] ?? 0);
$project = investor_portal_project($workspaceContext, $entityType, $entityId);
if (!$project) { http_response_code(403); exit('You do not have access to this project.'); }
$displayUser = is_array($staffUser) && admin_portal_role($staffUser) ? $staffUser : $workspaceContext['user'];
$name = (string)(($displayUser['name'] ?? $displayUser['full_name'] ?? 'Investor'));
$location = trim((string)($project['city']??'').((!empty($project['city'])&&!empty($project['state']))?', ':'').(string)($project['state']??''));
?><?php znp_workspace_document_start((string)$project['project_name'].' | ZNP Investors', 'investor-portal-page', '/assets/site.css', '20260722-528'); ?>
<header class="znp-investor-top znp-workspace-top"><?php znp_render_workspace_brand('Investors', '/portal/', 'ZNP Investors', 'znp-investor-brand'); ?><div class="znp-investor-actions"><?php znp_render_workspace_icons('investor'); ?></div></header>
<main class="investor-model-page"><div class="investor-model-shell"><a class="investor-project-back" href="<?=e(app_url('/portal/'))?>">← Investment Opportunities</a><section class="investor-model-header"><div><span class="investor-eyebrow">WATERFALL MODEL</span><h1><?=e($project['project_name'])?></h1><?php if($location!==''):?><p><?=e($location)?></p><?php endif;?></div><span class="investor-model-badge">Live Scenario</span></section><div class="waterfall-calculator" data-waterfall-calculator><p class="investor-model-loading">Loading investment model…</p></div></div></main>
<script src="<?=e(app_url('/portal/assets/waterfall-calculator.js'))?>?v=20260722-528"></script>
<?php znp_workspace_footer_styles();znp_render_workspace_footer('Investor Portal',znp_application_version_label(),'Signed in as '.$name,'investor-footer');znp_workspace_document_end(); ?>
