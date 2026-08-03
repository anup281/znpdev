<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_login();
if($_SERVER['REQUEST_METHOD']!=='POST'||!csrf_check((string)($_POST['csrf_token']??''))){http_response_code(419);exit('Your session expired.');}

if(isset($_POST['stop'])){
    $original=$_SESSION['super_admin_user']??null;
    if(!is_array($original)||!super_admin_role($original)){http_response_code(403);exit('No Super Admin session is available.');}
    $returnTo=(string)($_POST['return_to']??'');$returnPath=(string)(parse_url($returnTo,PHP_URL_PATH)??'');$returnQuery=(string)(parse_url($returnTo,PHP_URL_QUERY)??'');$basePath=app_base_path();$allowed=false;
    foreach(['/admin/','/dev/','/manage/'] as $portalPath){if(str_starts_with($returnPath,$basePath.$portalPath)){$allowed=true;break;}}
    if(!$allowed||preg_match('/[\x00-\x1F\x7F]/',$returnTo))$returnTo=app_url('/admin/users.php');else $returnTo=$returnPath.($returnQuery!==''?'?'.$returnQuery:'');
    session_regenerate_id(true);$_SESSION['admin_user']=$original;unset($_SESSION['super_admin_user']);
    header('Location: '.$returnTo);exit;
}

$current=admin_user();
if(!super_admin_role($current)||isset($_SESSION['super_admin_user'])){http_response_code(403);exit('Only a Super Admin may view the portal as another user.');}
$targetId=(int)($_POST['user_id']??0);$statement=db()->prepare('SELECT * FROM admin_users WHERE id=? AND is_active=1');$statement->execute([$targetId]);$target=$statement->fetch();
if(!$target||super_admin_role($target)){http_response_code(422);exit('Select an active non-Super-Admin user.');}
session_regenerate_id(true);$_SESSION['super_admin_user']=$current;$_SESSION['admin_user']=portal_session_user($target);$_SESSION['admin_user']['must_change_password']=0;
error_log('Super Admin '.(int)($current['id']??0).' started View As session for user '.(int)$target['id'].'.');
header('Location: '.login_destination($_SESSION['admin_user']));exit;
