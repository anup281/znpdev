<?php
$devDashboardPage=true;
require_once __DIR__.'/includes/bootstrap.php';
$user=admin_user();
$userName=trim((string)($user['name']??$user['full_name']??'Team Member'));
$projects=dev_projects(false);
require __DIR__.'/includes/header.php';
?>
<!-- ZNP CONSTRUCTION DASHBOARD REDESIGN START -->
<link rel="stylesheet" href="../assets/portal-dashboard.css?v=20260730-1">
<link rel="stylesheet" href="assets/construction-dashboard.css?v=20260730-5">

<section class="admin-portal-home construction-portal-home" aria-labelledby="constructionDashboardGreeting">
  <?php znp_render_dashboard_greeting($userName, 'constructionDashboardGreeting'); ?>

  <section class="construction-portal-projects" aria-label="Construction projects">
    <div class="admin-portal-actions construction-portal-actions admin-portal-title-only">
      <?php foreach($projects as $project): ?>
        <a class="admin-portal-button construction-portal-project-button" href="project.php?project_id=<?=(int)$project['id']?>">
          <i class="fa-solid fa-building-circle-check" aria-hidden="true"></i>
          <span><?=e($project['project_name'])?></span>
        </a>
      <?php endforeach; ?>
      <?php if(!$projects): ?>
        <div class="construction-portal-empty">
          <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
          <strong>No active projects are available.</strong>
        </div>
      <?php endif; ?>
    </div>
  </section>
</section>

<script src="../assets/dashboard-greeting.js?v=20260730-1" defer></script>
<!-- ZNP CONSTRUCTION DASHBOARD REDESIGN END -->
<?php require __DIR__.'/includes/footer.php';?>
