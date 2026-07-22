<?php
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/functions.php';
if(!dev_is_super()){http_response_code(403);exit('Administrator access required.');}
$pdo=db();$error='';$success=isset($_GET['saved']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Session expired. Refresh and try again.');
  $templateId=(int)($_POST['template_id']??0);$name=trim((string)($_POST['template_name']??''));$scope=(string)($_POST['schedule_scope']??'');
  if($templateId<1||$name===''||!in_array($scope,['Project','Building'],true))throw new RuntimeException('Invalid schedule template.');
  $ids=$_POST['item_id']??[];$activities=$_POST['activity_name']??[];$durations=$_POST['duration_days']??[];$trades=$_POST['default_trade']??[];
  $newActivities=$_POST['new_activity_name']??[];$newDurations=$_POST['new_duration_days']??[];$newTrades=$_POST['new_default_trade']??[];
  if(!is_array($ids)||!is_array($activities)||!is_array($durations)||!is_array($trades))throw new RuntimeException('Invalid template items.');
  $pdo->beginTransaction();
  $pdo->prepare('UPDATE construction_schedule_templates SET template_name=?,schedule_scope=?,is_active=1,is_default=1,updated_at=NOW() WHERE id=?')->execute([$name,$scope,$templateId]);
  $pdo->prepare('UPDATE construction_schedule_templates SET is_default=0 WHERE schedule_scope=? AND id<>?')->execute([$scope,$templateId]);
  $update=$pdo->prepare('UPDATE construction_schedule_template_items SET sequence_no=?,activity_name=?,default_duration_days=?,default_trade=? WHERE id=? AND template_id=?');
  $seq=10;
  foreach($ids as $i=>$rawId){$id=(int)$rawId;$activity=trim((string)($activities[$i]??''));if($id<1||$activity==='')continue;$duration=max(1,(int)($durations[$i]??1));$trade=trim((string)($trades[$i]??''));$update->execute([$seq,$activity,$duration,$trade!==''?$trade:null,$id,$templateId]);$seq+=10;}
  $insert=$pdo->prepare('INSERT INTO construction_schedule_template_items(template_id,sequence_no,activity_name,default_duration_days,default_trade,created_at) VALUES(?,?,?,?,?,NOW())');
  foreach($newActivities as $i=>$raw){$activity=trim((string)$raw);if($activity==='')continue;$duration=max(1,(int)($newDurations[$i]??1));$trade=trim((string)($newTrades[$i]??''));$insert->execute([$templateId,$seq,$activity,$duration,$trade!==''?$trade:null]);$seq+=10;}
  $deleteIds=$_POST['delete_item_ids']??[];if(is_array($deleteIds)&&$deleteIds){$del=$pdo->prepare('DELETE FROM construction_schedule_template_items WHERE id=? AND template_id=?');foreach($deleteIds as $id){$del->execute([(int)$id,$templateId]);}}
  $pdo->commit();header('Location: schedule_templates.php?template_id='.$templateId.'&saved=1');exit;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
$templates=$pdo->query("SELECT * FROM construction_schedule_templates WHERE is_active=1 ORDER BY FIELD(schedule_scope,'Project','Building'),is_default DESC,id")->fetchAll();
$templateId=(int)($_GET['template_id']??($templates[0]['id']??0));$template=null;$items=[];
foreach($templates as $t){if((int)$t['id']===$templateId){$template=$t;break;}}
if($template){$st=$pdo->prepare('SELECT * FROM construction_schedule_template_items WHERE template_id=? ORDER BY sequence_no,id');$st->execute([$templateId]);$items=$st->fetchAll();}
$trades=$pdo->query('SELECT trade_name FROM construction_trades WHERE is_active=1 ORDER BY display_order,trade_name')->fetchAll(PDO::FETCH_COLUMN);
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>Schedule Templates</h1><p class="muted">Manage the task list used when a new project or building schedule is initialized. Existing schedules are not changed.</p></div></div>
<?php if($success):?><div class="card notice-success">Schedule template saved.</div><?php endif;?>
<?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<div class="template-layout">
 <aside class="card template-list"><h2>Templates</h2><?php foreach($templates as $t):?><a class="template-link <?=$templateId===(int)$t['id']?'active':''?>" href="schedule_templates.php?template_id=<?=(int)$t['id']?>"><strong><?=e($t['template_name'])?></strong><span><?=e($t['schedule_scope'])?><?=$t['is_default']?' · Default':''?></span></a><?php endforeach;?></aside>
 <section><?php if($template):?><form method="post" id="scheduleTemplateForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="template_id" value="<?=$templateId?>"><input type="hidden" id="deleteItemIds" name="delete_item_ids[]" value="">
  <div class="card template-toolbar"><label>Template Name<input name="template_name" value="<?=e($template['template_name'])?>" required></label><label>Scope<select name="schedule_scope"><option value="Project" <?=$template['schedule_scope']==='Project'?'selected':''?>>Project</option><option value="Building" <?=$template['schedule_scope']==='Building'?'selected':''?>>Building</option></select></label><div class="template-actions"><button type="button" class="btn" id="addTemplateItem">+ Add Item</button><button class="btn btn-primary" type="submit">Save Template</button></div></div>
  <div class="card"><div class="table-scroll"><table class="schedule-template-table"><thead><tr><th></th><th>Activity</th><th>Duration</th><th>Default Trade</th><th></th></tr></thead><tbody id="templateRows"><?php foreach($items as $item):?><tr draggable="true" data-id="<?=$item['id']?>"><td class="drag-handle">☰</td><td><input type="hidden" name="item_id[]" value="<?=$item['id']?>"><input name="activity_name[]" value="<?=e($item['activity_name'])?>" required></td><td><input type="number" min="1" name="duration_days[]" value="<?=(int)$item['default_duration_days']?>"></td><td><input name="default_trade[]" list="trade-options" value="<?=e((string)$item['default_trade'])?>"></td><td><button type="button" class="btn btn-danger remove-template-item">Remove</button></td></tr><?php endforeach;?></tbody></table></div></div>
 </form><?php else:?><div class="card notice-error">No active schedule templates were found.</div><?php endif;?></section>
</div>
<datalist id="trade-options"><?php foreach($trades as $trade):?><option value="<?=e($trade)?>"><?php endforeach;?></datalist>
<template id="newTemplateItem"><tr class="new-template-row"><td class="drag-handle">☰</td><td><input name="new_activity_name[]" placeholder="Activity name" required></td><td><input type="number" min="1" name="new_duration_days[]" value="5"></td><td><input name="new_default_trade[]" list="trade-options"></td><td><button type="button" class="btn btn-danger remove-template-item">Remove</button></td></tr></template>
<style>.template-layout{display:grid;grid-template-columns:280px minmax(0,1fr);gap:18px}.template-list{align-self:start}.template-list h2{margin-top:0}.template-link{display:block;padding:12px;border-radius:10px;text-decoration:none;color:inherit;margin-top:8px;border:1px solid #d9e1ec}.template-link span{display:block;font-size:12px;color:#667085;margin-top:4px}.template-link.active{border-color:#1f5ea8;background:#edf5ff}.template-toolbar{display:grid;grid-template-columns:minmax(220px,1fr) 180px auto;gap:12px;align-items:end;margin-bottom:16px}.template-toolbar label{font-weight:600}.template-toolbar input,.template-toolbar select{display:block;width:100%;margin-top:6px}.template-actions{display:flex;gap:8px}.schedule-template-table{min-width:760px}.schedule-template-table th:first-child,.schedule-template-table td:first-child{width:44px;text-align:center}.schedule-template-table th:nth-child(3),.schedule-template-table td:nth-child(3){width:110px}.schedule-template-table th:last-child,.schedule-template-table td:last-child{width:110px}.schedule-template-table input{width:100%}.drag-handle{cursor:grab;font-size:20px}.is-dragging{opacity:.45}@media(max-width:900px){.template-layout{grid-template-columns:1fr}.template-toolbar{grid-template-columns:1fr}.template-actions{justify-content:flex-start}}</style>
<script>(function(){const body=document.getElementById('templateRows'),tpl=document.getElementById('newTemplateItem'),add=document.getElementById('addTemplateItem'),del=document.getElementById('deleteItemIds');if(!body)return;let dragged=null;add?.addEventListener('click',()=>{const row=tpl.content.firstElementChild.cloneNode(true);body.appendChild(row);row.querySelector('input').focus();});body.addEventListener('click',e=>{const btn=e.target.closest('.remove-template-item');if(!btn)return;const row=btn.closest('tr');if(row.dataset.id){const current=del.value?del.value.split(','):[];current.push(row.dataset.id);del.value=current.join(',');}row.remove();});body.addEventListener('dragstart',e=>{const r=e.target.closest('tr');if(!r)return;dragged=r;r.classList.add('is-dragging');});body.addEventListener('dragend',()=>{dragged?.classList.remove('is-dragging');dragged=null;});body.addEventListener('dragover',e=>{e.preventDefault();const r=e.target.closest('tr');if(!dragged||!r||r===dragged)return;const box=r.getBoundingClientRect();body.insertBefore(dragged,e.clientY<box.top+box.height/2?r:r.nextSibling);});})();</script>
<?php require __DIR__.'/includes/footer.php';?>
