<?php
declare(strict_types=1);

function manage_opera_support_error(): RuntimeException {
    return new RuntimeException('The Opera reports could not be validated. Contact Anup for Support.');
}

function manage_opera_pdf_pages(string $contents): array {
    $autoload=__DIR__.'/../../vendor/autoload.php';
    if(!class_exists(Smalot\PdfParser\Parser::class)){if(!is_file($autoload))throw manage_opera_support_error();require_once $autoload;}
    try{$document=(new Smalot\PdfParser\Parser())->parseContent($contents);$pages=[];foreach($document->getPages() as $page)$pages[]=$page->getText();return $pages;}
    catch(Throwable $error){throw manage_opera_support_error();}
}

function manage_opera_lines(string $text): array {
    $lines=[];foreach(preg_split('/\R/u',$text)?:[] as $line){$line=trim(preg_replace('/\s+/u',' ',$line)??$line);if($line!=='')$lines[]=$line;}return $lines;
}

function manage_opera_amount(string $value): float {
    if(!preg_match('/-?\s*\d[\d,]*(?:\.\d+)?/',$value,$match))throw manage_opera_support_error();
    $amount=str_replace([',',' '],'',$match[0]);if(!is_numeric($amount))throw manage_opera_support_error();return (float)$amount;
}

function manage_opera_period_amount(string $value,int $column=0): float {
    if(!preg_match_all('/-?\s*\d[\d,]*(?:\.\d+)?/',$value,$matches)||!isset($matches[0][$column]))throw manage_opera_support_error();return manage_opera_amount($matches[0][$column]);
}

function manage_opera_exact_index(array $lines,string $needle,int $start=0): int {
    for($index=max(0,$start),$count=count($lines);$index<$count;$index++)if(strcasecmp($lines[$index],$needle)===0)return $index;return -1;
}

function manage_opera_description_value_header(array $lines,int $start): int {
    for($index=max(0,$start),$count=count($lines);$index<$count;$index++){
        if(preg_match('/Description\s*Trn\.?/i',$lines[$index]))return $index;
        if(strcasecmp($lines[$index],'Description')===0)for($next=$index+1;$next<min($count,$index+5);$next++)if(preg_match('/^Trn\.?$/i',$lines[$next]))return $next;
    }
    return -1;
}

function manage_opera_code_matches(array $lines,string $code,string $expectedDescription): bool {
    $codeIndex=manage_opera_exact_index($lines,$code);if($codeIndex<0)return false;$codeStart=$codeIndex;$codeEnd=$codeIndex;while($codeStart>0&&preg_match('/^\d+$/',$lines[$codeStart-1]))$codeStart--;while(isset($lines[$codeEnd+1])&&preg_match('/^\d+$/',$lines[$codeEnd+1]))$codeEnd++;$descriptionIndex=$codeEnd+1+($codeIndex-$codeStart);
    return isset($lines[$descriptionIndex])&&preg_match($expectedDescription,$lines[$descriptionIndex])===1;
}

function manage_opera_manager_values(array $pages,int $year,int $month): array {
    $expected=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month));$monthName=strtoupper($expected->format('F'));
    foreach($pages as $text){
        if(!preg_match('/(?:Manager\s*-\s*Flash|\*?F08\s*-\s*Manager Report)/i',$text))continue;
        $lines=manage_opera_lines($text);$labelsStart=manage_opera_exact_index($lines,'% Rooms Occupied');$labels=['room_revenue'=>'Room Revenue','rooms_available'=>'Total Rooms in Hotel','rooms_occupied'=>'Rooms Occupied'];$offsets=[];
        if($labelsStart<0)throw manage_opera_support_error();foreach($labels as $field=>$label){$labelIndex=manage_opera_exact_index($lines,$label,$labelsStart);if($labelIndex<$labelsStart)throw manage_opera_support_error();$offsets[$field]=$labelIndex-$labelsStart;}$headerIndex=-1;$values=[];
        if(preg_match('/Filter\s*Calendar\/Month to Date\s+(\d{2}-\d{2}-\d{2})/i',$text,$dateMatch)){
            $date=DateTimeImmutable::createFromFormat('!m-d-y',$dateMatch[1]);if(!$date||$date->format('Y-m-d')!==$expected->format('Y-m-t'))throw manage_opera_support_error();
            for($index=0,$count=count($lines);$index<$count;$index++)if(preg_match('/^DAY\s+MONTH\s+YEAR\s+DAY\s+MONTH\s+YEAR$/i',$lines[$index])){$headerIndex=$index;break;}
            if($headerIndex<1||!preg_match('/^'.preg_quote((string)$year,'/').'\s+'.preg_quote((string)$year,'/').'\s+'.preg_quote((string)$year,'/').'\s+\d{4}\s+\d{4}\s+\d{4}$/',$lines[$headerIndex-1]))throw manage_opera_support_error();foreach($offsets as $field=>$offset){$valueIndex=$headerIndex+1+$offset;if(!isset($lines[$valueIndex]))throw manage_opera_support_error();$values[$field]=manage_opera_period_amount($lines[$valueIndex],1);}
        }else{
            if(!preg_match('/Filter\s*Calendar\/Month\s*'.preg_quote((string)$year,'/').'\s*,\s*'.preg_quote(str_pad((string)$month,2,'0',STR_PAD_LEFT),'/').'/i',$text))throw manage_opera_support_error();
            for($index=0,$count=count($lines);$index<$count;$index++)if(strcasecmp($lines[$index],$monthName.' '.$monthName)===0){$headerIndex=$index;break;}
            if($headerIndex<0||!isset($lines[$headerIndex+1])||!preg_match('/^(\d{4})\s+\d{4}$/',$lines[$headerIndex+1],$yearMatch)||(int)$yearMatch[1]!==$year)throw manage_opera_support_error();foreach($offsets as $field=>$offset){$valueIndex=$headerIndex+2+$offset;if(!isset($lines[$valueIndex]))throw manage_opera_support_error();$values[$field]=manage_opera_amount($lines[$valueIndex]);}
        }
        foreach(['rooms_available','rooms_occupied'] as $field)if($values[$field]<0||abs($values[$field]-round($values[$field]))>0.001)throw manage_opera_support_error();$values['rooms_available']=(int)round($values['rooms_available']);$values['rooms_occupied']=(int)round($values['rooms_occupied']);return $values;
    }
    throw manage_opera_support_error();
}

function manage_opera_manager_room_revenue(array $pages,int $year,int $month): float {
    return (float)manage_opera_manager_values($pages,$year,$month)['room_revenue'];
}

function manage_opera_code_value(array $lines,string $code,string $expectedDescription,int $periodColumn=0): float {
    $codeIndex=manage_opera_exact_index($lines,$code);if($codeIndex<0)throw manage_opera_support_error();$codeStart=$codeIndex;$codeEnd=$codeIndex;
    while($codeStart>0&&preg_match('/^\d+$/',$lines[$codeStart-1]))$codeStart--;while(isset($lines[$codeEnd+1])&&preg_match('/^\d+$/',$lines[$codeEnd+1]))$codeEnd++;
    $offset=$codeIndex-$codeStart;$descriptionIndex=$codeEnd+1+$offset;if(!isset($lines[$descriptionIndex])||!preg_match($expectedDescription,$lines[$descriptionIndex]))throw manage_opera_support_error();
    $valueHeader=manage_opera_description_value_header($lines,$codeEnd+1);
    $valueIndex=$valueHeader+1+$offset;if($valueHeader<0||!isset($lines[$valueIndex]))throw manage_opera_support_error();return manage_opera_period_amount($lines[$valueIndex],$periodColumn);
}

function manage_opera_reported_tax_subtotal(array $lines,string $subgroup,int $periodColumn=0): float {
    $actualIndex=-1;foreach($lines as $index=>$line)if(preg_match('/^Actual(?:\s+Actual)*$/i',$line)){$actualIndex=$index;break;}$descriptionHeader=-1;if($actualIndex<0)throw manage_opera_support_error();
    $descriptionHeader=manage_opera_description_value_header($lines,$actualIndex+1);
    if($descriptionHeader<0)throw manage_opera_support_error();$subgroupHeader=manage_opera_exact_index($lines,'Sub Group');if($subgroupHeader<0||$subgroupHeader>=$actualIndex)throw manage_opera_support_error();$subgroupCount=0;for($index=$subgroupHeader;$index<$actualIndex&&strcasecmp($lines[$index],'Sub Group')===0;$index++)$subgroupCount++;$subgroups=array_map('strtoupper',array_slice($lines,$subgroupHeader-$subgroupCount,$subgroupCount));
    $ordinal=array_search(strtoupper($subgroup),$subgroups,true);if($ordinal===false)throw manage_opera_support_error();$lastSubtotal=-1;
    for($index=$actualIndex+1;$index<$descriptionHeader;$index++)if(strcasecmp($lines[$index],'Subgroup Total')===0)$lastSubtotal=$index;
    $valueIndex=$lastSubtotal+1+(int)$ordinal;if($lastSubtotal<0||$valueIndex>=$descriptionHeader||!isset($lines[$valueIndex]))throw manage_opera_support_error();return manage_opera_period_amount($lines[$valueIndex],$periodColumn);
}

function manage_opera_validated_tax_subtotal(array $lines,string $taxCode,string $adjustmentCode,string $taxDescription,string $adjustmentDescription,string $subgroup,int $periodColumn=0): float {
    $calculated=manage_opera_code_value($lines,$taxCode,$taxDescription,$periodColumn)+manage_opera_code_value($lines,$adjustmentCode,$adjustmentDescription,$periodColumn);
    $subtotal=manage_opera_reported_tax_subtotal($lines,$subgroup,$periodColumn);if(abs($subtotal-$calculated)>0.011)throw manage_opera_support_error();return $subtotal;
}

function manage_opera_suite_shop_revenue(?float $salesTax,?float $marketRevenue,?float $sundriesRevenue,?float $pantryRevenue=null): float {
    $revenue=$salesTax!==null?round($salesTax/0.0825,2):($marketRevenue??$sundriesRevenue??$pantryRevenue);
    if($revenue===null)throw manage_opera_support_error();return $revenue;
}

function manage_opera_validate_restaurant_period(string $text,int $year,int $month): void {
    if(!preg_match('/Period From\s*:\s*(\d{2}\/\d{2}\/\d{4})\s+To\s*:\s*(\d{2}\/\d{2}\/\d{4})/i',$text,$match))throw manage_opera_support_error();
    $start=DateTimeImmutable::createFromFormat('!m/d/Y',$match[1]);$end=DateTimeImmutable::createFromFormat('!m/d/Y',$match[2]);$expected=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month));
    if(!$start||!$end||$start->format('Y-m-d')!==$expected->format('Y-m-01')||$end->format('Y-m-d')!==$expected->format('Y-m-t'))throw manage_opera_support_error();
}

function manage_opera_restaurant_net_sale(string $text,string $category): float {
    foreach(manage_opera_lines($text) as $line)if(preg_match('/^'.preg_quote($category,'/').'\s+\d+\b/i',$line)&&preg_match('/(-?\s*\d[\d,]*\.\d{2})\s*\d+(?:\.\d+)?%\s*$/',$line,$match))return manage_opera_amount($match[1]);
    throw manage_opera_support_error();
}

function manage_opera_restaurant_values(string $salesText,string $taxText,int $year,int $month): array {
    if(!preg_match('/Consolidated SYS Major Group Sales Detail/i',$salesText)||!preg_match('/Consolidated SYS Tax Totals/i',$taxText))throw manage_opera_support_error();manage_opera_validate_restaurant_period($salesText,$year,$month);manage_opera_validate_restaurant_period($taxText,$year,$month);
    $taxableSales=null;foreach(manage_opera_lines($taxText) as $line)if(preg_match('/4\s*-\s*Mix Bev Sales Tax\s*$/i',$line)&&preg_match('/^(-?\s*\d[\d,]*\.\d{2})/',$line,$match)){$taxableSales=manage_opera_amount($match[1]);break;}
    if($taxableSales===null)throw manage_opera_support_error();return ['liquor_net_sales'=>manage_opera_restaurant_net_sale($salesText,'Liquor'),'beer_net_sales'=>manage_opera_restaurant_net_sale($salesText,'Beer'),'wine_net_sales'=>manage_opera_restaurant_net_sale($salesText,'Wine'),'taxable_sales_mix_bev_sales_tax'=>$taxableSales];
}

function manage_opera_find_period_column(string $text,int $year,int $month): int {
    $expected=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month));$period=$expected->format('F Y');
    if(preg_match('/Calendar\s*\/?\s*Month\s*\(\s*'.preg_quote($period,'/').'\s*\)/i',$text))return 0;
    if(preg_match('/Calendar\s*\/\s*Month to Date\s*\(\s*Date\s+(\d{2}-\d{2}-\d{2})\s*\)/i',$text,$match)){$date=DateTimeImmutable::createFromFormat('!m-d-y',$match[1]);if(!$date||$date->format('Y-m-d')!==$expected->format('Y-m-t')||!preg_match('/DAY\s+MONTH\s+YEAR.*?Actual\s+Actual\s+Actual/is',$text))throw manage_opera_support_error();return 1;}
    throw manage_opera_support_error();
}

function manage_opera_find_values(array $pages,int $year,int $month): array {
    $allText=implode("\n",$pages);if(!preg_match('/All\s*\(Pymt\.,\s*Rev\.\s*&\s*Non\s*Rev\.\)/i',$allText)||!preg_match('/All by Transaction Codes (?:Gross|Net)/i',$allText))throw manage_opera_support_error();$periodColumn=manage_opera_find_period_column($allText,$year,$month);
    $marketRevenue=null;$sundriesRevenue=null;$pantryRevenue=null;$salesTax=null;$cityTax=null;$stateTax=null;$banquetTax=null;
    foreach($pages as $text){$lines=manage_opera_lines($text);
        if($marketRevenue===null&&manage_opera_code_matches($lines,'5106','/^Market\s*-?\s*Taxable$/i'))$marketRevenue=manage_opera_code_value($lines,'5106','/^Market\s*-?\s*Taxable$/i',$periodColumn);
        if($sundriesRevenue===null&&manage_opera_code_matches($lines,'5500','/^Sundries$/i')){$sundriesRevenue=manage_opera_code_value($lines,'5500','/^Sundries$/i',$periodColumn);if(manage_opera_code_matches($lines,'5505','/^Sundries\s*-\s*Adj$/i'))$sundriesRevenue+=manage_opera_code_value($lines,'5505','/^Sundries\s*-\s*Adj$/i',$periodColumn);}
        if($pantryRevenue===null&&manage_opera_code_matches($lines,'5106','/^Pantry$/i')){$pantryRevenue=manage_opera_code_value($lines,'5106','/^Pantry$/i',$periodColumn);if(manage_opera_code_matches($lines,'5156','/^Pantry\s*-\s*Adj$/i'))$pantryRevenue+=manage_opera_code_value($lines,'5156','/^Pantry\s*-\s*Adj$/i',$periodColumn);}
        if($cityTax===null&&manage_opera_code_matches($lines,'7100','/^City Tax$/i'))$cityTax=manage_opera_validated_tax_subtotal($lines,'7100','7200','/^City Tax$/i','/^City Tax\s*-\s*Adj$/i','TAX1',$periodColumn);
        if($cityTax===null&&manage_opera_code_matches($lines,'7101','/^City Tax$/i'))$cityTax=manage_opera_validated_tax_subtotal($lines,'7101','7201','/^City Tax$/i','/^City Tax\s*-\s*Adj$/i','TAX2',$periodColumn);
        if($cityTax===null&&manage_opera_code_matches($lines,'7101','/^City Tax\s*-\s*Room$/i'))$cityTax=manage_opera_validated_tax_subtotal($lines,'7101','7201','/^City Tax\s*-\s*Room$/i','/^City Tax\s*-\s*Room\s*-\s*Adj$/i','TAX2',$periodColumn);
        if($stateTax===null&&manage_opera_code_matches($lines,'7101','/^State Tax$/i'))$stateTax=manage_opera_validated_tax_subtotal($lines,'7101','7201','/^State Tax$/i','/^State Tax\s*-\s*Adj$/i','TAX2',$periodColumn);
        if($stateTax===null&&manage_opera_code_matches($lines,'7100','/^State Tax$/i'))$stateTax=manage_opera_validated_tax_subtotal($lines,'7100','7200','/^State Tax$/i','/^State Tax\s*-\s*Adj$/i','TAX1',$periodColumn);
        if($stateTax===null&&manage_opera_code_matches($lines,'7100','/^State Tax\s*-\s*Room$/i'))$stateTax=manage_opera_validated_tax_subtotal($lines,'7100','7200','/^State Tax\s*-\s*Room$/i','/^State Tax\s*-\s*Room\s*-\s*Adj$/i','TAX1',$periodColumn);
        if($salesTax===null&&manage_opera_code_matches($lines,'7102','/^Sales Tax$/i'))$salesTax=manage_opera_validated_tax_subtotal($lines,'7102','7202','/^Sales Tax$/i','/^Sales Tax\s*-\s*Adj$/i','TAX3',$periodColumn);
        if($salesTax===null&&manage_opera_code_matches($lines,'7102','/^State Sales Tax$/i'))$salesTax=manage_opera_validated_tax_subtotal($lines,'7102','7202','/^State Sales Tax$/i','/^State Sales Tax\s*-\s*Adj$/i','TAX3',$periodColumn);
        if($banquetTax===null&&manage_opera_code_matches($lines,'7109','/^Banquet Tax$/i'))$banquetTax=manage_opera_validated_tax_subtotal($lines,'7109','7209','/^Banquet Tax$/i','/^Banquet Tax\s*(?:-|)\s*Adj\.?$/i','TAX10',$periodColumn);
    }
    $suiteShop=manage_opera_suite_shop_revenue($salesTax,$marketRevenue,$sundriesRevenue,$pantryRevenue);
    if($stateTax===null)throw manage_opera_support_error();return ['suite_shop_revenue'=>$suiteShop,'city_tax'=>$cityTax??0.0,'state_tax'=>$stateTax,'banquet_tax'=>$banquetTax??0.0,'sales_tax'=>$salesTax??round($suiteShop*0.0825,2)];
}

function manage_opera_extract_month_end(array $reports,int $year,int $month,bool $restaurant=false): array {
    $managerPages=null;$findPages=null;$restaurantSalesText=null;$restaurantTaxText=null;
    foreach($reports as $report){$pages=manage_opera_pdf_pages((string)($report['contents']??''));$text=implode("\n",$pages);if($managerPages===null&&preg_match('/(?:Manager\s*-\s*Flash|\*?F08\s*-\s*Manager Report)/i',$text))$managerPages=$pages;if($findPages===null&&preg_match('/All by Transaction Codes (?:Gross|Net)/i',$text))$findPages=$pages;if($restaurantSalesText===null&&preg_match('/Consolidated SYS Major Group Sales Detail/i',$text))$restaurantSalesText=$text;if($restaurantTaxText===null&&preg_match('/Consolidated SYS Tax Totals/i',$text))$restaurantTaxText=$text;}
    if($managerPages===null||$findPages===null)throw manage_opera_support_error();$values=array_merge(manage_opera_find_values($findPages,$year,$month),manage_opera_manager_values($managerPages,$year,$month));if($restaurant){if($restaurantSalesText===null||$restaurantTaxText===null)throw manage_opera_support_error();$values=array_merge($values,manage_opera_restaurant_values($restaurantSalesText,$restaurantTaxText,$year,$month));}else foreach(['liquor_net_sales','beer_net_sales','wine_net_sales','taxable_sales_mix_bev_sales_tax'] as $field)$values[$field]=0.0;return $values;
}
