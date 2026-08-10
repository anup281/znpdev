<?php require_once __DIR__.'/../../includes/workspace_footer.php'; ?>
</main>
<?php
znp_render_workspace_footer(
    'Construction Portal',
    znp_application_version_label(),
    'Logged in as ' . (string)($user['name'] ?? 'Team Member'),
    'dev-footer'
);
?><div id="dev-toast" class="dev-toast" hidden></div>
<script src="assets/dev-app.js?v=543" defer></script>
<script src="../assets/admin-menu.js?v=20260730-3" defer></script>
<?php znp_workspace_document_end(); ?>
