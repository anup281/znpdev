<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/includes/contracts.php';

dev_ensure_contract_schema();
$token=trim((string)($_REQUEST['token']??''));
if(!preg_match('/^[a-f0-9]{64}$/',$token)){http_response_code(404);exit('This signing link is invalid.');}

$sql="SELECT e.*,p.project_name,pc.trade_role,c.company_name FROM construction_contract_envelopes e JOIN construction_projects p ON p.id=e.construction_project_id JOIN construction_contracts ct ON ct.id=e.construction_contract_id JOIN construction_project_companies pc ON pc.id=ct.construction_project_company_id JOIN construction_companies c ON c.id=pc.construction_company_id WHERE e.access_token=? OR e.owner_access_token=? LIMIT 1";
$q=db()->prepare($sql);$q->execute([$token,$token]);$envelope=$q->fetch();
if(!$envelope){http_response_code(404);exit('This signing link is invalid.');}
$isOwner=!empty($envelope['owner_access_token'])&&hash_equals((string)$envelope['owner_access_token'],$token);
$requiresOwner=!empty($envelope['owner_access_token']);
$waitingForOwner=!$isOwner&&$requiresOwner&&empty($envelope['owner_signed_at']);
$error='';$handoffWarning='';

if($_SERVER['REQUEST_METHOD']==='POST'&&!in_array($envelope['status'],['signed','voided'],true)&&!$waitingForOwner){
    try{
        $name=mb_substr(trim((string)($_POST['signer_name']??'')),0,190);
        $email=strtolower(trim((string)($_POST['signer_email']??'')));
        $signature=mb_substr(trim((string)($_POST['signature_text']??'')),0,255);
        $signatureData=trim((string)($_POST['signature_data']??''));
        $expectedEmail=(string)($isOwner?$envelope['owner_email']:$envelope['recipient_email']);
        if($name===''||$signature==='')throw new RuntimeException('Enter your full name and typed signature.');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strcasecmp($email,$expectedEmail)!==0)throw new RuntimeException('Use the email address that received this signing request.');
        if(empty($_POST['accept_terms']))throw new RuntimeException('Confirm that you have reviewed and accept the agreement.');
        if(!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#',$signatureData)||strlen($signatureData)>500000)throw new RuntimeException('Draw your signature in the signature box.');
        $ip=mb_substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);$agent=mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);
        if($isOwner){
            $stmt=db()->prepare("UPDATE construction_contract_envelopes SET status='contractor_pending',owner_signed_at=NOW(),owner_signature_text=?,owner_signature_data=?,owner_signer_ip=?,owner_signer_user_agent=? WHERE id=? AND owner_signed_at IS NULL AND status<>'voided'");
            $stmt->execute([$signature,$signatureData,$ip,$agent,$envelope['id']]);
            if(!$stmt->rowCount())throw new RuntimeException('The project owner signature has already been recorded.');
            $contractorUrl=dev_contract_sign_url((string)$envelope['access_token']);
            $mail='<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto"><h1 style="color:#0d294b">Contract ready for your signature</h1><p>Hello '.htmlspecialchars((string)($envelope['recipient_name']?:$envelope['company_name']),ENT_QUOTES,'UTF-8').',</p><p>The project owner has signed the subcontractor agreement for <strong>'.htmlspecialchars((string)$envelope['project_name'],ENT_QUOTES,'UTF-8').'</strong>. Please review and sign it.</p><p><a style="display:inline-block;background:#1e5c8f;color:#fff;text-decoration:none;padding:12px 18px;border-radius:7px" href="'.htmlspecialchars($contractorUrl,ENT_QUOTES,'UTF-8').'">Review and Sign Agreement</a></p><p style="color:#6b7785;font-size:12px">This link is unique to you. Do not forward it.</p></div>';
            $delivery=app_send_mail_detailed((string)$envelope['recipient_email'],(string)$envelope['subject'],$mail);
            db()->prepare('UPDATE construction_contract_envelopes SET status=?,contractor_sent_at=IF(?,NOW(),contractor_sent_at) WHERE id=?')->execute([$delivery['ok']?'contractor_pending':'contractor_delivery_failed',$delivery['ok']?1:0,$envelope['id']]);
            if(!$delivery['ok'])$handoffWarning='Your signature was saved, but the contractor email could not be delivered. The project team can resend it from the portal.';
        }else{
            $stmt=db()->prepare("UPDATE construction_contract_envelopes SET status='signed',signed_at=NOW(),signer_name=?,signer_email=?,signature_text=?,signature_data=?,signer_ip=?,signer_user_agent=? WHERE id=? AND signed_at IS NULL AND status<>'voided'");
            $stmt->execute([$name,$email,$signature,$signatureData,$ip,$agent,$envelope['id']]);
            if(!$stmt->rowCount())throw new RuntimeException('This agreement has already been signed.');
        }
        $q->execute([$token,$token]);$envelope=$q->fetch();
    }catch(Throwable $exception){$error=$exception->getMessage();}
}
if($_SERVER['REQUEST_METHOD']!=='POST'&&!in_array($envelope['status'],['signed','voided'],true)&&!$waitingForOwner&&(!$isOwner||empty($envelope['owner_signed_at']))){
    $viewStatus=$isOwner?'owner_viewed':'contractor_viewed';
    db()->prepare('UPDATE construction_contract_envelopes SET status=?,first_viewed_at=COALESCE(first_viewed_at,NOW()),last_viewed_at=NOW() WHERE id=?')->execute([$viewStatus,$envelope['id']]);
    $envelope['status']=$viewStatus;
}
$expectedName=(string)($isOwner?$envelope['owner_name']:$envelope['recipient_name']);
$expectedEmail=(string)($isOwner?$envelope['owner_email']:$envelope['recipient_email']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars((string)$envelope['subject'])?></title>
<style>:root{--navy:#0d294b;--blue:#1e5c8f;--line:#dbe4eb;--green:#277a50;--red:#aa3434}*{box-sizing:border-box}body{margin:0;background:#f2f6f9;color:#172333;font-family:Arial,sans-serif}.sign-wrap{width:min(920px,94%);margin:35px auto}.sign-head,.sign-document,.sign-panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;box-shadow:0 8px 28px rgba(13,41,75,.07);margin-bottom:16px}.sign-head{display:flex;justify-content:space-between;gap:20px;align-items:center}.sign-head h1{margin:0 0 6px;color:var(--navy)}.sign-head p{margin:0;color:#6b7785}.sign-badge{padding:7px 11px;border-radius:999px;background:#e7eef4;color:var(--navy);font-size:11px;font-weight:800;text-transform:uppercase}.sign-document{line-height:1.65}.sign-document h1,.sign-document h2,.sign-document h3{color:var(--navy)}.sign-panel form{display:grid;grid-template-columns:1fr 1fr;gap:14px}.sign-panel label{display:grid;gap:6px;font-size:12px;font-weight:700}.sign-panel input{width:100%;padding:11px;border:1px solid #cbd6df;border-radius:7px;font:inherit}.sign-panel .full{grid-column:1/-1}.sign-accept{display:flex!important;grid-template-columns:auto 1fr!important;align-items:flex-start}.sign-accept input{width:auto;margin-top:2px}.signature-pad{grid-column:1/-1}.signature-pad-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}.signature-pad-head button{border:0;background:none;color:var(--blue);font-weight:700;cursor:pointer}.signature-canvas{display:block;width:100%;height:180px;border:1px solid #b9c9d8;border-radius:8px;background:#fff;touch-action:none}.sign-button{grid-column:1/-1;border:0;border-radius:8px;background:var(--blue);color:#fff;padding:13px 18px;font-weight:800;cursor:pointer}.sign-error,.sign-warning{padding:12px;border-radius:8px;margin-bottom:14px}.sign-error{background:#fde7e7;color:var(--red)}.sign-warning{background:#fff4d8;color:#79570e}.sign-complete{border-left:5px solid var(--green)}.sign-complete strong{color:var(--green)}.sign-void{border-left:5px solid var(--red)}.signature-image{display:block;max-width:360px;max-height:120px;margin-top:10px;border-bottom:1px solid var(--line)}@media(max-width:650px){.sign-head{align-items:flex-start;flex-direction:column}.sign-panel form{grid-template-columns:1fr}.sign-panel .full,.sign-button,.signature-pad{grid-column:auto}}</style></head><body><main class="sign-wrap">
<header class="sign-head"><div><h1><?=htmlspecialchars((string)$envelope['project_name'])?></h1><p><?=htmlspecialchars((string)$envelope['trade_role'].' — '.(string)$envelope['company_name'])?></p></div><span class="sign-badge"><?=htmlspecialchars(ucwords(str_replace('_',' ',(string)$envelope['status'])))?></span></header>
<article class="sign-document"><?=$envelope['rendered_html']?></article>
<?php if($envelope['status']==='signed'):?><section class="sign-panel sign-complete"><h2>Agreement Fully Executed</h2><p><?php if($requiresOwner):?><strong>Project owner <?=htmlspecialchars((string)$envelope['owner_name'])?></strong> signed on <?=date('F j, Y \a\t g:i A',strtotime((string)$envelope['owner_signed_at']))?>.<br><?php endif;?><strong>Contractor <?=htmlspecialchars((string)$envelope['signer_name'])?></strong> signed on <?=date('F j, Y \a\t g:i A',strtotime((string)$envelope['signed_at']))?>.</p><p>The complete acceptance record has been saved with the project contract.</p></section>
<?php elseif($envelope['status']==='voided'):?><section class="sign-panel sign-void"><h2>Signing Request Voided</h2><p>This request is no longer available. Contact the project team for a new agreement.</p></section>
<?php elseif($waitingForOwner):?><section class="sign-panel"><h2>Waiting for Project Owner</h2><p>The project owner must sign this agreement before the contractor signature step opens. You will receive an email when it is ready.</p></section>
<?php elseif($isOwner&&!empty($envelope['owner_signed_at'])):?><section class="sign-panel sign-complete"><h2>Owner Signature Recorded</h2><?php if($handoffWarning):?><div class="sign-warning"><?=htmlspecialchars($handoffWarning)?></div><?php endif;?><p>Your signature has been saved. The contractor is now the next signer.</p></section>
<?php else:?><section class="sign-panel"><h2><?=$isOwner?'Project Owner Signature':'Contractor Signature'?></h2><?php if($error):?><div class="sign-error"><?=htmlspecialchars($error)?></div><?php endif;?><form method="post" data-signature-form><input type="hidden" name="token" value="<?=htmlspecialchars($token)?>"><input type="hidden" name="signature_data" data-signature-data><label><span>Full Legal Name</span><input name="signer_name" value="<?=htmlspecialchars($expectedName)?>" required autocomplete="name"></label><label><span>Email Address</span><input type="email" name="signer_email" value="<?=htmlspecialchars($expectedEmail)?>" required autocomplete="email"></label><label class="full"><span>Typed Signature</span><input name="signature_text" required placeholder="Type your full legal name"></label><div class="signature-pad"><div class="signature-pad-head"><strong>Draw Signature</strong><button type="button" data-clear-signature>Clear</button></div><canvas class="signature-canvas" data-signature-canvas aria-label="Draw your signature"></canvas></div><label class="full sign-accept"><input type="checkbox" name="accept_terms" value="1" required><span>I have reviewed this agreement, consent to use an electronic signature, and agree that my typed and drawn signatures represent my intent to sign and accept the agreement.</span></label><button class="sign-button" type="submit"><?=$isOwner?'Sign and Send to Contractor':'Sign Agreement'?></button></form></section><?php endif;?></main>
<script>(()=>{const form=document.querySelector('[data-signature-form]');if(!form)return;const canvas=form.querySelector('[data-signature-canvas]'),ctx=canvas.getContext('2d'),output=form.querySelector('[data-signature-data]');let drawing=false,hasInk=false;function resize(){const ratio=Math.max(window.devicePixelRatio||1,1),rect=canvas.getBoundingClientRect();canvas.width=Math.round(rect.width*ratio);canvas.height=Math.round(rect.height*ratio);ctx.setTransform(ratio,0,0,ratio,0,0);ctx.lineWidth=2;ctx.lineCap='round';ctx.strokeStyle='#172333'}resize();function point(event){const rect=canvas.getBoundingClientRect();return{x:event.clientX-rect.left,y:event.clientY-rect.top}}canvas.addEventListener('pointerdown',event=>{drawing=true;hasInk=true;canvas.setPointerCapture(event.pointerId);const p=point(event);ctx.beginPath();ctx.moveTo(p.x,p.y)});canvas.addEventListener('pointermove',event=>{if(!drawing)return;const p=point(event);ctx.lineTo(p.x,p.y);ctx.stroke()});canvas.addEventListener('pointerup',()=>drawing=false);canvas.addEventListener('pointercancel',()=>drawing=false);form.querySelector('[data-clear-signature]').addEventListener('click',()=>{ctx.clearRect(0,0,canvas.width,canvas.height);hasInk=false;output.value='' });form.addEventListener('submit',event=>{if(!hasInk){event.preventDefault();alert('Please draw your signature in the signature box.');return}output.value=canvas.toDataURL('image/png')})})();</script></body></html>
