<?php
declare(strict_types=1);
require __DIR__.'/../includes/auth.php';
if(investor_user()){header('Location: '.app_url('/portal/'));exit;}
$token=(string)($_SESSION['investor_verification_token']??'');
$email=(string)($_SESSION['investor_verification_email']??'');
if($token===''||$email===''){header('Location: '.app_url('/login.php?tab=investor'));exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $code=preg_replace('/\D+/','',(string)($_POST['code']??'')) ?: '';
    if(!csrf_check((string)($_POST['csrf_token']??''))){$error='Your session expired. Refresh the page and try again.';}
    elseif(strlen($code)!==6){$error='Enter the six-digit code from your email.';}
    else{
        try{
            $s=db()->prepare('SELECT * FROM investor_login_codes WHERE request_token=? AND email=? LIMIT 1');$s->execute([$token,$email]);$row=$s->fetch();
            if(!$row||!empty($row['used_at'])){$error='This verification request is no longer valid. Request a new code.';}
            elseif(strtotime((string)$row['expires_at'])<time()){$error='This verification code has expired. Request a new code.';}
            elseif((int)$row['attempts']>=5){$error='Too many incorrect attempts. Request a new verification code.';}
            elseif(!password_verify($code,(string)$row['code_hash'])){
                db()->prepare('UPDATE investor_login_codes SET attempts=attempts+1 WHERE id=?')->execute([$row['id']]);$error='That verification code is incorrect.';
            }else{
                $table=$row['contact_type']==='lead'?'leads':'contact_inquiries';
                $s=db()->prepare("SELECT id,full_name,email,phone,status FROM {$table} WHERE id=? LIMIT 1");$s->execute([(int)$row['contact_id']]);$contact=$s->fetch();
                if(!$contact){$error='Your contact record could not be found.';}
                else{
                    db()->prepare('UPDATE investor_login_codes SET used_at=NOW() WHERE id=?')->execute([$row['id']]);
                    session_regenerate_id(true);
                    $_SESSION['investor_user']=['contact_type'=>$row['contact_type'],'contact_id'=>(int)$contact['id'],'name'=>$contact['full_name'],'email'=>$contact['email'],'phone'=>$contact['phone'],'verified_at'=>date('c')];
                    unset($_SESSION['investor_verification_token'],$_SESSION['investor_verification_email']);
                    header('Location: '.app_url('/portal/'));exit;
                }
            }
        }catch(Throwable $e){error_log('Investor verification failed: '.$e->getMessage());$error='Verification could not be completed. Please try again.';}
    }
}
$pageTitle='Verify Investor Access | ZNP Development';$activePage='login';$bodyClass='public-login-page';require __DIR__.'/../includes/header.php';
?>
<main class="public-login-shell"><section class="public-login-card"><form method="post" class="portal-login-form investor-code-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><h1>Check Your Email</h1><p>Enter the six-digit code sent to <?=e($email)?>. The code expires in 10 minutes.</p><?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?><label>Verification Code<input class="verification-code-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="000000"></label><button class="primary" type="submit">Verify and Continue</button><a class="admin-forgot-link" href="<?=e(app_url('/login.php?tab=investor'))?>">Request a New Code</a></form></section></main>
<?php require __DIR__.'/../includes/footer.php';?>
