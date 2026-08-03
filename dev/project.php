<?php require __DIR__.'/includes/header.php';$p=dev_require_project($projectId);
$launchers=[
 ['label'=>'Progress','description'=>'Site and building workflows','href'=>'progress.php?project_id='.$projectId,'icon'=>'▤','primary'=>true],
 ['label'=>'Daily Log','description'=>'Daily field reports','href'=>'daily_logs.php?project_id='.$projectId,'icon'=>'✎'],
 ['label'=>'Project Team','description'=>'Vendors and project contacts','href'=>'project_team.php?project_id='.$projectId,'icon'=>'♟'],
 ['label'=>'Files & Permits','description'=>'Project files and permit numbers','href'=>'documents.php?project_id='.$projectId,'icon'=>'▱'],
 ['label'=>'Photos','description'=>'Project photo library','href'=>'photos.php?project_id='.$projectId,'icon'=>'▧'],
];
?>
<div class="page-head"><div><span class="badge"><?=e($p['status'])?></span><h1><?=e($p['project_name'])?></h1><p class="muted"><?=e(trim(($p['city']??'').', '.($p['state']??''),', '))?> · <?=e((string)($p['phase']??''))?></p></div><?php if(dev_is_super()):?><a class="btn btn-secondary" href="projects.php?edit=<?=$projectId?>">Edit Project</a><?php endif;?></div>
<section class="project-launcher-grid" aria-label="Project modules"><?php foreach($launchers as $launcher):?><a class="card project-launcher-card<?=$launcher['primary']?' is-primary':''?>" href="<?=e($launcher['href'])?>"><span class="project-launcher-icon" aria-hidden="true"><?=e($launcher['icon'])?></span><span><strong><?=e($launcher['label'])?></strong><small><?=e($launcher['description'])?></small></span><span class="project-launcher-arrow" aria-hidden="true">→</span></a><?php endforeach;?></section>
<?php require __DIR__.'/includes/footer.php'; ?>
