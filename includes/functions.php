<?php
declare(strict_types=1);

function app_base_path(): string {
 $script='/'.ltrim(str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'')),'/');
 if(preg_match('#^(.*?)/(?:admin|dev|portal|manage|lead|helpers)(?:/|$)#',$script,$matches))return rtrim((string)$matches[1],'/');
 $directory=rtrim(str_replace('\\','/',dirname($script)),'/');
 return $directory==='/'?'':$directory;
}
function app_url(string $path=''): string {
 if($path===''||str_starts_with($path,'#')||preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i',$path))return $path;
 return app_base_path().'/'.ltrim($path,'/');
}
function e(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }
function csrf_token(): string {
 if(session_status()!==PHP_SESSION_ACTIVE) session_start();
 if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
 return $_SESSION['csrf'];
}
function csrf_check(string $token): bool {
 return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'],$token);
}
function setting(string $key,string $default=''): string {
 $s=db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key=?');
 $s->execute([$key]); $v=$s->fetchColumn();
 return $v===false?$default:(string)$v;
}
function money_compact(float $v): string {
 return '$'.rtrim(rtrim(number_format($v/1000000,1), '0'),'.').'M+';
}
function format_phone(?string $phone): string {
 $digits=preg_replace('/\D+/','',(string)$phone);
 if(strlen($digits)===11 && $digits[0]==='1') $digits=substr($digits,1);
 if(strlen($digits)===10) return substr($digits,0,3).'-'.substr($digits,3,3).'-'.substr($digits,6,4);
 return trim((string)$phone);
}
function phone_href(?string $phone): string {
 $digits=preg_replace('/\D+/','',(string)$phone);
 if(strlen($digits)===10) return 'tel:+1'.$digits;
 return $digits!==''?'tel:+'.$digits:'#';
}
function phone_link(?string $phone,string $empty='—'): string {
 if(trim((string)$phone)==='') return e($empty);
 return '<a href="'.e(phone_href($phone)).'">'.e(format_phone($phone)).'</a>';
}
function email_link(?string $email,string $empty='—'): string {
 $email=trim((string)$email); if($email==='') return e($empty);
 return '<a href="mailto:'.e($email).'">'.e($email).'</a>';
}
function app_delete_managed_file(?string $relativePath,array $allowedPrefixes): bool {
 $relativePath=ltrim(str_replace('\\','/',trim((string)$relativePath)),'/');
 if($relativePath===''||str_contains($relativePath,'..'))return false;
 $allowed=false;
 foreach($allowedPrefixes as $prefix){$prefix=trim(str_replace('\\','/',$prefix),'/').'/';if(str_starts_with($relativePath,$prefix)){$allowed=true;break;}}
 if(!$allowed)return false;
 $root=realpath(__DIR__.'/..');$absolute=realpath(__DIR__.'/../'.$relativePath);
 if($root===false||$absolute===false||!str_starts_with($absolute,$root.DIRECTORY_SEPARATOR)||!is_file($absolute))return false;
 return unlink($absolute);
}


function simple_pdf_escape(string $s): string { return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s); }
function generic_nda_pdf(string $recipient): string {
 $date=date('F j, Y');
 $lines=[
 'MUTUAL NON-DISCLOSURE AGREEMENT','',
 'This Mutual Non-Disclosure Agreement (the "Agreement") is entered into as of '.$date.',',
 'between ZNP Development and '.$recipient.' (each a "Party" and together the "Parties").','',
 '1. Purpose. The Parties may exchange confidential information while evaluating a potential',
 'business, investment, development, or other commercial relationship.','',
 '2. Confidential Information. Confidential Information includes non-public business, financial,',
 'technical, property, investor, customer, and project information disclosed in any form.','',
 '3. Obligations. Each Party will protect Confidential Information with reasonable care, use it',
 'only for the stated purpose, and disclose it only to representatives who need to know and are',
 'bound by confidentiality obligations.','',
 '4. Exclusions. Confidential Information does not include information that is publicly available',
 'without breach, already lawfully known, independently developed, or lawfully received from a',
 'third party without restriction.','',
 '5. Required Disclosure. A Party may disclose information when legally required after providing',
 'prompt notice when permitted and reasonable cooperation in seeking protective treatment.','',
 '6. Return or Destruction. Upon request, each Party will return or destroy Confidential Information,',
 'except for archival copies maintained by law or routine backup systems.','',
 '7. Term. These confidentiality obligations continue for three years from each disclosure; trade',
 'secrets remain protected for as long as they qualify as trade secrets under applicable law.','',
 '8. No License or Commitment. No intellectual-property license or obligation to complete a',
 'transaction is created by this Agreement.','',
 '9. Governing Law. This Agreement is governed by the laws of the State of Texas.','',
 'ZNP DEVELOPMENT                              RECIPIENT','',
 'By: __________________________               By: __________________________','',
 'Name: ________________________               Name: '.$recipient,'',
 'Date: _________________________              Date: _________________________','',
 'Generic template for review; replace with counsel-approved language before reliance.'
 ];
 $stream="BT\n/F1 9 Tf\n50 760 Td\n";
 foreach($lines as $i=>$line){
  if($i==0){$stream.="/F1 16 Tf (".simple_pdf_escape($line).") Tj\n/F1 9 Tf\n0 -24 Td\n";continue;}
  $stream.='('.simple_pdf_escape($line).") Tj\n0 -13 Td\n";
 }
 $stream.="ET";
 $objs=[];
 $objs[]='1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj';
 $objs[]='2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj';
 $objs[]='3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >> endobj';
 $objs[]='4 0 obj << /Length '.strlen($stream).' >> stream\n'.$stream.'\nendstream endobj';
 $objs[]='5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj';
 $pdf="%PDF-1.4\n";$offs=[0];
 foreach($objs as $o){$offs[]=strlen($pdf);$pdf.=$o."\n";}
 $xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";
 for($i=1;$i<=5;$i++)$pdf.=sprintf('%010d 00000 n ', $offs[$i])."\n";
 $pdf.="trailer << /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
 return $pdf;
}
function setting_save(string $key,string $value,string $type='string'): void {
 $s=db()->prepare('INSERT INTO site_settings(setting_key,setting_value,setting_type,is_public) VALUES(?,?,?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),setting_type=VALUES(setting_type)');
 $s->execute([$key,$value,$type]);
}
function znp_secret_key(): string {
 $seed=(defined('APP_KEY')?(string)APP_KEY:'').(defined('DB_NAME')?(string)DB_NAME:'').(defined('DB_USER')?(string)DB_USER:'');
 return hash('sha256',$seed,true);
}
function znp_legacy_secret_keys(): array {
 $prefix=(defined('APP_KEY')?(string)APP_KEY:'').(defined('DB_NAME')?(string)DB_NAME:'').(defined('DB_USER')?(string)DB_USER:'');
 $directories=[__DIR__];
 $documentRoot=rtrim(str_replace('\\','/',realpath((string)($_SERVER['DOCUMENT_ROOT']??''))?:''),'/');
 if($documentRoot!==''){
  $directories[]=$documentRoot.'/includes';
  $directories[]=$documentRoot.'/test/includes';
 }
 return array_values(array_unique(array_map(static fn(string $directory):string=>hash('sha256',$prefix.$directory,true),$directories)));
}
function znp_decrypt_with_key(string $stored,string $key): string|false {
 $raw=base64_decode(substr($stored,4),true);if($raw===false||strlen($raw)<17)return false;
 return openssl_decrypt(substr($raw,16),'AES-256-CBC',$key,OPENSSL_RAW_DATA,substr($raw,0,16));
}
function znp_decrypted_secret_is_valid(string|false $plain): bool {
 return is_string($plain)&&$plain!==''&&preg_match('//u',$plain)===1&&!preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$plain);
}
function encrypt_setting(string $plain): string {
 if($plain==='')return '';
 if(!function_exists('openssl_encrypt')) return 'plain:'.base64_encode($plain);
 $iv=random_bytes(16);$cipher=openssl_encrypt($plain,'AES-256-CBC',znp_secret_key(),OPENSSL_RAW_DATA,$iv);
 return 'enc:'.base64_encode($iv.$cipher);
}
function decrypt_setting(string $stored): string {
 if(str_starts_with($stored,'plain:')) return (string)base64_decode(substr($stored,6),true);
 if(!str_starts_with($stored,'enc:')||!function_exists('openssl_decrypt')) return $stored;
 $decrypted=znp_decrypt_with_key($stored,znp_secret_key());
 if(znp_decrypted_secret_is_valid($decrypted))return $decrypted;
 foreach(znp_legacy_secret_keys() as $legacyKey){
  $decrypted=znp_decrypt_with_key($stored,$legacyKey);
  if(znp_decrypted_secret_is_valid($decrypted))return $decrypted;
 }
 return '';
}
function migrate_secret_setting(string $key): bool {
 $stored=setting($key);
 if(!str_starts_with($stored,'enc:')||!function_exists('openssl_decrypt'))return false;
 if(znp_decrypted_secret_is_valid(znp_decrypt_with_key($stored,znp_secret_key())))return false;
 $plain=decrypt_setting($stored);
 if($plain==='')return false;
 setting_save($key,encrypt_setting($plain),'secret');
 return true;
}
function smtp_configured(): bool {
 return setting('smtp_host')!=='' && setting('smtp_username')!=='' && decrypt_setting(setting('smtp_password'))!=='';
}
function smtp_read($socket): string {
 $response='';
 while(($line=fgets($socket,515))!==false){$response.=$line;if(strlen($line)<4||$line[3]===' ')break;}
 return $response;
}
function smtp_expect($socket,array $codes,string $step): string {
 $response=smtp_read($socket);$code=(int)substr($response,0,3);
 if(!in_array($code,$codes,true)) throw new RuntimeException($step.' failed: '.trim($response));
 return $response;
}
function smtp_command($socket,string $command,array $codes,string $step): string {
 fwrite($socket,$command."\r\n");return smtp_expect($socket,$codes,$step);
}
function app_send_mail_detailed(string $to,string $subject,string $html,array $attachments=[],array $cc=[]): array {
 $to=trim($to);if(!filter_var($to,FILTER_VALIDATE_EMAIL))return ['ok'=>false,'error'=>'Invalid recipient email address.'];
 $cc=array_values(array_unique(array_filter(array_map(static fn(mixed $email):string=>strtolower(trim((string)$email)),$cc),static fn(string $email):bool=>filter_var($email,FILTER_VALIDATE_EMAIL)!==false&&strcasecmp($email,$to)!==0)));
 if(!smtp_configured())return ['ok'=>false,'error'=>'SMTP is not configured in Admin Settings.'];
 $host=setting('smtp_host');$port=(int)setting('smtp_port','587');$security=strtolower(setting('smtp_encryption','tls'));
 $username=setting('smtp_username');$password=decrypt_setting(setting('smtp_password'));$from=setting('mail_from_email',$username);$fromName=setting('mail_from_name','ZNP Development');$reply=setting('mail_reply_to',$from);
 $target=($security==='ssl'?'ssl://':'').$host;
 $errno=0;$errstr='';$socket=@stream_socket_client($target.':'.$port,$errno,$errstr,12,STREAM_CLIENT_CONNECT);
 if(!$socket)return ['ok'=>false,'error'=>'SMTP connection failed: '.$errstr.' ('.$errno.')'];
 stream_set_timeout($socket,12);
 try{
  smtp_expect($socket,[220],'Connect');smtp_command($socket,'EHLO '.($_SERVER['SERVER_NAME']??'znpdev.com'),[250],'EHLO');
  if($security==='tls'){
   smtp_command($socket,'STARTTLS',[220],'STARTTLS');
   if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('TLS negotiation failed.');
   smtp_command($socket,'EHLO '.($_SERVER['SERVER_NAME']??'znpdev.com'),[250],'EHLO after TLS');
  }
  smtp_command($socket,'AUTH LOGIN',[334],'Authentication');smtp_command($socket,base64_encode($username),[334],'SMTP username');smtp_command($socket,base64_encode($password),[235],'SMTP password');
  smtp_command($socket,'MAIL FROM:<'.$from.'>',[250],'Sender');smtp_command($socket,'RCPT TO:<'.$to.'>',[250,251],'Recipient');foreach($cc as $ccEmail)smtp_command($socket,'RCPT TO:<'.$ccEmail.'>',[250,251],'CC recipient');smtp_command($socket,'DATA',[354],'Message data');
  $boundary='znp_'.bin2hex(random_bytes(12));
  $headers=['Date: '.date(DATE_RFC2822),'From: '.$fromName.' <'.$from.'>','Reply-To: '.$reply,'To: <'.$to.'>'];if($cc)$headers[]='Cc: '.implode(', ',array_map(static fn(string $email):string=>'<'.$email.'>',$cc));$headers=array_merge($headers,['Subject: =?UTF-8?B?'.base64_encode($subject).'?=','MIME-Version: 1.0','Content-Type: multipart/mixed; boundary="'.$boundary.'"']);
  $body="--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($html)."\r\n";
  foreach($attachments as $a){$data=(string)($a['data']??'');$name=preg_replace('/[^A-Za-z0-9._-]/','_',($a['name']??'attachment.pdf'));$type=$a['type']??'application/octet-stream';$body.="--$boundary\r\nContent-Type: $type; name=\"$name\"\r\nContent-Disposition: attachment; filename=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($data))."\r\n";}
  $body.="--$boundary--\r\n";$message=implode("\r\n",$headers)."\r\n\r\n".$body;
  $message=preg_replace('/(?m)^\./','..',$message);fwrite($socket,$message."\r\n.\r\n");smtp_expect($socket,[250],'Send');@smtp_command($socket,'QUIT',[221],'Quit');fclose($socket);
  try{db()->prepare('INSERT INTO mail_delivery_log(recipient,subject,status,error_message) VALUES(?,?,?,NULL)')->execute([$to,$subject,'accepted']);}catch(Throwable $logError){error_log('Mail delivery success log failed: '.$logError->getMessage());}
  return ['ok'=>true,'error'=>''];
 }catch(Throwable $e){@fwrite($socket,"QUIT\r\n");@fclose($socket);try{db()->prepare('INSERT INTO mail_delivery_log(recipient,subject,status,error_message) VALUES(?,?,?,?)')->execute([$to,$subject,'failed',substr($e->getMessage(),0,1000)]);}catch(Throwable $logError){error_log('Mail delivery failure log failed: '.$logError->getMessage());}return ['ok'=>false,'error'=>$e->getMessage()];}
}
function app_send_mail(string $to,string $subject,string $html,array $attachments=[]): bool { return app_send_mail_detailed($to,$subject,$html,$attachments)['ok']; }
function znp_system_diagnostics(): array {
 $uploadDir=__DIR__.'/../uploads';$dataDir=__DIR__.'/../data';
 $dbOk=false;try{$dbOk=(bool)db()->query('SELECT 1')->fetchColumn();}catch(Throwable $e){error_log('System diagnostics database check failed: '.$e->getMessage());}
 return [
  ['label'=>'Database','ok'=>$dbOk,'detail'=>$dbOk?'Connected':'Connection failed'],
  ['label'=>'SMTP Configuration','ok'=>smtp_configured(),'detail'=>smtp_configured()?'Configured':'Not configured'],
  ['label'=>'Uploads Folder','ok'=>is_dir($uploadDir)&&is_writable($uploadDir),'detail'=>(is_dir($uploadDir)&&is_writable($uploadDir))?'Writable':'Not writable'],
  ['label'=>'Data Folder','ok'=>is_dir($dataDir)&&is_writable($dataDir),'detail'=>(is_dir($dataDir)&&is_writable($dataDir))?'Writable':'Not writable'],
  ['label'=>'Image Processing','ok'=>extension_loaded('gd'),'detail'=>extension_loaded('gd')?'GD enabled':'GD unavailable'],
  ['label'=>'OpenSSL','ok'=>extension_loaded('openssl'),'detail'=>extension_loaded('openssl')?'Enabled':'Unavailable'],
  ['label'=>'PHP Version','ok'=>version_compare(PHP_VERSION,'8.0.0','>='),'detail'=>PHP_VERSION],
 ];
}

function app_public_url(string $path=''): string {
 $configured=rtrim(setting('website_url',''),' /');
 if($configured!=='') return $configured.'/'.ltrim($path,'/');
 $https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
 $host=$_SERVER['HTTP_HOST']??'localhost';
 $script=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'/');
 $base=preg_replace('#/admin/[^/]*$#','',$script);
 return ($https?'https':'http').'://'.$host.rtrim((string)$base,'/').'/'.ltrim($path,'/');
}
function app_upload_url(string $path): string {
 $normalized=ltrim(str_replace('\\','/',trim($path)),'/');
 $uploadsAt=strpos($normalized,'uploads/');
 $uploadPath=$uploadsAt===false?'uploads/'.basename($normalized):substr($normalized,$uploadsAt);
 $configured=trim(setting('website_url',''));
 $parts=$configured!==''?parse_url($configured):false;
 if(is_array($parts)&&!empty($parts['host'])){
  $scheme=(string)($parts['scheme']??'https');
  $port=isset($parts['port'])?':'.(int)$parts['port']:'';
  return $scheme.'://'.$parts['host'].$port.'/'.ltrim($uploadPath,'/');
 }
 $https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
 $host=(string)($_SERVER['HTTP_HOST']??'localhost');
 return ($https?'https':'http').'://'.$host.'/'.ltrim($uploadPath,'/');
}
/* Version 1.6.2: eligible agreements and contact activity */
function eligible_agreement_entities(): array {
 $rows=[];
 foreach(db()->query("SELECT id,project_name AS name,city,state,'project' AS entity_type FROM projects WHERE portfolio_category='under_development' AND is_visible=1 ORDER BY display_order,project_name")->fetchAll() as $r){$rows[]=$r;}
 foreach(db()->query("SELECT o.id,o.project_name AS name,o.city,o.state,'opportunity' AS entity_type FROM investment_opportunities o WHERE o.status='raising_capital' AND o.is_visible=1 AND o.accepting_inquiries=1 ORDER BY o.display_order,o.project_name")->fetchAll() as $r){$rows[]=$r;}
 return $rows;
}
function agreement_entity(string $type,int $id): ?array {
 if($type==='opportunity'){$s=db()->prepare("SELECT id,project_name AS name,city,state,'opportunity' AS entity_type FROM investment_opportunities WHERE id=? AND status='raising_capital' AND is_visible=1 AND accepting_inquiries=1");}
 else{$s=db()->prepare("SELECT id,project_name AS name,city,state,'project' AS entity_type FROM projects WHERE id=? AND portfolio_category='under_development' AND is_visible=1");}
 $s->execute([$id]);$r=$s->fetch();return $r?:null;
}
function contact_activity_log(string $type,int $id,string $event,string $title,?string $details=null,?int $adminId=null,?int $deliveryId=null,?string $occurredAt=null): void {
 try{$s=db()->prepare('INSERT INTO contact_activity(contact_type,contact_id,event_type,title,details,admin_user_id,document_delivery_id,occurred_at) VALUES(?,?,?,?,?,?,?,COALESCE(?,NOW()))');$s->execute([$type,$id,$event,$title,$details,$adminId,$deliveryId,$occurredAt]);}catch(Throwable $e){error_log('Contact activity write failed: '.$e->getMessage());}
}
function contact_activity_rows(string $type,int $id): array {
 try{$s=db()->prepare('SELECT a.*,u.full_name AS admin_name FROM contact_activity a LEFT JOIN admin_users u ON u.id=a.admin_user_id WHERE a.contact_type=? AND a.contact_id=? ORDER BY a.occurred_at DESC,a.id DESC');$s->execute([$type,$id]);return $s->fetchAll();}catch(Throwable $e){error_log('Contact activity read failed: '.$e->getMessage());return [];}
}
function contact_changed_fields(array $before,array $after,array $labels): array {
 $changes=[];foreach($labels as $key=>$label){$old=trim((string)($before[$key]??''));$new=trim((string)($after[$key]??''));if($old!==$new)$changes[]=$label.': '.($old===''?'Not provided':$old).' → '.($new===''?'Not provided':$new);}return $changes;
}


/* Version 1.6.5: editable public page headings */
function public_page_heading(string $page, string $defaultTitle, string $defaultSubtitle=''): array {
 $page=preg_replace('/[^a-z0-9_]/','',strtolower($page));
 $title=trim(setting('page_'.$page.'_title',$defaultTitle));
 $subtitle=trim(setting('page_'.$page.'_subtitle',$defaultSubtitle));
 return [
  'title'=>$title!==''?$title:$defaultTitle,
  'subtitle'=>$subtitle!==''?$subtitle:$defaultSubtitle,
 ];
}
function render_public_page_header(string $page,string $defaultTitle,string $defaultSubtitle='',string $extraClass=''): void {
 $heading=public_page_heading($page,$defaultTitle,$defaultSubtitle);
 $classes=trim('blueprint-banner '.$extraClass);
 echo '<section class="'.e($classes).'"><div class="container"><h1>'.e($heading['title']).'</h1>';
 if($heading['subtitle']!=='') echo '<h2>'.e($heading['subtitle']).'</h2>';
 echo '</div></section>';
}

/* Version 1.7: multi-template Document Center */
function document_templates_for_entity(string $type,int $id,bool $activeOnly=false): array {
 $field=$type==='opportunity'?'investment_opportunity_id':'project_id';
 $sql="SELECT * FROM document_templates WHERE {$field}=?".($activeOnly?' AND is_active=1':'')." ORDER BY document_name,updated_at DESC,id DESC";
 $s=db()->prepare($sql);$s->execute([$id]);return $s->fetchAll();
}
function document_template_by_id(int $id,bool $activeOnly=true): ?array {
 $sql="SELECT d.*,COALESCE(p.project_name,o.project_name) AS entity_name,CASE WHEN d.investment_opportunity_id IS NULL THEN 'project' ELSE 'opportunity' END AS entity_type,COALESCE(d.project_id,d.investment_opportunity_id) AS entity_id FROM document_templates d LEFT JOIN projects p ON p.id=d.project_id LEFT JOIN investment_opportunities o ON o.id=d.investment_opportunity_id WHERE d.id=?".($activeOnly?' AND d.is_active=1':'')." LIMIT 1";
 $s=db()->prepare($sql);$s->execute([$id]);$r=$s->fetch();return $r?:null;
}
function eligible_document_templates(): array {
 $rows=[];
 foreach(eligible_agreement_entities() as $entity){
  foreach(document_templates_for_entity($entity['entity_type'],(int)$entity['id'],true) as $template){$template['entity_name']=$entity['name'];$template['entity_type']=$entity['entity_type'];$template['entity_id']=$entity['id'];$rows[]=$template;}
 }
 return $rows;
}
function uploaded_attachment(string $field,int $maxBytes=15728640): array {
 if(empty($_FILES[$field]['name'])||!is_uploaded_file($_FILES[$field]['tmp_name']))return ['ok'=>true,'attachment'=>null];
 if((int)$_FILES[$field]['size']>$maxBytes)return ['ok'=>false,'error'=>'The attachment exceeds the 15 MB limit.'];
 $name=basename((string)$_FILES[$field]['name']);$mime=(string)(mime_content_type($_FILES[$field]['tmp_name'])?:'application/octet-stream');
 $allowed=['application/pdf','application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','image/jpeg','image/png'];
 if(!in_array($mime,$allowed,true))return ['ok'=>false,'error'=>'This attachment type is not allowed.'];
 $data=file_get_contents($_FILES[$field]['tmp_name']);if($data===false)return ['ok'=>false,'error'=>'The attachment could not be read.'];
 return ['ok'=>true,'attachment'=>['name'=>$name,'type'=>$mime,'data'=>$data]];
}

/** Normalize PHP's single- and multi-file upload shapes into one list. */
function app_uploaded_file_entries(array $files): array {
 $names=$files['name']??[];
 if(!is_array($names))return [[
  'name'=>(string)$names,
  'type'=>(string)($files['type']??''),
  'tmp_name'=>(string)($files['tmp_name']??''),
  'error'=>(int)($files['error']??UPLOAD_ERR_NO_FILE),
  'size'=>(int)($files['size']??0),
 ]];
 $entries=[];
 foreach($names as $index=>$name)$entries[]=[
  'name'=>(string)$name,
  'type'=>(string)($files['type'][$index]??''),
  'tmp_name'=>(string)($files['tmp_name'][$index]??''),
  'error'=>(int)($files['error'][$index]??UPLOAD_ERR_NO_FILE),
  'size'=>(int)($files['size'][$index]??0),
 ];
 return $entries;
}

/* Version 1.8.1: SEO and public form protection */
function seo_defaults(): array {
 return [
  'home'=>['ZNP Development | Texas Real Estate Development & Investment','ZNP Development develops and invests in multifamily, townhome, hospitality, commercial, and residential real estate projects throughout Texas.','ZNP Development','Texas-Based. Vertically Integrated. Built to Perform.'],
  'projects'=>['Real Estate Development Projects | ZNP Development','Explore ZNP Development’s active and completed multifamily, townhome, hospitality, commercial, and residential real estate projects throughout Texas.','ZNP Development Projects','View active developments and completed real estate projects delivered by ZNP Development.'],
  'team'=>['Leadership Team | ZNP Development','Meet the ZNP Development leadership team and learn about the complementary expertise guiding development, capital, investor relations, and asset management.','ZNP Development Leadership Team','Leadership built on complementary expertise across real estate development, capital, investor relations, and asset management.'],
  'invest'=>['Texas Real Estate Investment Opportunities | ZNP Development','Explore current Texas real estate investment opportunities offered by ZNP Development, including townhome, multifamily, and residential development projects.','Invest With ZNP Development','Discover current real estate investment opportunities with ZNP Development.'],
  'contact'=>['Contact ZNP Development | Real Estate Development & Investment','Contact ZNP Development to discuss Texas real estate development projects, investment opportunities, partnerships, and potential acquisitions.','Start a Conversation | ZNP Development','Connect with ZNP Development regarding development projects, investment opportunities, and strategic partnerships.'],
 ];
}
function seo_page_data(string $page): array {
 $all=seo_defaults();$d=$all[$page]??$all['home'];
 return [
  'title'=>setting('seo_'.$page.'_title',$d[0]),
  'description'=>setting('seo_'.$page.'_description',$d[1]),
  'og_title'=>setting('seo_'.$page.'_og_title',$d[2]),
  'og_description'=>setting('seo_'.$page.'_og_description',$d[3]),
 ];
}
function current_canonical_url(): string {
 $base=rtrim(setting('website_url',app_public_url()),'/');
 $path=basename(parse_url($_SERVER['REQUEST_URI']??'index.php',PHP_URL_PATH)?:'index.php');
 if($path===''||$path==='index.php')return $base.'/';
 return $base.'/'.$path;
}
function contact_captcha_create(): array {
 if(session_status()!==PHP_SESSION_ACTIVE)session_start();
 $now=time();$challenges=is_array($_SESSION['contact_captcha_challenges']??null)?$_SESSION['contact_captcha_challenges']:[];
 foreach($challenges as $token=>$challenge)if(!is_array($challenge)||(int)($challenge['created_at']??0)<$now-1800)unset($challenges[$token]);
 $left=random_int(2,9);$right=random_int(1,9);$token=bin2hex(random_bytes(16));
 $challenges[$token]=['answer'=>$left+$right,'created_at'=>$now];
 $_SESSION['contact_captcha_challenges']=array_slice($challenges,-5,null,true);
 return ['token'=>$token,'question'=>'What is '.$left.' + '.$right.'?'];
}
function contact_captcha_check(array $post): array {
 if(session_status()!==PHP_SESSION_ACTIVE)session_start();
 $token=trim((string)($post['captcha_token']??''));$answer=trim((string)($post['captcha_answer']??''));
 $challenges=is_array($_SESSION['contact_captcha_challenges']??null)?$_SESSION['contact_captcha_challenges']:[];
 $challenge=preg_match('/^[a-f0-9]{32}$/',$token)?($challenges[$token]??null):null;
 if(isset($challenges[$token]))unset($challenges[$token]);
 $_SESSION['contact_captcha_challenges']=$challenges;
 if(!is_array($challenge)||(int)($challenge['created_at']??0)<time()-1800)return [false,'The security question expired. Please answer the new question below.'];
 if(!preg_match('/^\d{1,2}$/',$answer)||(int)$answer!==(int)$challenge['answer'])return [false,'Please answer the security question correctly.'];
 return [true,''];
}
function contact_spam_check(array $post): array {
 if(trim((string)($post['website']??''))!=='')return [false,'Unable to submit this form.'];
 $started=(int)($_SESSION['contact_form_started']??0);$elapsed=time()-$started;
 if($started<1||$elapsed<3||$elapsed>7200)return [false,'Please refresh the page and try again.'];
 $name=trim((string)($post['name']??''));$email=trim((string)($post['email']??''));$phone=trim((string)($post['phone']??''));$message=trim((string)($post['message']??''));
 if(strlen($name)<2||strlen($name)>120||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||strlen($phone)>40||strlen($message)>5000)return [false,'Please review the form fields and try again.'];
 $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');$ipHash=hash('sha256',$ip);
 try{
  $q=db()->prepare('SELECT COUNT(*) FROM public_form_submissions WHERE ip_hash=? AND submitted_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)');$q->execute([$ipHash]);if((int)$q->fetchColumn()>=5)return [false,'Too many submissions. Please wait and try again.'];
  $fingerprint=hash('sha256',strtolower($email).'|'.strtolower($name).'|'.$message);
  $q=db()->prepare('SELECT COUNT(*) FROM public_form_submissions WHERE fingerprint=? AND submitted_at>=DATE_SUB(NOW(),INTERVAL 10 MINUTE)');$q->execute([$fingerprint]);if((int)$q->fetchColumn()>0)return [false,'This message was already received.'];
  db()->prepare('INSERT INTO public_form_submissions(ip_hash,fingerprint,submitted_at) VALUES(?,?,NOW())')->execute([$ipHash,$fingerprint]);
 }catch(Throwable $e){error_log('Public form anti-spam check failed: '.$e->getMessage());return [false,'The form is temporarily unavailable. Please try again later.'];}
 return [true,''];
}

/**
 * Send a consistent JSON response and terminate the request.
 *
 * Endpoint-specific helpers may wrap this function when a domain-oriented
 * name makes the calling code easier to read.
 */
function app_json_response(array $payload,int $status=200,bool $clearOutput=false): never {
 if($clearOutput)while(ob_get_level()>0)ob_end_clean();
 http_response_code($status);
 header('Content-Type: application/json; charset=utf-8');
 header('Cache-Control: no-store');
 try{$json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
 catch(JsonException $exception){error_log('JSON response encoding failed: '.$exception->getMessage());http_response_code(500);$json='{"ok":false,"message":"The response could not be prepared."}';}
 echo $json;
 exit;
}

function app_json_result(bool $ok,string $message,array $extra=[],int $status=200,bool $clearOutput=false): never {
 app_json_response(array_merge(['ok'=>$ok,'message'=>$message],$extra),$status,$clearOutput);
}

/** Check database capabilities without repeating SHOW TABLE/COLUMN queries. */
function db_table_exists(string $table): bool {
 static $cache=[];
 if(isset($cache[$table]))return $cache[$table];
 if(!preg_match('/^[A-Za-z0-9_]+$/',$table))throw new InvalidArgumentException('Invalid database table name.');
 $query=db()->query('SHOW TABLE STATUS WHERE Name='.db()->quote($table));
 return $cache[$table]=(bool)$query->fetch();
}

function db_column_exists(string $table,string $column): bool {
 static $cache=[];
 $key=$table.'.'.$column;
 if(isset($cache[$key]))return $cache[$key];
 if(!preg_match('/^[A-Za-z0-9_]+$/',$table)||!preg_match('/^[A-Za-z0-9_]+$/',$column))throw new InvalidArgumentException('Invalid database schema name.');
 $query=db()->query('SHOW COLUMNS FROM `'.$table.'` WHERE Field='.db()->quote($column));
 return $cache[$key]=(bool)$query->fetch();
}

function db_schema_ready(array $tables=[],array $columns=[]): bool {
 try{
  foreach($tables as $table)if(!db_table_exists((string)$table))return false;
  foreach($columns as $table=>$requiredColumns)foreach((array)$requiredColumns as $column)if(!db_column_exists((string)$table,(string)$column))return false;
  return true;
 }catch(Throwable $exception){error_log('Database capability check failed: '.$exception->getMessage());return false;}
}

require_once __DIR__.'/newsletter.php';
