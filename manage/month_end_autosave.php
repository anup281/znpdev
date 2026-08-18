<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
function month_end_json(bool $ok,string $message,array $extra=[]): never {
    app_json_result($ok,$message,$extra);
}

if($_SERVER['REQUEST_METHOD']!=='POST')month_end_json(false,'Invalid request.');
if(!hash_equals(csrf_token(),(string)($_POST['csrf_token']??'')))month_end_json(false,'Your session expired. Refresh the page and try again.');
if(!manage_month_end_schema_ready())month_end_json(false,'Month End storage is unavailable.');

$propertyId=(int)($_POST['property_id']??0);$year=(int)($_POST['report_year']??0);$month=(int)($_POST['report_month']??0);
if(!manage_can_access_property($propertyId))month_end_json(false,'Property access denied.');
if($year<2026||$year>2045||$month<1||$month>12||($year===2026&&$month<6))month_end_json(false,'Invalid Month End period.');
$action=(string)($_POST['action']??'save_fields');

try{
    $periodQuery=db()->prepare('SELECT id,status,banquet_tax FROM management_month_end_submissions WHERE management_property_id=? AND report_year=? AND report_month=?');$periodQuery->execute([$propertyId,$year,$month]);$currentPeriod=$periodQuery->fetch()?:null;
    if($action==='reset_month'){
        if(!manage_is_admin())month_end_json(false,'Only a Super Admin may reset a Month End period.');
        if($currentPeriod&&$currentPeriod['status']==='finalized')month_end_json(false,'A finalized month cannot be reset. An Admin must unfinalize it first.');$filePaths=[];if($currentPeriod){$q=db()->prepare('SELECT file_path FROM management_month_end_files WHERE management_month_end_submission_id=?');$q->execute([(int)$currentPeriod['id']]);$filePaths=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));}db()->beginTransaction();if($currentPeriod)db()->prepare('DELETE FROM management_month_end_files WHERE management_month_end_submission_id=?')->execute([(int)$currentPeriod['id']]);db()->prepare('DELETE FROM management_month_end_tax_statuses WHERE management_property_id=? AND report_year=? AND report_month=?')->execute([$propertyId,$year,$month]);if($currentPeriod)db()->prepare('DELETE FROM management_month_end_submissions WHERE id=?')->execute([(int)$currentPeriod['id']]);db()->commit();foreach($filePaths as $filePath)try{znp_storage_delete($filePath);}catch(Throwable $cleanup){error_log('Month End reset file cleanup failed: '.$cleanup->getMessage());}month_end_json(true,'Month End reset.');
    }
    if($currentPeriod&&$currentPeriod['status']==='finalized'&&$action!=='toggle_tax_status')month_end_json(false,'This report is finalized. An Admin must unfinalize it before changes can be made.');
    if($action==='find_opera_calculations'){
        $property=manage_property($propertyId);if((string)($property['pms_system']??'')!=='opera')month_end_json(false,'FIND CALCULATIONS is only available for Opera properties.');$restaurant=!empty($property['has_restaurant']);$requiredPdfCount=$restaurant?4:2;if(!$currentPeriod)month_end_json(false,'Upload all required Opera PDF reports first.');
        try{
            $query=db()->prepare("SELECT original_name,file_path FROM management_month_end_files WHERE management_month_end_submission_id=? AND (LOWER(original_name) LIKE '%.pdf' OR mime_type='application/pdf') ORDER BY id DESC LIMIT 12");$query->execute([(int)$currentPeriod['id']]);$files=$query->fetchAll();if(count($files)<$requiredPdfCount)month_end_json(false,'Upload all '.$requiredPdfCount.' required Opera PDF reports first.');$reports=[];
            foreach($files as $file)$reports[]=['name'=>(string)$file['original_name'],'contents'=>znp_storage_contents((string)$file['file_path'])];require_once __DIR__.'/includes/opera_month_end.php';$values=manage_opera_extract_month_end($reports,$year,$month,$restaurant);$salesTax=round((float)($values['sales_tax']??((float)$values['suite_shop_revenue']*0.0825)),2);
            db()->prepare("UPDATE management_month_end_submissions SET rooms_available=?,rooms_occupied=?,room_revenue=?,suite_shop_revenue=?,state_tax=?,state_tax_adjustments=0,city_tax=?,city_tax_adjustments=0,banquet_tax=?,sales_tax=?,liquor_net_sales=?,beer_net_sales=?,wine_net_sales=?,taxable_sales_mix_bev_sales_tax=?,status='submitted',submitted_by_admin_user_id=?,submitted_at=NOW() WHERE id=?")->execute([(int)$values['rooms_available'],(int)$values['rooms_occupied'],number_format((float)$values['room_revenue'],2,'.',''),number_format((float)$values['suite_shop_revenue'],2,'.',''),number_format((float)$values['state_tax'],2,'.',''),number_format((float)$values['city_tax'],2,'.',''),number_format((float)($values['banquet_tax']??0),2,'.',''),number_format($salesTax,2,'.',''),number_format((float)($values['liquor_net_sales']??0),2,'.',''),number_format((float)($values['beer_net_sales']??0),2,'.',''),number_format((float)($values['wine_net_sales']??0),2,'.',''),number_format((float)($values['taxable_sales_mix_bev_sales_tax']??0),2,'.',''),(int)($managementUser['id']??0),(int)$currentPeriod['id']]);
            month_end_json(true,'Opera calculations found and saved.',['values'=>['rooms_available'=>(int)$values['rooms_available'],'rooms_occupied'=>(int)$values['rooms_occupied'],'room_revenue'=>number_format((float)$values['room_revenue'],2,'.',''),'suite_shop_revenue'=>number_format((float)$values['suite_shop_revenue'],2,'.',''),'state_tax'=>number_format((float)$values['state_tax'],2,'.',''),'city_tax'=>number_format((float)$values['city_tax'],2,'.',''),'banquet_tax'=>number_format((float)($values['banquet_tax']??0),2,'.',''),'sales_tax'=>number_format($salesTax,2,'.',''),'liquor_net_sales'=>number_format((float)($values['liquor_net_sales']??0),2,'.',''),'beer_net_sales'=>number_format((float)($values['beer_net_sales']??0),2,'.',''),'wine_net_sales'=>number_format((float)($values['wine_net_sales']??0),2,'.',''),'taxable_sales_mix_bev_sales_tax'=>number_format((float)($values['taxable_sales_mix_bev_sales_tax']??0),2,'.','')],'period_status'=>'submitted']);
        }catch(Throwable $error){error_log('Opera Month End calculation failed: '.$error->getMessage());month_end_json(false,'The Opera reports could not be validated. Contact Anup for Support.');}
    }
    if($action==='find_hotelkey_calculations'){
        $property=manage_property($propertyId);if((string)($property['pms_system']??'hotel_key')!=='hotel_key')month_end_json(false,'FIND CALCULATIONS is only available for HotelKey properties.');if(!$currentPeriod)month_end_json(false,'Upload both required HotelKey Excel reports first.');
        try{
            $query=db()->prepare("SELECT original_name,file_path FROM management_month_end_files WHERE management_month_end_submission_id=? AND LOWER(original_name) LIKE '%.xlsx' ORDER BY id DESC LIMIT 12");$query->execute([(int)$currentPeriod['id']]);$files=$query->fetchAll();if(count($files)<2)month_end_json(false,'Upload both required HotelKey Excel reports first.');$reports=[];foreach($files as $file)$reports[]=['name'=>(string)$file['original_name'],'contents'=>znp_storage_contents((string)$file['file_path'])];require_once __DIR__.'/includes/hotelkey_month_end.php';$values=manage_hotelkey_extract_month_end($reports,$year,$month);
            db()->prepare("UPDATE management_month_end_submissions SET rooms_available=?,rooms_occupied=?,room_revenue=?,suite_shop_revenue=?,state_tax=?,state_tax_adjustments=0,city_tax=?,city_tax_adjustments=0,banquet_tax=0,sales_tax=?,liquor_net_sales=0,beer_net_sales=0,wine_net_sales=0,taxable_sales_mix_bev_sales_tax=0,status='submitted',submitted_by_admin_user_id=?,submitted_at=NOW() WHERE id=?")->execute([(int)$values['rooms_available'],(int)$values['rooms_occupied'],number_format((float)$values['room_revenue'],2,'.',''),number_format((float)$values['suite_shop_revenue'],2,'.',''),number_format((float)$values['state_tax'],2,'.',''),number_format((float)$values['city_tax'],2,'.',''),number_format((float)$values['sales_tax'],2,'.',''),(int)($managementUser['id']??0),(int)$currentPeriod['id']]);
            month_end_json(true,'HotelKey calculations found and saved.',['values'=>['rooms_available'=>(int)$values['rooms_available'],'rooms_occupied'=>(int)$values['rooms_occupied'],'room_revenue'=>number_format((float)$values['room_revenue'],2,'.',''),'suite_shop_revenue'=>number_format((float)$values['suite_shop_revenue'],2,'.',''),'state_tax'=>number_format((float)$values['state_tax'],2,'.',''),'city_tax'=>number_format((float)$values['city_tax'],2,'.',''),'banquet_tax'=>'0.00','sales_tax'=>number_format((float)$values['sales_tax'],2,'.',''),'liquor_net_sales'=>'0.00','beer_net_sales'=>'0.00','wine_net_sales'=>'0.00','taxable_sales_mix_bev_sales_tax'=>'0.00'],'period_status'=>'submitted']);
        }catch(Throwable $error){error_log('HotelKey Month End calculation failed: '.$error->getMessage());month_end_json(false,'The HotelKey reports could not be validated. Contact Anup for Support.');}
    }
    if($action==='find_fossee_calculations'){
        $property=manage_property($propertyId);if((string)($property['pms_system']??'')!=='fossee')month_end_json(false,'FIND CALCULATIONS is only available for Fossee properties.');if(!$currentPeriod)month_end_json(false,'Upload all four required Fossee PDF reports first.');
        try{
            $query=db()->prepare("SELECT id,original_name,file_path FROM management_month_end_files WHERE management_month_end_submission_id=? AND (LOWER(original_name) LIKE '%.pdf' OR mime_type='application/pdf') ORDER BY id DESC LIMIT 16");$query->execute([(int)$currentPeriod['id']]);$files=$query->fetchAll();if(count($files)<4)month_end_json(false,'Upload all four required Fossee PDF reports first.');require_once __DIR__.'/includes/fossee_month_end.php';$ocrPayload=(string)($_POST['ocr_reports']??'');
            if($ocrPayload!==''){
                if(strlen($ocrPayload)>1048576)throw manage_fossee_scan_error();try{$ocrReports=json_decode($ocrPayload,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $decodeError){throw manage_fossee_support_error();}if(!is_array($ocrReports)||count($ocrReports)<4||count($ocrReports)>8)throw manage_fossee_scan_error();$allowedFiles=[];foreach($files as $file)$allowedFiles[(int)$file['id']]=$file;$reports=[];$seen=[];
                foreach($ocrReports as $ocrReport){$fileId=(int)($ocrReport['file_id']??0);$text=(string)($ocrReport['text']??'');if(isset($seen[$fileId])||!isset($allowedFiles[$fileId])||strlen($text)<250||strlen($text)>200000)throw manage_fossee_scan_error();$seen[$fileId]=true;$reports[]=['name'=>(string)$allowedFiles[$fileId]['original_name'],'text'=>$text];}$values=manage_fossee_extract_from_texts($reports,$year,$month);
            }else{$reports=[];foreach($files as $file)$reports[]=['name'=>(string)$file['original_name'],'contents'=>znp_storage_contents((string)$file['file_path'])];$values=manage_fossee_extract_month_end($reports,$year,$month);}
            db()->prepare("UPDATE management_month_end_submissions SET rooms_available=?,rooms_occupied=?,room_revenue=?,suite_shop_revenue=?,state_tax=?,state_tax_adjustments=0,city_tax=?,city_tax_adjustments=0,banquet_tax=0,sales_tax=?,liquor_net_sales=0,beer_net_sales=0,wine_net_sales=0,taxable_sales_mix_bev_sales_tax=0,status='submitted',submitted_by_admin_user_id=?,submitted_at=NOW() WHERE id=?")->execute([(int)$values['rooms_available'],(int)$values['rooms_occupied'],number_format((float)$values['room_revenue'],2,'.',''),number_format((float)$values['suite_shop_revenue'],2,'.',''),number_format((float)$values['state_tax'],2,'.',''),number_format((float)$values['city_tax'],2,'.',''),number_format((float)$values['sales_tax'],2,'.',''),(int)($managementUser['id']??0),(int)$currentPeriod['id']]);
            month_end_json(true,'Fossee calculations found and saved.',['values'=>['rooms_available'=>(int)$values['rooms_available'],'rooms_occupied'=>(int)$values['rooms_occupied'],'room_revenue'=>number_format((float)$values['room_revenue'],2,'.',''),'suite_shop_revenue'=>number_format((float)$values['suite_shop_revenue'],2,'.',''),'state_tax'=>number_format((float)$values['state_tax'],2,'.',''),'city_tax'=>number_format((float)$values['city_tax'],2,'.',''),'banquet_tax'=>'0.00','sales_tax'=>number_format((float)$values['sales_tax'],2,'.',''),'liquor_net_sales'=>'0.00','beer_net_sales'=>'0.00','wine_net_sales'=>'0.00','taxable_sales_mix_bev_sales_tax'=>'0.00'],'period_status'=>'submitted']);
        }catch(ManageFosseeScanException|ManageFosseePeriodException $error){error_log('Fossee Month End validation failed: '.$error->getMessage());month_end_json(false,$error->getMessage());}
        catch(Throwable $error){error_log('Fossee Month End calculation failed: '.$error->getMessage());month_end_json(false,'Fossee scan processing is unavailable. Contact Anup for Support.');}
    }
    if($action==='save_fields'){
        $property=manage_property($propertyId);$pms=(string)($property['pms_system']??'hotel_key');if(in_array($pms,['opera','hotel_key','fossee'],true))month_end_json(false,'Month End values for this PMS can only be created from uploaded reports.');$source=$_POST;$values=manage_month_end_values($source,!empty($property['has_restaurant']),false);
        manage_save_month_end_values($propertyId,$year,$month,$values,'blank',(int)($managementUser['id']??0));
        $statusQuery=db()->prepare('SELECT status FROM management_month_end_submissions WHERE management_property_id=? AND report_year=? AND report_month=?');$statusQuery->execute([$propertyId,$year,$month]);
        month_end_json(true,'Saved',['period_status'=>(string)$statusQuery->fetchColumn()]);
    }
    if($action==='toggle_tax_status'){
        if(!manage_is_admin())month_end_json(false,'Only a Super Admin may change tax submission statuses.');
        $taxKey=(string)($_POST['tax_key']??'');$property=manage_property($propertyId);
        $allowed=['city_tax','state_tax','suite_shop_tax'];
        if((float)($currentPeriod['banquet_tax']??0)>0)$allowed[]='banquet_tax';
        if(!empty($property['has_restaurant']))$allowed=array_merge($allowed,['mixed_beverage_gross_receipts','mixed_beverage_sales_tax']);
        if(!in_array($taxKey,$allowed,true))month_end_json(false,'Invalid tax card.');
        $query=db()->prepare('SELECT status FROM management_month_end_tax_statuses WHERE management_property_id=? AND report_year=? AND report_month=? AND tax_key=?');
        $query->execute([$propertyId,$year,$month,$taxKey]);$newStatus=$query->fetchColumn()==='submitted'?'unsubmitted':'submitted';
        db()->prepare('INSERT INTO management_month_end_tax_statuses(management_property_id,report_year,report_month,tax_key,status,updated_by_admin_user_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),updated_by_admin_user_id=VALUES(updated_by_admin_user_id)')->execute([$propertyId,$year,$month,$taxKey,$newStatus,(int)($managementUser['id']??0)]);
        month_end_json(true,'Tax status updated.',['tax_status'=>$newStatus]);
    }
    if($action==='upload'){
        $property=manage_property($propertyId);$pms=(string)($property['pms_system']??'hotel_key');$names=$_FILES['report_files']['name']??[];if(!is_array($names))$names=[$names];if(in_array($pms,['opera','fossee'],true))foreach($names as $name)if((string)$name!==''&&strtolower(pathinfo((string)$name,PATHINFO_EXTENSION))!=='pdf')month_end_json(false,ucfirst($pms).' Month End accepts PDF reports only.');if($pms==='hotel_key')foreach($names as $name)if((string)$name!==''&&strtolower(pathinfo((string)$name,PATHINFO_EXTENSION))!=='xlsx')month_end_json(false,'HotelKey Month End accepts XLSX reports only.');
        $uploads=manage_store_month_end_files($_FILES['report_files']??[],$propertyId,$year,$month);
        if(!$uploads)month_end_json(false,'Choose at least one file.');
        try{
            db()->beginTransaction();
            $isAutomated=in_array($pms,['opera','hotel_key','fossee'],true);
            $submissionSql=$isAutomated?"INSERT INTO management_month_end_submissions(management_property_id,report_year,report_month,status,submitted_by_admin_user_id,submitted_at) VALUES(?,?,?,'blank',?,NOW()) ON DUPLICATE KEY UPDATE rooms_available=0,rooms_occupied=0,room_revenue=0,suite_shop_revenue=0,state_tax=0,state_tax_adjustments=0,city_tax=0,city_tax_adjustments=0,banquet_tax=0,sales_tax=0,liquor_net_sales=0,beer_net_sales=0,wine_net_sales=0,taxable_sales_mix_bev_sales_tax=0,status='blank',submitted_by_admin_user_id=VALUES(submitted_by_admin_user_id),submitted_at=NOW()":"INSERT INTO management_month_end_submissions(management_property_id,report_year,report_month,status,submitted_by_admin_user_id,submitted_at) VALUES(?,?,?,'blank',?,NOW()) ON DUPLICATE KEY UPDATE status=IF(status='finalized','submitted',status),submitted_by_admin_user_id=VALUES(submitted_by_admin_user_id),submitted_at=NOW()";
            db()->prepare($submissionSql)->execute([$propertyId,$year,$month,(int)($managementUser['id']??0)]);
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
        $property=manage_property($propertyId);$deleteUpdate=in_array((string)($property['pms_system']??'hotel_key'),['opera','hotel_key','fossee'],true)?"UPDATE management_month_end_submissions SET rooms_available=0,rooms_occupied=0,room_revenue=0,suite_shop_revenue=0,state_tax=0,state_tax_adjustments=0,city_tax=0,city_tax_adjustments=0,banquet_tax=0,sales_tax=0,liquor_net_sales=0,beer_net_sales=0,wine_net_sales=0,taxable_sales_mix_bev_sales_tax=0,status='blank' WHERE id=?":"UPDATE management_month_end_submissions SET status=IF(status='finalized','submitted',status) WHERE id=?";db()->prepare($deleteUpdate)->execute([(int)$file['management_month_end_submission_id']]);
        $statusQuery=db()->prepare('SELECT status FROM management_month_end_submissions WHERE id=?');$statusQuery->execute([(int)$file['management_month_end_submission_id']]);
        month_end_json(true,'File deleted.',['period_status'=>(string)$statusQuery->fetchColumn()]);
    }
    month_end_json(false,'Invalid action.');
}catch(Throwable $exception){
    error_log('Month End autosave failed: '.$exception->getMessage());
    month_end_json(false,'The change could not be saved. Please try again.');
}
