<?php
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../../includes/workspace_header.php';
$current=basename($_SERVER['PHP_SELF']??'index.php');
$projectId=dev_active_project_id((int)($_GET['project_id']??$_POST['project_id']??0));
$headerProjects=dev_projects();
$currentUser=admin_user();
$currentProject=$projectId?dev_project($projectId):null;
$projectPages=['project.php','progress.php','buildings.php','schedule.php','documents.php','project_team.php','daily_logs.php','photos.php','expenses.php'];
$projectsActive=in_array($current,array_merge(['projects.php'],$projectPages),true);
$insideActiveProject=$projectId>0 && in_array($current,$projectPages,true) && $currentProject && dev_can_access($projectId);
$projectTabMap=['project.php'=>'overview','progress.php'=>'progress','buildings.php'=>'progress','schedule.php'=>'progress','documents.php'=>'documents','project_team.php'=>'team','daily_logs.php'=>'logs','photos.php'=>'photos','expenses.php'=>'expenses'];
$activeProjectTab=$projectTabMap[$current]??'';
$mobileProjectId=$projectId;
$projectUrl=static function(string $page) use ($mobileProjectId): string {
    return $mobileProjectId>0 ? $page.'?project_id='.$mobileProjectId : 'projects.php';
};
?><?php znp_workspace_document_start('ZNP Construction', (string)($devBodyClass??''), 'assets/dev.css', '20260809-617'); ?>
<header class="dev-top dev-top-admin-style admin-top znp-workspace-top">
  <a class="dev-brand admin-brand znp-workspace-brand" href="index.php" aria-label="ZNP Construction Dashboard"><span>ZNP</span><strong>CONSTRUCTION</strong></a>
  <div class="dev-mobile-header-icons"><?php znp_render_workspace_icons('construction'); ?></div>
  <details class="dev-native-menu">
    <summary aria-label="Open construction menu"><span class="dev-menu-bars" aria-hidden="true"><i></i><i></i><i></i></span></summary>
    <div class="dev-native-menu-panel">
      <a class="<?=$projectsActive?'active':''?>" href="projects.php">Projects</a>
      <?php foreach($headerProjects as $hp):?><a class="dev-native-project <?=$projectId===(int)$hp['id']?'active':''?>" href="project.php?project_id=<?=(int)$hp['id']?>"><?=e($hp['project_name'])?></a><?php endforeach;?>
      <?php if(dev_is_super()):?><a class="<?=$current==='users.php'?'active':''?>" href="users.php">Users</a><a class="<?=in_array($current,['companies.php','vendor_profile.php','trade_settings.php'],true)?'active':''?>" href="companies.php">Vendors</a><a class="<?=$current==='schedule_templates.php'?'active':''?>" href="schedule_templates.php">Templates</a><?php endif;?>
    </div>
  </details>
  <nav id="devPrimaryNav" class="znp-workspace-nav" aria-label="Construction navigation">
    <div class="admin-hover-group dev-admin-dropdown">
      <button type="button" class="<?=$projectsActive?'active':''?>">Projects</button>
      <div class="admin-hover-menu" aria-label="Available construction projects">
        <a class="<?=$current==='projects.php'?'active':''?>" href="projects.php">All Projects</a>
        <?php foreach($headerProjects as $hp):?><a class="<?=$projectId===(int)$hp['id']?'active':''?>" href="project.php?project_id=<?=(int)$hp['id']?>"><?=e($hp['project_name'])?></a><?php endforeach;?>
      </div>
    </div>
    <?php if(dev_is_super()):?><a class="admin-top-link <?=$current==='users.php'?'active':''?>" href="users.php">Users</a><a class="admin-top-link <?=in_array($current,['companies.php','vendor_profile.php','trade_settings.php'],true)?'active':''?>" href="companies.php">Vendors</a><a class="admin-top-link <?=$current==='schedule_templates.php'?'active':''?>" href="schedule_templates.php">Templates</a><?php endif;?>
    <?php znp_render_workspace_icons('construction'); ?>
  </nav>
</header>
<main class="dev-main<?=!empty($devDashboardPage)?' dev-main-dashboard':''?>">
<?php if($insideActiveProject): ?>
<div class="project-context"><strong>CURRENT PROJECT: <?=e(strtoupper((string)$currentProject['project_name']))?></strong></div>
<nav class="project-nav" aria-label="Project sections">
<a class="<?=$activeProjectTab==='overview'?'active':''?>" href="project.php?project_id=<?=$projectId?>">Overview</a>
<a class="<?=$activeProjectTab==='progress'?'active':''?>" href="progress.php?project_id=<?=$projectId?>">Progress</a>
<a class="<?=$activeProjectTab==='documents'?'active':''?>" href="documents.php?project_id=<?=$projectId?>">Files & Permits</a>
<a class="<?=$activeProjectTab==='team'?'active':''?>" href="project_team.php?project_id=<?=$projectId?>">Project Team</a>
<?php if(dev_is_super()):?><a class="<?=$activeProjectTab==='expenses'?'active':''?>" href="expenses.php?project_id=<?=$projectId?>">Expenses</a><?php endif;?>
<a class="<?=$activeProjectTab==='logs'?'active':''?>" href="daily_logs.php?project_id=<?=$projectId?>">Daily Logs</a>
<a class="<?=$activeProjectTab==='photos'?'active':''?>" href="photos.php?project_id=<?=$projectId?>">Photos</a>
</nav>
<?php endif; ?>
<?php if($insideActiveProject): ?>
<nav class="mobile-project-nav" aria-label="Construction mobile navigation">
<a class="<?=$activeProjectTab==='progress'?'active':''?>" href="<?=e($projectUrl('progress.php'))?>"><span aria-hidden="true">▤</span>Progress</a>
<a class="<?=$activeProjectTab==='logs'?'active':''?>" href="<?=e($projectUrl('daily_logs.php'))?>"><span aria-hidden="true">✎</span>Daily Log</a>
<a class="<?=$activeProjectTab==='team'?'active':''?>" href="<?=e($projectUrl('project_team.php'))?>"><span aria-hidden="true">♟</span>Project Team</a>
<a class="<?=$activeProjectTab==='documents'?'active':''?>" href="<?=e($projectUrl('documents.php'))?>"><span aria-hidden="true">▣</span>Files &amp; Permits</a>
<details class="mobile-more-native <?=($activeProjectTab==='photos'||(dev_is_super()&&$activeProjectTab==='expenses'))?'mobile-more-active':''?>"><summary><span aria-hidden="true">•••</span>More</summary><div class="mobile-more-sheet">
<?php if(dev_is_super()):?><a href="<?=e($projectUrl('expenses.php'))?>">Expenses</a><?php endif;?>
<a href="<?=e($projectUrl('photos.php'))?>">Photos</a>
</div></details>
</nav>
<?php endif; ?>
