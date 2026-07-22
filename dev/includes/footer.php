<?php require_once __DIR__.'/../../includes/workspace_footer.php'; ?>
</main>
<?php
znp_workspace_component_styles();
znp_workspace_footer_styles();
znp_render_workspace_footer(
    'Construction Portal',
    znp_application_version_label(),
    (string)($user['name'] ?? ''),
    'dev-footer'
);
?><div id="dev-toast" class="dev-toast" hidden></div>
<script src="assets/dev-app.js?v=538" defer></script>
<?php znp_workspace_document_end(); ?>
