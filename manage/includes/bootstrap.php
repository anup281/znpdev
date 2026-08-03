<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/workspace_bootstrap.php';
require_once __DIR__ . '/../../includes/storage.php';
$managementContext = znp_workspace_bootstrap('management');
$managementUser = $managementContext['user'];
$currentManagementPage = $managementContext['current_page'];

if (!function_exists('manage_e')) {
    function manage_e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('manage_nav_active')) {
    function manage_nav_active(array $pages): string {
        global $currentManagementPage;
        return in_array($currentManagementPage, $pages, true) ? 'active' : '';
    }
}
function manage_is_admin(): bool {
    if(isset($_SESSION['super_admin_user'])&&is_array($_SESSION['super_admin_user']))return false;
    return management_admin_role($GLOBALS['managementUser']??null);
}
function manage_require_admin(): void { if(!manage_is_admin()){http_response_code(403);exit('Only a Super Admin may access this page.');} }
function manage_require_s4_storage(): void {
    if(!znp_storage_uses_s4())throw new RuntimeException('Management Portal uploads require MEGA S4 storage. Enable MEGA S4 in Admin Settings before uploading files.');
}
function manage_plain_bank_value(mixed $value): string {
    $stored=(string)$value;
    return str_starts_with($stored,'enc:')||str_starts_with($stored,'plain:')?decrypt_setting($stored):$stored;
}
function manage_schema_ready(): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=(bool)db()->query("SHOW TABLES LIKE 'management_properties'")->fetchColumn()&&(bool)db()->query("SHOW TABLES LIKE 'management_property_users'")->fetchColumn();}
    catch(Throwable $e){$ready=false;}return $ready;
}
function manage_properties(bool $includeInactive=false): array {
    if(!manage_schema_ready())return [];
    $active=$includeInactive?'':' AND mp.is_active=1';
    if(manage_is_admin())return db()->query("SELECT mp.* FROM management_properties mp WHERE 1=1$active ORDER BY mp.property_name")->fetchAll();
    $s=db()->prepare("SELECT mp.* FROM management_properties mp JOIN management_property_users mpu ON mpu.management_property_id=mp.id WHERE mpu.admin_user_id=?$active ORDER BY mp.property_name");
    $s->execute([(int)($GLOBALS['managementUser']['id']??0)]);return $s->fetchAll();
}
function manage_can_access_property(int $propertyId): bool {
    if($propertyId<1||!manage_schema_ready())return false;
    if(manage_is_admin()){$s=db()->prepare('SELECT 1 FROM management_properties WHERE id=? LIMIT 1');$s->execute([$propertyId]);return (bool)$s->fetchColumn();}
    $s=db()->prepare('SELECT 1 FROM management_property_users mpu JOIN management_properties mp ON mp.id=mpu.management_property_id WHERE mpu.admin_user_id=? AND mpu.management_property_id=? AND mp.is_active=1');
    $s->execute([(int)($GLOBALS['managementUser']['id']??0),$propertyId]);return (bool)$s->fetchColumn();
}
function manage_active_property_id(int $candidate=0): int {
    if($candidate>0&&manage_can_access_property($candidate)){$_SESSION['management_property_id']=$candidate;return $candidate;}
    $stored=(int)($_SESSION['management_property_id']??0);if($stored>0&&manage_can_access_property($stored))return $stored;
    $properties=manage_properties();$fallback=(int)($properties[0]['id']??0);if($fallback>0)$_SESSION['management_property_id']=$fallback;return $fallback;
}
function manage_property(int $propertyId): ?array {
    if(!manage_can_access_property($propertyId))return null;
    $s=db()->prepare('SELECT * FROM management_properties WHERE id=?');$s->execute([$propertyId]);$row=$s->fetch();return $row?:null;
}
function manage_clean_rich_html(string $html): string {
    $html=strip_tags(trim($html),'<p><br><h2><h3><h4><strong><b><em><i><u><ul><ol><li><a><blockquote><hr>');
    $html=preg_replace('/\s(?:on\w+|style|class|id)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i','',$html)??$html;
    $html=preg_replace_callback('/<a\b([^>]*)>/i',static function(array $match): string {
        $href='';
        if(preg_match('/href\s*=\s*("|\')([^"\']*)\1/i',$match[1],$found)){
            $candidate=trim($found[2]);
            if(preg_match('#^(https?://|mailto:|tel:|/|#)#i',$candidate))$href=' href="'.htmlspecialchars($candidate,ENT_QUOTES,'UTF-8').'"';
        }
        return '<a'.$href.(str_starts_with($href,' href="http')?' target="_blank" rel="noopener"':'').'>';
    },$html)??$html;
    return $html;
}
function manage_month_end_schema_ready(): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{
        $restaurant=db()->query("SHOW COLUMNS FROM management_properties LIKE 'has_restaurant'")->fetchColumn();
        $suiteTaxFrequency=db()->query("SHOW COLUMNS FROM management_properties LIKE 'suite_shop_tax_frequency'")->fetchColumn();
        $bank=db()->query("SHOW COLUMNS FROM management_properties LIKE 'bank_account_number'")->fetchColumn();
        $rooms=db()->query("SHOW COLUMNS FROM management_month_end_submissions LIKE 'rooms_occupied'")->fetchColumn();
        $stateTaxAdjustments=db()->query("SHOW COLUMNS FROM management_month_end_submissions LIKE 'state_tax_adjustments'")->fetchColumn();
        $cityTaxAdjustments=db()->query("SHOW COLUMNS FROM management_month_end_submissions LIKE 'city_tax_adjustments'")->fetchColumn();
        $submissions=db()->query("SHOW TABLES LIKE 'management_month_end_submissions'")->fetchColumn();
        $files=db()->query("SHOW TABLES LIKE 'management_month_end_files'")->fetchColumn();
        $taxStatuses=db()->query("SHOW TABLES LIKE 'management_month_end_tax_statuses'")->fetchColumn();
        $ready=(bool)$restaurant&&(bool)$suiteTaxFrequency&&(bool)$bank&&(bool)$rooms&&(bool)$stateTaxAdjustments&&(bool)$cityTaxAdjustments&&(bool)$submissions&&(bool)$files&&(bool)$taxStatuses;
    }catch(Throwable $e){$ready=false;}
    return $ready;
}
function manage_month_end_values(array $source,bool $restaurant,bool $strict=true): array {
    $values=[];
    foreach(['rooms_occupied','rooms_available'] as $field){$raw=trim((string)($source[$field]??''));if($raw==='')$raw='0';if(!ctype_digit($raw))throw new RuntimeException('Rooms Occupied and Rooms Available must be whole numbers.');$values[$field]=(int)$raw;}
    $fields=['room_revenue','suite_shop_revenue','total_revenue','state_tax','state_tax_adjustments','city_tax','city_tax_adjustments','sales_tax'];
    if($restaurant)$fields=array_merge($fields,['liquor_net_sales','beer_net_sales','wine_net_sales','taxable_sales_mix_bev_sales_tax']);
    foreach($fields as $field){$raw=str_replace([',','$',' '],'',(string)($source[$field]??''));if($raw==='')$raw='0';if(!is_numeric($raw)||(float)$raw<0)throw new RuntimeException('Enter valid non-negative dollar amounts.');$values[$field]=number_format((float)$raw,2,'.','');}
    foreach(['liquor_net_sales','beer_net_sales','wine_net_sales','taxable_sales_mix_bev_sales_tax'] as $field)if(!isset($values[$field]))$values[$field]='0.00';
    return $values;
}
function manage_save_month_end_values(int $propertyId,int $year,int $month,array $values,string $status,int $userId): void {
    $columns=['rooms_occupied','rooms_available','room_revenue','suite_shop_revenue','total_revenue','state_tax','state_tax_adjustments','city_tax','city_tax_adjustments','sales_tax','liquor_net_sales','beer_net_sales','wine_net_sales','taxable_sales_mix_bev_sales_tax'];
    $insertColumns=implode(',',array_merge(['management_property_id','report_year','report_month'],$columns,['status','submitted_by_admin_user_id','submitted_at']));
    $placeholders=implode(',',array_fill(0,3+count($columns)+2,'?')).',NOW()';
    $updates=implode(',',array_map(static fn(string $column):string=>"$column=VALUES($column)",$columns));
    $sql="INSERT INTO management_month_end_submissions($insertColumns) VALUES($placeholders) ON DUPLICATE KEY UPDATE $updates,status=IF(VALUES(status)='submitted','submitted',IF(status='finalized','submitted',status)),submitted_by_admin_user_id=VALUES(submitted_by_admin_user_id),submitted_at=NOW()";
    $arguments=[$propertyId,$year,$month];foreach($columns as $column)$arguments[]=$values[$column];$arguments[]=$status;$arguments[]=$userId;db()->prepare($sql)->execute($arguments);
}
function manage_receipts_schema_ready(): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=(bool)db()->query("SHOW TABLES LIKE 'management_receipt_periods'")->fetchColumn()&&(bool)db()->query("SHOW TABLES LIKE 'management_receipt_files'")->fetchColumn()&&(bool)db()->query("SHOW COLUMNS FROM management_receipt_files LIKE 'receipt_group'")->fetchColumn()&&(bool)db()->query("SHOW TABLES LIKE 'management_receipt_transactions'")->fetchColumn()&&(bool)db()->query("SHOW TABLES LIKE 'management_receipt_analysis_runs'")->fetchColumn()&&(bool)db()->query("SHOW TABLES LIKE 'management_receipt_categories'")->fetchColumn()&&(bool)db()->query("SHOW COLUMNS FROM management_receipt_transactions LIKE 'analysis_run_id'")->fetchColumn();}
    catch(Throwable $e){$ready=false;}return $ready;
}
function manage_receipt_payments_schema_ready(): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=(bool)db()->query("SHOW TABLES LIKE 'management_receipt_payments'")->fetchColumn();}
    catch(Throwable $e){$ready=false;}return $ready;
}
function manage_cpa_delivery_schema_ready(): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=(bool)db()->query("SHOW TABLES LIKE 'management_cpa_deliveries'")->fetchColumn();}
    catch(Throwable $e){$ready=false;}return $ready;
}
function manage_cpa_delivery_cc_ready(): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=manage_cpa_delivery_schema_ready()&&(bool)db()->query("SHOW COLUMNS FROM management_cpa_deliveries LIKE 'cc_email'")->fetchColumn();}
    catch(Throwable $e){$ready=false;}return $ready;
}
function manage_receipt_period_sent_to_cpa(int $propertyId,int $year,int $month): bool {
    if($propertyId<1||!manage_cpa_delivery_schema_ready())return false;
    $query=db()->prepare('SELECT 1 FROM management_cpa_deliveries WHERE management_property_id=? AND report_year=? AND report_month=? LIMIT 1');
    $query->execute([$propertyId,$year,$month]);return (bool)$query->fetchColumn();
}
function manage_render_cpa_delivery_history(array $deliveries,int $year,int $month): void {
    $confirm=$deliveries?'This period was already emailed to the CPA. Send it again?':'Email this breakdown and payment history to the CPA?';
    $buttonLabel=$deliveries?'SEND AGAIN TO CPA':'SEND TO CPA';
    echo '<section class="manage-cpa-history"><div class="manage-cpa-history-heading"><h3>CPA Email History</h3><form method="post" action="send_receipts_to_cpa.php" onsubmit="return confirm(\''.manage_e($confirm).'\');"><input type="hidden" name="csrf_token" value="'.manage_e(csrf_token()).'"><input type="hidden" name="year" value="'.$year.'"><input type="hidden" name="month" value="'.$month.'"><button type="submit" class="manage-button primary">'.manage_e($buttonLabel).'</button></form></div>';
    if(!manage_cpa_delivery_schema_ready()){
        echo '<p class="manage-cpa-not-sent">History is not installed. <a href="'.manage_e(app_url('/admin/upgrade_management_cpa_delivery_history.php')).'">Install CPA Delivery History</a>.</p>';
    }elseif(!$deliveries){
        echo '<p class="manage-cpa-not-sent">Not emailed to the CPA for this period.</p>';
    }else{
        echo '<div class="manage-cpa-delivery-list">';
        foreach($deliveries as $delivery){
            $sentAt=date('M j, Y g:i A',strtotime((string)$delivery['sent_at']));
            $sender=trim((string)($delivery['sent_by_name']??''))?:'Super Admin';
            $cc=trim((string)($delivery['cc_email']??''));
            echo '<article><strong>Sent '.manage_e($sentAt).'</strong><span>To '.manage_e($delivery['recipient_email']).($cc!==''?' · CC '.manage_e($cc):'').' · by '.manage_e($sender).'</span></article>';
        }
        echo '</div>';
    }
    echo '</section>';
}
function manage_extract_statement_transactions(string $pdfData,int $reportYear,int $reportMonth): array {
    $autoload=__DIR__.'/../../vendor/autoload.php';if(!class_exists(Smalot\PdfParser\Parser::class)){if(!is_file($autoload))throw new RuntimeException('The PDF parser is not installed.');require_once $autoload;}
    $text=(new Smalot\PdfParser\Parser())->parseContent($pdfData)->getText();$lines=preg_split('/\R/u',$text)?:[];$transactions=[];$cardholder='';$lastFour='';$sectionType='';$hasSectionHeadings=(bool)preg_match('/#\d{4}:\s+Transactions/i',$text);
    foreach($lines as $line){$line=trim(preg_replace('/\s+/u',' ',$line)??'');if($line==='')continue;
        if(preg_match('/^(?<cardholder>.+?)\s+#(?<last4>\d{4}):\s+(?<section>Payments, Credits and Adjustments|Transactions)$/i',$line,$section)){$cardholder=trim($section['cardholder']);$lastFour=$section['last4'];$sectionType=strcasecmp($section['section'],'Transactions')===0?'charge':'payment';continue;}
        if(preg_match('/^(Fees|Interest Charged|Totals Year-to-Date)$/i',$line)){$sectionType='';continue;}
        if($hasSectionHeadings&&$sectionType==='')continue;
        $transactionMonth=0;$transactionDay=0;$description='';$amount=0.0;
        if(preg_match('/^(?<date>[A-Z][a-z]{2}\s+\d{1,2})\s+[A-Z][a-z]{2}\s+\d{1,2}\s+(?<description>.+?)\s+(?<negative>-\s*)?\$(?<amount>[\d,]+\.\d{2})$/',$line,$match)){
            $parsedDate=DateTimeImmutable::createFromFormat('!M j',$match['date']);if(!$parsedDate)continue;$transactionMonth=(int)$parsedDate->format('n');$transactionDay=(int)$parsedDate->format('j');$description=trim($match['description']);$amount=(float)str_replace(',','',$match['amount']);if(!empty($match['negative']))$amount=-abs($amount);
        }elseif(!$hasSectionHeadings&&preg_match('/^(?<date>\d{1,2}\/\d{1,2})(?:\/\d{2,4})?(?:\s+\d{1,2}\/\d{1,2}(?:\/\d{2,4})?)?\s+(?<description>.+?)\s+(?<amount>\(?-?\$?[\d,]+\.\d{2}\)?)(?:\s*(?<credit>CR))?$/i',$line,$match)){
            [$transactionMonth,$transactionDay]=array_map('intval',explode('/',$match['date']));$description=trim($match['description']);$amount=(float)str_replace(['$',',','(',')'],'',$match['amount']);if(str_contains($match['amount'],'(')||!empty($match['credit']))$amount=-abs($amount);
        }else continue;
        if(!checkdate($transactionMonth,$transactionDay,$reportYear))continue;$transactionYear=$reportYear;if($reportMonth===1&&$transactionMonth===12)$transactionYear--;elseif($reportMonth===12&&$transactionMonth===1)$transactionYear++;
        if($description===''||preg_match('/^(previous balance|new balance|minimum payment|credit limit|available credit|total fees|total interest)$/i',$description))continue;$description=mb_substr($description,0,500);
        $type=$sectionType==='payment'?(preg_match('/PYMT|PAYMENT/i',$description)?'payment':'credit'):'charge';
        $transactions[]=['date'=>sprintf('%04d-%02d-%02d',$transactionYear,$transactionMonth,$transactionDay),'cardholder'=>mb_substr($cardholder,0,190),'last_four'=>$lastFour,'type'=>$type,'description'=>$description,'amount'=>number_format($amount,2,'.',''),'source_line'=>mb_substr($line,0,1000)];
    }
    return $transactions;
}
function manage_fees_schema_ready(): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=(bool)db()->query("SHOW TABLES LIKE 'management_fee_periods'")->fetchColumn()&&(bool)db()->query("SHOW TABLES LIKE 'management_fee_files'")->fetchColumn();}
    catch(Throwable $e){$ready=false;}return $ready;
}
function manage_management_fee_records_schema_ready(): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=(bool)db()->query("SHOW TABLES LIKE 'management_fee_records'")->fetchColumn()&&(bool)db()->query("SHOW TABLES LIKE 'management_fee_record_files'")->fetchColumn()&&(bool)db()->query("SHOW COLUMNS FROM management_fee_records LIKE 'fee_basis_revenue'")->fetchColumn()&&(bool)db()->query("SHOW COLUMNS FROM management_fee_records LIKE 'check_number_1'")->fetchColumn()&&(bool)db()->query("SHOW COLUMNS FROM management_fee_records LIKE 'check_number_2'")->fetchColumn()&&(bool)db()->query("SHOW COLUMNS FROM management_properties LIKE 'management_fee_recipient_1'")->fetchColumn()&&(bool)db()->query("SHOW COLUMNS FROM management_properties LIKE 'management_fee_percent_2'")->fetchColumn();}
    catch(Throwable $e){$ready=false;}return $ready;
}
function manage_bank_deposits_schema_ready(): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=(bool)db()->query("SHOW TABLES LIKE 'management_bank_deposit_records'")->fetchColumn()&&(bool)db()->query("SHOW COLUMNS FROM management_bank_deposit_records LIKE 'upload_group'")->fetchColumn()&&(bool)db()->query("SHOW TABLES LIKE 'management_bank_deposit_files'")->fetchColumn();}
    catch(Throwable $e){$ready=false;}return $ready;
}
function manage_store_bank_deposit_files(array $files,int $propertyId,int $year,string $uploadGroup): array {
    manage_require_s4_storage();$names=$files['name']??[];if(!is_array($names))$names=[$names];$allowed=['pdf'=>'application/pdf','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','csv'=>'text/csv','txt'=>'text/plain','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];$stored=[];
    try{foreach($names as $index=>$name){$error=(int)($files['error'][$index]??UPLOAD_ERR_NO_FILE);if($error===UPLOAD_ERR_NO_FILE)continue;if($error!==UPLOAD_ERR_OK)throw new RuntimeException('One of the bank deposit files could not be uploaded.');$size=(int)($files['size'][$index]??0);if($size<1||$size>26214400)throw new RuntimeException('Each bank deposit file must be 25 MB or smaller.');$original=basename((string)$name);$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));if(!isset($allowed[$ext]))throw new RuntimeException('Bank deposit files must be PDF, Excel, CSV, TXT, Word, JPG, PNG, or WebP.');$tmp=(string)($files['tmp_name'][$index]??'');$mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);if(!in_array($ext,['xls','xlsx','doc','docx'],true)&&$mime!==$allowed[$ext])throw new RuntimeException('A bank deposit file does not match its file extension.');$path='uploads/manage/bank-deposits/'.$propertyId.'/'.$year.'/'.$uploadGroup.'/'.bin2hex(random_bytes(16)).'.'.$ext;$absolute=znp_storage_local_path($path);if(!is_dir(dirname($absolute))&&!mkdir(dirname($absolute),0775,true))throw new RuntimeException('The bank deposit upload folder is not writable.');if(!move_uploaded_file($tmp,$absolute))throw new RuntimeException('A bank deposit file could not be saved.');try{znp_storage_put_file($path,$absolute,$mime);if(znp_storage_uses_s4())znp_storage_remove_local($path);}catch(Throwable $e){znp_storage_remove_local($path);throw $e;}$stored[]=['path'=>$path,'name'=>$original,'mime'=>$mime,'size'=>$size];}}catch(Throwable $exception){foreach($stored as $file)try{znp_storage_delete((string)$file['path']);}catch(Throwable $cleanup){}throw $exception;}return $stored;
}
function manage_recalculate_fee_year(int $propertyId,int $year): void {
    $query=db()->prepare('SELECT id,total_revenue,percent_1,percent_2 FROM management_fee_records WHERE management_property_id=? AND report_year=? ORDER BY fee_quarter,entry_date,id');$query->execute([$propertyId,$year]);$recognized=0.0;$update=db()->prepare('UPDATE management_fee_records SET fee_basis_revenue=?,fee_1=?,fee_2=?,total_fee=? WHERE id=?');
    foreach($query->fetchAll() as $record){$basis=(float)$record['total_revenue']-$recognized;$recognized+=$basis;$fee1=$basis*(float)$record['percent_1']/100;$fee2=$basis*(float)$record['percent_2']/100;$update->execute([number_format($basis,2,'.',''),number_format($fee1,2,'.',''),number_format($fee2,2,'.',''),number_format($fee1+$fee2,2,'.',''),(int)$record['id']]);}
}
function manage_store_fee_files(array $files,int $propertyId,int $year,int $month,string $feeType,string $category): array {
    manage_require_s4_storage();
    $names=$files['name']??[];if(!is_array($names))$names=[$names];$allowed=['txt'=>'text/plain','pdf'=>'application/pdf','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','csv'=>'text/csv','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];$stored=[];
    try{foreach($names as $index=>$name){$error=(int)($files['error'][$index]??UPLOAD_ERR_NO_FILE);if($error===UPLOAD_ERR_NO_FILE)continue;if($error!==UPLOAD_ERR_OK)throw new RuntimeException('One of the fee files could not be uploaded.');$size=(int)($files['size'][$index]??0);if($size<1||$size>26214400)throw new RuntimeException('Each fee file must be 25 MB or smaller.');$original=basename((string)$name);$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));if($feeType==='management'&&$category==='records'&&$ext!=='txt')throw new RuntimeException('Manager Flash Backup files must be TXT files.');if(!isset($allowed[$ext]))throw new RuntimeException('Fee files must be TXT, PDF, Excel, CSV, Word, JPG, PNG, or WebP.');$tmp=(string)($files['tmp_name'][$index]??'');$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);if(!in_array($ext,['xls','xlsx','doc','docx'],true)&&$mime!==$allowed[$ext])throw new RuntimeException('A fee file does not match its file extension.');$path='uploads/manage/fees/'.$feeType.'/'.$propertyId.'/'.$year.'/'.str_pad((string)$month,2,'0',STR_PAD_LEFT).'/'.$category.'/'.bin2hex(random_bytes(16)).'.'.$ext;$absolute=znp_storage_local_path($path);if(!is_dir(dirname($absolute))&&!mkdir(dirname($absolute),0775,true))throw new RuntimeException('The fee upload folder is not writable.');if(!move_uploaded_file($tmp,$absolute))throw new RuntimeException('A fee file could not be saved.');try{znp_storage_put_file($path,$absolute,$mime);if(znp_storage_uses_s4())znp_storage_remove_local($path);}catch(Throwable $e){znp_storage_remove_local($path);throw $e;}$stored[]=['path'=>$path,'name'=>$original,'mime'=>$mime,'size'=>$size];}}catch(Throwable $exception){foreach($stored as $file)try{znp_storage_delete((string)$file['path']);}catch(Throwable $cleanup){}throw $exception;}return $stored;
}
function manage_store_receipt_files(array $files,int $propertyId,int $year,int $month,string $category): array {
    manage_require_s4_storage();
    $names=$files['name']??[];if(!is_array($names))$names=[$names];
    $allowed=['pdf'=>'application/pdf','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','csv'=>'text/csv','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    $stored=[];
    try{foreach($names as $index=>$name){
        $error=(int)($files['error'][$index]??UPLOAD_ERR_NO_FILE);if($error===UPLOAD_ERR_NO_FILE)continue;if($error!==UPLOAD_ERR_OK)throw new RuntimeException('One of the receipt files could not be uploaded.');
        $size=(int)($files['size'][$index]??0);if($size<1||$size>26214400)throw new RuntimeException('Each receipt file must be 25 MB or smaller.');
        $original=basename((string)$name);$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
        if(in_array($category,['statement','payment'],true)&&$ext!=='pdf')throw new RuntimeException('Statements and payments must be uploaded as PDF files.');
        if(!isset($allowed[$ext]))throw new RuntimeException('Receipt files must be PDF, Excel, CSV, Word, JPG, PNG, or WebP.');
        $tmp=(string)($files['tmp_name'][$index]??'');$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);if(!in_array($ext,['xls','xlsx','doc','docx'],true)&&$mime!==$allowed[$ext])throw new RuntimeException('A receipt file does not match its file extension.');
        $path='uploads/manage/receipts/'.$propertyId.'/'.$year.'/'.str_pad((string)$month,2,'0',STR_PAD_LEFT).'/'.$category.'/'.bin2hex(random_bytes(16)).'.'.$ext;$absolute=znp_storage_local_path($path);
        if(!is_dir(dirname($absolute))&&!mkdir(dirname($absolute),0775,true))throw new RuntimeException('The receipt upload folder is not writable.');if(!move_uploaded_file($tmp,$absolute))throw new RuntimeException('A receipt file could not be saved.');
        $emailData=null;if($category==='statement'){$emailData=file_get_contents($absolute);if($emailData===false)throw new RuntimeException('The statement PDF could not be prepared for email.');}
        try{znp_storage_put_file($path,$absolute,$mime);if(znp_storage_uses_s4())znp_storage_remove_local($path);}catch(Throwable $e){znp_storage_remove_local($path);throw $e;}
        $stored[]=['path'=>$path,'name'=>$original,'mime'=>$mime,'size'=>$size,'data'=>$emailData];
    }}catch(Throwable $exception){foreach($stored as $file)try{znp_storage_delete((string)$file['path']);}catch(Throwable $cleanup){}throw $exception;}
    return $stored;
}
function manage_store_month_end_files(array $files,int $propertyId,int $year,int $month): array {
    manage_require_s4_storage();
    $names=$files['name']??[];
    if(!is_array($names))$names=[$names];
    $allowed=[
        'pdf'=>'application/pdf','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv'=>'text/csv','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'
    ];
    $stored=[];
    try{foreach($names as $index=>$name){
        $error=(int)($files['error'][$index]??UPLOAD_ERR_NO_FILE);
        if($error===UPLOAD_ERR_NO_FILE)continue;
        if($error!==UPLOAD_ERR_OK)throw new RuntimeException('One of the report files could not be uploaded.');
        $size=(int)($files['size'][$index]??0);
        if($size<1||$size>26214400)throw new RuntimeException('Each report file must be 25 MB or smaller.');
        $original=basename((string)$name);$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
        if(!isset($allowed[$ext]))throw new RuntimeException('Report files must be PDF, Excel, CSV, Word, JPG, PNG, or WebP.');
        $tmp=(string)($files['tmp_name'][$index]??'');$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);
        $officeExtensions=['xls','xlsx','doc','docx'];
        if(!in_array($ext,$officeExtensions,true)&&$mime!==$allowed[$ext])throw new RuntimeException('A report file does not match its file extension.');
        $path='uploads/manage/month-end/'.$propertyId.'/'.$year.'/'.str_pad((string)$month,2,'0',STR_PAD_LEFT).'/'.bin2hex(random_bytes(16)).'.'.$ext;
        $absolute=znp_storage_local_path($path);
        if(!is_dir(dirname($absolute))&&!mkdir(dirname($absolute),0775,true))throw new RuntimeException('The report upload folder is not writable.');
        if(!move_uploaded_file($tmp,$absolute))throw new RuntimeException('A report file could not be saved.');
        try{znp_storage_put_file($path,$absolute,$mime);if(znp_storage_uses_s4())znp_storage_remove_local($path);}
        catch(Throwable $e){znp_storage_remove_local($path);throw $e;}
        $stored[]=['path'=>$path,'name'=>$original,'mime'=>$mime,'size'=>$size];
    }}catch(Throwable $exception){
        foreach($stored as $file)try{znp_storage_delete((string)$file['path']);}catch(Throwable $cleanup){}
        throw $exception;
    }
    return $stored;
}
function manage_store_logo(array $file): ?array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
    manage_require_s4_storage();
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('The logo upload failed.');
    if((int)($file['size']??0)>5242880)throw new RuntimeException('Logo files must be 5 MB or smaller.');
    $ext=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));
    $allowed=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    if(!isset($allowed[$ext]))throw new RuntimeException('Upload a JPG, PNG, or WebP logo.');
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file((string)$file['tmp_name']);
    if($mime!==$allowed[$ext])throw new RuntimeException('The logo content does not match its file extension.');
    $relative='uploads/manage/properties/'.bin2hex(random_bytes(16)).'.'.$ext;$absolute=znp_storage_local_path($relative);
    if(!is_dir(dirname($absolute))&&!mkdir(dirname($absolute),0775,true))throw new RuntimeException('The logo upload folder is not writable.');
    if(!move_uploaded_file((string)$file['tmp_name'],$absolute))throw new RuntimeException('The logo could not be saved.');
    try{znp_storage_put_file($relative,$absolute,$mime);if(znp_storage_uses_s4())znp_storage_remove_local($relative);}
    catch(Throwable $e){znp_storage_remove_local($relative);throw $e;}
    return ['path'=>$relative,'mime'=>$mime];
}
