<?php
require_once __DIR__ . '/../includes/workspace_footer.php';
?>
</main>
<?php
znp_render_workspace_footer(
    'Admin Portal',
    znp_application_version_label(),
    'Logged in as ' . (string)($user['name'] ?? 'Administrator'),
    'admin-footer'
);
?>
<div id="admin-toast-tray" class="admin-toast-tray" aria-live="polite" aria-atomic="false"></div>
<script src="../assets/admin-notes.js" defer></script>
<script src="../assets/admin-accordion.js" defer></script>
<script src="../assets/admin-live-search.js" defer></script>
<script src="../assets/admin-clock.js" defer></script>
<script src="../assets/admin-menu.js?v=20260717-2" defer></script>
<script src="../assets/admin-modal.js" defer></script>
<script src="../assets/admin-toast.js" defer></script>
<?php znp_workspace_document_end(); ?>
