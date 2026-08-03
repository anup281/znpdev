<?php
require_once __DIR__.'/includes/bootstrap.php';$projectId=(int)($_GET['project_id']??$_POST['project_id']??0);$p=dev_require_project($projectId);$teamId=(int)($_GET['team_id']??$_POST['team_id']??0);$all=!empty($_GET['all'])||!empty($_POST['all']);$error='';$success='';$uploads=[];$messageSaved=false;
$teamStmt=db()->prepare("SELECT pc.*,c.company_name,c.email company_email,c.primary_contact,c.cell_phone,c.office_phone FROM construction_project_companies pc JOIN construction_companies c ON c.id=pc.construction_company_id WHERE pc.construction_project_id=? ORDER BY pc.trade_role,c.company_name");$teamStmt->execute([$projectId]);$team=$teamStmt->fetchAll();$selected=null;foreach($team as $t)if((int)$t['id']===$teamId)$selected=$t;if(!$all&&$teamId&&!$selected){http_response_code(404);exit('Project trade not found.');}
if($_SERVER['REQUEST_METHOD']==='POST')try{
 if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Session expired.');$subject=trim((string)($_POST['subject']??''));$body=trim((string)($_POST['message_body']??''));$internal=!empty($_POST['internal_only']);if($subject===''||$body==='')throw new RuntimeException('Subject and message are required.');
 $targets=[];if(!empty($_POST['all'])){$ids=array_map('intval',(array)($_POST['team_ids']??[]));foreach($team as $t)if(in_array((int)$t['id'],$ids,true))$targets[]=$t;if(!$targets)throw new RuntimeException('Select at least one trade.');}else{$targets=[$selected];}
 if(!empty($_FILES['attachments']['name'])){$names=$_FILES['attachments']['name'];$isImages=true;foreach((array)$names as $n){$ext=strtolower(pathinfo((string)$n,PATHINFO_EXTENSION));if(!in_array($ext,['jpg','jpeg','png','webp','heic','heif'],true)){$isImages=false;break;}}$uploads=$isImages?dev_compress_images($_FILES['attachments'],'messages'):dev_upload_many($_FILES['attachments'],'messages',['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','webp','zip'],15728640);}
 foreach($uploads as $upload)znp_storage_put_file((string)$upload['path'],znp_storage_local_path((string)$upload['path']),(string)$upload['mime']);
 $msg=db()->prepare('INSERT INTO construction_messages(construction_project_id,project_company_id,admin_user_id,subject,message_body,is_internal,is_bulk,created_at) VALUES(?,?,?,?,?,?,?,NOW())');$rec=db()->prepare('INSERT INTO construction_message_recipients(message_id,project_company_id,recipient_name,recipient_email,delivery_status,created_at) VALUES(?,?,?,?,?,NOW())');$att=db()->prepare('INSERT INTO construction_message_attachments(message_id,file_path,original_name,mime_type,file_size,created_at) VALUES(?,?,?,?,?,NOW())');
 $msg->execute([$projectId,$all?null:$teamId,$user['id'],$subject,$body,$internal?1:0,$all?1:0]);$messageId=(int)db()->lastInsertId();foreach($uploads as $f)$att->execute([$messageId,$f['path'],$f['name'],$f['mime'],$f['size']]);$messageSaved=true;
 $sentCount=0;$failedCount=0;$noEmailCount=0;$emailDeliveryStatuses=[];$senderCopy=null;
 $contactQuery=db()->prepare("SELECT name,email FROM construction_vendor_contacts WHERE construction_company_id=? AND is_active=1 AND email IS NOT NULL AND TRIM(email)<>'' ORDER BY is_primary DESC,id");
 foreach($targets as $t){
  $contactQuery->execute([$t['construction_company_id']]);
  $tradeRecipients=[];$seenEmails=[];
  foreach($contactQuery->fetchAll() as $contact){
   $email=trim((string)($contact['email']??''));$emailKey=strtolower($email);
   if($email===''||isset($seenEmails[$emailKey]))continue;
   $seenEmails[$emailKey]=true;
   $tradeRecipients[]=['name'=>trim((string)($contact['name']??''))?:($t['primary_contact']?:$t['company_name']),'email'=>$email];
  }
  if(!$tradeRecipients){
   $companyEmail=trim((string)($t['company_email']??''));
   if($companyEmail!=='')$tradeRecipients[]=['name'=>$t['primary_contact']?:$t['company_name'],'email'=>$companyEmail];
  }
  if(!$tradeRecipients){
   $noEmailCount++;
   $rec->execute([$messageId,$t['id'],$t['primary_contact']?:$t['company_name'],'',$internal?'Internal':'No Email']);
   continue;
  }
  foreach($tradeRecipients as $tradeRecipient){
   $name=$tradeRecipient['name'];$email=$tradeRecipient['email'];$status=$internal?'Internal':'Pending';
   if(!$internal){
    $batchEmailKey=strtolower(trim($email));
    if(isset($emailDeliveryStatuses[$batchEmailKey])){
     $status=$emailDeliveryStatuses[$batchEmailKey];
    }elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){
     $status='Failed';$failedCount++;$emailDeliveryStatuses[$batchEmailKey]=$status;
    }else{
     $host=strtolower((string)($_SERVER['HTTP_HOST']??''));$host=preg_replace('/:\d+$/','',$host);$host=preg_replace('/^www\./','',$host);if(!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/',$host))$host='znpdev.com';
     $from='noreply@'.$host;$boundary='znp_'.bin2hex(random_bytes(8));$encodedSubject='=?UTF-8?B?'.base64_encode($subject).'?=';
     $headers="From: ZNP Construction <$from>\r\nReply-To: $from\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"\r\nX-Mailer: PHP/".PHP_VERSION;
     $senderName=trim((string)($user['name']??$user['full_name']??''));
     if($senderName==='')$senderName='ZNP Construction Team';
     $emailMessage="From: ".$senderName."\r\nProject: ".(string)$p['project_name']."\r\n\r\n".$body;
     $mailBody="--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$emailMessage\r\n";
     foreach($uploads as $f){$local=znp_storage_local_file((string)$f['path']);if($local===null)continue;$data=chunk_split(base64_encode((string)file_get_contents($local)));$filename=preg_replace('/[^A-Za-z0-9._-]/','_',basename($f['name']));$mailBody.="--$boundary\r\nContent-Type: {$f['mime']}; name=\"$filename\"\r\nContent-Disposition: attachment; filename=\"$filename\"\r\nContent-Transfer-Encoding: base64\r\n\r\n$data\r\n";}
     $mailBody.="--$boundary--\r\n";
     if($senderCopy===null)$senderCopy=['headers'=>$headers,'body'=>$mailBody,'from'=>$from];
     $sent=@mail($email,$encodedSubject,$mailBody,$headers,'-f'.$from);$status=$sent?'Sent':'Failed';$emailDeliveryStatuses[$batchEmailKey]=$status;if($sent)$sentCount++;else $failedCount++;
    }
   }
   $rec->execute([$messageId,$t['id'],$name,$email,$status]);
  }
 }
 $copyEmail=trim((string)($user['email']??''));
 if(!$internal&&$senderCopy!==null&&filter_var($copyEmail,FILTER_VALIDATE_EMAIL)){
  $copySubject='=?UTF-8?B?'.base64_encode('Copy: '.$subject).'?=';
  if(!@mail($copyEmail,$copySubject,$senderCopy['body'],$senderCopy['headers'],'-f'.$senderCopy['from']))error_log('Project message sender copy could not be delivered to '.$copyEmail.'.');
 }
 dev_activity($projectId,'project_message_sent',($all?'Message processed for project trades: ':'Message processed for '.$selected['trade_role'].': ').$subject,'message',$messageId);
 if(znp_storage_uses_s4())foreach($uploads as $upload)try{znp_storage_remove_local((string)$upload['path']);}catch(Throwable $cleanupError){error_log('Message attachment local cleanup failed: '.$cleanupError->getMessage());}
 $result=$internal?'internal':($failedCount>0?'failed':($noEmailCount>0?'partial':'sent'));
 $selectedIds=$all?implode(',',array_map(static fn(array $target): int => (int)$target['id'],$targets)):'';
 header('Location: messages.php?project_id='.$projectId.($all?'&all=1&selected_ids='.rawurlencode($selectedIds):'&team_id='.$teamId).'&delivery='.$result.'&sent_count='.$sentCount.'&failed_count='.$failedCount.'&no_email_count='.$noEmailCount);exit;
}catch(Throwable $e){if(!$messageSaved)foreach($uploads as $upload)try{znp_storage_delete((string)$upload['path']);}catch(Throwable $cleanupError){error_log('Message attachment cleanup failed: '.$cleanupError->getMessage());}$error=$e->getMessage();}
$sql="SELECT m.*,au.full_name,GROUP_CONCAT(DISTINCT CONCAT(pc.trade_role,' — ',c.company_name) SEPARATOR ', ') recipients FROM construction_messages m LEFT JOIN admin_users au ON au.id=m.admin_user_id LEFT JOIN construction_message_recipients mr ON mr.message_id=m.id LEFT JOIN construction_project_companies pc ON pc.id=mr.project_company_id LEFT JOIN construction_companies c ON c.id=pc.construction_company_id WHERE m.construction_project_id=?";$args=[$projectId];if(!$all&&$teamId){$sql.=' AND EXISTS(SELECT 1 FROM construction_message_recipients x WHERE x.message_id=m.id AND x.project_company_id=?)';$args[]=$teamId;}$sql.=' GROUP BY m.id ORDER BY m.created_at DESC';$s=db()->prepare($sql);$s->execute($args);$messages=$s->fetchAll();$messageAttachments=[];if($messages){$ids=array_column($messages,'id');$ph=implode(',',array_fill(0,count($ids),'?'));$q=db()->prepare("SELECT * FROM construction_message_attachments WHERE message_id IN ($ph) ORDER BY id");$q->execute($ids);foreach($q->fetchAll() as $f)$messageAttachments[(int)$f['message_id']][]=$f;}
$read=db()->prepare('INSERT IGNORE INTO construction_message_reads(message_id,admin_user_id,read_at) VALUES(?,?,NOW())');foreach($messages as $m)$read->execute([(int)$m['id'],(int)$user['id']]);
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1><?=$all?'Message All Trades':e(($selected['trade_role']??'Trade').' Messages')?></h1><p class="muted"><?=e($p['project_name'])?><?=$selected?' · '.e($selected['company_name']):''?></p></div><a class="btn btn-secondary" href="project_team.php?project_id=<?=$projectId?>">Back to Project Team</a></div>
<?php
$delivery=(string)($_GET['delivery']??'');$sentCount=(int)($_GET['sent_count']??0);$failedCount=(int)($_GET['failed_count']??0);$noEmailCount=(int)($_GET['no_email_count']??0);
$submittedTradeIds=$delivery!==''&&isset($_GET['selected_ids'])?array_values(array_filter(array_map('intval',explode(',',(string)$_GET['selected_ids'])))):null;
if($delivery==='sent'):?><div class="card notice-success">Email accepted for delivery to <?=$sentCount?> recipient<?=$sentCount===1?'':'s'?>.</div><?php
elseif($delivery==='partial'):?><div class="card notice-error">Email accepted for <?=$sentCount?> recipient<?=$sentCount===1?'':'s'?>, but <?=$noEmailCount?> selected trade<?=$noEmailCount===1?' has':'s have'?> no email address.</div><?php
elseif($delivery==='failed'):?><div class="card notice-error">The message was recorded, but email delivery failed for <?=$failedCount?> recipient<?=$failedCount===1?'':'s'?>. Verify the server mail configuration and recipient addresses.</div><?php
elseif($delivery==='internal'):?><div class="card notice-success">Internal note recorded successfully. No email was sent.</div><?php endif;?><?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<form method="post" enctype="multipart/form-data" class="card form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="team_id" value="<?=$teamId?>"><input type="hidden" name="all" value="<?=$all?1:0?>">
<?php if($all):?><div class="form-full"><div class="recipient-selection-heading"><label>Recipients</label><div class="recipient-selection-actions"><button class="btn btn-secondary btn-small" type="button" data-select-all-trades>Select All</button><button class="btn btn-secondary btn-small" type="button" data-clear-all-trades>Clear All</button></div></div><div class="recipient-grid" data-trade-recipients><?php foreach($team as $t):?><label class="check-card"><input type="checkbox" name="team_ids[]" value="<?=$t['id']?>" <?=$submittedTradeIds===null||in_array((int)$t['id'],$submittedTradeIds,true)?'checked':''?>> <span><strong><?=e($t['trade_role'])?></strong><small><?=e($t['company_name'])?></small></span></label><?php endforeach;?></div></div><?php endif;?>
<div class="form-full"><label>Subject</label><input name="subject" required></div><div class="form-full"><label>Message</label><textarea name="message_body" rows="7" required></textarea></div><div class="form-full"><label>Photos or Files</label><input type="file" name="attachments[]" multiple accept="image/*,.heic,.heif,.pdf,.doc,.docx,.xls,.xlsx,.zip"><small class="muted">Images are compressed automatically. Other files may be up to 15 MB each.</small></div><div><label class="inline-check"><input type="checkbox" name="internal_only" value="1"> Internal note only — do not email</label></div><div class="form-full"><button class="primary">Send Message</button></div></form>
<section class="znp-mt-6"><h2>Communication History</h2><div class="timeline"><?php foreach($messages as $m):?><article class="card message-card"><div class="message-head"><div><h3><?=e($m['subject'])?></h3><p class="muted"><?=e($m['recipients']?:'Internal project note')?> · <?=e(dev_datetime($m['created_at']))?> · <?=e($m['full_name']?:'User')?></p></div><span class="badge"><?=$m['is_internal']?'Internal':'Emailed'?></span></div><p><?=nl2br(e($m['message_body']))?></p><?php if(!empty($messageAttachments[(int)$m['id']])):?><div class="attachment-list"><?php foreach($messageAttachments[(int)$m['id']] as $f):?><a href="message_file.php?id=<?=$f['id']?>" target="_blank">📎 <?=e($f['original_name'])?></a><?php endforeach;?></div><?php endif;?></article><?php endforeach;?><?php if(!$messages):?><div class="card empty">No communications recorded yet.</div><?php endif;?></div></section>
<?php if($all):?><script>
document.addEventListener('DOMContentLoaded',function(){
 const recipients=document.querySelector('[data-trade-recipients]');
 const selectAll=document.querySelector('[data-select-all-trades]');
 const clearAll=document.querySelector('[data-clear-all-trades]');
 if(!recipients||!selectAll||!clearAll)return;
 const setAll=checked=>recipients.querySelectorAll('input[name="team_ids[]"]').forEach(input=>{input.checked=checked;});
 selectAll.addEventListener('click',()=>setAll(true));
 clearAll.addEventListener('click',()=>setAll(false));
});
</script><?php endif;?>
<?php require __DIR__.'/includes/footer.php';?>
