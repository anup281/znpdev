<?php
declare(strict_types=1);
function newsletter_token(): string { return bin2hex(random_bytes(32)); }
function newsletter_subscribe(string $email,string $name='',string $source='website'): array {
 $email=strtolower(trim($email));$name=trim($name);
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)) return ['ok'=>false,'message'=>'Enter a valid email address.'];
 $pdo=db();$s=$pdo->prepare('SELECT id,status FROM newsletter_subscribers WHERE email=?');$s->execute([$email]);$row=$s->fetch();
 if($row){
  $pdo->prepare("UPDATE newsletter_subscribers SET full_name=COALESCE(NULLIF(?,''),full_name),status='active',source=?,consent_ip=?,consent_at=NOW(),unsubscribed_at=NULL WHERE id=?")->execute([$name,$source,$_SERVER['REMOTE_ADDR']??null,$row['id']]);
 }else{
  $pdo->prepare("INSERT INTO newsletter_subscribers(full_name,email,status,source,consent_ip,consent_at,unsubscribe_token) VALUES(?,?,'active',?,?,NOW(),?)")->execute([$name,$email,$source,$_SERVER['REMOTE_ADDR']??null,newsletter_token()]);
 }
 return ['ok'=>true,'message'=>'Thank you for subscribing.'];
}
function newsletter_unsubscribe_url(array $subscriber): string { return app_public_url('helpers/newsletter_unsubscribe.php?token='.rawurlencode((string)$subscriber['unsubscribe_token'])); }
function newsletter_track_links(string $html,int $deliveryId): string {
 return preg_replace_callback('/href=("|\')(https?:\/\/[^"\']+)\1/i',function($m)use($deliveryId){$url=app_public_url('helpers/newsletter_click.php?d='.$deliveryId.'&u='.rawurlencode(base64_encode($m[2])));return 'href='.$m[1].e($url).$m[1];},$html)??$html;
}
function newsletter_render_email(array $campaign,array $subscriber,array $delivery): string {
 $body=newsletter_track_links((string)$campaign['html_body'],(int)$delivery['id']);
 $preview=e((string)($campaign['preview_text']??''));$unsubscribe=e(newsletter_unsubscribe_url($subscriber));$address=e(setting('newsletter_physical_address','Dallas, Texas'));
 $pixel=e(app_public_url('helpers/newsletter_open.php?d='.(int)$delivery['id']));
 return '<!doctype html><html><body style="margin:0;background:#f3f6f8;font-family:Arial,sans-serif;color:#17283b"><div style="display:none;max-height:0;overflow:hidden">'.$preview.'</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px"><table role="presentation" width="640" style="max-width:640px;background:#fff;border-radius:10px;overflow:hidden"><tr><td style="background:#0b2343;color:#fff;padding:24px 30px;font-size:22px;font-weight:700">ZNP Development</td></tr><tr><td style="padding:32px 30px;line-height:1.65">'.$body.'</td></tr><tr><td style="padding:22px 30px;background:#eef3f7;color:#627181;font-size:12px;line-height:1.5">'.$address.'<br><a href="'.$unsubscribe.'" style="color:#365e85">Unsubscribe</a></td></tr></table></td></tr></table><img src="'.$pixel.'" width="1" height="1" alt="" style="display:block"></body></html>';
}

function newsletter_send_campaign_now(int $campaignId): array {
 $pdo=db();
 $campaignStmt=$pdo->prepare('SELECT * FROM newsletter_campaigns WHERE id=?');
 $campaignStmt->execute([$campaignId]);
 $campaign=$campaignStmt->fetch();
 if(!$campaign) throw new RuntimeException('Campaign not found.');
 if(($campaign['status']??'')==='sent') throw new RuntimeException('This campaign has already been sent. Duplicate it to send a new campaign.');
 $subscribers=$pdo->query("SELECT * FROM newsletter_subscribers WHERE status='active' ORDER BY id")->fetchAll();
 if(!$subscribers) throw new RuntimeException('There are no active newsletter subscribers.');
 if(function_exists('set_time_limit')){@set_time_limit(0);}
 $pdo->prepare("UPDATE newsletter_campaigns SET status='sending',queued_at=NOW(),completed_at=NULL WHERE id=?")->execute([$campaignId]);
 $sent=0;$failed=0;$skipped=0;
 foreach($subscribers as $subscriber){
  $existing=$pdo->prepare('SELECT id,status FROM newsletter_deliveries WHERE campaign_id=? AND subscriber_id=? LIMIT 1');
  $existing->execute([$campaignId,(int)$subscriber['id']]);
  $delivery=$existing->fetch();
  if($delivery && $delivery['status']==='accepted'){$skipped++;continue;}
  if($delivery){
   $deliveryId=(int)$delivery['id'];
   $pdo->prepare("UPDATE newsletter_deliveries SET recipient_email=?,status='processing',attempts=attempts+1,last_error=NULL WHERE id=?")
       ->execute([$subscriber['email'],$deliveryId]);
  }else{
   $pdo->prepare("INSERT INTO newsletter_deliveries(campaign_id,subscriber_id,recipient_email,status,attempts,queued_at) VALUES(?,?,?,'processing',1,NOW())")
       ->execute([$campaignId,(int)$subscriber['id'],$subscriber['email']]);
   $deliveryId=(int)$pdo->lastInsertId();
  }
  $delivery=['id'=>$deliveryId];
  $html=newsletter_render_email($campaign,$subscriber,$delivery);
  $result=app_send_mail_detailed((string)$subscriber['email'],(string)$campaign['subject'],$html);
  if($result['ok']){
   $pdo->prepare("UPDATE newsletter_deliveries SET status='accepted',sent_at=NOW(),last_error=NULL WHERE id=?")->execute([$deliveryId]);
   $sent++;
  }else{
   $pdo->prepare("UPDATE newsletter_deliveries SET status='failed',last_error=? WHERE id=?")->execute([substr((string)$result['error'],0,2000),$deliveryId]);
   $failed++;
  }
 }
 $pdo->prepare("UPDATE newsletter_campaigns SET status='sent',completed_at=NOW() WHERE id=?")->execute([$campaignId]);
 return ['sent'=>$sent,'failed'=>$failed,'skipped'=>$skipped,'total'=>count($subscribers)];
}
function newsletter_process_queue(int $limit=25): array {
 $pdo=db();$done=0;$failed=0;
 $rows=$pdo->query("SELECT d.*,c.subject,c.preview_text,c.html_body,c.text_body,s.full_name,s.unsubscribe_token,s.status AS subscriber_status FROM newsletter_deliveries d JOIN newsletter_campaigns c ON c.id=d.campaign_id JOIN newsletter_subscribers s ON s.id=d.subscriber_id WHERE d.status='queued' AND (c.scheduled_at IS NULL OR c.scheduled_at<=NOW()) ORDER BY d.id LIMIT ".max(1,min(200,$limit)))->fetchAll();
 foreach($rows as $row){
  if($row['subscriber_status']!=='active'){$pdo->prepare("UPDATE newsletter_deliveries SET status='skipped',last_error='Subscriber inactive' WHERE id=?")->execute([$row['id']]);continue;}
  $pdo->prepare("UPDATE newsletter_deliveries SET status='processing',attempts=attempts+1 WHERE id=? AND status='queued'")->execute([$row['id']]);
  $html=newsletter_render_email($row,$row,$row);$result=app_send_mail_detailed($row['recipient_email'],$row['subject'],$html);
  if($result['ok']){$pdo->prepare("UPDATE newsletter_deliveries SET status='accepted',sent_at=NOW(),last_error=NULL WHERE id=?")->execute([$row['id']]);$done++;}
  else{$pdo->prepare("UPDATE newsletter_deliveries SET status='failed',last_error=? WHERE id=?")->execute([substr($result['error'],0,2000),$row['id']]);$failed++;}
 }
 $pdo->exec("UPDATE newsletter_campaigns c SET status='sent',completed_at=NOW() WHERE status IN ('queued','sending') AND NOT EXISTS(SELECT 1 FROM newsletter_deliveries d WHERE d.campaign_id=c.id AND d.status IN ('queued','processing'))");
 return ['sent'=>$done,'failed'=>$failed,'processed'=>count($rows)];
}
