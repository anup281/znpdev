<?php
declare(strict_types=1);

function dev_ensure_budget_schema(): void
{
    $pdo=db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_project_budgets (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        construction_project_id BIGINT UNSIGNED NOT NULL,
        contingency_percent DECIMAL(7,4) NOT NULL DEFAULT 5.0000,
        management_fee_percent DECIMAL(7,4) NOT NULL DEFAULT 5.0000,
        partnership_percent DECIMAL(7,4) NOT NULL DEFAULT 30.0000,
        bank_percent DECIMAL(7,4) NOT NULL DEFAULT 70.0000,
        contingency_reallocated_amount DECIMAL(16,2) NOT NULL DEFAULT 0.00,
        contingency_reallocation_note VARCHAR(1000) NOT NULL DEFAULT '',
        contingency_is_completed TINYINT(1) NOT NULL DEFAULT 0,
        is_locked TINYINT(1) NOT NULL DEFAULT 0,
        template_file_path VARCHAR(500) NULL,
        template_original_name VARCHAR(255) NULL,
        template_mime_type VARCHAR(150) NULL,
        template_file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_by_admin_user_id BIGINT UNSIGNED NULL,
        updated_by_admin_user_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        UNIQUE KEY uq_construction_project_budget_project(construction_project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if(!$pdo->query("SHOW COLUMNS FROM construction_project_budgets LIKE 'is_locked'")->fetchColumn())$pdo->exec("ALTER TABLE construction_project_budgets ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER bank_percent");
    if(!$pdo->query("SHOW COLUMNS FROM construction_project_budgets LIKE 'contingency_reallocated_amount'")->fetchColumn())$pdo->exec("ALTER TABLE construction_project_budgets ADD COLUMN contingency_reallocated_amount DECIMAL(16,2) NOT NULL DEFAULT 0.00 AFTER bank_percent");
    if(!$pdo->query("SHOW COLUMNS FROM construction_project_budgets LIKE 'contingency_reallocation_note'")->fetchColumn())$pdo->exec("ALTER TABLE construction_project_budgets ADD COLUMN contingency_reallocation_note VARCHAR(1000) NOT NULL DEFAULT '' AFTER contingency_reallocated_amount");
    if(!$pdo->query("SHOW COLUMNS FROM construction_project_budgets LIKE 'contingency_is_completed'")->fetchColumn())$pdo->exec("ALTER TABLE construction_project_budgets ADD COLUMN contingency_is_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER contingency_reallocation_note");
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_budget_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        construction_project_budget_id BIGINT UNSIGNED NOT NULL,
        category_key VARCHAR(80) NOT NULL,
        category_name VARCHAR(190) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        amount DECIMAL(16,2) NOT NULL DEFAULT 0.00,
        reallocated_amount DECIMAL(16,2) NOT NULL DEFAULT 0.00,
        reallocation_note VARCHAR(1000) NOT NULL DEFAULT '',
        is_completed TINYINT(1) NOT NULL DEFAULT 0,
        display_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        KEY idx_construction_budget_items_budget(construction_project_budget_id,display_order,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if(!$pdo->query("SHOW COLUMNS FROM construction_budget_items LIKE 'notes'")->fetchColumn())$pdo->exec("ALTER TABLE construction_budget_items ADD COLUMN notes VARCHAR(500) NOT NULL DEFAULT '' AFTER amount");
    if(!$pdo->query("SHOW COLUMNS FROM construction_budget_items LIKE 'reallocated_amount'")->fetchColumn())$pdo->exec("ALTER TABLE construction_budget_items ADD COLUMN reallocated_amount DECIMAL(16,2) NOT NULL DEFAULT 0.00 AFTER amount");
    if(!$pdo->query("SHOW COLUMNS FROM construction_budget_items LIKE 'reallocation_note'")->fetchColumn())$pdo->exec("ALTER TABLE construction_budget_items ADD COLUMN reallocation_note VARCHAR(1000) NOT NULL DEFAULT '' AFTER reallocated_amount");
    if(!$pdo->query("SHOW COLUMNS FROM construction_budget_items LIKE 'is_completed'")->fetchColumn())$pdo->exec("ALTER TABLE construction_budget_items ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER reallocation_note");
    if(!$pdo->query("SHOW COLUMNS FROM construction_budget_items LIKE 'construction_project_company_id'")->fetchColumn())$pdo->exec("ALTER TABLE construction_budget_items ADD COLUMN construction_project_company_id BIGINT UNSIGNED NULL AFTER notes");
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_budget_equity_sources (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        construction_project_budget_id BIGINT UNSIGNED NOT NULL,
        source_name VARCHAR(190) NOT NULL DEFAULT '',
        source_date DATE NULL,
        amount DECIMAL(16,2) NOT NULL DEFAULT 0.00,
        method VARCHAR(100) NOT NULL DEFAULT '',
        display_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        KEY idx_budget_equity_sources_budget(construction_project_budget_id,display_order,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function dev_budget_xlsx_cell_value(SimpleXMLElement $cell, array $sharedStrings): string
{
    $type=(string)($cell['t']??'');
    if($type==='inlineStr'){
        $parts=[];
        foreach($cell->is->xpath('.//*[local-name()="t"]')?:[] as $text)$parts[]=(string)$text;
        return implode('',$parts);
    }
    $raw=(string)($cell->v??'');
    if($type==='s')return $sharedStrings[(int)$raw]??'';
    if($type==='b')return $raw==='1'?'1':'0';
    return $raw;
}

function dev_parse_budget_xlsx(string $path): array
{
    if(!class_exists(ZipArchive::class))throw new RuntimeException('Excel import is not available on this server.');
    $zip=new ZipArchive();
    if($zip->open($path)!==true)throw new RuntimeException('The uploaded file is not a valid .xlsx workbook.');
    try{
        $sharedStrings=[];
        $sharedXml=$zip->getFromName('xl/sharedStrings.xml');
        if(is_string($sharedXml)){
            $shared=simplexml_load_string($sharedXml);
            if($shared===false)throw new RuntimeException('The workbook shared strings could not be read.');
            foreach($shared->si as $entry){$parts=[];foreach($entry->xpath('.//*[local-name()="t"]')?:[] as $text)$parts[]=(string)$text;$sharedStrings[]=implode('',$parts);}
        }
        $sheetPath=null;
        for($index=1;$index<=100;$index++)if($zip->locateName('xl/worksheets/sheet'.$index.'.xml')!==false){$sheetPath='xl/worksheets/sheet'.$index.'.xml';break;}
        if($sheetPath===null)throw new RuntimeException('The workbook does not contain a readable worksheet.');
        $sheetXml=$zip->getFromName($sheetPath);
        $sheet=is_string($sheetXml)?simplexml_load_string($sheetXml):false;
        if($sheet===false)throw new RuntimeException('The first worksheet could not be read.');
        $rows=[];
        foreach($sheet->sheetData->row as $row){
            $values=[];$present=[];
            foreach($row->c as $cell){
                $reference=(string)($cell['r']??'');
                if(!preg_match('/^([A-Z]+)/',$reference,$match))continue;
                $column=$match[1];
                if(!in_array($column,['A','B'],true))continue;
                $values[$column]=trim(dev_budget_xlsx_cell_value($cell,$sharedStrings));$present[$column]=true;
            }
            $hasAmount=isset($present['B'])&&($values['B']??'')!=='';
            if(($values['A']??'')!==''||$hasAmount)$rows[]=['label'=>$values['A']??'','amount'=>$values['B']??'','has_amount'=>$hasAmount];
        }
    }finally{$zip->close();}
    $items=[];$currentCategory='';$currentKey='';$categoryCounts=[];$assumptions=[];
    foreach($rows as $index=>$row){
        $label=trim((string)$row['label']);if($label==='')continue;
        $normalized=strtolower(preg_replace('/\s+/',' ',$label));
        if(preg_match('/contingency[^0-9]*([0-9]+(?:\.[0-9]+)?)\s*%/i',$label,$match))$assumptions['contingency_percent']=(float)$match[1];
        if(preg_match('/project management fee[^0-9]*([0-9]+(?:\.[0-9]+)?)\s*%/i',$label,$match))$assumptions['management_fee_percent']=(float)$match[1];
        if(preg_match('/([0-9]+(?:\.[0-9]+)?)\s*%\s*partnership/i',$label,$match))$assumptions['partnership_percent']=(float)$match[1];
        if(preg_match('/([0-9]+(?:\.[0-9]+)?)\s*%\s*bank/i',$label,$match))$assumptions['bank_percent']=(float)$match[1];
        if(preg_match('/^(budget|total budget|contingency\b|project management fee\b|total project cost\b|equity requirements?\b|bank structure\b|[0-9]+(?:\.[0-9]+)?\s*%\s*(?:partnership|bank)\b)/i',$normalized))continue;
        if(!$row['has_amount']){
            $next=null;
            for($look=$index+1;$look<count($rows);$look++)if($rows[$look]['label']!==''||$rows[$look]['has_amount']){$next=$rows[$look];break;}
            if(!$next||!$next['has_amount'])continue;
            $currentCategory=$label;
            $baseKey=trim(preg_replace('/[^a-z0-9]+/','_',strtolower($label)),'_')?:'category';
            $categoryCounts[$baseKey]=($categoryCounts[$baseKey]??0)+1;
            $currentKey=$baseKey.($categoryCounts[$baseKey]>1?'_'.$categoryCounts[$baseKey]:'');
            continue;
        }
        if($currentCategory==='')continue;
        $rawAmount=str_replace([',','$','(',')',' '],['','','-','',''],(string)$row['amount']);
        if($rawAmount===''||!is_numeric($rawAmount))throw new RuntimeException('Budget amount for "'.$label.'" is not numeric.');
        $items[]=['category_key'=>$currentKey,'category_name'=>$currentCategory,'item_name'=>$label,'amount'=>max(0,(float)$rawAmount)];
    }
    if(!$items)throw new RuntimeException('No budget categories and line items were found. Use column A for category/item names and column B for amounts.');
    if(isset($assumptions['partnership_percent'])&&!isset($assumptions['bank_percent']))$assumptions['bank_percent']=100-$assumptions['partnership_percent'];
    if(isset($assumptions['bank_percent'])&&!isset($assumptions['partnership_percent']))$assumptions['partnership_percent']=100-$assumptions['bank_percent'];
    return ['items'=>$items,'assumptions'=>$assumptions];
}

function dev_budget_for_project(int $projectId, int $userId): array
{
    $pdo=db();
    $query=$pdo->prepare('SELECT * FROM construction_project_budgets WHERE construction_project_id=?');
    $query->execute([$projectId]);
    $budget=$query->fetch();
    if(!$budget){
        $pdo->prepare('INSERT INTO construction_project_budgets(construction_project_id,created_by_admin_user_id,updated_by_admin_user_id) VALUES(?,?,?)')->execute([$projectId,$userId?:null,$userId?:null]);
        $query->execute([$projectId]);
        $budget=$query->fetch();
    }
    return $budget?:[];
}

function dev_budget_items(int $budgetId): array
{
    $query=db()->prepare('SELECT * FROM construction_budget_items WHERE construction_project_budget_id=? ORDER BY display_order,id');
    $query->execute([$budgetId]);
    return $query->fetchAll()?:[];
}

function dev_budget_group_items(array $items): array
{
    $groups=[];
    foreach($items as $item){
        $key=(string)$item['category_key'];
        if(!isset($groups[$key]))$groups[$key]=['name'=>(string)$item['category_name'],'items'=>[]];
        $groups[$key]['items'][]=$item;
    }
    return $groups;
}

function dev_budget_equity_sources(int $budgetId): array
{
    $query=db()->prepare('SELECT * FROM construction_budget_equity_sources WHERE construction_project_budget_id=? ORDER BY display_order,id');
    $query->execute([$budgetId]);
    return $query->fetchAll()?:[];
}

function dev_budget_project_companies(int $projectId): array
{
    $query=db()->prepare("SELECT pc.id,c.company_name,pc.trade_role FROM construction_project_companies pc JOIN construction_companies c ON c.id=pc.construction_company_id WHERE pc.construction_project_id=? ORDER BY pc.trade_role,c.company_name");
    $query->execute([$projectId]);
    return $query->fetchAll()?:[];
}

function dev_budget_excel_xml(string $value): string{return htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');}
function dev_budget_excel_text_cell(string $column,int $row,string $value,int $style=0): string{return '<c r="'.$column.$row.'" t="inlineStr"'.($style?' s="'.$style.'"':'').'><is><t xml:space="preserve">'.dev_budget_excel_xml($value).'</t></is></c>';}
function dev_budget_excel_number_cell(string $column,int $row,float $value,int $style=2): string{return '<c r="'.$column.$row.'" s="'.$style.'"><v>'.rtrim(rtrim(sprintf('%.4F',$value),'0'),'.').'</v></c>';}
function dev_budget_excel_formula_cell(string $column,int $row,string $formula,int $style=2,float $cached=0): string{return '<c r="'.$column.$row.'" s="'.$style.'"><f>'.dev_budget_excel_xml($formula).'</f><v>'.rtrim(rtrim(sprintf('%.4F',$cached),'0'),'.').'</v></c>';}
function dev_budget_excel_column(int $number): string{$column='';while($number>0){$number--;$column=chr(65+($number%26)).$column;$number=intdiv($number,26);}return $column;}

function dev_build_aia_budget_xlsx(string $projectName,array $rows,array $drawGroups): string
{
    if(!class_exists(ZipArchive::class))throw new RuntimeException('Excel export is not available on this server.');
    $headers=['Category','Budget Item','Budget Amount','Budget Reallocate','New Budget','Total Spent','Allowance Left','Contract Value','Contract Remaining','Allowance After Contract'];foreach($drawGroups as $group)$headers[]=(string)$group;
    $columnCount=count($headers);$lastColumn=dev_budget_excel_column($columnCount);$merges=['A1:'.$lastColumn.'1'];$sheetRows=[];$sheetRows[]='<row r="1" ht="28" customHeight="1">'.dev_budget_excel_text_cell('A',1,$projectName.' — Budget Cost Schedule',1).'</row>';
    $headerCells='';foreach($headers as $index=>$header)$headerCells.=dev_budget_excel_text_cell(dev_budget_excel_column($index+1),3,$header,3);$sheetRows[]='<row r="3" ht="30" customHeight="1">'.$headerCells.'</row>';
    $excelRow=4;$firstDataRow=$excelRow;
    foreach($rows as $entry){$newBudget=(float)$entry['budget']+(float)$entry['reallocation'];$allowance=$newBudget-(float)$entry['spent'];$afterContract=$allowance-(float)$entry['contract_remaining'];$cells=dev_budget_excel_text_cell('A',$excelRow,(string)$entry['category']).dev_budget_excel_text_cell('B',$excelRow,(string)$entry['item']).dev_budget_excel_number_cell('C',$excelRow,(float)$entry['budget']).dev_budget_excel_number_cell('D',$excelRow,(float)$entry['reallocation']).dev_budget_excel_formula_cell('E',$excelRow,'C'.$excelRow.'+D'.$excelRow,2,$newBudget).dev_budget_excel_number_cell('F',$excelRow,(float)$entry['spent']).dev_budget_excel_formula_cell('G',$excelRow,'E'.$excelRow.'-F'.$excelRow,2,$allowance).dev_budget_excel_number_cell('H',$excelRow,(float)$entry['contract_value']).dev_budget_excel_number_cell('I',$excelRow,(float)$entry['contract_remaining']).dev_budget_excel_formula_cell('J',$excelRow,'E'.$excelRow.'-F'.$excelRow.'-I'.$excelRow,2,$afterContract);foreach($drawGroups as $index=>$group)$cells.=dev_budget_excel_number_cell(dev_budget_excel_column(11+$index),$excelRow,(float)($entry['draws'][$group]??0));$sheetRows[]='<row r="'.$excelRow.'">'.$cells.'</row>';$excelRow++;}
    $lastDataRow=$excelRow-1;$totalRow=$excelRow;$totalCells=dev_budget_excel_text_cell('A',$totalRow,'TOTAL',6);for($column=3;$column<=$columnCount;$column++){$letter=dev_budget_excel_column($column);$cached=array_sum(array_map(static function(array $entry)use($column,$drawGroups):float{if($column===3)return (float)$entry['budget'];if($column===4)return (float)$entry['reallocation'];if($column===5)return (float)$entry['budget']+(float)$entry['reallocation'];if($column===6)return (float)$entry['spent'];if($column===7)return (float)$entry['budget']+(float)$entry['reallocation']-(float)$entry['spent'];if($column===8)return (float)$entry['contract_value'];if($column===9)return (float)$entry['contract_remaining'];if($column===10)return (float)$entry['budget']+(float)$entry['reallocation']-(float)$entry['spent']-(float)$entry['contract_remaining'];$group=$drawGroups[$column-11]??'';return (float)($entry['draws'][$group]??0);},$rows));$totalCells.=dev_budget_excel_formula_cell($letter,$totalRow,$lastDataRow>=$firstDataRow?'SUM('.$letter.$firstDataRow.':'.$letter.$lastDataRow.')':'0',6,$cached);}$sheetRows[]='<row r="'.$totalRow.'" ht="24" customHeight="1">'.$totalCells.'</row>';
    $excelRow+=3;$notesHeaderRow=$excelRow;$merges[]='A'.$excelRow.':'.$lastColumn.$excelRow;$sheetRows[]='<row r="'.$excelRow.'" ht="24" customHeight="1">'.dev_budget_excel_text_cell('A',$excelRow,'REALLOCATION NOTES',3).'</row>';$excelRow++;$merges[]='C'.$excelRow.':'.$lastColumn.$excelRow;$sheetRows[]='<row r="'.$excelRow.'">'.dev_budget_excel_text_cell('A',$excelRow,'Budget Item',3).dev_budget_excel_text_cell('B',$excelRow,'Reallocation',3).dev_budget_excel_text_cell('C',$excelRow,'Reason / Notes',3).'</row>';$excelRow++;
    $hasNotes=false;foreach($rows as $entry){$note=trim((string)($entry['note']??''));if($note==='')continue;$hasNotes=true;$merges[]='C'.$excelRow.':'.$lastColumn.$excelRow;$sheetRows[]='<row r="'.$excelRow.'" ht="42" customHeight="1">'.dev_budget_excel_text_cell('A',$excelRow,(string)$entry['item']).dev_budget_excel_number_cell('B',$excelRow,(float)$entry['reallocation']).dev_budget_excel_text_cell('C',$excelRow,$note,7).'</row>';$excelRow++;}if(!$hasNotes){$merges[]='A'.$excelRow.':'.$lastColumn.$excelRow;$sheetRows[]='<row r="'.$excelRow.'">'.dev_budget_excel_text_cell('A',$excelRow,'No reallocation notes recorded.',7).'</row>';}
    $cols='<col min="1" max="1" width="25" customWidth="1"/><col min="2" max="2" width="34" customWidth="1"/><col min="3" max="'.$columnCount.'" width="18" customWidth="1"/>';
    $mergeXml='<mergeCells count="'.count($merges).'">';foreach($merges as $merge)$mergeXml.='<mergeCell ref="'.$merge.'"/>';$mergeXml.='</mergeCells>';$worksheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane xSplit="2" ySplit="3" topLeftCell="C4" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><cols>'.$cols.'</cols><sheetData>'.implode('',$sheetRows).'</sheetData><autoFilter ref="A3:'.$lastColumn.$lastDataRow.'"/>'.$mergeXml.'</worksheet>';
    $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="$#,##0.00;[Red]($#,##0.00);-"/></numFmts><fonts count="3"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="16"/><color rgb="FF0D294B"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF174B83"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border/><border><bottom style="thin"><color rgb="FFD9E1EA"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/><xf numFmtId="164" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs></styleSheet>';
    $files=['[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>','_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>','xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Budget Cost Schedule" sheetId="1" r:id="rId1"/></sheets><calcPr calcId="0" fullCalcOnLoad="1"/></workbook>','xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>','xl/worksheets/sheet1.xml'=>$worksheet,'xl/styles.xml'=>$styles];
    $path=tempnam(sys_get_temp_dir(),'znp-aia-budget-');if($path===false)throw new RuntimeException('Could not create the Excel export.');$xlsx=$path.'.xlsx';rename($path,$xlsx);$zip=new ZipArchive();if($zip->open($xlsx,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not create the Excel export.');foreach($files as $name=>$contents)$zip->addFromString($name,$contents);$zip->close();return $xlsx;
}

function dev_build_budget_xlsx(string $projectName,array $items,array $budget,array $equitySources): string
{
    if(!class_exists(ZipArchive::class))throw new RuntimeException('Excel export is not available on this server.');
    $rows=[];$row=1;$rows[]='<row r="1" ht="26" customHeight="1">'.dev_budget_excel_text_cell('A',1,$projectName,1).'</row>';$row=3;$firstItemRow=0;$lastItemRow=0;$currentCategory=null;
    foreach($items as $item){
        if($currentCategory!==$item['category_key']){$currentCategory=$item['category_key'];$rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,(string)$item['category_name'],3).'</row>';$row++;}
        if(!$firstItemRow)$firstItemRow=$row;$lastItemRow=$row;$contractor=trim((string)($item['trade_role']??'').((string)($item['company_name']??'')!==''?' — '.$item['company_name']:''));
        $rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,(string)$item['item_name']).dev_budget_excel_number_cell('B',$row,(float)$item['amount']).dev_budget_excel_text_cell('C',$row,(string)($item['notes']??'')).dev_budget_excel_text_cell('D',$row,$contractor).'</row>';$row++;
    }
    $subtotal=array_sum(array_map(static fn(array $item):float=>(float)$item['amount'],$items));$row++;$budgetRow=$row;$range=$firstItemRow&&$lastItemRow?'B'.$firstItemRow.':B'.$lastItemRow:'';$rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,'Budget',4).($range?dev_budget_excel_formula_cell('B',$row,'SUM('.$range.')',4,$subtotal):dev_budget_excel_number_cell('B',$row,0,4)).'</row>';$row++;
    $contingencyRow=$row;$contingencyPercent=(float)($budget['contingency_percent']??5);$contingency=$contingencyPercent/100;$contingencyAmount=$subtotal*$contingency;$rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,'Contingency '.rtrim(rtrim(number_format($contingencyPercent,2,'.',''),'0'),'.').'%',4).dev_budget_excel_formula_cell('B',$row,'B'.$budgetRow.'*C'.$row,4,$contingencyAmount).dev_budget_excel_number_cell('C',$row,$contingency,5).'</row>';$row++;
    $managementRow=$row;$managementPercent=(float)($budget['management_fee_percent']??5);$management=$managementPercent/100;$managementAmount=$subtotal*$management;$rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,'Project Management Fee '.rtrim(rtrim(number_format($managementPercent,2,'.',''),'0'),'.').'%',4).dev_budget_excel_formula_cell('B',$row,'B'.$budgetRow.'*C'.$row,4,$managementAmount).dev_budget_excel_number_cell('C',$row,$management,5).'</row>';$row++;
    $totalProjectCost=$subtotal+$contingencyAmount+$managementAmount;$totalRow=$row;$rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,'TOTAL PROJECT COST',6).dev_budget_excel_formula_cell('B',$row,'SUM(B'.$budgetRow.':B'.$managementRow.')',6,$totalProjectCost).'</row>';$row+=2;
    $rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,'BANK STRUCTURE',3).'</row>';$row++;
    $partnershipPercent=(float)($budget['partnership_percent']??30);$partnership=$partnershipPercent/100;$partnershipRow=$row;$rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,rtrim(rtrim(number_format($partnershipPercent,2,'.',''),'0'),'.').'% Partnership',4).dev_budget_excel_formula_cell('B',$row,'B'.$totalRow.'*C'.$row,4,$totalProjectCost*$partnership).dev_budget_excel_number_cell('C',$row,$partnership,5).'</row>';$row++;
    $bankPercent=(float)($budget['bank_percent']??70);$bank=$bankPercent/100;$rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,rtrim(rtrim(number_format($bankPercent,2,'.',''),'0'),'.').'% Bank',4).dev_budget_excel_formula_cell('B',$row,'B'.$totalRow.'*C'.$row,4,$totalProjectCost*$bank).dev_budget_excel_number_cell('C',$row,$bank,5).'</row>';$row+=2;
    $rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,'EQUITY SOURCES',3).'</row>';$row++;
    $rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,'Name',4).dev_budget_excel_text_cell('B',$row,'Date',4).dev_budget_excel_text_cell('C',$row,'Amount',4).dev_budget_excel_text_cell('D',$row,'Method',4).'</row>';$row++;$firstEquityRow=$row;
    foreach($equitySources as $source){$rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,(string)$source['source_name']).dev_budget_excel_text_cell('B',$row,(string)($source['source_date']??'')).dev_budget_excel_number_cell('C',$row,(float)$source['amount']).dev_budget_excel_text_cell('D',$row,(string)$source['method']).'</row>';$row++;}
    $lastEquityRow=$row-1;$equityTotal=array_sum(array_map(static fn(array $source):float=>(float)$source['amount'],$equitySources));$row++;$minimumRow=$row;$rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,'Minimum Partnership Equity',4).dev_budget_excel_formula_cell('C',$row,'B'.$partnershipRow,4,$totalProjectCost*$partnership).'</row>';$row++;$equityTotalRow=$row;$equityFormula=$lastEquityRow>=$firstEquityRow?'SUM(C'.$firstEquityRow.':C'.$lastEquityRow.')':'0';$rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,'Total Equity Sources',4).dev_budget_excel_formula_cell('C',$row,$equityFormula,4,$equityTotal).'</row>';$row++;$rows[]='<row r="'.$row.'">'.dev_budget_excel_text_cell('A',$row,'Equity Above / (Below) Requirement',6).dev_budget_excel_formula_cell('C',$row,'C'.$equityTotalRow.'-C'.$minimumRow,6,$equityTotal-($totalProjectCost*$partnership)).'</row>';
    $worksheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews><cols><col min="1" max="1" width="48" customWidth="1"/><col min="2" max="2" width="18" customWidth="1"/><col min="3" max="3" width="36" customWidth="1"/><col min="4" max="4" width="34" customWidth="1"/></cols><sheetData>'.implode('',$rows).'</sheetData></worksheet>';
    $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="$#,##0.00;[Red]($#,##0.00);-"/><numFmt numFmtId="165" formatCode="0.00%"/></numFmts><fonts count="3"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="16"/><color rgb="FF0D294B"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF174B83"/><bgColor indexed="64"/></patternFill></fill><borders count="2"><border/><border><bottom style="thin"><color rgb="FFD9E1EA"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="7"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0"/><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>';
    $files=['[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>','_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>','xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Budget" sheetId="1" r:id="rId1"/></sheets><calcPr calcId="0" fullCalcOnLoad="1"/></workbook>','xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>','xl/worksheets/sheet1.xml'=>$worksheet,'xl/styles.xml'=>$styles];
    $files['xl/styles.xml']=str_replace('</patternFill></fill><borders','</patternFill></fill></fills><borders',$files['xl/styles.xml']);
    $path=tempnam(sys_get_temp_dir(),'znp-budget-');if($path===false)throw new RuntimeException('Could not create the Excel export.');$xlsx=$path.'.xlsx';rename($path,$xlsx);$zip=new ZipArchive();if($zip->open($xlsx,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not create the Excel export.');foreach($files as $name=>$contents)$zip->addFromString($name,$contents);$zip->close();return $xlsx;
}
