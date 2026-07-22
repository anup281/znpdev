<?php
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/functions.php';
if(!dev_is_super()){http_response_code(403);exit('Administrator access required.');}
$error='';$success=isset($_GET['saved']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Session expired. Please refresh and try again.');
  $action=(string)($_POST['action']??'add');
  if(in_array($action,['archive_trade','restore_trade'],true)){
   $companyId=(int)($_POST['company_id']??0);$linkId=(int)($_POST['link_id']??0);if($companyId<1||$linkId<1)throw new RuntimeException('Vendor trade could not be identified.');
   $archivedAt=$action==='archive_trade'?date('Y-m-d H:i:s'):null;
   $s=db()->prepare('UPDATE construction_company_trades SET archived_at=?,updated_at=NOW() WHERE id=? AND construction_company_id=?');$s->execute([$archivedAt,$linkId,$companyId]);
   if($s->rowCount()<1)throw new RuntimeException('Vendor trade could not be found or already has that status.');
   header('Location: companies.php?'.($action==='archive_trade'?'archived':'restored').'=1');exit;
  }
  if($action==='restore_company'){
   $companyId=(int)($_POST['company_id']??0);if($companyId<1)throw new RuntimeException('Vendor could not be identified.');
   db()->prepare('UPDATE construction_companies SET is_active=1,updated_at=NOW() WHERE id=?')->execute([$companyId]);
   header('Location: companies.php?company_restored=1');exit;
  }
  $name=trim((string)($_POST['company_name']??''));$contact=trim((string)($_POST['primary_contact']??''));
  $phone=trim((string)($_POST['cell_phone']??''));$email=trim((string)($_POST['email']??''));$tradeId=(int)($_POST['trade_id']??0);
  if($tradeId<1)throw new RuntimeException('Please select a trade.');
  if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Please enter a valid email address.');
  $pdo=db();$pdo->beginTransaction();
  if($action==='update'){
   $companyId=(int)($_POST['company_id']??0);$linkId=(int)($_POST['link_id']??0);
   if($companyId<1||$linkId<1||$name==='')throw new RuntimeException('Vendor row could not be identified.');
   $s=$pdo->prepare('UPDATE construction_companies SET company_name=?,primary_contact=?,cell_phone=?,email=?,updated_at=NOW() WHERE id=?');$s->execute([$name,$contact,$phone,$email,$companyId]);
   $s=$pdo->prepare('UPDATE construction_company_trades SET construction_trade_id=?,updated_at=NOW() WHERE id=? AND construction_company_id=?');$s->execute([$tradeId,$linkId,$companyId]);
  }elseif($action==='add_trade'){
   $companyId=(int)($_POST['company_id']??0);if($companyId<1)throw new RuntimeException('Vendor could not be identified.');
   $s=$pdo->prepare('INSERT INTO construction_company_trades(construction_company_id,construction_trade_id,created_at,updated_at) VALUES(?,?,NOW(),NOW())');$s->execute([$companyId,$tradeId]);
  }else{
   if($name==='')throw new RuntimeException('Company name is required.');
   $s=$pdo->prepare("INSERT INTO construction_companies(company_name,primary_trade,primary_contact,office_phone,cell_phone,email,website,address,insurance_expiration,w9_on_file,license_number,notes,is_active,created_at,updated_at) VALUES(?,?,?,'',?,?,'','',NULL,0,'','',1,NOW(),NOW())");
   $tradeNameStmt=$pdo->prepare('SELECT trade_name FROM construction_trades WHERE id=?');$tradeNameStmt->execute([$tradeId]);$tradeName=(string)$tradeNameStmt->fetchColumn();
   $s->execute([$name,$tradeName,$contact,$phone,$email]);$companyId=(int)$pdo->lastInsertId();
   $s=$pdo->prepare('INSERT INTO construction_company_trades(construction_company_id,construction_trade_id,created_at,updated_at) VALUES(?,?,NOW(),NOW())');$s->execute([$companyId,$tradeId]);
  }
  $pdo->commit();header('Location: companies.php?saved=1');exit;
 }catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$msg=$e->getMessage();$error=str_contains(strtolower($msg),'duplicate')?'That vendor is already assigned to the selected trade.':$msg;}
}
$trades=[];$rows=[];$archivedTradeRows=[];$inactiveCompanies=[];
try{
 $trades=db()->query('SELECT id,trade_name FROM construction_trades WHERE is_active=1 ORDER BY display_order,trade_name')->fetchAll();
 $rows=db()->query("SELECT ct.id link_id,c.id company_id,t.id trade_id,t.trade_name,c.company_name,c.primary_contact,c.cell_phone,c.email
 FROM construction_company_trades ct JOIN construction_companies c ON c.id=ct.construction_company_id JOIN construction_trades t ON t.id=ct.construction_trade_id
 WHERE c.is_active=1 AND t.is_active=1 AND ct.archived_at IS NULL ORDER BY t.display_order,t.trade_name,c.company_name")->fetchAll();
 $archivedTradeRows=db()->query("SELECT ct.id link_id,c.id company_id,c.company_name,t.trade_name,ct.archived_at FROM construction_company_trades ct JOIN construction_companies c ON c.id=ct.construction_company_id JOIN construction_trades t ON t.id=ct.construction_trade_id WHERE ct.archived_at IS NOT NULL ORDER BY t.display_order,t.trade_name,c.company_name")->fetchAll();
 $inactiveCompanies=db()->query("SELECT id company_id,company_name FROM construction_companies WHERE is_active=0 ORDER BY company_name")->fetchAll();
}catch(Throwable $e){$error=$error?:'Vendor trade tables are not installed. Run install_vendor_trades_v3_3_2.php first.';}
require __DIR__.'/includes/header.php';
?>

<?php if($success):?><div class="card notice-success">Vendor information saved.</div><?php endif;?><?php if(isset($_GET['archived'])):?><div class="card notice-success">Vendor trade archived. Other trades remain active.</div><?php endif;?><?php if(isset($_GET['restored'])):?><div class="card notice-success">Vendor trade restored.</div><?php endif;?><?php if(isset($_GET['company_restored'])):?><div class="card notice-success">Vendor restored. You can now archive only the applicable trade.</div><?php endif;?><?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<section class="vendor-directory" aria-labelledby="vendor-directory-heading">
<div class="vendor-directory-toolbar"><div class="vendor-directory-summary"><h2 id="vendor-directory-heading">Vendor Directory</h2><p><strong id="vendorVisibleCount"><?=count($rows)?></strong> active trade relationship<?=count($rows)===1?'':'s'?></p></div><label class="vendor-search"><span>Search vendors</span><input id="vendorSearch" type="search" placeholder="Trade, company, contact, phone, or email" aria-label="Search vendors"></label><div class="vendor-directory-actions"><a class="btn" href="trade_settings.php">Trade Settings</a><a class="btn btn-primary" href="#company-modal">Add Vendor</a></div></div>
<div id="vendorDirectory" class="vendor-card-grid">
<?php foreach($rows as $r):?><article class="vendor-card vendor-row" data-search="<?=e(strtolower(implode(' ',[$r['trade_name'],$r['company_name'],$r['primary_contact'],$r['cell_phone'],$r['email']])))?>">
<div class="vendor-card-head"><span class="vendor-trade-pill"><?=e($r['trade_name'])?></span><span class="vendor-card-status">Active</span></div>
<div class="vendor-card-company"><span class="vendor-card-avatar" aria-hidden="true"><?=e(strtoupper(substr(trim((string)$r['company_name']),0,1)))?></span><div><strong><a href="vendor_profile.php?id=<?=$r['company_id']?>"><?=e($r['company_name'])?></a></strong><small>Vendor company</small></div></div>
<dl class="vendor-card-details">
<div><dt>Contact</dt><dd><?=e($r['primary_contact']?:'Not provided')?></dd></div>
<div><dt>Phone</dt><dd><?php if($r['cell_phone']):?><a href="tel:<?=e(preg_replace('/\D+/','',$r['cell_phone']))?>"><?=e($r['cell_phone'])?></a><?php else:?><span class="vendor-empty">Not provided</span><?php endif;?></dd></div>
<div><dt>Email</dt><dd><?php if($r['email']):?><a href="mailto:<?=e($r['email'])?>"><?=e($r['email'])?></a><?php else:?><span class="vendor-empty">Not provided</span><?php endif;?></dd></div>
</dl>
</article><?php endforeach;?>
<?php if(!$rows):?><div class="card vendor-empty-state"><strong>No active vendor trades</strong><p class="muted">Add a vendor to begin building the directory.</p><a class="btn btn-primary" href="#company-modal">Add Vendor</a></div><?php endif;?>
</div><div id="vendorNoResults" class="card vendor-empty-state" hidden><strong>No matching vendors</strong><p class="muted">Try a different company, trade, contact, phone, or email.</p></div>
</section>
<?php if($archivedTradeRows):?><section class="card archived-vendors"><h2>Archived Vendor Trades</h2><?php foreach($archivedTradeRows as $vendor):?><div class="archived-vendor-row"><span><strong><?=e($vendor['company_name'].' — '.$vendor['trade_name'])?></strong><small>Archived <?=e(dev_datetime($vendor['archived_at']))?></small></span><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="restore_trade"><input type="hidden" name="company_id" value="<?=(int)$vendor['company_id']?>"><input type="hidden" name="link_id" value="<?=(int)$vendor['link_id']?>"><button class="btn btn-secondary">Restore Trade</button></form></div><?php endforeach;?></section><?php endif;?>
<?php if($inactiveCompanies):?><section class="card archived-vendors"><h2>Previously Archived Vendors</h2><p class="muted">Restore these company-level archives, then archive only the applicable trade.</p><?php foreach($inactiveCompanies as $vendor):?><div class="archived-vendor-row"><strong><?=e($vendor['company_name'])?></strong><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="restore_company"><input type="hidden" name="company_id" value="<?=(int)$vendor['company_id']?>"><button class="btn btn-secondary">Restore Vendor</button></form></div><?php endforeach;?></section><?php endif;?>
<div class="dev-modal" id="company-modal" hidden><div class="dev-modal-panel"><a class="modal-close" href="#" aria-label="Close">×</a><h2>Add Vendor</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add"><div><label>Trade</label><select name="trade_id" required><option value="">Choose trade...</option><?php foreach($trades as $t):?><option value="<?=$t['id']?>"><?=e($t['trade_name'])?></option><?php endforeach;?></select></div><div><label>Company</label><input name="company_name" required></div><div><label>Contact Name</label><input name="primary_contact"></div><div><label>Phone</label><input name="cell_phone"></div><div class="form-full"><label>Email</label><input type="email" name="email"></div><div class="form-full"><button class="primary">Add Vendor</button></div></form></div></div>
<script>(function(){const search=document.getElementById('vendorSearch'),count=document.getElementById('vendorVisibleCount'),noResults=document.getElementById('vendorNoResults');if(search)search.addEventListener('input',function(){const q=this.value.trim().toLowerCase();let visible=0;document.querySelectorAll('.vendor-row').forEach(r=>{const match=!q||r.dataset.search.includes(q);r.hidden=!match;if(match)visible++});if(count)count.textContent=String(visible);if(noResults)noResults.hidden=visible!==0})})();</script>
<?php require __DIR__.'/includes/footer.php';?>
