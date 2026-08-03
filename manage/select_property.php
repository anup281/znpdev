<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';

if($_SERVER['REQUEST_METHOD']!=='POST'||!csrf_check((string)($_POST['csrf_token']??''))){http_response_code(419);exit('Your session expired.');}
$propertyId=(int)($_POST['property_id']??0);
if(!manage_can_access_property($propertyId)){http_response_code(403);exit('Property access denied.');}

$_SESSION['management_property_id']=$propertyId;
$returnTo=(string)($_POST['return_to']??'index.php');
$returnPath=(string)(parse_url($returnTo,PHP_URL_PATH)??'');
if($returnPath===''||basename($returnPath)!==$returnPath||!preg_match('/^[a-zA-Z0-9_-]+\.php$/',$returnPath))$returnPath='index.php';
$returnParams=[];
parse_str((string)(parse_url($returnTo,PHP_URL_QUERY)??''),$requestedParams);
foreach(['year','month','analysis_run_id'] as $key){if(isset($requestedParams[$key])&&is_scalar($requestedParams[$key]))$returnParams[$key]=(int)$requestedParams[$key];}
header('Location: '.$returnPath.($returnParams?'?'.http_build_query($returnParams):''));
exit;
