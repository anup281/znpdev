<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';

$fileId=(int)($_GET['id']??0);
$query=db()->prepare('SELECT f.*,s.management_property_id FROM management_month_end_files f JOIN management_month_end_submissions s ON s.id=f.management_month_end_submission_id WHERE f.id=?');
$query->execute([$fileId]);$file=$query->fetch();
if(!$file||!manage_can_access_property((int)$file['management_property_id'])){http_response_code(404);exit('File not found.');}
$extension=strtolower(pathinfo((string)$file['original_name'],PATHINFO_EXTENSION));
if($extension!=='pdf'&&(string)$file['mime_type']!=='application/pdf'){http_response_code(415);exit('PDF required.');}
try{$contents=znp_storage_contents((string)$file['file_path']);}
catch(Throwable $error){error_log('Month End OCR file read failed: '.$error->getMessage());http_response_code(404);exit('File not found.');}
header('Content-Type: application/pdf');header('Content-Length: '.strlen($contents));header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');echo $contents;
