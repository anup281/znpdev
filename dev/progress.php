<?php
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/progress_workflow.php';
$projectId=dev_active_project_id((int)($_GET['project_id']??0));
$p=dev_require_project($projectId);
$s=db()->prepare('SELECT * FROM construction_buildings WHERE construction_project_id=? AND is_archived=0 ORDER BY building_name');
$s->execute([$projectId]);
$buildings=$s->fetchAll();
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>Progress</h1><p class="muted"><?=e($p['project_name'])?> · Site and building workflows</p></div></div>
<div class="card progress-toolbar">
  <label class="progress-scope">Workflow
    <select data-progress-selector data-endpoint="progress_workflow.php?project_id=<?=$projectId?>">
      <option value="" selected disabled>Select Site or a building…</option>
      <option value="0">Site</option>
      <?php foreach($buildings as $building):?><option value="<?=(int)$building['id']?>"><?=e($building['building_name'])?></option><?php endforeach;?>
    </select>
  </label>
  <?php if(dev_is_super()):?><div class="progress-admin-actions"><a class="btn btn-primary" href="buildings.php?project_id=<?=$projectId?>&new=1">Add Building</a><a class="btn btn-secondary" href="buildings.php?project_id=<?=$projectId?>">Manage Buildings</a></div><?php endif;?>
</div>
<section data-progress-workflow aria-live="polite"><div class="card empty">Select Site or a building to load its workflow.</div></section>
<script>
(function(){
  const selector=document.querySelector('[data-progress-selector]');
  const target=document.querySelector('[data-progress-workflow]');
  if(!selector||!target)return;
  let request=null;
  selector.addEventListener('change',async function(){
    if(request)request.abort();
    request=new AbortController();
    const option=selector.options[selector.selectedIndex];
    const endpoint=selector.getAttribute('data-endpoint');
    const url=endpoint+'&building_id='+encodeURIComponent(selector.value)+'&_='+Date.now();
    target.setAttribute('aria-busy','true');
    const loading=document.createElement('div');
    loading.className='card progress-loading';
    loading.textContent='Loading '+(option?option.textContent:'workflow')+'…';
    target.replaceChildren(loading);
    try{
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'},signal:request.signal});
      if(!response.ok)throw new Error('The selected workflow could not be loaded.');
      target.innerHTML=await response.text();
      const pageUrl=new URL(window.location.href);
      if(selector.value==='0')pageUrl.searchParams.delete('building_id');else pageUrl.searchParams.set('building_id',selector.value);
      window.history.replaceState({},'',pageUrl);
    }catch(error){
      if(error.name!=='AbortError')target.innerHTML='<div class="card notice-error">'+error.message+'</div>';
    }finally{
      target.removeAttribute('aria-busy');
    }
  });
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
