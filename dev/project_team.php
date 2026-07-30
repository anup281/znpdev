<?php
require_once __DIR__.'/includes/bootstrap.php';
$projectId=dev_active_project_id((int)($_GET['project_id']??$_POST['project_id']??0));
$p=dev_require_project($projectId);
$error='';
$projectContactsReady=false;
function project_team_phone_format(string $phone): string {
    $digits=preg_replace('/\D+/','',$phone)??'';
    if(strlen($digits)===11&&$digits[0]==='1')$digits=substr($digits,1);
    return strlen($digits)===10?substr($digits,0,3).'-'.substr($digits,3,3).'-'.substr($digits,6):$phone;
}
try{$q=db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='construction_project_contacts'");$q->execute();$projectContactsReady=(int)$q->fetchColumn()>0;}catch(Throwable $exception){error_log('Project contacts schema check failed: '.$exception->getMessage());}

if($_SERVER['REQUEST_METHOD']==='POST')try{
    if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Session expired.');
    $action=(string)($_POST['action']??'assign');
    if($action==='remove_assignment'){
        $assignmentId=(int)($_POST['assignment_id']??0);
        if($assignmentId<1)throw new RuntimeException('Project Team assignment was not found.');
        $q=db()->prepare('DELETE FROM construction_project_companies WHERE id=? AND construction_project_id=?');$q->execute([$assignmentId,$projectId]);
        if(!$q->rowCount())throw new RuntimeException('Project Team assignment was not found.');
        header("Location: project_team.php?project_id=$projectId&removed=1");exit;
    }
    if(in_array($action,['add_project_contact','remove_project_contact'],true)){
        if(!dev_is_super())throw new RuntimeException('Only an Admin or Super Admin may manage project contacts.');
        if(!$projectContactsReady)throw new RuntimeException('Project contacts are unavailable because the required database table is missing. Contact the system administrator.');
        if($action==='remove_project_contact'){
            $contactId=(int)($_POST['contact_id']??0);
            $q=db()->prepare('UPDATE construction_project_contacts SET is_active=0,updated_at=NOW() WHERE id=? AND construction_project_id=? AND is_active=1');$q->execute([$contactId,$projectId]);
            if(!$q->rowCount())throw new RuntimeException('Project contact was not found.');
            try{dev_activity($projectId,'project_contact_removed','Non-vendor project contact removed.','project_contact',$contactId);}catch(Throwable $exception){error_log('Project contact removal activity log failed: '.$exception->getMessage());}
            header("Location: project_team.php?project_id=$projectId&contact_removed=1");exit;
        }
        $role=trim((string)($_POST['role_title']??''));$organization=trim((string)($_POST['organization_name']??''));$name=trim((string)($_POST['contact_name']??''));$phone=trim((string)($_POST['phone']??''));$email=trim((string)($_POST['email']??''));$address=trim((string)($_POST['address']??''));$notes=trim((string)($_POST['notes']??''));
        if($role==='')throw new RuntimeException('Project role is required.');
        if($name===''&&$organization==='')throw new RuntimeException('Enter a contact name or organization.');
        if($phone!==''){$phoneDigits=preg_replace('/\D+/','',$phone)??'';if(strlen($phoneDigits)===11&&$phoneDigits[0]==='1')$phoneDigits=substr($phoneDigits,1);if(strlen($phoneDigits)!==10)throw new RuntimeException('Phone number must contain 10 digits.');$phone=project_team_phone_format($phoneDigits);}
        if(strlen($role)>150||strlen($organization)>190||strlen($name)>190||strlen($phone)>50)throw new RuntimeException('One or more project contact fields are too long.');
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
        $q=db()->prepare('INSERT INTO construction_project_contacts(construction_project_id,role_title,organization_name,contact_name,phone,email,address,notes,is_active,created_by_admin_user_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,1,?,NOW(),NOW())');$q->execute([$projectId,$role,$organization,$name,$phone,$email,$address,$notes,$user['id']??null]);$contactId=(int)db()->lastInsertId();
        try{dev_activity($projectId,'project_contact_added','Project contact added: '.$role.' — '.($organization?:$name),'project_contact',$contactId);}catch(Throwable $exception){error_log('Project contact creation activity log failed: '.$exception->getMessage());}
        header("Location: project_team.php?project_id=$projectId&contact_saved=1");exit;
    }
    $choice=trim((string)($_POST['vendor_trade_choice']??''));
    $parts=explode(':',$choice,2);
    $cid=(int)($parts[0]??0);$tradeId=(int)($parts[1]??0);
    if(!$cid || !$tradeId)throw new RuntimeException('Company and trade are required.');

    $tradeStmt=db()->prepare("SELECT t.trade_name FROM construction_company_trades ct JOIN construction_trades t ON t.id=ct.construction_trade_id AND t.is_active=1 WHERE ct.construction_company_id=? AND ct.construction_trade_id=? AND ct.archived_at IS NULL LIMIT 1");
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
$companies=db()->query("SELECT c.id,c.company_name,c.primary_contact,c.cell_phone,c.office_phone,c.email,t.id trade_id,t.trade_name FROM construction_companies c JOIN construction_company_trades ct ON ct.construction_company_id=c.id AND ct.archived_at IS NULL JOIN construction_trades t ON t.id=ct.construction_trade_id AND t.is_active=1 WHERE c.is_active=1 ORDER BY t.trade_name ASC,c.company_name ASC")->fetchAll();
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
$projectContacts=[];
if($projectContactsReady){$q=db()->prepare('SELECT * FROM construction_project_contacts WHERE construction_project_id=? AND is_active=1 ORDER BY role_title,organization_name,contact_name,id');$q->execute([$projectId]);$projectContacts=$q->fetchAll();}
$contacts=[];foreach($rows as $r){$q=db()->prepare('SELECT * FROM construction_vendor_contacts WHERE construction_company_id=? AND is_active=1 ORDER BY is_primary DESC,name');$q->execute([$r['construction_company_id']]);$contacts[(int)$r['construction_company_id']]=$q->fetchAll();}
?>
<style>.project-team-area-picker{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:22px}.project-team-area-button{min-height:92px;justify-content:flex-start;text-align:left;padding:20px;border:2px solid var(--line);background:#fff;color:var(--navy);font-size:17px}.project-team-area-button span{display:block}.project-team-area-button small{display:block;margin-top:5px;color:var(--muted);font-weight:500}.project-team-area-button.is-active{border-color:var(--blue);background:var(--pale);box-shadow:inset 4px 0 0 var(--blue)}.project-team-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin:4px 0 18px}.project-team-section-head h2{margin:0 0 5px}.project-team-section[hidden]{display:none}@media(max-width:700px){.project-team-area-picker{grid-template-columns:1fr}.project-team-section-head{align-items:stretch;flex-direction:column}.project-team-section-head .actions{display:grid}.project-team-section-head .btn,.project-team-section-head button{width:100%}}</style>
<div class="page-head project-page-actions"><div><h1>Project Team</h1><p class="muted">Choose the type of project team member you want to view.</p></div></div>
<?php if(isset($_GET['saved'])):?><div class="card notice-success">Project Team assignment saved.</div><?php endif;?>
<?php if(isset($_GET['removed'])):?><div class="card notice-success">Project Team assignment removed.</div><?php endif;?>
<?php if(isset($_GET['contact_saved'])):?><div class="card notice-success">Project contact added.</div><?php endif;?>
<?php if(isset($_GET['contact_removed'])):?><div class="card notice-success">Project contact removed.</div><?php endif;?>
<?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<?php if(dev_is_super()&&!$projectContactsReady):?><div class="card notice-warning">The project contacts database table is not installed in this environment.</div><?php endif;?>

<div class="project-team-area-picker" role="group" aria-label="Project Team areas"><button type="button" class="project-team-area-button" data-team-area="trades" aria-pressed="false"><span>TRADES<small>Vendors and assigned trade partners</small></span></button><button type="button" class="project-team-area-button" data-team-area="contacts" aria-pressed="false"><span>THIRD PARTY CONTACTS<small>Architects, engineers, agencies and inspectors</small></span></button></div>

<section class="project-team-section" data-team-panel="trades" hidden><div class="project-team-section-head"><div><h2>Trades</h2><p class="muted">Assigned project vendors organized by trade.</p></div><div class="actions no-top"><a class="btn btn-primary" href="messages.php?project_id=<?=$projectId?>&all=1">Message All</a><button class="btn btn-secondary" type="button" data-open-modal="assign-modal">Assign Project Team</button></div></div><div class="project-team-cards">
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
<?php if(!$rows):?><div class="card empty">No trades are assigned to this project.</div><?php endif;?>
</div></section>

<section class="project-team-section" data-team-panel="contacts" hidden><div class="project-team-section-head"><div><h2>Third Party Contacts</h2><p class="muted">Project professionals, government agencies and inspectors.</p></div><?php if(dev_is_super()&&$projectContactsReady):?><button class="btn btn-primary" type="button" data-open-modal="contact-modal">Add Contact</button><?php endif;?></div><div class="project-team-cards">
<?php foreach($projectContacts as $contact):$displayName=$contact['organization_name']?:$contact['contact_name'];?>
<article class="project-team-card" data-details-modal="contact-info-<?=$contact['id']?>" tabindex="0" role="button" aria-label="Open details for <?=e($contact['role_title'].' '.$displayName)?>">
  <div class="project-team-card-head"><h2><span><?=e(strtoupper($contact['role_title']))?></span><span class="project-team-divider">|</span><span><?=e(strtoupper($displayName))?></span></h2></div>
  <div class="project-team-actions"><?php if($contact['phone']):?><a class="btn btn-primary" href="tel:<?=e(preg_replace('/\D+/','',$contact['phone']))?>">Call</a><?php else:?><span></span><?php endif;?><?php if($contact['email']):?><a class="btn btn-secondary" href="mailto:<?=e($contact['email'])?>">Email</a><?php endif;?></div>
</article>
<?php endforeach;?>
<?php if(!$projectContacts):?><div class="card empty">No third party contacts have been added.</div><?php endif;?>
</div></section>

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
<div class="actions project-team-detail-actions"><a class="btn btn-secondary" href="vendor_profile.php?id=<?=$r['construction_company_id']?>">Vendor Profile</a><form method="post" onsubmit="return confirm('Remove this Project Team assignment?');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="remove_assignment"><input type="hidden" name="assignment_id" value="<?=$r['id']?>"><button class="btn btn-danger" type="submit">Remove From Project</button></form></div>
</div></div>
<?php endforeach;?>

<?php foreach($projectContacts as $contact):?>
<div class="dev-modal" id="contact-info-<?=$contact['id']?>" hidden><div class="dev-modal-panel vendor-info-modal"><button class="modal-close" type="button" data-close-modal aria-label="Close">×</button><h2><?=e($contact['role_title'].' | '.($contact['organization_name']?:$contact['contact_name']))?></h2><div class="detail-grid project-team-detail-grid"><div><strong>Role</strong><p><?=e($contact['role_title'])?></p></div><div><strong>Organization</strong><p><?=e($contact['organization_name']?:'—')?></p></div><div><strong>Contact</strong><p><?=e($contact['contact_name']?:'—')?></p></div><div><strong>Phone</strong><p><?php if($contact['phone']):?><a href="tel:<?=e(preg_replace('/\D+/','',$contact['phone']))?>"><?=e(project_team_phone_format((string)$contact['phone']))?></a><?php else:?>—<?php endif;?></p></div><div><strong>Email</strong><p><?php if($contact['email']):?><a href="mailto:<?=e($contact['email'])?>"><?=e($contact['email'])?></a><?php else:?>—<?php endif;?></p></div><div><strong>Address</strong><p><?=nl2br(e($contact['address']?:'—'))?></p></div></div><h3>Notes</h3><p><?=nl2br(e($contact['notes']?:'No notes.'))?></p><?php if(dev_is_super()):?><form method="post" onsubmit="return confirm('Remove this project contact?');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="remove_project_contact"><input type="hidden" name="contact_id" value="<?=$contact['id']?>"><button class="btn btn-danger" type="submit">Remove From Project</button></form><?php endif;?></div></div>
<?php endforeach;?>

<?php if(dev_is_super()&&$projectContactsReady):?><div class="dev-modal" id="contact-modal" hidden><div class="dev-modal-panel"><button class="modal-close" type="button" data-close-modal aria-label="Close">×</button><h2>Add Project Contact</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="add_project_contact"><div><label>Project Role</label><input name="role_title" list="project-contact-roles" maxlength="150" required placeholder="Project Architect"><datalist id="project-contact-roles"><option value="Project Architect"><option value="Project Engineer"><option value="Civil Engineer"><option value="Structural Engineer"><option value="Fire Marshal"><option value="City Inspector"><option value="Building Inspector"><option value="Owner Representative"><option value="Utility Representative"></datalist></div><div><label>Organization / Agency</label><input name="organization_name" maxlength="190" placeholder="City of ..."></div><div><label>Contact Name</label><input name="contact_name" maxlength="190"></div><div><label>Phone</label><input name="phone" type="tel" inputmode="numeric" maxlength="12" placeholder="XXX-XXX-XXXX" data-project-contact-phone></div><div><label>Email</label><input name="email" type="email" maxlength="190"></div><div><label>Address</label><input name="address"></div><div class="form-full"><label>Notes</label><textarea name="notes"></textarea></div><div class="form-full actions"><button type="button" class="btn btn-secondary" data-close-modal>Cancel</button><button type="submit" class="primary">Add Project Contact</button></div></form></div></div><?php endif;?>

<div class="dev-modal" id="assign-modal" hidden><div class="dev-modal-panel"><button class="modal-close" type="button" data-close-modal aria-label="Close">×</button><h2>Assign Project Team</h2><form method="post" class="form-grid assignment-edit-form" id="assign-project-team-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="assign"><div class="form-full"><label>Trade — Company</label><select name="vendor_trade_choice" class="vendor-trade-choice" required><option value="">Choose a trade and company...</option><?php foreach($companies as $c):?><option value="<?=$c['id']?>:<?=$c['trade_id']?>" data-company="<?=e($c['company_name'])?>" data-contact="<?=e($c['display_contact'])?>" data-phone="<?=e($c['display_phone'])?>" data-email="<?=e($c['display_email'])?>"><?=e($c['trade_name'].' — '.$c['company_name'])?></option><?php endforeach;?></select></div><div><label>Company</label><input class="assign-company-display" type="text" readonly></div><div><label>Contact</label><input class="assign-contact-display" type="text" readonly></div><div><label>Phone</label><input class="assign-phone-display" type="text" readonly></div><div><label>Email</label><input class="assign-email-display" type="text" readonly></div><div class="form-full"><label>Notes <span class="muted">(optional)</span></label><textarea name="notes"></textarea></div><div class="form-full"><button class="primary">Assign Project Team</button></div></form></div></div>
<script>
(function(){
  var areaButtons=document.querySelectorAll('[data-team-area]'),areaPanels=document.querySelectorAll('[data-team-panel]');
  function showArea(area){areaPanels.forEach(function(panel){panel.hidden=panel.dataset.teamPanel!==area;});areaButtons.forEach(function(button){var active=button.dataset.teamArea===area;button.classList.toggle('is-active',active);button.setAttribute('aria-pressed',active?'true':'false');});}
  areaButtons.forEach(function(button){button.addEventListener('click',function(){showArea(button.dataset.teamArea);});});
  <?php if(isset($_GET['contact_saved'])||isset($_GET['contact_removed'])):?>showArea('contacts');<?php elseif(isset($_GET['saved'])):?>showArea('trades');<?php endif;?>
  document.querySelectorAll('[data-project-contact-phone]').forEach(function(input){input.addEventListener('input',function(){var digits=input.value.replace(/\D/g,'').slice(0,10),parts=[];if(digits.length)parts.push(digits.slice(0,3));if(digits.length>3)parts.push(digits.slice(3,6));if(digits.length>6)parts.push(digits.slice(6,10));input.value=parts.join('-');});});
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
