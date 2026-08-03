<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
$user=admin_user();
$id=(int)($_GET['id']??$_POST['id']??0);
$message='';$error='';$sendSummary=null;
$campaign=['id'=>0,'name'=>'','subject'=>'','preview_text'=>'','html_body'=>'<h2>Project Update</h2><p>Share your latest ZNP Development news here.</p>','text_body'=>'','header_image'=>'','status'=>'draft','scheduled_at'=>''];
if($id){$s=db()->prepare('SELECT * FROM newsletter_campaigns WHERE id=?');$s->execute([$id]);$campaign=$s->fetch()?:$campaign;}
if($_SERVER['REQUEST_METHOD']==='POST'&&csrf_check($_POST['csrf_token']??'')){
 try{
  $action=$_POST['action']??'save';
  if($action==='delete'&&$id){if(!super_admin_role()){http_response_code(403);exit('Only a Super Admin may delete newsletter campaigns.');}db()->beginTransaction();db()->prepare('DELETE FROM newsletter_deliveries WHERE campaign_id=?')->execute([$id]);db()->prepare('DELETE FROM newsletter_campaigns WHERE id=?')->execute([$id]);db()->commit();header('Location: newsletter_campaigns.php?deleted=1');exit;}
  if($action==='duplicate'&&$id){
   $s=db()->prepare("INSERT INTO newsletter_campaigns(name,subject,preview_text,html_body,text_body,header_image,status,created_by) SELECT CONCAT(name,' Copy'),subject,preview_text,html_body,text_body,header_image,'draft',? FROM newsletter_campaigns WHERE id=?");
   $s->execute([(int)($user['id']??0),$id]);
   header('Location: newsletter_campaign_edit.php?id='.db()->lastInsertId());exit;
  }
  $name=trim((string)($_POST['name']??''));
  $subject=trim((string)($_POST['subject']??''));
  $preview=trim((string)($_POST['preview_text']??''));
  $html=trim((string)($_POST['html_body']??''));
  $text=trim((string)($_POST['text_body']??''));
  if($name===''||$subject===''||$html==='')throw new RuntimeException('Campaign name, subject, and email body are required.');
  if($id){
   db()->prepare('UPDATE newsletter_campaigns SET name=?,subject=?,preview_text=?,html_body=?,text_body=?,scheduled_at=NULL WHERE id=?')->execute([$name,$subject,$preview,$html,$text,$id]);
  }else{
   db()->prepare('INSERT INTO newsletter_campaigns(name,subject,preview_text,html_body,text_body,status,scheduled_at,created_by) VALUES(?,?,?,?,?,"draft",NULL,?)')->execute([$name,$subject,$preview,$html,$text,(int)($user['id']??0)]);
   $id=(int)db()->lastInsertId();
  }
  if($action==='test'){
   $to=trim((string)($_POST['test_email']??''));
   if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid test recipient.');
   $subscriber=['unsubscribe_token'=>'test','full_name'=>'Test Recipient'];
   $delivery=['id'=>0];
   $result=app_send_mail_detailed($to,'TEST: '.$subject,newsletter_render_email(['subject'=>$subject,'preview_text'=>$preview,'html_body'=>$html],$subscriber,$delivery));
   if(!$result['ok'])throw new RuntimeException($result['error']);
   $message='Test email sent.';
  }elseif($action==='send_now'){
   $sendSummary=newsletter_send_campaign_now($id);
   $message='Campaign sent now.';
  }else{$message='Campaign saved.';}
  $s=db()->prepare('SELECT * FROM newsletter_campaigns WHERE id=?');$s->execute([$id]);$campaign=$s->fetch();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error=$e->getMessage();}
}
$stats=['total'=>0,'accepted'=>0,'failed'=>0,'opened'=>0,'clicked'=>0];
if($id){$s=db()->prepare("SELECT COUNT(*) total,SUM(status='accepted') accepted,SUM(status='failed') failed,SUM(opened_at IS NOT NULL) opened,SUM(clicked_at IS NOT NULL) clicked FROM newsletter_deliveries WHERE campaign_id=?");$s->execute([$id]);$stats=array_merge($stats,$s->fetch()?:[]);}
$activeSubscribers=(int)db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status='active'")->fetchColumn();
require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><h1><?=$id?'Edit Campaign':'New Campaign'?></h1><a class="secondary button-link" href="newsletter_campaigns.php">Campaigns</a></div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<?php if($sendSummary):?><div class="newsletter-send-summary"><div><strong><?=(int)$sendSummary['sent']?></strong><span>Sent</span></div><div><strong><?=(int)$sendSummary['failed']?></strong><span>Failed</span></div><div><strong><?=(int)$sendSummary['skipped']?></strong><span>Skipped</span></div></div><?php endif;?>
<?php if($id):?><div class="newsletter-stats"><div><strong><?=(int)$stats['total']?></strong><span>Recipients</span></div><div><strong><?=(int)$stats['accepted']?></strong><span>Accepted by SMTP</span></div><div><strong><?=(int)$stats['failed']?></strong><span>Failed</span></div><div><strong><?=(int)$stats['opened']?></strong><span>Opened*</span></div><div><strong><?=(int)$stats['clicked']?></strong><span>Clicked</span></div></div><?php endif;?>
<form method="post" class="settings-card admin-form newsletter-campaign-form">
 <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>">
 <div class="settings-grid"><label>Internal Campaign Name<input name="name" required value="<?=e($campaign['name'])?>"></label><label>Email Subject<input name="subject" required value="<?=e($campaign['subject'])?>"></label><label>Preview Text<input name="preview_text" maxlength="255" value="<?=e($campaign['preview_text'])?>"></label></div>
 <label>HTML Email Body<textarea name="html_body" rows="18" required><?=e($campaign['html_body'])?></textarea><small>Basic HTML is supported. Every email automatically receives ZNP branding, tracking, mailing address, and an unsubscribe link.</small></label>
 <label>Plain-Text Fallback<textarea name="text_body" rows="8" placeholder="Leave blank to generate automatically"><?=e($campaign['text_body'])?></textarea></label>
 <div class="newsletter-campaign-actions">
  <button class="primary" name="action" value="save">Save Draft</button>
  <?php if($id):?><button class="secondary" name="action" value="duplicate">Duplicate</button><?php endif;?>
  <button class="primary admin-rounded-action" name="action" value="send_now" onclick="return confirm('Send this campaign now to <?=number_format($activeSubscribers)?> active subscriber<?= $activeSubscribers===1?'':'s' ?>? This action cannot be undone.')" <?=$activeSubscribers<1?'disabled':''?>>Send Now</button>
 </div>
 <div class="newsletter-test-row"><label>Test Recipient<input type="email" name="test_email" value="<?=e((string)($user['email']??''))?>"></label><button class="secondary" name="action" value="test">Send Test</button></div>
</form>
<?php if($id&&super_admin_role()):?><form method="post" class="admin-delete-campaign-form" onsubmit="return confirm('Delete this campaign and its delivery history?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><button class="danger" name="action" value="delete">Delete Campaign</button></form><?php endif;?>
<p class="admin-help-text">*Open tracking is approximate because some email clients block images or preload tracking pixels.</p>
<?php require __DIR__.'/_footer.php';?>
