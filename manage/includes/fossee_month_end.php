<?php
declare(strict_types=1);

final class ManageFosseeScanException extends RuntimeException {}
final class ManageFosseePeriodException extends RuntimeException {}

function manage_fossee_scan_error(): ManageFosseeScanException {
    return new ManageFosseeScanException('One or more Fossee reports could not be read clearly. Please upload better scanned copies.');
}

function manage_fossee_period_error(): ManageFosseePeriodException {
    return new ManageFosseePeriodException('The Fossee reports must cover the complete selected month. Please upload the correct full-month reports.');
}

function manage_fossee_support_error(): RuntimeException {
    return new RuntimeException('Fossee scan processing is unavailable. Contact Anup for Support.');
}

function manage_fossee_binary(string $environmentVariable,array $candidates): ?string {
    $configured=trim((string)getenv($environmentVariable));if($configured!==''&&is_file($configured)&&is_executable($configured))return $configured;
    foreach($candidates as $candidate)if(is_file($candidate)&&is_executable($candidate))return $candidate;return null;
}

function manage_fossee_embedded_text(string $contents): string {
    $autoload=__DIR__.'/../../vendor/autoload.php';if(!class_exists(Smalot\PdfParser\Parser::class)){if(!is_file($autoload))return'';require_once $autoload;}
    try{return (new Smalot\PdfParser\Parser())->parseContent($contents)->getText();}catch(Throwable $error){return'';}
}

function manage_fossee_ocr_text(string $contents): string {
    if(!function_exists('exec'))throw manage_fossee_support_error();
    $pdftoppm=manage_fossee_binary('ZNP_PDFTOPPM_BIN',['/usr/bin/pdftoppm','/usr/local/bin/pdftoppm','/opt/homebrew/bin/pdftoppm']);
    $tesseract=manage_fossee_binary('ZNP_TESSERACT_BIN',['/usr/bin/tesseract','/usr/local/bin/tesseract','/opt/homebrew/bin/tesseract']);
    if($pdftoppm===null||$tesseract===null)throw manage_fossee_support_error();
    $pdf=znp_storage_temp_path('pdf');$prefix=znp_storage_temp_path();$image=$prefix.'.png';
    if(file_put_contents($pdf,$contents,LOCK_EX)===false)throw manage_fossee_support_error();
    try{
        $renderOutput=[];$renderCode=1;exec(escapeshellarg($pdftoppm).' -f 1 -singlefile -png -r 300 '.escapeshellarg($pdf).' '.escapeshellarg($prefix).' 2>&1',$renderOutput,$renderCode);if($renderCode!==0||!is_file($image)||filesize($image)<1000)throw manage_fossee_scan_error();
        $best='';$bestScore=-1;
        foreach([6,4] as $pageMode){$output=[];$code=1;exec(escapeshellarg($tesseract).' '.escapeshellarg($image).' stdout -l eng --dpi 300 --psm '.$pageMode.' 2>&1',$output,$code);if($code!==0)continue;$text=implode("\n",$output);$score=strlen($text);foreach(['Revenue Report','Charge Code','Chg Code Total','TOTAL ROOM SALES','Thru'] as $anchor)if(stripos($text,$anchor)!==false)$score+=5000;if($score>$bestScore){$best=$text;$bestScore=$score;}}
        if(strlen(trim($best))<250)throw manage_fossee_scan_error();return $best;
    }finally{
        foreach([$pdf,$image] as $temporary)try{znp_storage_remove_temp_file($temporary);}catch(Throwable $cleanup){}
    }
}

function manage_fossee_pdf_text(string $contents): string {
    $embedded=manage_fossee_embedded_text($contents);if(strlen(trim($embedded))>=250)return $embedded;return manage_fossee_ocr_text($contents);
}

function manage_fossee_amounts(string $value): array {
    preg_match_all('/-?\s*(?:\d[\d,]*|\.\d+)(?:\.\d{2})?/',$value,$matches);$amounts=[];
    foreach($matches[0]??[] as $match){$clean=str_replace([',',' '],'',$match);if(str_starts_with($clean,'.'))$clean='0'.$clean;if(str_starts_with($clean,'-.'))$clean='-0'.substr($clean,1);if(is_numeric($clean))$amounts[]=(float)$clean;}return $amounts;
}

function manage_fossee_row_amounts(string $text,string $labelPattern): array {
    if(!preg_match('/'.$labelPattern.'[^\r\n]*/mi',$text,$match))throw manage_fossee_scan_error();$line=preg_replace('/^.*?'.$labelPattern.'/i','',$match[0],1)??'';$amounts=manage_fossee_amounts($line);if(count($amounts)<3)throw manage_fossee_scan_error();return $amounts;
}

function manage_fossee_short_date(string $value,int $expectedYear): ?DateTimeImmutable {
    if(!preg_match('/^(\d{2})([A-Za-z]{3})(.{2})$/u',trim($value),$match))return null;$expectedSuffix=substr((string)$expectedYear,-2);$suffix='';
    foreach(preg_split('//u',$match[3],-1,PREG_SPLIT_NO_EMPTY)?:[] as $index=>$character){if(ctype_digit($character)&&$character!==$expectedSuffix[$index])return null;$suffix.=$expectedSuffix[$index];}
    $date=DateTimeImmutable::createFromFormat('!dMy',$match[1].ucfirst(strtolower($match[2])).$suffix);return $date?:null;
}

function manage_fossee_validate_period(string $text,int $year,int $month,bool $managerFlash=false): void {
    $expected=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month));
    if($managerFlash){
        if(!preg_match('/\b(\d{2}[A-Za-z]{3}[^\s]{2})\s+Run\s+on\b/iu',$text,$dateMatch)||!preg_match('/DAY\s+(\d{1,2})\s+OF\s+PERIOD\s+(\d{1,2})\)\s*-\s*(\d{1,2})\s+DAY/i',$text,$periodMatch))throw manage_fossee_scan_error();$date=manage_fossee_short_date($dateMatch[1],$year);
        if(!$date||$date->format('Y-m-d')!==$expected->format('Y-m-t')||(int)$periodMatch[1]!=(int)$expected->format('t')||(int)$periodMatch[2]!==$month||(int)$periodMatch[3]!=(int)$expected->format('t'))throw manage_fossee_period_error();return;
    }
    if(!preg_match('/\b(\d{2}[A-Za-z]{3}[^\s]{2})\s+Thru\s+(\d{2}[A-Za-z]{3}[^\s]{2})/iu',$text,$match))throw manage_fossee_scan_error();$start=manage_fossee_short_date($match[1],$year);$end=manage_fossee_short_date($match[2],$year);
    if(!$start||!$end||$start->format('Y-m-d')!==$expected->format('Y-m-01')||$end->format('Y-m-d')!==$expected->format('Y-m-t'))throw manage_fossee_period_error();
}

function manage_fossee_tax_totals(string $text,float $rate): array {
    $amounts=manage_fossee_row_amounts($text,'Chg\s+Code\s+Total');if(count($amounts)<6)throw manage_fossee_scan_error();[$revenue,$exempt,$previouslyTaxed,$taxable,$taxDue,$taxPosted]=$amounts;
    if(min($revenue,$exempt,$previouslyTaxed,$taxable,$taxDue,$taxPosted)<0||abs(round($taxable*$rate,2)-$taxDue)>0.06)throw manage_fossee_scan_error();return ['revenue'=>$revenue,'taxable_revenue'=>$taxable,'tax_due'=>$taxDue,'tax_posted'=>$taxPosted];
}

function manage_fossee_extract_from_texts(array $reports,int $year,int $month): array {
    $manager=null;$city=null;$state=null;$sales=null;
    foreach($reports as $report){$text=(string)($report['text']??'');if(strlen(trim($text))<250)throw manage_fossee_scan_error();
        if(preg_match('/\bRevenue\s+Report\b/i',$text)){if($manager!==null)continue;manage_fossee_validate_period($text,$year,$month,true);$roomRevenue=manage_fossee_row_amounts($text,'TOTAL\s+ROOM\s+SALES');$roomsAvailable=manage_fossee_row_amounts($text,'#?\s*(?:ROOMS\s+)?AVAILABLE\s+FOR\s+SALE');$roomsOccupied=manage_fossee_row_amounts($text,'#?\s*ROOMS\s+OCCUPIED');$manager=['room_revenue'=>round($roomRevenue[2],2),'rooms_available'=>(int)round($roomsAvailable[2]),'rooms_occupied'=>(int)round($roomsOccupied[2])];if($manager['room_revenue']<=0||$manager['rooms_available']<=0||$manager['rooms_occupied']<0||$manager['rooms_occupied']>$manager['rooms_available'])throw manage_fossee_scan_error();continue;}
        if(preg_match('/Charge\s+Code\s*:\s*T[1I]\s+Occupancy\s+Sales\s+Tax\s+At\s+9(?:\.0+)?%/i',$text)){if($city!==null)continue;manage_fossee_validate_period($text,$year,$month);$city=manage_fossee_tax_totals($text,0.09);continue;}
        if(preg_match('/Charge\s+Code\s*:\s*T2\s+State\s+Occupancy\s+Tax\s+At\s+6(?:\.0+)?%/i',$text)){if($state!==null)continue;manage_fossee_validate_period($text,$year,$month);$state=manage_fossee_tax_totals($text,0.06);continue;}
        if(preg_match('/Charge\s+Code\s*:\s*T9\s+Sales\s+Tax\s+At\s+8\.25(?:0+)?%/i',$text)){if($sales!==null)continue;manage_fossee_validate_period($text,$year,$month);$sales=manage_fossee_tax_totals($text,0.0825);continue;}
        throw manage_fossee_scan_error();
    }
    if($manager===null||$city===null||$state===null||$sales===null)throw manage_fossee_scan_error();
    return array_merge($manager,['suite_shop_revenue'=>round($sales['tax_posted']/0.0825,2),'city_tax'=>round($city['tax_posted'],2),'state_tax'=>round($state['tax_posted'],2),'sales_tax'=>round($sales['tax_posted'],2),'banquet_tax'=>0.0,'liquor_net_sales'=>0.0,'beer_net_sales'=>0.0,'wine_net_sales'=>0.0,'taxable_sales_mix_bev_sales_tax'=>0.0]);
}

function manage_fossee_extract_month_end(array $reports,int $year,int $month): array {
    $texts=[];foreach($reports as $report)$texts[]=['name'=>(string)($report['name']??''),'text'=>manage_fossee_pdf_text((string)($report['contents']??''))];return manage_fossee_extract_from_texts($texts,$year,$month);
}
