<?php
declare(strict_types=1);

function dev_ensure_expense_tracker_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS construction_expense_groups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        construction_project_id BIGINT UNSIGNED NOT NULL,
        group_name VARCHAR(100) NOT NULL,
        group_type VARCHAR(30) NOT NULL DEFAULT 'draw',
        draw_number INT UNSIGNED NULL,
        date_submitted DATE NULL,
        date_funded DATE NULL,
        amount_funded DECIMAL(16,2) NOT NULL DEFAULT 0.00,
        display_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_by_admin_user_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        UNIQUE KEY uq_expense_group_project_name(construction_project_id,group_name),
        KEY idx_expense_groups_project(construction_project_id,display_order,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if(!db()->query("SHOW COLUMNS FROM construction_expense_groups LIKE 'date_submitted'")->fetchColumn())db()->exec("ALTER TABLE construction_expense_groups ADD COLUMN date_submitted DATE NULL AFTER draw_number");
    if(!db()->query("SHOW COLUMNS FROM construction_expense_groups LIKE 'date_funded'")->fetchColumn())db()->exec("ALTER TABLE construction_expense_groups ADD COLUMN date_funded DATE NULL AFTER date_submitted");
    if(!db()->query("SHOW COLUMNS FROM construction_expense_groups LIKE 'amount_funded'")->fetchColumn())db()->exec("ALTER TABLE construction_expense_groups ADD COLUMN amount_funded DECIMAL(16,2) NOT NULL DEFAULT 0.00 AFTER date_funded");
    db()->exec("CREATE TABLE IF NOT EXISTS construction_project_expenses (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        construction_project_id BIGINT UNSIGNED NOT NULL,
        expense_number VARCHAR(40) NOT NULL DEFAULT '',
        expense_name VARCHAR(255) NOT NULL DEFAULT '',
        amount DECIMAL(16,2) NOT NULL DEFAULT 0.00,
        expense_date DATE NULL,
        payment_method VARCHAR(500) NOT NULL DEFAULT '',
        category VARCHAR(190) NOT NULL DEFAULT '',
        grouping_name VARCHAR(100) NOT NULL DEFAULT 'UNGROUPED',
        expense_group_id BIGINT UNSIGNED NULL,
        construction_budget_item_id BIGINT UNSIGNED NULL,
        construction_contract_id BIGINT UNSIGNED NULL,
        construction_contract_payment_id BIGINT UNSIGNED NULL,
        retainage_amount DECIMAL(16,2) NOT NULL DEFAULT 0.00,
        total_paid DECIMAL(16,2) NOT NULL DEFAULT 0.00,
        is_contingency TINYINT(1) NOT NULL DEFAULT 0,
        display_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_by_admin_user_id BIGINT UNSIGNED NULL,
        updated_by_admin_user_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        KEY idx_project_expenses_project(construction_project_id,display_order,id),
        KEY idx_project_expenses_category(construction_project_id,category),
        KEY idx_project_expenses_contract(construction_contract_id),
        KEY idx_project_expenses_contract_payment(construction_contract_payment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if(!db()->query("SHOW COLUMNS FROM construction_project_expenses LIKE 'grouping_name'")->fetchColumn())db()->exec("ALTER TABLE construction_project_expenses ADD COLUMN grouping_name VARCHAR(100) NOT NULL DEFAULT 'UNGROUPED' AFTER category");
    if(!db()->query("SHOW COLUMNS FROM construction_project_expenses LIKE 'expense_group_id'")->fetchColumn())db()->exec("ALTER TABLE construction_project_expenses ADD COLUMN expense_group_id BIGINT UNSIGNED NULL AFTER grouping_name, ADD KEY idx_project_expenses_group(expense_group_id)");
    if(!db()->query("SHOW COLUMNS FROM construction_project_expenses LIKE 'construction_budget_item_id'")->fetchColumn())db()->exec("ALTER TABLE construction_project_expenses ADD COLUMN construction_budget_item_id BIGINT UNSIGNED NULL AFTER expense_group_id, ADD KEY idx_project_expenses_budget_item(construction_budget_item_id)");
    if(!db()->query("SHOW COLUMNS FROM construction_project_expenses LIKE 'construction_contract_id'")->fetchColumn())db()->exec("ALTER TABLE construction_project_expenses ADD COLUMN construction_contract_id BIGINT UNSIGNED NULL AFTER construction_budget_item_id, ADD KEY idx_project_expenses_contract(construction_contract_id)");
    if(!db()->query("SHOW INDEX FROM construction_project_expenses WHERE Key_name='idx_project_expenses_contract'")->fetchColumn())db()->exec("ALTER TABLE construction_project_expenses ADD KEY idx_project_expenses_contract(construction_contract_id)");
    if(!db()->query("SHOW COLUMNS FROM construction_project_expenses LIKE 'construction_contract_payment_id'")->fetchColumn())db()->exec("ALTER TABLE construction_project_expenses ADD COLUMN construction_contract_payment_id BIGINT UNSIGNED NULL AFTER construction_contract_id, ADD KEY idx_project_expenses_contract_payment(construction_contract_payment_id)");
    if(!db()->query("SHOW INDEX FROM construction_project_expenses WHERE Key_name='idx_project_expenses_contract_payment'")->fetchColumn())db()->exec("ALTER TABLE construction_project_expenses ADD KEY idx_project_expenses_contract_payment(construction_contract_payment_id)");
    if(!db()->query("SHOW COLUMNS FROM construction_project_expenses LIKE 'retainage_amount'")->fetchColumn())db()->exec("ALTER TABLE construction_project_expenses ADD COLUMN retainage_amount DECIMAL(16,2) NOT NULL DEFAULT 0.00 AFTER construction_contract_payment_id");
    if(!db()->query("SHOW COLUMNS FROM construction_project_expenses LIKE 'total_paid'")->fetchColumn())db()->exec("ALTER TABLE construction_project_expenses ADD COLUMN total_paid DECIMAL(16,2) NOT NULL DEFAULT 0.00 AFTER retainage_amount");
    if(!db()->query("SHOW COLUMNS FROM construction_project_expenses LIKE 'is_contingency'")->fetchColumn())db()->exec("ALTER TABLE construction_project_expenses ADD COLUMN is_contingency TINYINT(1) NOT NULL DEFAULT 0 AFTER total_paid");
    db()->exec("CREATE TABLE IF NOT EXISTS construction_expense_attachments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        construction_project_expense_id BIGINT UNSIGNED NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(150) NOT NULL DEFAULT 'application/octet-stream',
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_by_admin_user_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        KEY idx_expense_attachments_expense(construction_project_expense_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function dev_expense_upload_invoice(array $file,int $projectId,string $groupName,int $maxBytes=15728640): ?array
{
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Invoice upload failed.');
    if((int)($file['size']??0)>$maxBytes)throw new RuntimeException('The invoice exceeds the 15 MB upload limit.');
    $extension=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));$allowed=['pdf','jpg','jpeg','png','webp'];if(!in_array($extension,$allowed,true))throw new RuntimeException('The invoice must be a PDF, JPG, PNG, or WebP file.');
    $mime='application/octet-stream';if(class_exists('finfo')){$finfo=new finfo(FILEINFO_MIME_TYPE);$detected=$finfo->file((string)($file['tmp_name']??''));if(is_string($detected)&&$detected!=='')$mime=$detected;}$valid=['pdf'=>['application/pdf'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp']];if(!in_array($mime,$valid[$extension],true))throw new RuntimeException('The invoice content does not match its file extension.');
    $groupFolder=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','-',$groupName),'-'))?:'ungrouped';$path='uploads/dev/expense-invoices/project-'.$projectId.'/'.$groupFolder.'/'.bin2hex(random_bytes(20)).'.'.$extension;znp_storage_store_uploaded_file($path,(string)$file['tmp_name'],$extension,$mime);return ['path'=>$path,'name'=>(string)$file['name'],'mime'=>$mime,'size'=>(int)$file['size']];
}

function dev_expense_attachments(int $projectId): array
{
    $query=db()->prepare('SELECT a.* FROM construction_expense_attachments a JOIN construction_project_expenses e ON e.id=a.construction_project_expense_id WHERE e.construction_project_id=? ORDER BY a.created_at,a.id');$query->execute([$projectId]);$files=[];foreach($query->fetchAll()?:[] as $file)$files[(int)$file['construction_project_expense_id']][]=$file;return $files;
}

function dev_ensure_expense_group(int $projectId,string $name,int $userId=0): int
{
    $name=mb_substr(strtoupper(trim(preg_replace('/\s+/',' ',$name))),0,100);if($name==='')$name='UNGROUPED';$type=$name==='EQUITY'?'equity':($name==='UNGROUPED'?'ungrouped':'draw');$drawNumber=preg_match('/^DRAW\s+(\d+)$/',$name,$match)?(int)$match[1]:null;
    $query=db()->prepare('SELECT id FROM construction_expense_groups WHERE construction_project_id=? AND group_name=?');$query->execute([$projectId,$name]);$id=(int)$query->fetchColumn();if($id)return $id;
    $order=(int)db()->query('SELECT COALESCE(MAX(display_order),0)+1 FROM construction_expense_groups WHERE construction_project_id='.(int)$projectId)->fetchColumn();db()->prepare('INSERT INTO construction_expense_groups(construction_project_id,group_name,group_type,draw_number,display_order,created_by_admin_user_id) VALUES(?,?,?,?,?,?)')->execute([$projectId,$name,$type,$drawNumber,$order,$userId?:null]);return (int)db()->lastInsertId();
}

function dev_sync_expense_groups(int $projectId,int $userId=0): int
{
    $query=db()->prepare("SELECT DISTINCT grouping_name FROM construction_project_expenses WHERE construction_project_id=? AND grouping_name<>''");$query->execute([$projectId]);$count=0;foreach($query->fetchAll()?:[] as $row){dev_ensure_expense_group($projectId,(string)$row['grouping_name'],$userId);$count++;}dev_ensure_expense_group($projectId,'UNGROUPED',$userId);db()->prepare('UPDATE construction_project_expenses e JOIN construction_expense_groups g ON g.construction_project_id=e.construction_project_id AND g.group_name=e.grouping_name SET e.expense_group_id=g.id WHERE e.construction_project_id=? AND (e.expense_group_id IS NULL OR e.expense_group_id<>g.id)')->execute([$projectId]);return $count;
}

function dev_expense_groups(int $projectId): array
{
    $query=db()->prepare('SELECT g.*,COUNT(e.id) expense_count,COALESCE(SUM(e.amount),0) expense_total FROM construction_expense_groups g LEFT JOIN construction_project_expenses e ON e.expense_group_id=g.id WHERE g.construction_project_id=? GROUP BY g.id ORDER BY g.display_order,g.id');$query->execute([$projectId]);return $query->fetchAll()?:[];
}

function dev_expense_group_items(array $expenses): array
{
    $groups=[];foreach($expenses as $expense){$name=trim((string)($expense['grouping_name']??''))?:'UNGROUPED';if(!isset($groups[$name]))$groups[$name]=[];$groups[$name][]=$expense;}
    foreach($groups as &$groupExpenses)usort($groupExpenses,static function(array $left,array $right):int{
        $leftNumber=trim((string)($left['expense_number']??''));$rightNumber=trim((string)($right['expense_number']??''));
        if($leftNumber===''||$rightNumber===''){if($leftNumber===$rightNumber)return (int)$left['id']<=>(int)$right['id'];return $leftNumber===''?1:-1;}
        $comparison=strnatcasecmp($leftNumber,$rightNumber);return $comparison!==0?$comparison:((int)$left['id']<=>(int)$right['id']);
    });unset($groupExpenses);
    return $groups;
}

function dev_expense_budget_items(int $projectId): array
{
    $query=db()->prepare('SELECT bi.id,bi.item_name,bi.category_name FROM construction_budget_items bi JOIN construction_project_budgets b ON b.id=bi.construction_project_budget_id WHERE b.construction_project_id=? ORDER BY bi.category_name,bi.display_order,bi.id');
    $query->execute([$projectId]);return $query->fetchAll()?:[];
}

function dev_sync_expense_budget_items(int $projectId): int
{
    $stmt=db()->prepare('UPDATE construction_project_expenses e JOIN construction_project_budgets b ON b.construction_project_id=e.construction_project_id JOIN construction_budget_items bi ON bi.construction_project_budget_id=b.id AND LOWER(TRIM(bi.item_name))=LOWER(TRIM(e.category)) SET e.construction_budget_item_id=bi.id,e.category=bi.item_name WHERE e.construction_project_id=? AND (e.construction_budget_item_id IS NULL OR e.construction_budget_item_id<>bi.id)');$stmt->execute([$projectId]);$updated=$stmt->rowCount();
    $contingency=db()->prepare("UPDATE construction_project_expenses SET construction_budget_item_id=NULL,is_contingency=1,category='Contingency' WHERE construction_project_id=? AND LOWER(TRIM(category))='contingency' AND (construction_budget_item_id IS NOT NULL OR is_contingency<>1 OR category<>'Contingency')");$contingency->execute([$projectId]);
    return $updated+$contingency->rowCount();
}

function dev_expense_group_names(int $projectId): array
{
    return array_map(static fn(array $group):string=>(string)$group['group_name'],dev_expense_groups($projectId));
}

function dev_project_expenses(int $projectId): array
{
    $query=db()->prepare('SELECT * FROM construction_project_expenses WHERE construction_project_id=? ORDER BY display_order,id');
    $query->execute([$projectId]);
    return $query->fetchAll()?:[];
}
