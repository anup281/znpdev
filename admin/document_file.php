<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
$id=(int)($_GET['id']??0);
$template=$id>0?document_template_by_id($id,false):null;
if($template&&investments_only_role()&&(empty($template['investment_opportunity_id'])||!can_access_investment((int)$template['investment_opportunity_id']))){http_response_code(403);exit('You do not have access to this investment document.');}
if(!$template||empty($template['template_path'])){http_response_code(404);exit('Document not found.');}
$root=realpath(__DIR__.'/../data/documents');
$file=realpath(__DIR__.'/../'.ltrim((string)$template['template_path'],'/'));
if(!$root||!$file||!is_file($file)||strpos($file,$root.DIRECTORY_SEPARATOR)!==0){http_response_code(404);exit('Document not found.');}
$name=basename($file);
$mime=(string)($template['attachment_mime']??'application/octet-stream');
$mode=($_GET['mode']??'inline')==='download'?'attachment':'inline';
header('X-Content-Type-Options: nosniff');
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($file));
header("Content-Disposition: {$mode}; filename*=UTF-8''".rawurlencode($name));
readfile($file);
exit;
