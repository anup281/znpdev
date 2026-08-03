<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function month_end_json(bool $ok,string $message,array $extra=[]): never {
    echo json_encode(array_merge(['ok'=>$ok,'message'=>$message],$extra),JSON_UNESCAPED_SLASHES);
    exit;
}

if($_SERVER['REQUEST_METHOD']!=='POST')month_end_json(false,'Invalid request.');
if(!hash_equals(csrf_token(),(string)($_POST['csrf_token']??'')))month_end_json(false,'Your session expired. Refresh the page and try again.');
if(!manage_month_end_schema_ready())month_end_json(false,'Month End storage is unavailable.');

$propertyId=(int)($_POST['property_id']??0);$year=(int)($_POST['report_year']??0);$month=(int)($_POST['report_month']??0);
if(!manage_can_access_property($propertyId))month_end_json(false,'Property access denied.');
if($year<2026||$year>2045||$month<1||$month>12||($year===2026&&$month<7))month_end_json(false,'Invalid Month End period.');
$action=(string)($_POST['action']??'save_fields');

try{
    $periodQuery=db()->prepare('SELECT id,status FROM management_month_end_submissions WHERE management_property_id=? AND report_year=? AND report_month=?');$periodQuery->execute([$propertyId,$year,$month]);$currentPeriod=$periodQuery->fetch()?:null;
    if($action==='reset_month'){
        if(!manage_is_admin())month_end_json(false,'Only a Super Admin may reset a month.');$filePaths=[];if($currentPeriod){$q=db()->prepare('SELECT file_path FROM management_month_end_files WHERE management_month_end_submission_id=?');$q->execute([(int)$currentPeriod['id']]);$filePaths=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));}db()->beginTransaction();if($currentPeriod)db()->prepare('DELETE FROM management_month_end_files WHERE management_month_end_submission_id=?')->execute([(int)$currentPeriod['id']]);db()->prepare('DELETE FROM management_month_end_tax_statuses WHERE management_property_id=? AND report_year=? AND report_month=?')->execute([$propertyId,$year,$month]);if($currentPeriod)db()->prepare('DELETE FROM management_month_end_submissions WHERE id=?')->execute([(int)$currentPeriod['id']]);db()->commit();foreach($filePaths as $filePath)try{znp_storage_delete($filePath);}catch(Throwable $cleanup){error_log('Month End reset file cleanup failed: '.$cleanup->getMessage());}month_end_json(true,'Month End reset.');
    }
    if($currentPeriod&&$currentPeriod['status']==='finalized'&&$action!=='toggle_tax_status')month_end_json(false,'This report is finalized. An Admin must unfinalize it before changes can be made.');
    if($action==='save_fields'){
        $property=manage_property($propertyId);$values=manage_month_end_values($_POST,!empty($property['has_restaurant']),false);
        manage_save_month_end_values($propertyId,$year,$month,$values,'blank',(int)($managementUser['id']??0));
        $statusQuery=db()->prepare('SELECT status FROM management_month_end_submissions WHERE management_property_id=? AND report_year=? AND report_month=?');$statusQuery->execute([$propertyId,$year,$month]);
        month_end_json(true,'Saved',['period_status'=>(string)$statusQuery->fetchColumn()]);
    }
    if($action==='toggle_tax_status'){
        if(!manage_is_admin())month_end_json(false,'Only a Super Admin may change tax submission statuses.');
        $taxKey=(string)($_POST['tax_key']??'');$property=manage_property($propertyId);
        $allowed=['city_tax','state_tax','suite_shop_tax'];
        if(!empty($property['has_restaurant']))$allowed=array_merge($allowed,['mixed_beverage_gross_receipts','mixed_beverage_sales_tax']);
        if(!in_array($taxKey,$allowed,true))month_end_json(false,'Invalid tax card.');
        $query=db()->prepare('SELECT status FROM management_month_end_tax_statuses WHERE management_property_id=? AND report_year=? AND report_month=? AND tax_key=?');
        $query->execute([$propertyId,$year,$month,$taxKey]);$newStatus=$query->fetchColumn()==='submitted'?'unsubmitted':'submitted';
        db()->prepare('INSERT INTO management_month_end_tax_statuses(management_property_id,report_year,report_month,tax_key,status,updated_by_admin_user_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),updated_by_admin_user_id=VALUES(updated_by_admin_user_id)')->execute([$propertyId,$year,$month,$taxKey,$newStatus,(int)($managementUser['id']??0)]);
        month_end_json(true,'Tax status updated.',['tax_status'=>$newStatus]);
    }
    if($action==='upload'){
        $uploads=manage_store_month_end_files($_FILES['report_files']??[],$propertyId,$year,$month);
        if(!$uploads)month_end_json(false,'Choose at least one file.');
        try{
            db()->beginTransaction();
            db()->prepare("INSERT INTO management_month_end_submissions(management_property_id,report_year,report_month,status,submitted_by_admin_user_id,submitted_at) VALUES(?,?,?,'blank',?,NOW()) ON DUPLICATE KEY UPDATE status=IF(status='finalized','submitted',status),submitted_by_admin_user_id=VALUES(submitted_by_admin_user_id),submitted_at=NOW()")->execute([$propertyId,$year,$month,(int)($managementUser['id']??0)]);
            $find=db()->prepare('SELECT id FROM management_month_end_submissions WHERE management_property_id=? AND report_year=? AND report_month=?');$find->execute([$propertyId,$year,$month]);$submissionId=(int)$find->fetchColumn();
            $insert=db()->prepare('INSERT INTO management_month_end_files(management_month_end_submission_id,file_path,original_name,mime_type,file_size) VALUES(?,?,?,?,?)');$items=[];
            foreach($uploads as $file){$insert->execute([$submissionId,$file['path'],$file['name'],$file['mime'],$file['size']]);$items[]=['id'=>(int)db()->lastInsertId(),'name'=>$file['name'],'url'=>'month_end_file.php?id='.(int)db()->lastInsertId()];}
            $statusQuery=db()->prepare('SELECT status FROM management_month_end_submissions WHERE id=?');$statusQuery->execute([$submissionId]);$periodStatus=(string)$statusQuery->fetchColumn();
            db()->commit();month_end_json(true,count($items)===1?'File uploaded.':'Files uploaded.',['files'=>$items,'period_status'=>$periodStatus]);
        }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();foreach($uploads as $file)try{znp_storage_delete((string)$file['path']);}catch(Throwable $cleanup){}throw $exception;}
    }
    if($action==='delete_file'){
        $fileId=(int)($_POST['file_id']??0);$query=db()->prepare('SELECT f.*,s.management_property_id FROM management_month_end_files f JOIN management_month_end_submissions s ON s.id=f.management_month_end_submission_id WHERE f.id=?');$query->execute([$fileId]);$file=$query->fetch();
        if(!$file||(int)$file['management_property_id']!==$propertyId)month_end_json(false,'File not found.');
        znp_storage_delete((string)$file['file_path']);db()->prepare('DELETE FROM management_month_end_files WHERE id=?')->execute([$fileId]);
        db()->prepare("UPDATE management_month_end_submissions SET status=IF(status='finalized','submitted',status) WHERE id=?")->execute([(int)$file['management_month_end_submission_id']]);
        $statusQuery=db()->prepare('SELECT status FROM management_month_end_submissions WHERE id=?');$statusQuery->execute([(int)$file['management_month_end_submission_id']]);
        month_end_json(true,'File deleted.',['period_status'=>(string)$statusQuery->fetchColumn()]);
    }
    month_end_json(false,'Invalid action.');
}catch(Throwable $exception){
    error_log('Month End autosave failed: '.$exception->getMessage());
    month_end_json(false,'The change could not be saved. Please try again.');
}
