<?php
require_once __DIR__.'/includes/bootstrap.php';
$user=admin_user();
$hour=(int)date('G');
$greeting=$hour<12?'Good Morning':($hour<17?'Good Afternoon':'Good Evening');
$projects=dev_projects(false);
require __DIR__.'/includes/header.php';
?>
<section class="construction-home-hero">
  <h1><?=e(strtoupper($greeting))?></h1>
  <div class="construction-home-time" data-dev-clock><?=e(date('g:i:s A'))?></div>
  <div class="construction-home-date" data-dev-date><?=e(date('l, F j, Y'))?></div>
</section>

<section class="construction-home-projects" aria-labelledby="activeProjectsHeading">
  <h2 id="activeProjectsHeading">ACTIVE PROJECTS</h2>
  <div class="construction-project-buttons">
    <?php foreach($projects as $project): ?>
      <a class="construction-project-button" href="project.php?project_id=<?=(int)$project['id']?>">
        <strong><?=e($project['project_name'])?></strong>
        <?php $location=trim((string)($project['city']??'').((!empty($project['city'])&&!empty($project['state']))?', ':'').(string)($project['state']??'')); ?>
        <?php if($location!==''): ?><span><?=e($location)?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <?php if(!$projects): ?><p class="muted construction-no-projects">No active projects are available.</p><?php endif; ?>
  </div>
</section>
<script>
(function(){
  const clock=document.querySelector('[data-dev-clock]');
  const date=document.querySelector('[data-dev-date]');
  if(!clock||!date)return;
  const update=function(){
    const now=new Date();
    clock.textContent=new Intl.DateTimeFormat('en-US',{hour:'numeric',minute:'2-digit',second:'2-digit'}).format(now);
    date.textContent=new Intl.DateTimeFormat('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric'}).format(now);
  };
  update();
  setInterval(update,1000);
})();
</script>
<?php require __DIR__.'/includes/footer.php';?>
