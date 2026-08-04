<?php
declare(strict_types=1);

function manage_hotelkey_support_error(): RuntimeException {
    return new RuntimeException('The HotelKey reports could not be validated. Contact Anup for Support.');
}

function manage_hotelkey_xlsx_sheets(string $contents): array {
    require_once __DIR__.'/xlsx_preview.php';
    if(!class_exists(ZipArchive::class)||!class_exists(DOMDocument::class))throw manage_hotelkey_support_error();
    $temporary=znp_storage_temp_path('xlsx');
    if(file_put_contents($temporary,$contents,LOCK_EX)===false)throw manage_hotelkey_support_error();
    $zip=new ZipArchive();$opened=false;
    try{
        if($zip->open($temporary)!==true)throw manage_hotelkey_support_error();$opened=true;
        $workbookXml=manage_xlsx_xml(manage_xlsx_entry($zip,'xl/workbook.xml'));
        $relationsXml=manage_xlsx_xml(manage_xlsx_entry($zip,'xl/_rels/workbook.xml.rels'));
        $relations=[];foreach($relationsXml->query('//p:Relationship')?:[] as $relation)if($relation instanceof DOMElement)$relations[$relation->getAttribute('Id')]=$relation->getAttribute('Target');
        $sharedStrings=[];
        if($zip->locateName('xl/sharedStrings.xml')!==false){
            $sharedXml=manage_xlsx_xml(manage_xlsx_entry($zip,'xl/sharedStrings.xml'));
            foreach($sharedXml->query('//x:si')?:[] as $item){$parts=[];foreach((new DOMXPath($item->ownerDocument))->query('.//*[local-name()="t"]',$item)?:[] as $text)$parts[]=$text->textContent;$sharedStrings[]=implode('',$parts);}
        }
        $sheets=[];
        foreach($workbookXml->query('//x:sheets/x:sheet')?:[] as $sheet){
            if(!$sheet instanceof DOMElement)continue;$relationshipId=$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');$target=$relations[$relationshipId]??'';if($target==='')continue;$target=ltrim(str_replace('../','',$target),'/');$path=str_starts_with($target,'xl/')?$target:'xl/'.$target;
            $sheetXml=manage_xlsx_xml(manage_xlsx_entry($zip,$path));$rows=[];
            foreach($sheetXml->query('//x:sheetData/x:row/x:c')?:[] as $cell){
                if(!$cell instanceof DOMElement)continue;$reference=$cell->getAttribute('r');if(!preg_match('/^([A-Z]+)(\d+)$/i',$reference,$match))continue;$row=(int)$match[2];$column=manage_xlsx_column_index($match[1]);$type=$cell->getAttribute('t');$raw='';$inline='';
                foreach($cell->childNodes as $child)if($child instanceof DOMElement){if($child->localName==='v')$raw=$child->textContent;elseif($child->localName==='is')$inline=$child->textContent;}
                if($type==='s')$value=$sharedStrings[(int)$raw]??'';elseif($type==='inlineStr')$value=$inline;elseif($type==='b')$value=$raw==='1';elseif($type==='str'||$type==='d')$value=$raw;elseif($raw!==''&&is_numeric($raw))$value=(float)$raw;else $value=$raw;
                $rows[$row][$column]=$value;
            }
            $sheets[]=['name'=>$sheet->getAttribute('name')?:'Sheet','rows'=>$rows];
        }
        if(!$sheets)throw manage_hotelkey_support_error();return $sheets;
    }catch(Throwable $error){if($error instanceof RuntimeException&&$error->getMessage()===manage_hotelkey_support_error()->getMessage())throw $error;throw manage_hotelkey_support_error();}
    finally{if($opened)$zip->close();znp_storage_remove_temp_file($temporary);}
}

function manage_hotelkey_text(mixed $value): string {
    return trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value);
}

function manage_hotelkey_number(mixed $value): float {
    if(is_int($value)||is_float($value))return (float)$value;$clean=str_replace([',','$',' '],'',manage_hotelkey_text($value));if($clean===''||!is_numeric($clean))throw manage_hotelkey_support_error();return (float)$clean;
}

function manage_hotelkey_report_period(array $sheets,int $year,int $month): void {
    $expected=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month));
    foreach($sheets as $sheet)foreach($sheet['rows'] as $row)foreach($row as $value){
        if(!is_string($value)||!preg_match('/Date Range\s*:\s*([A-Za-z]{3,9}\s+\d{1,2},\s*\d{4})\s*-\s*([A-Za-z]{3,9}\s+\d{1,2},\s*\d{4})/i',$value,$match))continue;
        $start=DateTimeImmutable::createFromFormat('!M j, Y',$match[1])?:DateTimeImmutable::createFromFormat('!F j, Y',$match[1]);$end=DateTimeImmutable::createFromFormat('!M j, Y',$match[2])?:DateTimeImmutable::createFromFormat('!F j, Y',$match[2]);
        if(!$start||!$end||$start->format('Y-m-d')!==$expected->format('Y-m-01')||$end->format('Y-m-d')!==$expected->format('Y-m-t'))throw manage_hotelkey_support_error();return;
    }
    throw manage_hotelkey_support_error();
}

function manage_hotelkey_excel_date(mixed $value): ?string {
    if(is_int($value)||is_float($value))return (new DateTimeImmutable('1899-12-30',new DateTimeZone('UTC')))->modify('+'.(int)floor((float)$value).' days')->format('Y-m-d');
    $text=manage_hotelkey_text($value);foreach(['!m/d/Y','!m/d/y','!d-M-Y','!d-M-y','!Y-m-d'] as $format){$date=DateTimeImmutable::createFromFormat($format,$text);if($date)return $date->format('Y-m-d');}return null;
}

function manage_hotelkey_header_map(array $row): array {
    $headers=[];foreach($row as $column=>$value){$label=strtolower(manage_hotelkey_text($value));if($label!=='')$headers[$label]=(int)$column;}return $headers;
}

function manage_hotelkey_occupancy_values(array $sheets,int $year,int $month): ?array {
    $reportText='';foreach($sheets as $sheet)foreach($sheet['rows'] as $row)foreach($row as $value)if(is_string($value))$reportText.=' '.$value;
    if(!preg_match('/\bOccupancy Summary\b/i',$reportText))return null;manage_hotelkey_report_period($sheets,$year,$month);$expected=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month));$expectedDates=[];for($date=$expected;$date<=$expected->modify('last day of this month');$date=$date->modify('+1 day'))$expectedDates[]=$date->format('Y-m-d');
    foreach($sheets as $sheet)foreach($sheet['rows'] as $headerRowNumber=>$row){
        $headers=manage_hotelkey_header_map($row);$dateColumn=$headers['date']??null;$roomRevenueColumn=$headers['room revenue']??$headers['total room revenue']??null;$roomsColumn=$headers['total rooms']??null;$soldColumn=$headers['total sold rooms']??null;
        if($dateColumn===null||$roomRevenueColumn===null||$roomsColumn===null||$soldColumn===null)continue;$dates=[];$roomRevenue=0.0;$roomsAvailable=0.0;$roomsSold=0.0;
        foreach($sheet['rows'] as $rowNumber=>$dataRow){if($rowNumber<=$headerRowNumber)continue;$date=manage_hotelkey_excel_date($dataRow[$dateColumn]??null);if($date===null)continue;$dates[]=$date;$roomRevenue+=manage_hotelkey_number($dataRow[$roomRevenueColumn]??null);$roomsAvailable+=manage_hotelkey_number($dataRow[$roomsColumn]??null);$roomsSold+=manage_hotelkey_number($dataRow[$soldColumn]??null);}
        if($dates!==$expectedDates||abs($roomsAvailable-round($roomsAvailable))>0.001||abs($roomsSold-round($roomsSold))>0.001)throw manage_hotelkey_support_error();
        return ['rooms_available'=>(int)round($roomsAvailable),'rooms_occupied'=>(int)round($roomsSold),'room_revenue'=>round($roomRevenue,2)];
    }
    throw manage_hotelkey_support_error();
}

function manage_hotelkey_tax_values(array $sheets,int $year,int $month): ?array {
    $reportText='';foreach($sheets as $sheet)foreach($sheet['rows'] as $row)foreach($row as $value)if(is_string($value))$reportText.=' '.$value;
    if(!preg_match('/\bTax Report\b/i',$reportText))return null;manage_hotelkey_report_period($sheets,$year,$month);
    foreach($sheets as $sheet)foreach($sheet['rows'] as $headerRowNumber=>$row){
        $headers=manage_hotelkey_header_map($row);$nameColumn=$headers['tax name']??null;$payableColumn=$headers['payable tax']??null;if($nameColumn===null||$payableColumn===null)continue;$values=[];
        foreach($sheet['rows'] as $rowNumber=>$dataRow){if($rowNumber<=$headerRowNumber)continue;$name=strtolower(manage_hotelkey_text($dataRow[$nameColumn]??''));if(in_array($name,['city tax','state tax','sales tax'],true))$values[$name]=manage_hotelkey_number($dataRow[$payableColumn]??null);if(count($values)===3)break;}
        if(count($values)!==3)throw manage_hotelkey_support_error();return ['city_tax'=>round($values['city tax'],2),'state_tax'=>round($values['state tax'],2),'sales_tax'=>round($values['sales tax'],2),'suite_shop_revenue'=>round($values['sales tax']/0.0825,2)];
    }
    throw manage_hotelkey_support_error();
}

function manage_hotelkey_extract_month_end(array $reports,int $year,int $month): array {
    $occupancy=null;$tax=null;
    foreach($reports as $report){$sheets=manage_hotelkey_xlsx_sheets((string)($report['contents']??''));if($occupancy===null)$occupancy=manage_hotelkey_occupancy_values($sheets,$year,$month);if($tax===null)$tax=manage_hotelkey_tax_values($sheets,$year,$month);if($occupancy!==null&&$tax!==null)break;}
    if($occupancy===null||$tax===null)throw manage_hotelkey_support_error();return array_merge($occupancy,$tax,['banquet_tax'=>0.0,'liquor_net_sales'=>0.0,'beer_net_sales'=>0.0,'wine_net_sales'=>0.0,'taxable_sales_mix_bev_sales_tax'=>0.0]);
}
