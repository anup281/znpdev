<?php
declare(strict_types=1);
require __DIR__.'/includes/auth.php';

if (admin_user()) { header('Location: '.login_destination()); exit; }

$pageTitle='Login | ZNP Development';
$activePage='login';
$bodyClass='public-login-page';
$error='';
$message=isset($_GET['reset'])?'Your password was updated. You can sign in now.':'';

if($_SERVER['REQUEST_METHOD']==='POST'){
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
require __DIR__.'/includes/header.php';
?>
<main class="public-login-shell"><section class="public-login-card">
<form method="post" class="portal-login-form"><h1>Portal Login</h1><p>Access the ZNP Development administrative and construction portals.</p><?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?><label>Email or Username<input name="username" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="primary" type="submit">Login</button><a class="admin-forgot-link" href="admin/forgot_password.php">Forgot Password?</a></form>
</section></main>
<?php require __DIR__.'/includes/footer.php';?>
