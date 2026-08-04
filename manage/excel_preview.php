<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
$excelPreviewFinished=false;
register_shutdown_function(static function()use(&$excelPreviewFinished):void{
    if($excelPreviewFinished)return;
    $fatal=error_get_last();
    if(!$fatal||!in_array((int)$fatal['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
    error_log('Excel preview fatal error: '.(string)$fatal['message']);
    if(!headers_sent()){
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'");
    }
    $detail=manage_is_admin()?'<p class="detail">'.manage_e((string)$fatal['message']).'</p>':'';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Excel Preview</title><style>body{display:grid;min-height:100vh;margin:0;place-items:center;background:#eef2f5;color:#38536a;font-family:Arial,sans-serif}.error{max-width:620px;padding:28px;text-align:center}.error h1{color:#203f59;font-size:20px}.detail{padding:10px;border-radius:8px;background:#fff;color:#8b2d24;font-size:12px;overflow-wrap:anywhere}</style></head><body><div class="error"><h1>Excel preview unavailable</h1><p>The workbook renderer stopped unexpectedly. Use Download while this is being corrected.</p>'.$detail.'</div></body></html>';
});
require_once __DIR__.'/includes/xlsx_preview.php';

$source=(string)($_GET['source']??'');$id=(int)($_GET['id']??0);$sheet=(int)($_GET['sheet']??0);$file=null;
switch($source){
    case 'bank_deposit_file.php':$query=db()->prepare('SELECT * FROM management_bank_deposit_files WHERE id=?');$query->execute([$id]);$file=$query->fetch()?:null;break;
    case 'fee_file.php':manage_require_admin();$query=db()->prepare('SELECT f.*,p.management_property_id FROM management_fee_files f JOIN management_fee_periods p ON p.id=f.management_fee_period_id WHERE f.id=?');$query->execute([$id]);$file=$query->fetch()?:null;break;
    case 'management_fee_record_file.php':manage_require_admin();$query=db()->prepare('SELECT f.*,r.management_property_id FROM management_fee_record_files f JOIN management_fee_records r ON r.id=f.management_fee_record_id WHERE f.id=?');$query->execute([$id]);$file=$query->fetch()?:null;break;
    case 'month_end_file.php':$query=db()->prepare('SELECT f.*,s.management_property_id FROM management_month_end_files f JOIN management_month_end_submissions s ON s.id=f.management_month_end_submission_id WHERE f.id=?');$query->execute([$id]);$file=$query->fetch()?:null;break;
    case 'receipt_file.php':$query=db()->prepare('SELECT f.*,p.management_property_id FROM management_receipt_files f JOIN management_receipt_periods p ON p.id=f.management_receipt_period_id WHERE f.id=?');$query->execute([$id]);$file=$query->fetch()?:null;if($file&&!manage_is_admin()&&(in_array((string)($file['receipt_group']??''),['owner','owner_ach_check'],true)||(string)($file['file_category']??'')==='payment'))$file=null;break;
}
if(!$file||!manage_can_access_property((int)$file['management_property_id'])||strtolower(pathinfo((string)$file['original_name'],PATHINFO_EXTENSION))!=='xlsx'){http_response_code(404);exit('Excel file not found.');}
try{header('Content-Type: text/html; charset=utf-8');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'");header('X-Content-Type-Options: nosniff');echo manage_xlsx_preview(znp_storage_contents((string)$file['file_path']),(string)$file['original_name'],$sheet,['source'=>$source,'id'=>$id]);}
catch(Throwable $error){error_log('Excel preview failed: '.$error->getMessage());http_response_code(422);?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Excel Preview</title><style>body{display:grid;min-height:100vh;margin:0;place-items:center;background:#eef2f5;color:#38536a;font-family:Arial,sans-serif}.error{max-width:560px;padding:28px;text-align:center}.error h1{color:#203f59;font-size:20px}</style></head><body><div class="error"><h1>Excel preview unavailable</h1><p><?=manage_e($error->getMessage())?></p><p>Use Download to open this workbook in Excel.</p></div></body></html><?php }
$excelPreviewFinished=true;
