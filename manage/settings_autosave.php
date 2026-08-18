<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
manage_require_admin();
function manage_settings_json(bool $ok,string $message,int $status=200):never{app_json_result($ok,$message,[],$status);}
if($_SERVER['REQUEST_METHOD']!=='POST')manage_settings_json(false,'POST requests only.',405);
if(!hash_equals(csrf_token(),(string)($_POST['csrf_token']??'')))manage_settings_json(false,'Your session expired. Refresh and try again.',419);
try{
 $cpaName=trim((string)($_POST['cpa_name']??''));
 $cpaEmail=strtolower(trim((string)($_POST['cpa_email']??'')));
 if($cpaEmail!==''&&!filter_var($cpaEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid CPA email address.');
 setting_save('management_cpa_name',$cpaName);
 setting_save('management_cpa_email',$cpaEmail);
 manage_settings_json(true,'Saved');
}catch(Throwable $error){manage_settings_json(false,$error->getMessage(),422);}
