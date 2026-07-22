<?php
declare(strict_types=1);
require __DIR__.'/includes/auth.php';

if (admin_user()) { header('Location: '.login_destination()); exit; }
if (investor_user() && (($_GET['tab'] ?? '') === 'investor' || !isset($_GET['tab']))) { header('Location: '.app_url('/portal/')); exit; }

$pageTitle='Login | ZNP Development';
$activePage='login';
$bodyClass='public-login-page';
$error='';
$message=isset($_GET['reset'])?'Your password was updated. You can sign in now.':'';
$tab=($_GET['tab']??'vendor')==='investor'?'investor':'vendor';

function investor_phone_last4(string $phone): string {
    $digits=preg_replace('/\D+/','',$phone) ?: '';
    return strlen($digits)>=4 ? substr($digits,-4) : '';
}
function investor_find_contact(string $email,string $last4): ?array {
    $matches=[];
    try {
        $s=db()->prepare("SELECT 'lead' source_type,id,full_name,email,phone,status,submitted_at FROM leads WHERE LOWER(email)=? ORDER BY submitted_at DESC");
        $s->execute([$email]);
        foreach($s->fetchAll() as $row) if(investor_phone_last4((string)$row['phone'])===$last4)$matches[]=$row;
    } catch(Throwable $ignored) {}
    try {
        $s=db()->prepare("SELECT 'inquiry' source_type,id,full_name,email,phone,status,submitted_at FROM contact_inquiries WHERE LOWER(email)=? ORDER BY submitted_at DESC");
        $s->execute([$email]);
        foreach($s->fetchAll() as $row) if(investor_phone_last4((string)$row['phone'])===$last4)$matches[]=$row;
    } catch(Throwable $ignored) {}
    if(!$matches)return null;
    usort($matches,static fn(array $a,array $b):int=>strcmp((string)$b['submitted_at'],(string)$a['submitted_at']));
    return $matches[0];
}
function investor_code_email(string $name,string $code): string {
    return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#17324d">'
        .'<h2 style="color:#0d345c">Your ZNP Investor Portal verification code</h2>'
        .'<p>Hello '.e($name ?: 'Investor').',</p>'
        .'<p>Use the following six-digit code to continue signing in:</p>'
        .'<div style="font-size:34px;font-weight:800;letter-spacing:8px;padding:18px 22px;background:#f3f6f9;border:1px solid #d9e3ec;border-radius:10px;text-align:center">'.e($code).'</div>'
        .'<p style="margin-top:22px">This code expires in 10 minutes. If you did not request it, you can ignore this email.</p>'
        .'<p>ZNP Development</p></div>';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $postedTab=(string)($_POST['portal_type']??'vendor');
    if($postedTab==='investor'){
        $tab='investor';
        $email=strtolower(trim((string)($_POST['email']??'')));
        $last4=preg_replace('/\D+/','',(string)($_POST['phone_last4']??'')) ?: '';
        if(!csrf_check((string)($_POST['csrf_token']??''))){
            $error='Your session expired. Refresh the page and try again.';
        } elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($last4)!==4){
            $error='Enter a valid email address and the last four digits of your phone number.';
        } else {
            $contact=investor_find_contact($email,$last4);
            if(!$contact){
                $error='We could not verify those details. Please use the email and phone number submitted with your inquiry.';
            } else {
                try {
                    $recent=db()->prepare('SELECT created_at FROM investor_login_codes WHERE email=? ORDER BY id DESC LIMIT 1');
                    $recent->execute([$email]);
                    $lastSent=$recent->fetchColumn();
                    if($lastSent && strtotime((string)$lastSent)>time()-60){
                        $error='A verification code was sent recently. Please wait one minute before requesting another.';
                    } else {
                        $code=(string)random_int(100000,999999);
                        $token=bin2hex(random_bytes(24));
                        $hash=password_hash($code,PASSWORD_DEFAULT);
                        db()->prepare('UPDATE investor_login_codes SET used_at=NOW() WHERE email=? AND used_at IS NULL')->execute([$email]);
                        $ins=db()->prepare('INSERT INTO investor_login_codes(contact_type,contact_id,email,code_hash,request_token,expires_at,attempts,ip_address,user_agent,created_at) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE),0,?,?,NOW())');
                        $ins->execute([$contact['source_type'],(int)$contact['id'],$email,$hash,$token,$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??null]);
                        $mail=app_send_mail_detailed($email,'Your ZNP Investor Portal verification code',investor_code_email((string)$contact['full_name'],$code));
                        if(empty($mail['ok'])){
                            db()->prepare('UPDATE investor_login_codes SET used_at=NOW() WHERE request_token=?')->execute([$token]);
                            $error='The verification email could not be sent. Please try again or contact ZNP Development.';
                        } else {
                            $_SESSION['investor_verification_token']=$token;
                            $_SESSION['investor_verification_email']=$email;
                            header('Location: '.app_url('/portal/verify.php'));exit;
                        }
                    }
                } catch(Throwable $e){
                    error_log('Investor login request failed: '.$e->getMessage());
                    $error='Investor access is not installed yet. Please ask the site administrator to run the Investor Portal installer.';
                }
            }
        }
    } else {
        $tab='vendor';
        $identity=strtolower(trim((string)($_POST['username']??$_POST['email']??'')));
        $pass=(string)($_POST['password']??'');
        $u=false;
        try{
            $hasUsername=(bool)db()->query("SHOW COLUMNS FROM admin_users LIKE 'username'")->fetch();
            if($hasUsername){
                $s=db()->prepare('SELECT * FROM admin_users WHERE is_active=1 AND (LOWER(username)=? OR LOWER(email)=?) LIMIT 1');
                $s->execute([$identity,$identity]);
            }else{
                $s=db()->prepare('SELECT * FROM admin_users WHERE is_active=1 AND LOWER(email)=? LIMIT 1');
                $s->execute([$identity]);
            }
            $u=$s->fetch();
        }catch(Throwable $e){$error='The portal could not complete the login request. Please contact the site administrator.';}
        $locked=$u&&!empty($u['locked_until'])&&strtotime((string)$u['locked_until'])>=time();
        $ok=$u&&!$locked&&password_verify($pass,(string)$u['password_hash']);
        try{db()->prepare('INSERT INTO admin_login_log(admin_user_id,email_attempted,was_successful,ip_address,user_agent) VALUES(?,?,?,?,?)')->execute([$u['id']??null,$identity,$ok?1:0,$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??null]);}catch(Throwable $ignored){}
        if($ok){
            session_regenerate_id(true);
            $_SESSION['admin_user']=['id'=>(int)$u['id'],'name'=>$u['full_name'],'role'=>$u['role'],'email'=>$u['email'],'username'=>$u['username']??$u['email'],'construction_only'=>(int)($u['construction_only']??0),'construction_role'=>$u['construction_role']??'','must_change_password'=>(int)($u['must_change_password']??0)];
            db()->prepare('UPDATE admin_users SET last_login_at=NOW(),failed_login_attempts=0,locked_until=NULL WHERE id=?')->execute([$u['id']]);
            $return=$_SESSION['login_return']??$_SESSION['dev_return']??'';unset($_SESSION['login_return'],$_SESSION['dev_return']);
            $destination=login_destination($_SESSION['admin_user']);if($return!==''&&substr($return,0,strlen($destination))===$destination)$destination=$return;
            header('Location: '.$destination);exit;
        }
        if($u&&!$locked){$a=(int)($u['failed_login_attempts']??0)+1;$lock=$a>=5?date('Y-m-d H:i:s',time()+900):null;db()->prepare('UPDATE admin_users SET failed_login_attempts=?,locked_until=? WHERE id=?')->execute([$a,$lock,$u['id']]);}
        if($error==='')$error=$locked?'This account is temporarily locked after multiple unsuccessful attempts. Please try again in 15 minutes or use Forgot Password.':'Invalid email, username, or password.';
    }
}
require __DIR__.'/includes/header.php';
?>
<main class="public-login-shell"><section class="public-login-card">
<div class="portal-tabs" role="tablist"><a class="<?=$tab==='investor'?'active':''?>" href="<?=e(app_url('/login.php?tab=investor'))?>">Investor</a><a class="<?=$tab==='vendor'?'active':''?>" href="<?=e(app_url('/login.php?tab=vendor'))?>">Vendor</a></div>
<?php if($tab==='investor'):?>
<form method="post" class="portal-login-form investor-login-form">
<input type="hidden" name="portal_type" value="investor"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
<h1>Investor Access</h1><p>Use the same email and phone number provided with your inquiry. We will email you a six-digit verification code.</p>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<label>Email Address<input type="email" name="email" autocomplete="email" required autofocus value="<?=e((string)($_POST['email']??''))?>"></label>
<label>Last 4 Digits of Phone<input name="phone_last4" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off" required placeholder="0000" value="<?=e((string)($_POST['phone_last4']??''))?>"></label>
<button class="primary" type="submit">Email Verification Code</button>
<p class="investor-login-note">Potential investors must already exist as a lead or inquiry in ZNP Development's records.</p>
</form>
<?php else:?>
<form method="post" class="portal-login-form"><input type="hidden" name="portal_type" value="vendor"><h1>Portal Login</h1><p>Access the ZNP Development administrative and construction portals.</p><?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?><label>Email or Username<input name="username" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="primary" type="submit">Login</button><a class="admin-forgot-link" href="admin/forgot_password.php">Forgot Password?</a></form>
<?php endif;?>
</section></main>
<?php require __DIR__.'/includes/footer.php';?>
