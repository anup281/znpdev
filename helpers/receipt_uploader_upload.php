<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/storage.php';
require_once __DIR__.'/../includes/receipt_uploader.php';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
header('Content-Type: application/json; charset=utf-8');

function public_receipt_json(bool $ok,string $message,array $extra=[]): never
{
    echo json_encode(array_merge(['ok'=>$ok,'message'=>$message],$extra),JSON_UNESCAPED_SLASHES);exit;
}

if($_SERVER['REQUEST_METHOD']!=='POST')public_receipt_json(false,'Invalid request.');
if(!csrf_check((string)($_POST['csrf_token']??'')))public_receipt_json(false,'Your session expired. Refresh and try again.');
if(!znp_receipt_uploader_is_unlocked())public_receipt_json(false,'Enter the Receipt Uploader passcode again.',['locked'=>true]);
if(trim((string)($_POST['website']??''))!=='')public_receipt_json(true,'Files uploaded.');

$propertyId=(int)($_POST['property_id']??0);$year=(int)($_POST['report_year']??0);$month=(int)($_POST['report_month']??0);$receiptGroup=(string)($_POST['receipt_group']??'');
if(!in_array($receiptGroup,['owner','owner_ach_check'],true))public_receipt_json(false,'Choose Receipts or ACH/CHECK.');
if($year<2026||$year>2045||$month<1||$month>12||($year===2026&&$month<7))public_receipt_json(false,'Choose a valid receipt month.');

$propertyQuery=db()->prepare('SELECT id,property_name FROM management_properties WHERE id=? AND is_active=1');$propertyQuery->execute([$propertyId]);$property=$propertyQuery->fetch();
if(!$property)public_receipt_json(false,'The selected property is unavailable.');

$submittedFiles=array_values(array_filter(znp_receipt_uploader_normalize_files($_FILES['files']??[]),static fn(array $file):bool=>$file['error']!==UPLOAD_ERR_NO_FILE));
try{
    znp_receipt_uploader_rate_limit(count($submittedFiles),true);
    $stored=znp_receipt_uploader_store_files($_FILES['files']??[],$propertyId,$year,$month);
    try{
        db()->beginTransaction();
        db()->prepare("INSERT INTO management_receipt_periods(management_property_id,report_year,report_month,status,updated_by_admin_user_id) VALUES(?,?,?,'submitted',NULL) ON DUPLICATE KEY UPDATE status='submitted'")->execute([$propertyId,$year,$month]);
        $periodQuery=db()->prepare('SELECT id FROM management_receipt_periods WHERE management_property_id=? AND report_year=? AND report_month=?');$periodQuery->execute([$propertyId,$year,$month]);$periodId=(int)$periodQuery->fetchColumn();
        if($periodId<1)throw new RuntimeException('The receipt period could not be created.');
        $insert=db()->prepare('INSERT INTO management_receipt_files(management_receipt_period_id,file_category,receipt_group,file_path,original_name,mime_type,file_size) VALUES(?,?,?,?,?,?,?)');
        foreach($stored as $file)$insert->execute([$periodId,'backup_invoices',$receiptGroup,$file['path'],$file['name'],$file['mime'],$file['size']]);
        db()->commit();
    }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();foreach($stored as $file)try{znp_storage_delete((string)$file['path']);}catch(Throwable $cleanup){}throw $exception;}
    $section=$receiptGroup==='owner_ach_check'?'ACH/CHECK':'Receipts';$count=count($stored);
    public_receipt_json(true,$count.' file'.($count===1?'':'s').' uploaded to '.$property['property_name'].' — '.$section.'.',['count'=>$count,'files'=>array_column($stored,'name')]);
}catch(Throwable $exception){error_log('Public receipt upload failed: '.$exception->getMessage());public_receipt_json(false,$exception instanceof ZnpReceiptUploaderException?$exception->getMessage():'The files could not be uploaded.');}
