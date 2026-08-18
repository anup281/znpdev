<?php
require_once __DIR__.'/includes/bootstrap.php';

$projectId=dev_active_project_id((int)($_GET['project_id']??$_POST['project_id']??0));
$p=dev_require_project($projectId);
$error='';
$success='';
$permitsReady=false;
try{$permitsReady=db_table_exists('construction_project_permits');}catch(Throwable $e){error_log('Construction permits table check failed: '.$e->getMessage());}

if(isset($_GET['uploaded']) && $_GET['uploaded']==='1'){
    $success='Project document uploaded successfully.';
}
if(isset($_GET['deleted']) && $_GET['deleted']==='1'){
    $success='Project document deleted successfully.';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $uploadedPath=null;
    try{
        if(empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH']??0)>0){
            throw new RuntimeException('The upload was rejected before it reached the application. The server upload limit must be at least 210 MB.');
        }
        if(!csrf_check((string)($_POST['csrf']??''))){
            throw new RuntimeException('Session expired. Please refresh the page and try again.');
        }

        $action=(string)($_POST['action']??'upload');
        if($action==='add_permits'){
            if(!dev_is_super())throw new RuntimeException('Only a Super Admin may add permits.');
            if(!$permitsReady)throw new RuntimeException('The permits database table is not installed.');
            $numbers=(array)($_POST['permit_number']??[]);$types=(array)($_POST['permit_type']??[]);$permitsToAdd=[];
            foreach($numbers as $index=>$number){$number=trim((string)$number);$type=trim((string)($types[$index]??''));if($number===''&&$type==='')continue;if($number===''||$type==='')throw new RuntimeException('Enter both the Permit Number and Type of Permit for every row.');if(strlen($number)>190||strlen($type)>190)throw new RuntimeException('Permit values may not exceed 190 characters.');$permitsToAdd[]=[$number,$type];}
            if(!$permitsToAdd)throw new RuntimeException('Add at least one permit.');
            db()->beginTransaction();$insert=db()->prepare('INSERT INTO construction_project_permits(construction_project_id,permit_number,permit_type,created_by_admin_user_id,created_at,updated_at) VALUES(?,?,?,?,NOW(),NOW())');
            foreach($permitsToAdd as [$number,$type]){$insert->execute([$projectId,$number,$type,$user['id']??null]);$permitId=(int)db()->lastInsertId();try{dev_activity($projectId,'permit_added','Permit added: '.$type.' — '.$number,'permit',$permitId);}catch(Throwable $activityError){error_log('Permit activity log failed: '.$activityError->getMessage());}}
            db()->commit();header('Location: documents.php?project_id='.$projectId.'&permits_saved=1#permits');exit;
        }elseif($action==='delete_permit'){
            if(!dev_is_super())throw new RuntimeException('Only a Super Admin may delete permits.');
            if(!$permitsReady)throw new RuntimeException('The permits database table is not installed.');
            $permitId=(int)($_POST['permit_id']??0);$delete=db()->prepare('DELETE FROM construction_project_permits WHERE id=? AND construction_project_id=?');$delete->execute([$permitId,$projectId]);
            if(!$delete->rowCount())throw new RuntimeException('The selected permit was not found.');
            header('Location: documents.php?project_id='.$projectId.'&permit_deleted=1#permits');exit;
        }elseif($action==='delete'){
            if(!dev_is_super())throw new RuntimeException('Only a Super Admin may delete project documents.');
            $documentId=(int)($_POST['document_id']??$_GET['document_id']??0);
            if($documentId<1)throw new RuntimeException('Select a valid document to delete.');
            $q=db()->prepare('SELECT id,document_name,file_path FROM construction_documents WHERE id=? AND construction_project_id=? AND archived_at IS NULL');
            $q->execute([$documentId,$projectId]);
            $document=$q->fetch();
            if(!$document)throw new RuntimeException('The selected document could not be found in this project.');
            db()->beginTransaction();
            db()->prepare('DELETE FROM construction_document_share_items WHERE document_id=?')->execute([$documentId]);
            db()->prepare('DELETE FROM construction_documents WHERE id=? AND construction_project_id=?')->execute([$documentId,$projectId]);
            db()->commit();
            try{
                znp_storage_delete((string)$document['file_path']);
            }catch(Throwable $storageError){
                error_log('Deleted project document storage cleanup failed: '.$storageError->getMessage());
                throw new RuntimeException('The document record was deleted, but its S4 object could not be removed. Check the server error log.');
            }
            try{dev_activity($projectId,'document_deleted','Project document deleted: '.$document['document_name'],'document',$documentId);}catch(Throwable $activityError){error_log('Document activity log failed: '.$activityError->getMessage());}
            header('Location: documents.php?project_id='.$projectId.'&deleted=1');
            exit;
        }elseif($action==='share'){
            $ids=array_values(array_filter(array_map('intval',(array)($_POST['document_ids']??[]))));
            if(!$ids)throw new RuntimeException('Select at least one document.');
            $teamEmail=trim((string)($_POST['team_recipient_email']??''));
            $manualEmail=trim((string)($_POST['recipient_email']??''));
            $email=filter_var($manualEmail!==''?$manualEmail:$teamEmail,FILTER_VALIDATE_EMAIL);
            if(!$email)throw new RuntimeException('Choose a Project Team contact or enter a valid email address.');
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $args=array_merge([$projectId],$ids);
            $q=db()->prepare("SELECT * FROM construction_documents WHERE construction_project_id=? AND id IN ($ph) AND archived_at IS NULL");
            $q->execute($args);
            $docs=$q->fetchAll();
            if(count($docs)!==count($ids))throw new RuntimeException('One or more selected documents could not be found.');
            $total=array_sum(array_map(fn($d)=>(int)$d['file_size'],$docs));
            $token=bin2hex(random_bytes(32));
            $expiresAt=new DateTimeImmutable('+7 days');
            $expires=$expiresAt->format('Y-m-d H:i:s');
            $messageText=trim((string)($_POST['message_text']??''));
            if($messageText==='')$messageText='Attached are the files requested for '.$p['project_name'].'.';
            db()->prepare('INSERT INTO construction_document_shares(construction_project_id,token,recipient_email,message_text,expires_at,created_by_admin_user_id,created_at) VALUES(?,?,?,?,?,?,NOW())')->execute([$projectId,$token,$email,$messageText,$expires,$user['id']]);
            $shareId=(int)db()->lastInsertId();
            $ins=db()->prepare('INSERT INTO construction_document_share_items(share_id,document_id) VALUES(?,?)');
            foreach($ids as $id)$ins->execute([$shareId,$id]);
            $base=((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http').'://'.($_SERVER['HTTP_HOST']??'').rtrim(dirname($_SERVER['SCRIPT_NAME']),'/');
            $link=$base.'/share.php?token='.$token;
            $subject=$p['project_name'].' — Shared Project Documents';
            $encodedSubject='=?UTF-8?B?'.base64_encode($subject).'?=';
            $body="Project: {$p['project_name']}\n\n".$messageText."\n\n";
            $body.="Download the shared files securely here:\n$link";
            $body.="\n\nThis file link will not be accessible after ".$expiresAt->format('F j, Y').'.';
            $headers="From: ZNP Construction <noreply@".($_SERVER['HTTP_HOST']??'localhost').">\r\n";
            $headers.="MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
            if(!@mail($email,$encodedSubject,$body,$headers))throw new RuntimeException('The share was created, but the server could not send the email. Copy this link: '.$link);
            dev_activity($projectId,'documents_shared','Project documents emailed to '.$email,'document_share',$shareId);
            $success='Documents emailed successfully by secure link. Total file size: '.dev_filesize($total).'.';
        }else{
            if(!dev_is_super())throw new RuntimeException('Only a Super Admin may upload project documents.');
            if(!isset($_FILES['document']) || !is_array($_FILES['document'])){
                throw new RuntimeException('Choose a document to upload.');
            }
            $up=dev_upload($_FILES['document'],'documents',['pdf','doc','docx','xls','xlsx','ppt','pptx','dwg','zip','jpg','jpeg','png'],209715200);
            if(!$up)throw new RuntimeException('Choose a document to upload.');
            $uploadedPath=(string)$up['path'];
            znp_storage_put_file($uploadedPath,znp_storage_local_path($uploadedPath),(string)$up['mime']);

            $s=db()->prepare('INSERT INTO construction_documents(construction_project_id,admin_user_id,folder_name,document_name,description,file_path,original_name,mime_type,file_size,revision,document_date,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $s->execute([
                $projectId,
                $user['id'],
                'Other',
                trim((string)($_POST['document_name']??''))?:$up['name'],
                '',
                $up['path'],
                $up['name'],
                $up['mime'],
                $up['size'],
                '',
                null,
                'Current'
            ]);
            $id=(int)db()->lastInsertId();
            $savedPath=$uploadedPath;
            $uploadedPath=null;
            if(znp_storage_uses_s4()){
                try{znp_storage_remove_local($savedPath);}catch(Throwable $cleanupError){error_log('Project document local cleanup failed: '.$cleanupError->getMessage());}
            }
            try{
                dev_activity($projectId,'document_uploaded','Project document uploaded: '.$up['name'],'document',$id);
            }catch(Throwable $activityError){
                error_log('Document activity log failed: '.$activityError->getMessage());
            }
            header('Location: documents.php?project_id='.$projectId.'&uploaded=1');
            exit;
        }
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        if($uploadedPath){
            try{znp_storage_delete($uploadedPath);}catch(Throwable $cleanupError){error_log('Project document cleanup failed: '.$cleanupError->getMessage());}
        }
        error_log('Project document action failed for project '.$projectId.': '.$e->getMessage());
        $error=$e->getMessage()?:'The document could not be processed. Please try again.';
    }
}

$devBodyClass='dev-documents-page';
require __DIR__.'/includes/header.php';

$teamContacts=[];$seenEmails=[];
try{
    $tc=db()->prepare("SELECT pc.trade_role,c.company_name,c.email company_email,c.primary_contact,c.cell_phone,c.office_phone,vc.name contact_name,vc.email contact_email,vc.is_primary FROM construction_project_companies pc JOIN construction_companies c ON c.id=pc.construction_company_id LEFT JOIN construction_vendor_contacts vc ON vc.construction_company_id=c.id AND vc.is_active=1 WHERE pc.construction_project_id=? ORDER BY pc.trade_role,c.company_name,vc.is_primary DESC,vc.name");
    $tc->execute([$projectId]);
    foreach($tc->fetchAll() as $row){
        $email=trim((string)($row['contact_email']?:$row['company_email']));if($email===''||isset($seenEmails[strtolower($email)]))continue;
        $seenEmails[strtolower($email)]=true;$name=trim((string)($row['contact_name']?:$row['primary_contact']?:$row['company_name']));
        $teamContacts[]=['trade'=>$row['trade_role'],'company'=>$row['company_name'],'name'=>$name,'email'=>$email];
    }
}catch(Throwable $e){error_log('Construction document team-contact lookup failed: '.$e->getMessage());}
$permits=[];if($permitsReady){$permitStatement=db()->prepare('SELECT * FROM construction_project_permits WHERE construction_project_id=? ORDER BY id DESC');$permitStatement->execute([$projectId]);$permits=$permitStatement->fetchAll();}
if(isset($_GET['permits_saved']))$success='Permit information saved successfully.';elseif(isset($_GET['permit_deleted']))$success='Permit deleted successfully.';
$s=db()->prepare('SELECT d.*,au.full_name FROM construction_documents d LEFT JOIN admin_users au ON au.id=d.admin_user_id WHERE d.construction_project_id=? AND d.archived_at IS NULL ORDER BY d.folder_name,d.id DESC');$s->execute([$projectId]);$rows=$s->fetchAll();?><div class="page-head"><div><h1>Files &amp; Permits</h1><p class="muted"><?=e($p['project_name'])?> · manage files and permit numbers</p></div><?php if(dev_is_super()):?><button class="btn btn-primary" data-open-modal="upload-modal">Upload File</button><?php endif;?></div><?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?><?php if($success):?><div class="card notice-success"><?=e($success)?></div><?php endif;?><form method="post" class="card"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="share"><div class="form-grid"><div><label>Project Team Contact</label><select name="team_recipient_email"><option value="">Choose a project contact...</option><?php $lastTrade=null;foreach($teamContacts as $tc):if($lastTrade!==$tc['trade']):if($lastTrade!==null):?></optgroup><?php endif;?><optgroup label="<?=e($tc['trade'])?>"><?php $lastTrade=$tc['trade'];endif;?><option value="<?=e($tc['email'])?>"><?=e($tc['name'].' — '.$tc['company'].' — '.$tc['email'])?></option><?php endforeach;if($lastTrade!==null):?></optgroup><?php endif;?></select></div><div><label>Or Enter Email Address</label><input type="email" name="recipient_email" placeholder="name@example.com"><small class="muted">A manually entered email overrides the selected Project Team contact.</small></div><div class="form-full"><label>Message</label><input name="message_text" placeholder="Attached are the latest project plans."></div><div class="form-full"><button class="primary">Email Selected</button></div></div><div class="table-scroll znp-mt-5"><table><thead><tr><th></th><th>Folder</th><th>Document</th><th>Revision</th><th>Size</th><th>Uploaded</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><input type="checkbox" name="document_ids[]" value="<?=$r['id']?>" class="znp-checkbox"></td><td><?=e($r['folder_name'])?></td><td><strong><?=e($r['document_name'])?></strong><br><span class="muted"><?=e($r['original_name'])?></span></td><td><?=e($r['revision']?:'—')?></td><td><?=e(dev_filesize((int)$r['file_size']))?></td><td><?=e(dev_datetime($r['created_at']))?></td><td><div class="actions znp-no-top"><a class="btn btn-secondary btn-small" href="file.php?id=<?=$r['id']?>">Download</a><?php if(dev_is_super()):?><button type="submit" class="btn btn-danger btn-small" name="action" value="delete" formaction="documents.php?project_id=<?=$projectId?>&amp;document_id=<?=$r['id']?>" formnovalidate onclick="return confirm('Delete this document? It will be removed from the active document list.');">Delete</button><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div></form><?php if(dev_is_super()):?><div class="dev-modal" id="upload-modal" hidden><div class="dev-modal-panel"><button type="button" class="modal-close" data-close-modal>×</button><h2>Upload Project File</h2><form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="upload"><div><label>Category</label><select name="folder_name"><option>Architectural Plans</option><option>Civil Plans</option><option>Structural Plans</option><option>MEP Plans</option><option>Specifications</option><option>Contracts</option><option>Permits</option><option>Inspections</option><option>Change Orders</option><option>Reports</option><option>Submittals</option><option>Other</option></select></div><div><label>Document Name</label><input name="document_name"></div><div><label>Revision</label><input name="revision"></div><div><label>Document Date</label><input type="date" name="document_date"></div><div class="form-full"><label>Description</label><textarea name="description"></textarea></div><div class="form-full"><label>File (up to 200 MB)</label><input type="file" name="document" required></div><div class="form-full"><button class="primary">Upload Document</button></div></form></div></div><?php endif;?>
<section class="card permit-section" id="permits">
  <div class="section-heading"><div><h2>Permits</h2><p class="muted">Permit numbers assigned to this job.</p></div></div>
  <?php if(!$permitsReady):?>
    <div class="notice-warning">Run the <a href="<?=e(app_url('/admin/upgrade_files_permits_partner_access.php'))?>">Files, Permits &amp; Partner Access installer</a> to enable project permits.</div>
  <?php else:?>
    <?php if($permits):?><div class="table-scroll permit-table"><table><thead><tr><th>Permit Number</th><th>Type of Permit</th><?php if(dev_is_super()):?><th></th><?php endif;?></tr></thead><tbody><?php foreach($permits as $permit):?><tr><td><?=e($permit['permit_number'])?></td><td><?=e($permit['permit_type'])?></td><?php if(dev_is_super()):?><td><form method="post" onsubmit="return confirm('Delete this permit?');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="delete_permit"><input type="hidden" name="permit_id" value="<?=(int)$permit['id']?>"><button class="btn btn-danger btn-small" type="submit">Delete</button></form></td><?php endif;?></tr><?php endforeach;?></tbody></table></div><?php else:?><p class="muted">No permits have been added to this job.</p><?php endif;?>
    <?php if(dev_is_super()):?><form method="post" class="permit-entry-form" data-permit-form><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="add_permits"><div data-permit-rows><div class="permit-entry-row"><div><label>Permit Number</label><input type="text" name="permit_number[]" maxlength="190" required></div><div><label>Type of Permit</label><input type="text" name="permit_type[]" maxlength="190" required></div><button class="btn btn-secondary btn-small permit-remove" type="button" data-remove-permit hidden>Remove</button></div></div><div class="actions"><button class="btn btn-secondary" type="button" data-add-permit>Add Another Permit</button><button class="primary" type="submit">Save Permits</button></div></form><?php endif;?>
  <?php endif;?>
</section>
<?php if($permitsReady&&dev_is_super()):?><script>
document.addEventListener('DOMContentLoaded',function(){var form=document.querySelector('[data-permit-form]');if(!form)return;var rows=form.querySelector('[data-permit-rows]');form.querySelector('[data-add-permit]').addEventListener('click',function(){var row=rows.firstElementChild.cloneNode(true);row.querySelectorAll('input').forEach(function(input){input.value='';});var remove=row.querySelector('[data-remove-permit]');remove.hidden=false;rows.appendChild(row);remove.focus();});rows.addEventListener('click',function(event){var button=event.target.closest('[data-remove-permit]');if(button&&rows.children.length>1)button.closest('.permit-entry-row').remove();});});
</script><?php endif;?>
<?php require __DIR__.'/includes/footer.php';?>
