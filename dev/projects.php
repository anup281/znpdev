<?php
require_once __DIR__.'/includes/bootstrap.php';
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!csrf_check((string)($_POST['csrf']??'')))$error='Your session expired.';
 elseif(!dev_is_super())$error='Only administrators can manage construction projects.';
 else try{
  $action=(string)($_POST['action']??'save');
  if($action==='restore'){
   $id=(int)($_POST['id']??0);$status=trim((string)($_POST['restore_status']??'Completed'));
   if(!in_array($status,dev_project_statuses(false),true)||$status==='Cancelled')$status='Completed';
   db()->prepare('UPDATE construction_projects SET is_archived=0,status=?,updated_at=NOW() WHERE id=?')->execute([$status,$id]);
   dev_activity($id,'project_restored','Construction project restored with status '.$status.'.','project',$id);
   header('Location: projects.php?restored=1');exit;
  }
  $id=(int)($_POST['id']??0);$name=trim((string)($_POST['project_name']??''));if($name==='')throw new RuntimeException('Project name is required.');
  $status=trim((string)($_POST['status']??'Planning'));if(!in_array($status,dev_project_statuses(),true))$status='Planning';$archived=$status==='Archived'?1:0;
  $vals=[(int)($_POST['public_project_id']??0)?:null,$name,trim((string)($_POST['project_code']??'')),trim((string)($_POST['city']??'')),trim((string)($_POST['state']??'')),trim((string)($_POST['phase']??'')),$status,($_POST['start_date']??'')?:null,($_POST['target_completion_date']??'')?:null,(int)($_POST['percent_complete']??0),trim((string)($_POST['description']??'')),$archived];
  if($id){$vals[]=$id;db()->prepare('UPDATE construction_projects SET public_project_id=?,project_name=?,project_code=?,city=?,state=?,phase=?,status=?,start_date=?,target_completion_date=?,percent_complete=?,description=?,is_archived=?,updated_at=NOW() WHERE id=?')->execute($vals);dev_activity($id,'project_updated','Construction project details updated.','project',$id);}else{db()->prepare('INSERT INTO construction_projects(public_project_id,project_name,project_code,city,state,phase,status,start_date,target_completion_date,percent_complete,description,is_archived,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')->execute($vals);$id=(int)db()->lastInsertId();dev_initialize_schedule($id,null,'Project',$vals[7]);dev_activity($id,'project_created','Construction project created.','project',$id);}
  header('Location: projects.php?saved=1&view='.($archived?'archived':'active'));exit;
 }catch(Throwable $e){$error=$e->getMessage();}
}
$view=((string)($_GET['view']??'active'))==='archived'?'archived':'active';
$message=isset($_GET['saved'])?'Project saved.':(isset($_GET['restored'])?'Project restored.':'');
$edit=isset($_GET['edit'])?dev_project((int)$_GET['edit']):null;
$public=[];
try {
 $public=db()->query("SELECT id,project_name FROM projects WHERE status='Under Development' OR portfolio_category='under_development' ORDER BY project_name")->fetchAll();
} catch(Throwable $e) {
 // Some older public-project schemas do not contain portfolio_category.
 try {$public=db()->query("SELECT id,project_name FROM projects ORDER BY project_name")->fetchAll();} catch(Throwable $ignored) {$public=[];}
 error_log('Construction projects public-project lookup: '.$e->getMessage());
}
$projects=dev_projects($view==='archived');
$ids=array_map('intval',array_column($projects,'id'));$buildingCounts=[];$inspectionCounts=[];
if($ids){
 $in=implode(',',$ids);
 try {foreach(db()->query("SELECT construction_project_id,COUNT(*) c FROM construction_buildings WHERE construction_project_id IN ($in) GROUP BY construction_project_id")->fetchAll() as $r)$buildingCounts[(int)$r['construction_project_id']]=(int)$r['c'];} catch(Throwable $e){error_log('Construction project building counts: '.$e->getMessage());}
 try {foreach(db()->query("SELECT construction_project_id,COUNT(*) c FROM construction_inspections WHERE construction_project_id IN ($in) AND status IN ('Scheduled','Requested','Reinspection Required') AND scheduled_datetime>=NOW() GROUP BY construction_project_id")->fetchAll() as $r)$inspectionCounts[(int)$r['construction_project_id']]=(int)$r['c'];} catch(Throwable $e){error_log('Construction project inspection counts: '.$e->getMessage());}
}
require __DIR__.'/includes/header.php';
?>
<div class="section-heading"><h1>Projects</h1><?php if(dev_is_super()):?><a class="btn btn-primary" href="projects.php?new=1">+ New Project</a><?php endif;?></div>
<div class="project-tabs"><a class="<?=($view==='active'?'active':'')?>" href="projects.php?view=active">Active</a><a class="<?=($view==='archived'?'active':'')?>" href="projects.php?view=archived">Archived</a></div>
<?php if($message):?><div class="card notice-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<?php if(dev_is_super()&&(isset($_GET['new'])||$edit)):?><form method="post" class="card form-grid project-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e((string)($edit['id']??0))?>"><input type="hidden" name="action" value="save"><div><label>Linked Public Project</label><select name="public_project_id"><option value="">None</option><?php foreach($public as $p):?><option value="<?=$p['id']?>" <?=((int)($edit['public_project_id']??0)===(int)$p['id'])?'selected':''?>><?=e($p['project_name'])?></option><?php endforeach;?></select></div><div><label>Project Name</label><input name="project_name" required value="<?=e($edit['project_name']??'')?>"></div><div><label>Project Code</label><input name="project_code" value="<?=e($edit['project_code']??'')?>"></div><div><label>Phase</label><input name="phase" value="<?=e($edit['phase']??'')?>"></div><div><label>City</label><input name="city" value="<?=e($edit['city']??'')?>"></div><div><label>State</label><input name="state" value="<?=e($edit['state']??'TX')?>"></div><div><label>Status</label><select name="status"><?php foreach(dev_project_statuses() as $s):?><option value="<?=e($s)?>" <?=($edit['status']??'Planning')===$s?'selected':''?>><?=e($s)?></option><?php endforeach;?></select></div><div><label>Percent Complete</label><input type="number" min="0" max="100" name="percent_complete" value="<?=e((string)($edit['percent_complete']??0))?>"></div><div><label>Start Date</label><input type="date" name="start_date" value="<?=e($edit['start_date']??'')?>"></div><div><label>Target Completion</label><input type="date" name="target_completion_date" value="<?=e($edit['target_completion_date']??'')?>"></div><div class="form-full"><label>Description</label><textarea name="description"><?=e($edit['description']??'')?></textarea></div><div class="form-full actions"><button class="primary">Save Project</button><a class="btn btn-secondary" href="projects.php?view=<?=$view?>">Cancel</a></div></form><?php endif;?>
<div class="project-toolbar"><input id="projectSearch" type="search" placeholder="Search projects..."><select id="projectSort"><option value="updated">Last Updated</option><option value="name">Project Name</option><option value="status">Status</option></select></div>
<div class="grid grid-3 project-card-grid" id="projectGrid">
<?php foreach($projects as $p):$pid=(int)$p['id'];$location=trim(($p['city']??'').', '.($p['state']??''),', ');?><article class="card project-card" data-name="<?=e(strtolower($p['project_name'].' '.$location))?>" data-status="<?=e(strtolower((string)$p['status']))?>" data-updated="<?=e((string)($p['updated_at']??''))?>"><span class="badge <?=e(dev_status_class((string)$p['status']))?>"><?=e((string)$p['status'])?></span><h2><a href="project.php?project_id=<?=$pid?>"><?=e($p['project_name'])?></a></h2><?php if($location):?><p><?=e($location)?></p><?php endif;?><div class="project-summary-grid"><span><strong><?=e((string)($buildingCounts[$pid]??0))?></strong> Buildings</span><span><strong><?=e((string)($p['percent_complete']??0))?>%</strong> Complete</span><span><strong><?=e((string)($inspectionCounts[$pid]??0))?></strong> Upcoming Inspections</span></div><p class="project-updated muted">Last updated <?=e(dev_datetime($p['updated_at']??$p['created_at']??null))?></p><div class="actions"><a class="btn btn-primary" href="project.php?project_id=<?=$pid?>">Open</a><?php if(dev_is_super()):?><?php if($view==='active'):?><a class="btn btn-secondary" href="projects.php?edit=<?=$pid?>&view=active">Edit</a><?php else:?><form method="post" class="restore-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?=$pid?>"><select name="restore_status"><?php foreach(['Planning','Preconstruction','Under Construction','Nearing Completion','Lease-Up','Completed'] as $s):?><option><?=e($s)?></option><?php endforeach;?></select><button class="primary">Restore Project</button></form><?php endif;?><?php endif;?></div></article><?php endforeach;?>
<?php if(!$projects):?><div class="card empty project-empty"><h2>No <?=ucfirst($view)?> Projects</h2><p><?=($view==='archived'?'Archived projects will appear here and can be restored at any time.':'Create your first project to get started.')?></p><?php if($view==='active'&&dev_is_super()):?><a class="btn btn-primary" href="projects.php?new=1">+ New Project</a><?php endif;?></div><?php endif;?>
</div>
<script>(function(){const search=document.getElementById('projectSearch'),sort=document.getElementById('projectSort'),grid=document.getElementById('projectGrid');if(!search||!sort||!grid)return;const cards=[...grid.querySelectorAll('.project-card')];function refresh(){const q=search.value.toLowerCase().trim();cards.forEach(c=>c.hidden=q&&!((c.dataset.name||'')+' '+(c.dataset.status||'')).includes(q));const visible=cards.filter(c=>!c.hidden);visible.sort((a,b)=>sort.value==='name'?(a.dataset.name||'').localeCompare(b.dataset.name||''):sort.value==='status'?(a.dataset.status||'').localeCompare(b.dataset.status||''):(b.dataset.updated||'').localeCompare(a.dataset.updated||''));visible.forEach(c=>grid.appendChild(c));}search.addEventListener('input',refresh);sort.addEventListener('change',refresh);})();</script>
<?php require __DIR__.'/includes/footer.php'; ?>
