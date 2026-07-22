<?php
declare(strict_types=1); session_start();
$pageTitle='Contact | ZNP Development'; $activePage='contact'; $bodyClass='contact-page';
require __DIR__.'/includes/db.php'; require __DIR__.'/includes/functions.php';
$selectedSubject=(string)($_GET['subject']??'general_inquiry');
$message='';$formError='';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
if($_SERVER['REQUEST_METHOD']!=='POST')$_SESSION['contact_form_started']=time();
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!csrf_check($_POST['csrf_token']??'')){ $formError='Session expired. Refresh the page and try again.'; }
 else { [$spamOk,$spamMessage]=contact_spam_check($_POST); if(!$spamOk){$formError=$spamMessage;} else {
  $type=$_POST['subject']??'general_inquiry';
  $ref='ZNP-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
  $stmt=db()->prepare("INSERT INTO contact_inquiries(reference_number,inquiry_type,investment_opportunity_id,full_name,company_name,email,phone,prospective_investor_type,investment_amount,message,source_page,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([$ref,$type,null,trim($_POST['name']),trim($_POST['company']),trim($_POST['email']),trim($_POST['phone']),$_POST['investor_type']?:null,$_POST['investment_amount']?:null,trim($_POST['message']),'contact.php',$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??null]);
  $inquiryId=(int)db()->lastInsertId();contact_activity_log('inquiry',$inquiryId,'submitted','Contact submitted','Public website inquiry form',null,null,date('Y-m-d H:i:s'));$message='Thank you. Your inquiry has been received.';$_SESSION['contact_form_started']=time();
 }}
}
require __DIR__.'/includes/header.php';
?>
<main><?php render_public_page_header('contact','Start a Conversation','Whether you are looking to invest, develop, acquire property, or discuss your next project, we would love to hear from you.','projects-blueprint'); ?>
<section class="contact-wrap"><div class="container">
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?><?php if($formError):?><div class="status error"><?=e($formError)?></div><?php endif;?>
<form class="contact-form" method="post" novalidate><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><div class="contact-honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
<div class="form-grid"><div class="field"><label>Full Name *</label><input name="name" required></div><div class="field"><label>Company</label><input name="company"></div></div>
<div class="form-grid"><div class="field"><label>Email *</label><input type="email" name="email" required></div><div class="field"><label>Phone</label><input name="phone" id="contact-phone" type="tel" inputmode="tel" autocomplete="tel"></div></div>
<div class="field"><label>What Can We Help You With?</label><select name="subject" id="subject"><option value="investment_opportunity" <?=$selectedSubject==='investment_opportunity'?'selected':''?>>Investment Opportunity</option><option value="development_opportunity" <?=$selectedSubject==='development_opportunity'?'selected':''?>>Development Services</option><option value="land_acquisition" <?=$selectedSubject==='land_acquisition'?'selected':''?>>Land Opportunity</option><option value="joint_venture" <?=$selectedSubject==='joint_venture'?'selected':''?>>Partnership</option><option value="general_inquiry" <?=$selectedSubject==='general_inquiry'?'selected':''?>>General Inquiry</option><option value="media_press" <?=$selectedSubject==='media_press'?'selected':''?>>Media</option><option value="other" <?=$selectedSubject==='other'?'selected':''?>>Other</option></select></div>
<div id="investment-fields" hidden><div class="form-grid"><div class="field"><label>Prospective Investor Type</label><select name="investor_type"><option value="">Select</option><option value="individual">Individual</option><option value="joint">Joint</option><option value="llc_partnership">LLC / Partnership</option><option value="trust">Trust</option><option value="retirement_account">Retirement Account</option><option value="other">Other</option></select></div><div class="field"><label>Amount You May Consider Investing</label><select name="investment_amount"><option value="">Select</option><?php foreach(['25000','50000','75000','100000','150000','250000'] as $a):?><option value="<?=$a?>">$<?=number_format((int)$a)?></option><?php endforeach;?></select></div></div></div>
<div class="field"><label>Message</label><textarea name="message"></textarea></div><button class="primary" type="submit">Send Message<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button></form></div></section></main>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const s=document.getElementById('subject'),f=document.getElementById('investment-fields');
 const u=()=>{f.hidden=s.value!=='investment_opportunity'};
 s.addEventListener('change',u);u();

 const phone=document.getElementById('contact-phone');
 if(phone){
  const formatPhone=()=>{
   const digits=phone.value.replace(/\D/g,'');
   if(digits.length>10){phone.value=digits;return;}
   if(digits.length>6){phone.value=digits.slice(0,3)+'-'+digits.slice(3,6)+'-'+digits.slice(6);return;}
   if(digits.length>3){phone.value=digits.slice(0,3)+'-'+digits.slice(3);return;}
   phone.value=digits;
  };
  phone.addEventListener('input',formatPhone);
  formatPhone();
 }
});
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
