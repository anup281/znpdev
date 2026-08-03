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
  $stmt=$pdo->prepare('UPDATE construction_trades SET display_order=?,updated_at=NOW() WHERE id=? AND is_active=1');
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
  $action=isset($_POST['archive_trade_id'])?'archive_trade':(string)($_POST['action']??'save_all');
  if(in_array($action,['archive_trade','restore_trade'],true)){
   $tradeId=(int)($_POST[$action==='archive_trade'?'archive_trade_id':'trade_id']??0);if($tradeId<1)throw new RuntimeException('Trade could not be identified.');
   $active=$action==='restore_trade'?1:0;
   $update=$pdo->prepare('UPDATE construction_trades SET is_active=?,updated_at=NOW() WHERE id=? AND is_active<>?');$update->execute([$active,$tradeId,$active]);
   if(!$update->rowCount())throw new RuntimeException('Trade was not found or already has that status.');
   header('Location: trade_settings.php?'.($active?'restored':'archived').'=1');exit;
  }
  if($action!=='save_all')throw new RuntimeException('Unsupported action.');
  $ids=$_POST['trade_id']??[];$names=$_POST['trade_name']??[];$newNames=$_POST['new_trade_name']??[];
  if(!is_array($ids)||!is_array($names)||!is_array($newNames))throw new RuntimeException('Invalid form data.');
  $pdo->beginTransaction();
  $update=$pdo->prepare('UPDATE construction_trades SET trade_name=?,updated_at=NOW() WHERE id=? AND is_active=1');
  $seen=[];
  foreach($ids as $i=>$rawId){
   $id=(int)$rawId;$name=trim((string)($names[$i]??''));
   if($id<1)continue;if($name==='')throw new RuntimeException('Trade names cannot be blank.');
   $key=strtolower($name);if(isset($seen[$key]))throw new RuntimeException('Trade names must be unique.');$seen[$key]=true;
   $update->execute([$name,$id]);
  }
  $maxOrder=(int)$pdo->query('SELECT COALESCE(MAX(display_order),0) FROM construction_trades')->fetchColumn();
  $insert=$pdo->prepare('INSERT INTO construction_trades(trade_name,is_active,display_order,created_at,updated_at) VALUES(?,1,?,NOW(),NOW())');
  foreach($newNames as $rawName){$name=trim((string)$rawName);if($name==='')continue;$key=strtolower($name);if(isset($seen[$key]))throw new RuntimeException('Trade names must be unique.');$seen[$key]=true;$maxOrder+=10;$insert->execute([$name,$maxOrder]);}
  $pdo->commit();header('Location: trade_settings.php?saved=1');exit;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$msg=$e->getMessage();$error=str_contains(strtolower($msg),'duplicate')?'Trade names must be unique.':$msg;}
}
$trades=[];$archivedTrades=[];
try{$trades=$pdo->query('SELECT id,trade_name,is_active,display_order FROM construction_trades WHERE is_active=1 ORDER BY display_order,trade_name')->fetchAll();$archivedTrades=$pdo->query('SELECT id,trade_name,is_active,display_order FROM construction_trades WHERE is_active=0 ORDER BY trade_name')->fetchAll();}
catch(Throwable $e){$error=$error?:'Run the Trade Settings v3.3.3 installer first.';}
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>Trade Settings</h1><p class="muted">Drag trades to reorder them. Order changes save automatically.</p></div><a class="btn" href="companies.php">Back to Vendors</a></div>
<?php if($success):?><div class="card notice-success">Trade changes saved.</div><?php endif;?>
<?php if(isset($_GET['archived'])):?><div class="card notice-success">Trade archived. Historical vendor records were preserved.</div><?php endif;?>
<?php if(isset($_GET['restored'])):?><div class="card notice-success">Trade restored.</div><?php endif;?>
<?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<div id="tradeOrderStatus" class="trade-save-status" aria-live="polite"></div>
<form method="post" id="tradeSettingsForm">
 <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_all">
 <div class="card trade-toolbar"><button type="button" class="btn" id="addTradeRow">+ Add Trade</button><button class="btn btn-primary" type="submit">Save All</button><button class="btn" type="reset">Cancel Changes</button></div>
 <div class="card"><div class="table-scroll"><table class="trade-settings-table"><thead><tr><th class="drag-col"></th><th>Trade</th><th>Action</th></tr></thead><tbody id="tradeRows">
 <?php foreach($trades as $i=>$t):?><tr draggable="true" data-id="<?=$t['id']?>">
  <td class="drag-handle" title="Drag to reorder" aria-label="Drag to reorder">☰</td>
  <td><input type="hidden" name="trade_id[]" value="<?=$t['id']?>"><input name="trade_name[]" value="<?=e($t['trade_name'])?>" required></td>
  <td><button class="btn btn-danger" type="submit" name="archive_trade_id" value="<?=$t['id']?>" formnovalidate data-confirm="Archive <?=e($t['trade_name'])?>? It will be hidden from new vendor and project selections, but historical records will remain.">Archive</button></td>
 </tr><?php endforeach;?>
 </tbody></table></div><p class="muted trade-help">Archived trades remain attached to historical vendor records but are hidden from new vendor and project selections.</p></div>
</form>
<template id="newTradeTemplate"><tr class="new-trade-row"><td class="drag-handle muted">—</td><td><input name="new_trade_name[]" placeholder="New trade name" required></td><td><span class="muted">New</span></td></tr></template>
<?php if($archivedTrades):?><section class="card archived-vendors"><h2>Archived Trades</h2><p class="muted">Restore a trade if it should be available for new assignments again.</p><?php foreach($archivedTrades as $trade):?><div class="archived-vendor-row"><strong><?=e($trade['trade_name'])?></strong><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="restore_trade"><input type="hidden" name="trade_id" value="<?=(int)$trade['id']?>"><button class="btn btn-secondary">Restore Trade</button></form></div><?php endforeach;?></section><?php endif;?>
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
 addBtn.addEventListener('click',()=>{const row=tpl.content.firstElementChild.cloneNode(true);tbody.appendChild(row);row.querySelector('input[name="new_trade_name[]"]').focus();});
})();
</script>
<?php require __DIR__.'/includes/footer.php';?>
