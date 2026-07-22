<?php
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/functions.php';
if(!dev_is_super()){http_response_code(403);exit('Administrator access required.');}

$pdo=db();

// AJAX autosave for drag-and-drop ordering.
if($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='reorder'){
 header('Content-Type: application/json; charset=utf-8');
 try{
  if(!csrf_check((string)($_POST['csrf']??''))) throw new RuntimeException('Session expired. Refresh and try again.');
  $ids=json_decode((string)($_POST['ids']??'[]'),true);
  if(!is_array($ids)||!$ids) throw new RuntimeException('No trade order was received.');
  $pdo->beginTransaction();
  $stmt=$pdo->prepare('UPDATE construction_trades SET display_order=?,updated_at=NOW() WHERE id=?');
  $order=10;
  foreach($ids as $id){$id=(int)$id;if($id<1)continue;$stmt->execute([$order,$id]);$order+=10;}
  $pdo->commit();
  echo json_encode(['ok'=>true,'message'=>'Order saved']);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
 exit;
}

$error='';$success=isset($_GET['saved']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Session expired. Refresh and try again.');
  $action=(string)($_POST['action']??'save_all');
  if($action!=='save_all')throw new RuntimeException('Unsupported action.');
  $ids=$_POST['trade_id']??[];$names=$_POST['trade_name']??[];$active=$_POST['is_active']??[];$newNames=$_POST['new_trade_name']??[];
  if(!is_array($ids)||!is_array($names)||!is_array($active)||!is_array($newNames))throw new RuntimeException('Invalid form data.');
  $pdo->beginTransaction();
  $update=$pdo->prepare('UPDATE construction_trades SET trade_name=?,is_active=?,updated_at=NOW() WHERE id=?');
  $seen=[];
  foreach($ids as $i=>$rawId){
   $id=(int)$rawId;$name=trim((string)($names[$i]??''));$isActive=((string)($active[$i]??'0'))==='1'?1:0;
   if($id<1)continue;if($name==='')throw new RuntimeException('Trade names cannot be blank.');
   $key=strtolower($name);if(isset($seen[$key]))throw new RuntimeException('Trade names must be unique.');$seen[$key]=true;
   $update->execute([$name,$isActive,$id]);
  }
  $maxOrder=(int)$pdo->query('SELECT COALESCE(MAX(display_order),0) FROM construction_trades')->fetchColumn();
  $insert=$pdo->prepare('INSERT INTO construction_trades(trade_name,is_active,display_order,created_at,updated_at) VALUES(?,1,?,NOW(),NOW())');
  foreach($newNames as $rawName){$name=trim((string)$rawName);if($name==='')continue;$key=strtolower($name);if(isset($seen[$key]))throw new RuntimeException('Trade names must be unique.');$seen[$key]=true;$maxOrder+=10;$insert->execute([$name,$maxOrder]);}
  $pdo->commit();header('Location: trade_settings.php?saved=1');exit;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$msg=$e->getMessage();$error=str_contains(strtolower($msg),'duplicate')?'Trade names must be unique.':$msg;}
}
$trades=[];
try{$trades=$pdo->query('SELECT id,trade_name,is_active,display_order FROM construction_trades ORDER BY display_order,trade_name')->fetchAll();}
catch(Throwable $e){$error=$error?:'Run the Trade Settings v3.3.3 installer first.';}
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>Trade Settings</h1><p class="muted">Drag trades to reorder them. Order changes save automatically.</p></div><a class="btn" href="companies.php">Back to Vendors</a></div>
<?php if($success):?><div class="card notice-success">Trade changes saved.</div><?php endif;?>
<?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<div id="tradeOrderStatus" class="trade-save-status" aria-live="polite"></div>
<form method="post" id="tradeSettingsForm">
 <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_all">
 <div class="card trade-toolbar"><button type="button" class="btn" id="addTradeRow">+ Add Trade</button><button class="btn btn-primary" type="submit">Save All</button><button class="btn" type="reset">Cancel Changes</button></div>
 <div class="card"><div class="table-scroll"><table class="trade-settings-table"><thead><tr><th class="drag-col"></th><th>Active</th><th>Trade</th></tr></thead><tbody id="tradeRows">
 <?php foreach($trades as $i=>$t):?><tr draggable="true" data-id="<?=$t['id']?>">
  <td class="drag-handle" title="Drag to reorder" aria-label="Drag to reorder">☰</td>
  <td><input type="hidden" name="trade_id[]" value="<?=$t['id']?>"><input type="hidden" class="active-value" name="is_active[]" value="<?=$t['is_active']?'1':'0'?>"><input class="active-toggle" type="checkbox" <?=$t['is_active']?'checked':''?> aria-label="Active"></td>
  <td><input name="trade_name[]" value="<?=e($t['trade_name'])?>" required></td>
 </tr><?php endforeach;?>
 </tbody></table></div><p class="muted trade-help">Inactive trades remain attached to historical vendor records but are hidden from new vendor dropdowns.</p></div>
</form>
<template id="newTradeTemplate"><tr class="new-trade-row"><td class="drag-handle muted">—</td><td><input type="hidden" name="new_trade_active[]" value="1"><input type="checkbox" checked disabled aria-label="Active"></td><td><input name="new_trade_name[]" placeholder="New trade name" required></td></tr></template>
<style>
.trade-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.table-scroll{overflow-x:auto}.trade-settings-table{min-width:620px}.trade-settings-table .drag-col{width:48px}.trade-settings-table th:nth-child(2),.trade-settings-table td:nth-child(2){width:90px;text-align:center}.trade-settings-table input[type=text],.trade-settings-table input:not([type]){width:100%}.drag-handle{cursor:grab;font-size:20px;text-align:center;user-select:none}.drag-handle:active{cursor:grabbing}#tradeRows tr.is-dragging{opacity:.45}#tradeRows tr.drag-over{box-shadow:inset 0 3px 0 #1f5ea8}.trade-save-status{min-height:28px;margin-bottom:8px;font-weight:600}.trade-save-status.is-saving{color:#6b7280}.trade-save-status.is-success{color:#18743a}.trade-save-status.is-error{color:#b42318}.trade-help{margin:14px 0 0}
</style>
<script>
(function(){
 const tbody=document.getElementById('tradeRows'),status=document.getElementById('tradeOrderStatus'),csrf=<?=json_encode(csrf_token())?>,addBtn=document.getElementById('addTradeRow'),tpl=document.getElementById('newTradeTemplate');
 if(!tbody)return;
 let dragged=null;
 function show(message,kind){status.textContent=message;status.className='trade-save-status '+(kind?'is-'+kind:'');if(kind==='success')setTimeout(()=>{if(status.textContent===message){status.textContent='';status.className='trade-save-status';}},1800);}
 function rows(){return Array.from(tbody.querySelectorAll('tr[data-id]'));}
 function saveOrder(){const ids=rows().map(r=>r.dataset.id);show('Saving order…','saving');const body=new URLSearchParams({action:'reorder',csrf:csrf,ids:JSON.stringify(ids)});fetch('trade_settings.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:body.toString()}).then(async r=>{const data=await r.json().catch(()=>({ok:false,message:'The server returned an invalid response.'}));if(!r.ok||!data.ok)throw new Error(data.message||'Order could not be saved.');show(data.message||'Order saved','success');}).catch(e=>show(e.message,'error'));}
 tbody.addEventListener('dragstart',e=>{const row=e.target.closest('tr[data-id]');if(!row)return;dragged=row;row.classList.add('is-dragging');e.dataTransfer.effectAllowed='move';});
 tbody.addEventListener('dragend',()=>{if(dragged)dragged.classList.remove('is-dragging');tbody.querySelectorAll('.drag-over').forEach(r=>r.classList.remove('drag-over'));dragged=null;});
 tbody.addEventListener('dragover',e=>{e.preventDefault();const target=e.target.closest('tr[data-id]');if(!dragged||!target||target===dragged)return;tbody.querySelectorAll('.drag-over').forEach(r=>r.classList.remove('drag-over'));target.classList.add('drag-over');const rect=target.getBoundingClientRect();tbody.insertBefore(dragged,e.clientY<rect.top+rect.height/2?target:target.nextSibling);});
 tbody.addEventListener('drop',e=>{e.preventDefault();tbody.querySelectorAll('.drag-over').forEach(r=>r.classList.remove('drag-over'));if(dragged)saveOrder();});
 tbody.addEventListener('change',e=>{if(e.target.classList.contains('active-toggle')){e.target.closest('td').querySelector('.active-value').value=e.target.checked?'1':'0';}});
 addBtn.addEventListener('click',()=>{const row=tpl.content.firstElementChild.cloneNode(true);tbody.appendChild(row);row.querySelector('input[name="new_trade_name[]"]').focus();});
})();
</script>
<?php require __DIR__.'/includes/footer.php';?>
