<?php
require __DIR__ . '/_header.php';

$userName = trim((string)($user['name'] ?? $user['full_name'] ?? 'Administrator'));
?>
<!-- ZNP ADMIN DASHBOARD REDESIGN START -->
<link rel="stylesheet" href="../assets/portal-dashboard.css?v=20260730-1">

<section class="admin-portal-home" aria-labelledby="adminDashboardGreeting">
  <?php znp_render_dashboard_greeting($userName, 'adminDashboardGreeting'); ?>

  <div class="admin-portal-actions admin-portal-actions-three admin-portal-title-only" aria-label="Admin dashboard destinations">
    <?php if(!$partnerUser):?><a class="admin-portal-button" href="../dev/">
      <i class="fa-solid fa-helmet-safety" aria-hidden="true"></i>
      <span>Construction Portal</span>
    </a><?php endif;?>
    <?php if(!$partnerUser):?><a class="admin-portal-button" href="../manage/">
      <i class="fa-solid fa-building" aria-hidden="true"></i>
      <span>Management Portal</span>
    </a><?php endif;?>
    <?php if($partnerCan('crm')):?><a class="admin-portal-button" href="contacts.php">
      <i class="fa-solid fa-address-book" aria-hidden="true"></i>
      <span>CRM</span>
    </a><?php endif;?>
  </div>
</section>

<script src="../assets/dashboard-greeting.js?v=20260730-1" defer></script>
<!-- ZNP ADMIN DASHBOARD REDESIGN END -->
<?php require __DIR__ . '/_footer.php'; ?>
