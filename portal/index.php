<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/workspace_bootstrap.php';
$workspaceContext = znp_workspace_bootstrap('investor');
$portalUser = $workspaceContext['user'];
$name = (string)($portalUser['name'] ?? $portalUser['full_name'] ?? 'Investor');
?><?php znp_workspace_document_start('ZNP Investors', 'investor-portal-page', '/assets/site.css', '20260718-431'); ?>
<header class="znp-investor-top znp-workspace-top">
<?php znp_render_workspace_brand('Investors', '/portal/', 'ZNP Investors', 'znp-investor-brand'); ?>
<div class="znp-investor-actions"><?php znp_render_workspace_icons('investor'); ?></div>
</header>
<main class="investor-placeholder"><section class="investor-placeholder-card"><span class="investor-eyebrow">INVESTOR PORTAL</span><h1>Welcome, <?=e($name)?></h1><p>Your secure Investor Portal is ready. We will build out investments, documents, updates, and distributions next.</p></section></main>
<?php
znp_workspace_footer_styles();
znp_render_workspace_footer('Investor Portal', znp_application_version_label(), 'Signed in as ' . $name, 'investor-footer');
znp_workspace_document_end();
?>
