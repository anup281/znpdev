<?php
require_once __DIR__.'/includes/bootstrap.php';
$projectId=dev_active_project_id((int)($_GET['project_id']??$_POST['project_id']??0));
$p=dev_require_project($projectId);
$error='';

if(isset($_GET['remove'])){
    $assignmentId=(int)$_GET['remove'];
    db()->prepare('DELETE FROM construction_project_companies WHERE id=? AND construction_project_id=?')->execute([$assignmentId,$projectId]);
    header("Location: project_team.php?project_id=$projectId");exit;
}

if($_SERVER['REQUEST_METHOD']==='POST')try{
    if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Session expired.');
    $action=(string)($_POST['action']??'assign');
    $choice=trim((string)($_POST['vendor_trade_choice']??''));
    $parts=explode(':',$choice,2);
    $cid=(int)($parts[0]??0);$tradeId=(int)($parts[1]??0);
    if(!$cid || !$tradeId)throw new RuntimeException('Company and trade are required.');

    $tradeStmt=db()->prepare("SELECT t.trade_name FROM construction_company_trades ct JOIN construction_trades t ON t.id=ct.construction_trade_id AND t.is_active=1 WHERE ct.construction_company_id=? AND ct.construction_trade_id=? LIMIT 1");
    $tradeStmt->execute([$cid,$tradeId]);$trade=(string)$tradeStmt->fetchColumn();
    if($trade==='')throw new RuntimeException('The selected trade is not assigned to this company.');
    $notes=trim((string)($_POST['notes']??''));

    if($action==='update_assignment'){
        $assignmentId=(int)($_POST['assignment_id']??0);
        if(!$assignmentId)throw new RuntimeException('Project Team assignment was not found.');
        db()->prepare("UPDATE construction_project_companies SET construction_company_id=?,trade_role=?,project_contact_name='',project_contact_phone='',project_contact_email='',contract_status='Active',notes=?,updated_at=NOW() WHERE id=? AND construction_project_id=?")
            ->execute([$cid,$trade,$notes,$assignmentId,$projectId]);
    }else{
        db()->prepare('INSERT INTO construction_project_companies(construction_project_id,construction_company_id,trade_role,project_contact_name,project_contact_phone,project_contact_email,contract_status,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,NOW(),NOW())')
            ->execute([$projectId,$cid,$trade,'','','','Active',$notes]);
    }
    header("Location: project_team.php?project_id=$projectId&saved=1");exit;
}catch(Throwable $e){$error=$e->getMessage();}

require __DIR__.'/includes/header.php';
$companies=db()->query("SELECT c.id,c.company_name,c.primary_contact,c.cell_phone,c.office_phone,c.email,t.id trade_id,t.trade_name FROM construction_companies c JOIN construction_company_trades ct ON ct.construction_company_id=c.id JOIN construction_trades t ON t.id=ct.construction_trade_id AND t.is_active=1 WHERE c.is_active=1 ORDER BY t.trade_name ASC,c.company_name ASC")->fetchAll();
$companyContactStmt=db()->prepare('SELECT name,phone,email FROM construction_vendor_contacts WHERE construction_company_id=? AND is_active=1 ORDER BY is_primary DESC,id LIMIT 1');
foreach($companies as &$companyOption){
    $companyContactStmt->execute([(int)$companyOption['id']]);
    $primaryVendorContact=$companyContactStmt->fetch()?:[];
    $companyOption['display_contact']=(string)($primaryVendorContact['name']??$companyOption['primary_contact']??'');
    $companyOption['display_phone']=(string)($primaryVendorContact['phone']??($companyOption['cell_phone']?:$companyOption['office_phone']));
    $companyOption['display_email']=(string)($primaryVendorContact['email']??$companyOption['email']??'');
}
unset($companyOption);

$s=db()->prepare("SELECT pc.*,c.company_name,c.primary_contact,c.cell_phone,c.office_phone,c.email,c.address,c.website,c.license_number,c.insurance_expiration,c.w9_on_file,c.notes vendor_notes,vc.name selected_contact,vc.phone selected_phone,vc.email selected_email,COALESCE(NULLIF(pc.trade_role,''),c.primary_trade,'') vendor_trade FROM construction_project_companies pc JOIN construction_companies c ON c.id=pc.construction_company_id LEFT JOIN construction_vendor_contacts vc ON vc.id=CAST(REPLACE(pc.project_contact_name,'contact_id:','') AS UNSIGNED) WHERE pc.construction_project_id=? ORDER BY COALESCE(NULLIF(pc.trade_role,''),c.primary_trade,'') ASC,c.company_name ASC");
$s->execute([$projectId]);$rows=$s->fetchAll();
$contacts=[];foreach($rows as $r){$q=db()->prepare('SELECT * FROM construction_vendor_contacts WHERE construction_company_id=? AND is_active=1 ORDER BY is_primary DESC,name');$q->execute([$r['construction_company_id']]);$contacts[(int)$r['construction_company_id']]=$q->fetchAll();}
?>
<div class="page-head project-page-actions"><div><h1>Project Team</h1></div><div class="actions no-top"><a class="btn btn-primary" href="messages.php?project_id=<?=$projectId?>&all=1">Message All</a><button class="btn btn-secondary" type="button" data-open-modal="assign-modal">Assign Project Team</button></div></div>
<?php if(isset($_GET['saved'])):?><div class="card notice-success">Project Team assignment saved.</div><?php endif;?>
<?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>

<div class="project-team-cards">
<?php foreach($rows as $r):
    $contact=$r['selected_contact']?:$r['primary_contact'];
    $phone=$r['selected_phone']?:($r['cell_phone']?:$r['office_phone']);
    $email=$r['selected_email']?:$r['email'];
?>
<article class="project-team-card" data-details-modal="info-<?=$r['id']?>" tabindex="0" role="button" aria-label="Open details for <?=e($r['vendor_trade'].' '.$r['company_name'])?>">
  <div class="project-team-card-head"><h2><span><?=e(strtoupper($r['vendor_trade']?:'TRADE NOT ASSIGNED'))?></span><span class="project-team-divider">|</span><span><?=e(strtoupper($r['company_name']))?></span></h2></div>
  <div class="project-team-actions">
    <?php if($phone):?><a class="btn btn-primary" href="tel:<?=e(preg_replace('/\D+/','',$phone))?>">Call</a><?php else:?><span></span><?php endif;?>
    <?php if($email):?><a class="btn btn-secondary" href="mailto:<?=e($email)?>">Email</a><?php endif;?>
  </div>
</article>
<?php endforeach;?>
</div>

<?php foreach($rows as $r):?>
<div class="dev-modal" id="info-<?=$r['id']?>" hidden><div class="dev-modal-panel vendor-info-modal"><button class="modal-close" type="button" data-close-modal aria-label="Close">×</button>
<h2><?=e($r['vendor_trade'].' | '.$r['company_name'])?></h2>
<div class="detail-grid project-team-detail-grid">
  <div><strong>Company</strong><p><?=e($r['company_name'])?></p></div>
  <div><strong>Trade</strong><p><?=e($r['vendor_trade']?:'—')?></p></div>
  <div><strong>Primary Contact</strong><p><?=e($r['selected_contact']?:$r['primary_contact']?:'—')?></p></div>
  <div><strong>Cell Phone</strong><p><?=e($r['selected_phone']?:$r['cell_phone']?:'—')?></p></div>
  <div><strong>Office Phone</strong><p><?=e($r['office_phone']?:'—')?></p></div>
  <div><strong>Email</strong><p><?php if($r['selected_email']?:$r['email']):?><a href="mailto:<?=e($r['selected_email']?:$r['email'])?>"><?=e($r['selected_email']?:$r['email'])?></a><?php else:?>—<?php endif;?></p></div>
  <div><strong>Address</strong><p><?=nl2br(e($r['address']?:'—'))?></p></div>
  <div><strong>Website</strong><p><?=e($r['website']?:'—')?></p></div>
  <div><strong>License Number</strong><p><?=e($r['license_number']?:'—')?></p></div>
  <div><strong>Insurance Expiration</strong><p><?=e($r['insurance_expiration']?:'—')?></p></div>
  <div><strong>W-9 On File</strong><p><?=$r['w9_on_file']?'Yes':'No'?></p></div>
  <div><strong>Status</strong><p><?=e($r['contract_status']?:'Active')?></p></div>
</div>
<h3>Contacts</h3><div class="team-contact-list"><?php if(empty($contacts[(int)$r['construction_company_id']])):?><p class="muted">No additional contacts.</p><?php else:?><?php foreach($contacts[(int)$r['construction_company_id']] as $c):?><div class="team-contact-row"><strong><?=e($c['name'])?></strong><span><?=e($c['title']?:'—')?></span><?php if($c['phone']):?><a href="tel:<?=e(preg_replace('/\D+/','',$c['phone']))?>"><?=e($c['phone'])?></a><?php endif;?><?php if($c['email']):?><a href="mailto:<?=e($c['email'])?>"><?=e($c['email'])?></a><?php endif;?></div><?php endforeach;?><?php endif;?></div>
<h3>Vendor Notes</h3><p><?=nl2br(e($r['vendor_notes']?:'No vendor notes.'))?></p>
<h3>Change Assignment</h3>
<form method="post" class="form-grid assignment-edit-form">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="update_assignment"><input type="hidden" name="assignment_id" value="<?=$r['id']?>">
<div class="form-full"><label>Trade — Company</label><select name="vendor_trade_choice" class="vendor-trade-choice" required><?php foreach($companies as $c):?><option value="<?=$c['id']?>:<?=$c['trade_id']?>" data-company="<?=e($c['company_name'])?>" data-contact="<?=e($c['display_contact'])?>" data-phone="<?=e($c['display_phone'])?>" data-email="<?=e($c['display_email'])?>" <?=$c['id']==$r['construction_company_id'] && strcasecmp($c['trade_name'],$r['vendor_trade'])===0?'selected':''?>><?=e($c['trade_name'].' — '.$c['company_name'])?></option><?php endforeach;?></select></div>
<div><label>Company</label><input class="assign-company-display" type="text" readonly></div><div><label>Contact</label><input class="assign-contact-display" type="text" readonly></div><div><label>Phone</label><input class="assign-phone-display" type="text" readonly></div><div><label>Email</label><input class="assign-email-display" type="text" readonly></div>
<div class="form-full"><label>Notes</label><textarea name="notes"><?=e($r['notes'])?></textarea></div><div class="form-full"><button class="primary">Save Assignment</button></div>
</form>
<div class="actions project-team-detail-actions"><a class="btn btn-secondary" href="vendor_profile.php?id=<?=$r['construction_company_id']?>">Vendor Profile</a><a class="btn btn-danger" data-confirm="Remove this Project Team assignment?" href="project_team.php?project_id=<?=$projectId?>&remove=<?=$r['id']?>">Remove From Project</a></div>
</div></div>
<?php endforeach;?>

<div class="dev-modal" id="assign-modal" hidden><div class="dev-modal-panel"><button class="modal-close" type="button" data-close-modal aria-label="Close">×</button><h2>Assign Project Team</h2><form method="post" class="form-grid assignment-edit-form" id="assign-project-team-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="assign"><div class="form-full"><label>Trade — Company</label><select name="vendor_trade_choice" class="vendor-trade-choice" required><option value="">Choose a trade and company...</option><?php foreach($companies as $c):?><option value="<?=$c['id']?>:<?=$c['trade_id']?>" data-company="<?=e($c['company_name'])?>" data-contact="<?=e($c['display_contact'])?>" data-phone="<?=e($c['display_phone'])?>" data-email="<?=e($c['display_email'])?>"><?=e($c['trade_name'].' — '.$c['company_name'])?></option><?php endforeach;?></select></div><div><label>Company</label><input class="assign-company-display" type="text" readonly></div><div><label>Contact</label><input class="assign-contact-display" type="text" readonly></div><div><label>Phone</label><input class="assign-phone-display" type="text" readonly></div><div><label>Email</label><input class="assign-email-display" type="text" readonly></div><div class="form-full"><label>Notes <span class="muted">(optional)</span></label><textarea name="notes"></textarea></div><div class="form-full"><button class="primary">Assign Project Team</button></div></form></div></div>
<script>
(function(){
  function populate(form){var choice=form.querySelector('.vendor-trade-choice'),o=choice&&choice.options[choice.selectedIndex];[['.assign-company-display','company'],['.assign-contact-display','contact'],['.assign-phone-display','phone'],['.assign-email-display','email']].forEach(function(x){var el=form.querySelector(x[0]);if(el)el.value=o&&o.value?(o.dataset[x[1]]||''):'';});}
  document.querySelectorAll('.assignment-edit-form').forEach(function(form){var choice=form.querySelector('.vendor-trade-choice');if(choice){choice.addEventListener('change',function(){populate(form);});populate(form);}});
  function closeModal(modal){if(!modal)return;modal.classList.remove('is-open');modal.hidden=true;if(window.location.hash==='#'+modal.id){history.replaceState(null,'',window.location.pathname+window.location.search);}if(!document.querySelector('.dev-modal.is-open'))document.body.classList.remove('modal-open');}
  document.querySelectorAll('[data-open-modal]').forEach(function(opener){opener.addEventListener('click',function(e){var modal=document.getElementById(opener.getAttribute('data-open-modal'));if(!modal)return;e.preventDefault();modal.hidden=false;modal.classList.add('is-open');document.body.classList.add('modal-open');});});
  document.querySelectorAll('[data-details-modal]').forEach(function(card){function open(e){if(e.target.closest('a,button,input,select,textarea,label'))return;var modal=document.getElementById(card.dataset.detailsModal);if(modal){modal.hidden=false;modal.classList.add('is-open');document.body.classList.add('modal-open');var close=modal.querySelector('[data-close-modal]');if(close)close.focus();}}card.addEventListener('click',open);card.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();open(e);}});});
  document.querySelectorAll('.dev-modal [data-close-modal]').forEach(function(close){close.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();closeModal(close.closest('.dev-modal'));});});
  document.querySelectorAll('.dev-modal').forEach(function(modal){modal.addEventListener('click',function(e){if(e.target===modal)closeModal(modal);});});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'){var modal=document.querySelector('.dev-modal.is-open');if(modal)closeModal(modal);}});
  var assignForm=document.getElementById('assign-project-team-form');
  if(assignForm){assignForm.addEventListener('submit',function(){var modal=assignForm.closest('.dev-modal');var submit=assignForm.querySelector('button[type=submit],button:not([type])');if(submit){submit.disabled=true;submit.textContent='Assigning...';}closeModal(modal);});}
})();
</script>
<?php require __DIR__.'/includes/footer.php';?>
