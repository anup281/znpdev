<?php
require_once __DIR__.'/includes/bootstrap.php';

$projectId=dev_active_project_id((int)($_GET['project_id']??$_POST['project_id']??0));
$p=dev_require_project($projectId);
$error='';
$success='';

if(isset($_GET['uploaded']) && $_GET['uploaded']==='1'){
    $success='Project document uploaded successfully.';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $uploadedAbsolutePath=null;
    try{
        if(!csrf_check((string)($_POST['csrf']??''))){
            throw new RuntimeException('Session expired. Please refresh the page and try again.');
        }

        $action=(string)($_POST['action']??'upload');
        if($action==='share'){
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
            $expires=(new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
            db()->prepare('INSERT INTO construction_document_shares(construction_project_id,token,recipient_email,message_text,expires_at,created_by_admin_user_id,created_at) VALUES(?,?,?,?,?,?,NOW())')->execute([$projectId,$token,$email,trim((string)($_POST['message_text']??'')),$expires,$user['id']]);
            $shareId=(int)db()->lastInsertId();
            $ins=db()->prepare('INSERT INTO construction_document_share_items(share_id,document_id) VALUES(?,?)');
            foreach($ids as $id)$ins->execute([$shareId,$id]);
            $base=((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http').'://'.($_SERVER['HTTP_HOST']??'').rtrim(dirname($_SERVER['SCRIPT_NAME']),'/');
            $link=$base.'/share.php?token='.$token;
            $subject=$p['project_name'].' — Shared Project Documents';
            $body="Project: {$p['project_name']}\n\n".trim((string)($_POST['message_text']??''))."\n\n";
            if($total>10485760){
                $body.="The selected files exceed 10 MB. Download them securely here (link expires in 7 days):\n$link";
            }else{
                $body.="Download the shared files here:\n$link\n\nThe link expires in 7 days.";
            }
            $headers='From: ZNP Construction <noreply@'.($_SERVER['HTTP_HOST']??'localhost').'>';
            if(!@mail($email,$subject,$body,$headers))throw new RuntimeException('The share was created, but the server could not send the email. Copy this link: '.$link);
            dev_activity($projectId,'documents_shared','Project documents emailed to '.$email,'document_share',$shareId);
            $success='Documents emailed successfully. Files totaling '.dev_filesize($total).' were sent by secure link'.($total>10485760?' because they exceed 10 MB.':'.');
        }else{
            if(!isset($_FILES['document']) || !is_array($_FILES['document'])){
                throw new RuntimeException('Choose a document to upload.');
            }
            $up=dev_upload($_FILES['document'],'documents',['pdf','doc','docx','xls','xlsx','ppt','pptx','dwg','zip','jpg','jpeg','png'],52428800);
            if(!$up)throw new RuntimeException('Choose a document to upload.');
            $uploadedAbsolutePath=__DIR__.'/../'.$up['path'];

            $s=db()->prepare('INSERT INTO construction_documents(construction_project_id,admin_user_id,folder_name,document_name,description,file_path,original_name,mime_type,file_size,revision,document_date,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $s->execute([
                $projectId,
                $user['id'],
                trim((string)($_POST['folder_name']??'Other')),
                trim((string)($_POST['document_name']??''))?:$up['name'],
                trim((string)($_POST['description']??'')),
                $up['path'],
                $up['name'],
                $up['mime'],
                $up['size'],
                trim((string)($_POST['revision']??'')),
                !empty($_POST['document_date'])?(string)$_POST['document_date']:null,
                'Current'
            ]);
            $id=(int)db()->lastInsertId();
            try{
                dev_activity($projectId,'document_uploaded','Project document uploaded: '.$up['name'],'document',$id);
            }catch(Throwable $activityError){
                error_log('Document activity log failed: '.$activityError->getMessage());
            }
            header('Location: documents.php?project_id='.$projectId.'&uploaded=1');
            exit;
        }
    }catch(Throwable $e){
        if($uploadedAbsolutePath && is_file($uploadedAbsolutePath)){
            @unlink($uploadedAbsolutePath);
        }
        error_log('Project document action failed for project '.$projectId.': '.$e->getMessage());
        $error=$e->getMessage()?:'The document could not be processed. Please try again.';
    }
}

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
}catch(Throwable $e){}
$s=db()->prepare('SELECT d.*,au.full_name FROM construction_documents d LEFT JOIN admin_users au ON au.id=d.admin_user_id WHERE d.construction_project_id=? AND d.archived_at IS NULL ORDER BY d.folder_name,d.id DESC');$s->execute([$projectId]);$rows=$s->fetchAll();?><div class="page-head"><div><h1>Plans & Documents</h1><p class="muted"><?=e($p['project_name'])?> · email selected files securely</p></div><button class="btn btn-primary" data-open-modal="upload-modal">Upload File</button></div><?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?><?php if($success):?><div class="card notice-success"><?=e($success)?></div><?php endif;?><form method="post" class="card"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="share"><div class="form-grid"><div><label>Project Team Contact</label><select name="team_recipient_email"><option value="">Choose a project contact...</option><?php $lastTrade=null;foreach($teamContacts as $tc):if($lastTrade!==$tc['trade']):if($lastTrade!==null):?></optgroup><?php endif;?><optgroup label="<?=e($tc['trade'])?>"><?php $lastTrade=$tc['trade'];endif;?><option value="<?=e($tc['email'])?>"><?=e($tc['name'].' — '.$tc['company'].' — '.$tc['email'])?></option><?php endforeach;if($lastTrade!==null):?></optgroup><?php endif;?></select></div><div><label>Or Enter Email Address</label><input type="email" name="recipient_email" placeholder="name@example.com"><small class="muted">A manually entered email overrides the selected Project Team contact.</small></div><div class="form-full"><label>Message</label><input name="message_text" placeholder="Attached are the latest project plans."></div><div class="form-full"><button class="primary">Email Selected</button> <span class="muted">Files are sent through a secure 7-day download link.</span></div></div><div class="table-scroll" style="margin-top:18px"><table><thead><tr><th></th><th>Folder</th><th>Document</th><th>Revision</th><th>Size</th><th>Uploaded</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><input type="checkbox" name="document_ids[]" value="<?=$r['id']?>" style="width:auto"></td><td><?=e($r['folder_name'])?></td><td><strong><?=e($r['document_name'])?></strong><br><span class="muted"><?=e($r['original_name'])?></span></td><td><?=e($r['revision']?:'—')?></td><td><?=e(dev_filesize((int)$r['file_size']))?></td><td><?=e(dev_datetime($r['created_at']))?></td><td><a class="btn btn-secondary btn-small" href="file.php?id=<?=$r['id']?>">Download</a></td></tr><?php endforeach;?></tbody></table></div></form><div class="dev-modal" id="upload-modal" hidden><div class="dev-modal-panel"><button class="modal-close" data-close-modal>×</button><h2>Upload Project File</h2><form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="upload"><div><label>Category</label><select name="folder_name"><option>Architectural Plans</option><option>Civil Plans</option><option>Structural Plans</option><option>MEP Plans</option><option>Specifications</option><option>Contracts</option><option>Permits</option><option>Inspections</option><option>Change Orders</option><option>Reports</option><option>Submittals</option><option>Other</option></select></div><div><label>Document Name</label><input name="document_name"></div><div><label>Revision</label><input name="revision"></div><div><label>Document Date</label><input type="date" name="document_date"></div><div class="form-full"><label>Description</label><textarea name="description"></textarea></div><div class="form-full"><label>File (up to 50 MB)</label><input type="file" name="document" required></div><div class="form-full"><button class="primary">Upload Document</button></div></form></div></div><?php require __DIR__.'/includes/footer.php';?>
