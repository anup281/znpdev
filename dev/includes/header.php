<?php
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../../includes/workspace_header.php';
$current=basename($_SERVER['PHP_SELF']??'index.php');
$projectId=dev_active_project_id((int)($_GET['project_id']??$_POST['project_id']??0));
$headerProjects=dev_projects();
$currentUser=admin_user();
$currentProject=$projectId?dev_project($projectId):null;
$projectPages=['project.php','progress.php','buildings.php','schedule.php','documents.php','project_team.php','daily_logs.php','photo_recovery.php','photos.php','expenses.php'];
$projectsActive=in_array($current,array_merge(['projects.php'],$projectPages),true);
$insideActiveProject=$projectId>0 && in_array($current,$projectPages,true) && $currentProject && dev_can_access($projectId);
$projectTabMap=['project.php'=>'overview','progress.php'=>'progress','buildings.php'=>'progress','schedule.php'=>'progress','documents.php'=>'documents','project_team.php'=>'team','daily_logs.php'=>'logs','photo_recovery.php'=>'logs','photos.php'=>'photos','expenses.php'=>'expenses'];
$activeProjectTab=$projectTabMap[$current]??'';
$mobileProjectId=$projectId;
$projectUrl=static function(string $page) use ($mobileProjectId): string {
    return $mobileProjectId>0 ? $page.'?project_id='.$mobileProjectId : 'projects.php';
};
?><?php znp_workspace_document_start('ZNP Construction', '', 'assets/dev.css', '20260721-536'); ?>
<header class="dev-top dev-top-admin-style znp-workspace-top">
  <a class="dev-brand znp-workspace-brand" href="index.php" aria-label="ZNP Construction Dashboard"><span>ZNP</span><strong>CONSTRUCTION</strong></a>
  <details class="dev-native-menu">
    <summary aria-label="Open construction menu"><span class="dev-menu-bars" aria-hidden="true"><i></i><i></i><i></i></span><strong>MENU</strong></summary>
    <div class="dev-native-menu-panel">
      <a class="<?=$current==='index.php'?'active':''?>" href="index.php">Dashboard</a>
      <a class="<?=$projectsActive?'active':''?>" href="projects.php">Projects</a>
      <?php foreach($headerProjects as $hp):?><a class="dev-native-project <?=$projectId===(int)$hp['id']?'active':''?>" href="project.php?project_id=<?=(int)$hp['id']?>"><?=e($hp['project_name'])?></a><?php endforeach;?>
      <?php if(dev_is_super()):?><a class="<?=$current==='users.php'?'active':''?>" href="users.php">Users</a><a class="<?=in_array($current,['companies.php','vendor_profile.php','trade_settings.php'],true)?'active':''?>" href="companies.php">Vendors</a><a class="<?=$current==='schedule_templates.php'?'active':''?>" href="schedule_templates.php">Templates</a><?php endif;?>
      <?php if(dev_is_super()):?><a href="../admin/index.php">Admin Portal</a><?php endif;?><a href="../index.php">Public Website</a><a href="../logout.php">Sign Out</a>
    </div>
  </details>
  <nav id="devPrimaryNav" aria-label="Construction navigation">
    <a class="<?=$current==='index.php'?'active':''?>" href="index.php">Dashboard</a>
    <div class="dev-project-group<?=$projectsActive?' is-active':''?>">
      <a class="dev-project-trigger<?=$projectsActive?' active':''?>" href="projects.php" aria-haspopup="true" aria-expanded="false">Projects <span aria-hidden="true">▾</span></a>
      <?php if($headerProjects):?><div class="dev-project-menu" aria-label="Available construction projects"><?php foreach($headerProjects as $hp):?><a class="<?=$projectId===(int)$hp['id']?'active':''?>" href="project.php?project_id=<?=(int)$hp['id']?>"><?=e($hp['project_name'])?></a><?php endforeach;?></div><?php endif;?>
    </div>
    <?php if(dev_is_super()):?><a class="<?=$current==='users.php'?'active':''?>" href="users.php">Users</a><a class="<?=in_array($current,['companies.php','vendor_profile.php','trade_settings.php'],true)?'active':''?>" href="companies.php">Vendors</a><a class="<?=$current==='schedule_templates.php'?'active':''?>" href="schedule_templates.php">Templates</a><?php endif;?>
<?php znp_render_workspace_icons('construction'); ?>
  </nav>
</header>
<main class="dev-main">
<?php if($insideActiveProject): ?>
<div class="project-context"><strong>CURRENT PROJECT: <?=e(strtoupper((string)$currentProject['project_name']))?></strong></div>
<nav class="project-nav" aria-label="Project sections">
<a class="<?=$activeProjectTab==='overview'?'active':''?>" href="project.php?project_id=<?=$projectId?>">Overview</a>
<a class="<?=$activeProjectTab==='progress'?'active':''?>" href="progress.php?project_id=<?=$projectId?>">Progress</a>
<a class="<?=$activeProjectTab==='documents'?'active':''?>" href="documents.php?project_id=<?=$projectId?>">Plans & Documents</a>
<a class="<?=$activeProjectTab==='team'?'active':''?>" href="project_team.php?project_id=<?=$projectId?>">Project Team</a>
<a class="<?=$activeProjectTab==='expenses'?'active':''?>" href="expenses.php?project_id=<?=$projectId?>">Expenses</a>
<a class="<?=$activeProjectTab==='logs'?'active':''?>" href="daily_logs.php?project_id=<?=$projectId?>">Daily Logs</a>
<a class="<?=$activeProjectTab==='photos'?'active':''?>" href="photos.php?project_id=<?=$projectId?>">Photos</a>
</nav>
<?php endif; ?>
<?php if($insideActiveProject): ?>
<nav class="mobile-project-nav" aria-label="Construction mobile navigation">
<a class="<?=$activeProjectTab==='progress'?'active':''?>" href="<?=e($projectUrl('progress.php'))?>"><span aria-hidden="true">▤</span>Progress</a>
<a class="<?=$activeProjectTab==='logs'?'active':''?>" href="<?=e($projectUrl('daily_logs.php'))?>"><span aria-hidden="true">✎</span>Daily Log</a>
<a class="<?=$activeProjectTab==='team'?'active':''?>" href="<?=e($projectUrl('project_team.php'))?>"><span aria-hidden="true">♟</span>Project Team</a>
<details class="mobile-more-native"><summary><span aria-hidden="true">•••</span>More</summary><div class="mobile-more-sheet">
<a href="<?=e($projectUrl('documents.php'))?>">Plans &amp; Documents</a>
<a href="<?=e($projectUrl('expenses.php'))?>">Expenses</a>
<a href="<?=e($projectUrl('photos.php'))?>">Photos</a>
<?php if(dev_is_super()):?><a href="companies.php">Vendor Directory</a><?php endif;?>
</div></details>
</nav>
<?php endif; ?>
