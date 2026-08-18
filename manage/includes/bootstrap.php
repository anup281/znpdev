<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/workspace_bootstrap.php';
require_once __DIR__ . '/../../includes/storage.php';
require_once __DIR__ . '/uploads.php';
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
    return db_schema_ready(['management_properties','management_property_users']);
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
    return db_schema_ready(
        ['management_month_end_submissions','management_month_end_files','management_month_end_tax_statuses'],
        [
            'management_properties'=>['has_restaurant','pms_system','suite_shop_tax_frequency','bank_account_number','state_tax_url','city_tax_url','tax_notes'],
            'management_month_end_submissions'=>['rooms_occupied','state_tax_adjustments','city_tax_adjustments','banquet_tax'],
        ]
    );
}
function manage_month_end_values(array $source,bool $restaurant,bool $strict=true): array {
    $values=[];
    foreach(['rooms_available','rooms_occupied'] as $field){$raw=trim((string)($source[$field]??''));if($raw==='')$raw='0';if(!ctype_digit($raw))throw new RuntimeException('Total Rooms Available and Total Rooms Sold must be whole numbers.');$values[$field]=(int)$raw;}
    $fields=['room_revenue','suite_shop_revenue','total_revenue','state_tax','state_tax_adjustments','city_tax','city_tax_adjustments'];
    if($restaurant)$fields=array_merge($fields,['liquor_net_sales','beer_net_sales','wine_net_sales','taxable_sales_mix_bev_sales_tax']);
    foreach($fields as $field){$raw=str_replace([',','$',' '],'',(string)($source[$field]??''));if($raw==='')$raw='0';if(!is_numeric($raw)||(float)$raw<0)throw new RuntimeException('Enter valid non-negative dollar amounts.');$values[$field]=number_format((float)$raw,2,'.','');}
    $values['sales_tax']=number_format((float)$values['suite_shop_revenue']*0.0825,2,'.','');
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
    return db_schema_ready(
        ['management_receipt_periods','management_receipt_files','management_receipt_transactions','management_receipt_analysis_runs','management_receipt_categories'],
        ['management_receipt_files'=>['receipt_group'],'management_receipt_transactions'=>['analysis_run_id','receipt_found']]
    );
}
function manage_receipt_file_comments_ready(): bool {
    return db_schema_ready([],['management_receipt_files'=>['file_comment']]);
}
function manage_owner_receipt_matching_ready(): bool {
    return db_schema_ready(
        ['management_receipt_card_settings'],
        ['management_receipt_files'=>['owner_receipt_number'],'management_receipt_transactions'=>['owner_receipt_file_id']]
    );
}
function manage_receipt_payments_schema_ready(): bool {
    return db_schema_ready(['management_receipt_payments']);
}
function manage_cpa_delivery_schema_ready(): bool {
    return db_schema_ready(['management_cpa_deliveries']);
}
function manage_cpa_delivery_cc_ready(): bool {
    return manage_cpa_delivery_schema_ready()&&db_schema_ready([],['management_cpa_deliveries'=>['cc_email']]);
}
function manage_receipt_period_sent_to_cpa(int $propertyId,int $year,int $month): bool {
    if($propertyId<1||!manage_cpa_delivery_schema_ready())return false;
    $query=db()->prepare('SELECT 1 FROM management_cpa_deliveries WHERE management_property_id=? AND report_year=? AND report_month=? LIMIT 1');
    $query->execute([$propertyId,$year,$month]);return (bool)$query->fetchColumn();
}
function manage_render_cpa_delivery_history(array $deliveries,int $year,int $month,float $remainingBalance,int $uncategorizedCount,int $receiptCount): void {
    $confirm=$deliveries?'This period was already emailed to the CPA. Send it again?':'Email this breakdown and payment history to the CPA?';
    $buttonLabel='SEND TO CPA';
    $canSend=$receiptCount>0&&$uncategorizedCount===0&&abs($remainingBalance)<0.005;
    $readinessMessage=$receiptCount<1?'Upload and analyze a statement before sending to the CPA.':($uncategorizedCount>0?'Categorize the remaining '.$uncategorizedCount.' receipt'.($uncategorizedCount===1?'':'s').' before sending to the CPA.':'Pay the remaining balance of $'.number_format(abs($remainingBalance),2).' before sending to the CPA.');
    echo '<section class="manage-cpa-history" data-cpa-uncategorized="'.$uncategorizedCount.'" data-cpa-receipt-count="'.$receiptCount.'"><div class="manage-cpa-history-heading"><h3>CPA Email History</h3><form method="post" action="send_receipts_to_cpa.php" onsubmit="return confirm(\''.manage_e($confirm).'\');"><input type="hidden" name="csrf_token" value="'.manage_e(csrf_token()).'"><input type="hidden" name="year" value="'.$year.'"><input type="hidden" name="month" value="'.$month.'"><button type="submit" class="manage-button manage-cpa-send-status '.($canSend?'ready':'not-ready').'" data-cpa-send'.($canSend?'':' disabled').'>'.manage_e($canSend?$buttonLabel:'NOT READY TO SEND TO CPA').'</button></form></div><p class="manage-cpa-not-sent" data-cpa-readiness'.($canSend?' hidden':'').'>'.manage_e($readinessMessage).'</p>';
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
    return db_schema_ready(['management_fee_periods','management_fee_files']);
}
function manage_management_fee_records_schema_ready(): bool {
    return db_schema_ready(
        ['management_fee_records','management_fee_record_files'],
        ['management_fee_records'=>['fee_basis_revenue','check_number_1','check_number_2'],'management_properties'=>['management_fee_recipient_1','management_fee_percent_2']]
    );
}
function manage_bank_deposits_schema_ready(): bool {
    return db_schema_ready(
        ['management_bank_deposit_records','management_bank_deposit_files'],
        ['management_bank_deposit_records'=>['upload_group','restaurant_cash','status','finalized_by_admin_user_id','finalized_at'],'management_properties'=>['participates_bank_deposits']]
    );
}
function manage_store_bank_deposit_files(array $files,int $propertyId,int $year,string $uploadGroup): array {
    return manage_store_document_uploads($files,[
        'allowed'=>manage_document_upload_types(true),
        'upload_error'=>'One of the bank deposit files could not be uploaded.',
        'size_error'=>'Each bank deposit file must be 25 MB or smaller.',
        'type_error'=>'Bank deposit files must be PDF, Excel, CSV, TXT, Word, JPG, PNG, or WebP.',
        'mismatch_error'=>'A bank deposit file does not match its file extension.',
        'path'=>static fn(string $extension): string=>'uploads/manage/bank-deposits/'.$propertyId.'/'.$year.'/'.$uploadGroup.'/'.bin2hex(random_bytes(16)).'.'.$extension,
    ]);
}
function manage_recalculate_fee_year(int $propertyId,int $year): void {
    $query=db()->prepare('SELECT id,total_revenue,percent_1,percent_2 FROM management_fee_records WHERE management_property_id=? AND report_year=? ORDER BY fee_quarter,entry_date,id');$query->execute([$propertyId,$year]);$recognized=0.0;$update=db()->prepare('UPDATE management_fee_records SET fee_basis_revenue=?,fee_1=?,fee_2=?,total_fee=? WHERE id=?');
    foreach($query->fetchAll() as $record){$basis=(float)$record['total_revenue']-$recognized;$recognized+=$basis;$fee1=$basis*(float)$record['percent_1']/100;$fee2=$basis*(float)$record['percent_2']/100;$update->execute([number_format($basis,2,'.',''),number_format($fee1,2,'.',''),number_format($fee2,2,'.',''),number_format($fee1+$fee2,2,'.',''),(int)$record['id']]);}
}
function manage_store_fee_files(array $files,int $propertyId,int $year,int $month,string $feeType,string $category): array {
    return manage_store_document_uploads($files,[
        'allowed'=>manage_document_upload_types(true),
        'upload_error'=>'One of the fee files could not be uploaded.',
        'size_error'=>'Each fee file must be 25 MB or smaller.',
        'type_error'=>'Fee files must be TXT, PDF, Excel, CSV, Word, JPG, PNG, or WebP.',
        'mismatch_error'=>'A fee file does not match its file extension.',
        'validate_extension'=>static function(array $file,string $extension)use($feeType,$category):void{if($feeType==='management'&&$category==='records'&&$extension!=='txt')throw new RuntimeException('Manager Flash Backup files must be TXT files.');},
        'path'=>static fn(string $extension): string=>'uploads/manage/fees/'.$feeType.'/'.$propertyId.'/'.$year.'/'.str_pad((string)$month,2,'0',STR_PAD_LEFT).'/'.$category.'/'.bin2hex(random_bytes(16)).'.'.$extension,
    ]);
}
function manage_store_receipt_files(array $files,int $propertyId,int $year,int $month,string $category): array {
    return manage_store_document_uploads($files,[
        'allowed'=>manage_document_upload_types(),
        'max_bytes'=>52428800,
        'upload_error'=>'One of the receipt files could not be uploaded.',
        'size_error'=>'Each receipt file must be 50 MB or smaller.',
        'type_error'=>'Receipt files must be PDF, Excel, CSV, Word, JPG, PNG, or WebP.',
        'mismatch_error'=>'A receipt file does not match its file extension.',
        'validate_extension'=>static function(array $file,string $extension)use($category):void{if(in_array($category,['statement','payment'],true)&&$extension!=='pdf')throw new RuntimeException('Statements and payments must be uploaded as PDF files.');},
        'enrich'=>static function(array $file)use($category):array{if($category!=='statement')return ['data'=>null];$data=file_get_contents($file['tmp_name']);if($data===false)throw new RuntimeException('The statement PDF could not be prepared for email.');return ['data'=>$data];},
        'path'=>static fn(string $extension): string=>'uploads/manage/receipts/'.$propertyId.'/'.$year.'/'.str_pad((string)$month,2,'0',STR_PAD_LEFT).'/'.$category.'/'.bin2hex(random_bytes(16)).'.'.$extension,
    ]);
}
function manage_store_month_end_files(array $files,int $propertyId,int $year,int $month): array {
    return manage_store_document_uploads($files,[
        'allowed'=>manage_document_upload_types(),
        'upload_error'=>'One of the report files could not be uploaded.',
        'size_error'=>'Each report file must be 25 MB or smaller.',
        'type_error'=>'Report files must be PDF, Excel, CSV, Word, JPG, PNG, or WebP.',
        'mismatch_error'=>'A report file does not match its file extension.',
        'path'=>static fn(string $extension): string=>'uploads/manage/month-end/'.$propertyId.'/'.$year.'/'.str_pad((string)$month,2,'0',STR_PAD_LEFT).'/'.bin2hex(random_bytes(16)).'.'.$extension,
    ]);
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
    $relative='uploads/manage/properties/'.bin2hex(random_bytes(16)).'.'.$ext;
    znp_storage_store_uploaded_file($relative,(string)$file['tmp_name'],$ext,$mime);
    return ['path'=>$relative,'mime'=>$mime];
}
