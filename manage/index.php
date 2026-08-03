<?php
$managementDashboardPage=true;
require_once __DIR__ . '/includes/header.php';
$userName=trim((string)($managementUser['name']??$managementUser['full_name']??'Administrator'));
?>
<!-- ZNP MANAGEMENT DASHBOARD REDESIGN START -->
<link rel="stylesheet" href="../assets/portal-dashboard.css?v=20260730-1">

<section class="admin-portal-home management-portal-home" aria-labelledby="managementDashboardGreeting">
  <?php znp_render_dashboard_greeting($userName, 'managementDashboardGreeting'); ?>

  <div class="admin-portal-actions admin-portal-title-only<?=manage_is_admin()?'':' admin-portal-actions-three'?>" aria-label="Management dashboard destinations">
    <a class="admin-portal-button" href="month_end.php">
      <i class="fa-solid fa-calendar-check" aria-hidden="true"></i>
      <span>Month End</span>
    </a>
    <a class="admin-portal-button" href="receipts.php">
      <i class="fa-solid fa-receipt" aria-hidden="true"></i>
      <span>Receipts</span>
    </a>
    <a class="admin-portal-button" href="bank_deposits.php">
      <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
      <span>Bank Deposits</span>
    </a>
    <?php if(manage_is_admin()):?>
    <a class="admin-portal-button" href="management_fees.php">
      <i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i>
      <span>Management Fees</span>
    </a>
    <a class="admin-portal-button" href="franchise_fees.php">
      <i class="fa-solid fa-percent" aria-hidden="true"></i>
      <span>Franchise Fees</span>
    </a>
    <?php endif;?>
  </div>
</section>

<script src="../assets/dashboard-greeting.js?v=20260730-1" defer></script>
<!-- ZNP MANAGEMENT DASHBOARD REDESIGN END -->
<?php require_once __DIR__ . '/includes/footer.php'; ?>
