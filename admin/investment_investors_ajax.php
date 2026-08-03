<?php
declare(strict_types=1);
ob_start();
require_once __DIR__.'/../includes/auth.php';
require_admin();

function investor_json(array $payload,int $status=200):void
{
    while(ob_get_level()>0)ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload,JSON_UNESCAPED_SLASHES);
    exit;
}

function investor_number(string $value,string $label,bool $allowZero=false):float
{
    $clean=str_replace([',','$',' '],'',$value);
    if($clean===''||!is_numeric($clean)||!is_finite((float)$clean)){
        throw new RuntimeException($label.' must be a valid number.');
    }
    $number=(float)$clean;
    if($number<0||(!$allowZero&&$number<=0)){
        throw new RuntimeException($label.' must be '.($allowZero?'zero or greater.':'greater than zero.'));
    }
    return $number;
}

function investor_name(string $value,string $label):string
{
    $value=trim($value);
    if($value==='')throw new RuntimeException($label.' is required.');
    if(mb_strlen($value)>100)throw new RuntimeException($label.' cannot exceed 100 characters.');
    return $value;
}

function investor_tables_ready(PDO $pdo):bool
{
    return (bool)$pdo->query("SHOW TABLES LIKE 'investment_investor_structures'")->fetchColumn()
        &&(bool)$pdo->query("SHOW TABLES LIKE 'investment_project_investors'")->fetchColumn();
}

function investor_payload(PDO $pdo,int $projectId):array
{
    $structure=$pdo->prepare('SELECT lp_total_units FROM investment_investor_structures WHERE investment_opportunity_id=?');
    $structure->execute([$projectId]);
    $totalUnits=(float)($structure->fetchColumn()?:2000000);
    $rows=$pdo->prepare(
        "SELECT id,investor_type,first_name,last_name,ownership_percent,investment_amount,unit_amount
         FROM investment_project_investors
         WHERE investment_opportunity_id=?
         ORDER BY investor_type,
                  CASE WHEN investor_type='GP' THEN ownership_percent END DESC,
                  CASE WHEN investor_type='LP' THEN unit_amount END DESC,
                  last_name, first_name, id"
    );
    $rows->execute([$projectId]);
    $gp=[];$lp=[];$gpPercent=0.0;$gpInvestment=0.0;$subscribed=0.0;
    foreach($rows->fetchAll() as $row){
        $item=[
            'id'=>(int)$row['id'],
            'first_name'=>(string)$row['first_name'],
            'last_name'=>(string)$row['last_name'],
            'ownership_percent'=>$row['ownership_percent']===null?null:(float)$row['ownership_percent'],
            'investment_amount'=>(float)$row['investment_amount'],
            'unit_amount'=>$row['unit_amount']===null?null:(float)$row['unit_amount'],
        ];
        if($row['investor_type']==='GP'){
            $gp[]=$item;
            $gpPercent+=(float)$row['ownership_percent'];
            $gpInvestment+=(float)$row['investment_amount'];
        }else{
            $lp[]=$item;
            $subscribed+=(float)$row['unit_amount'];
        }
    }
    return [
        'gp'=>$gp,
        'lp'=>$lp,
        'summary'=>[
            'gp_percent'=>$gpPercent,
            'gp_percent_remaining'=>max(0,100-$gpPercent),
            'gp_investment'=>$gpInvestment,
            'lp_total_units'=>$totalUnits,
            'lp_units_subscribed'=>$subscribed,
            'lp_units_remaining'=>max(0,$totalUnits-$subscribed),
        ],
    ];
}

if($_SERVER['REQUEST_METHOD']!=='POST')investor_json(['ok'=>false,'error'=>'POST requests only.'],405);
if(!csrf_check((string)($_POST['csrf_token']??'')))investor_json(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);

$projectId=(int)($_POST['investment_opportunity_id']??0);
$action=(string)($_POST['action']??'list');
if($projectId<1)investor_json(['ok'=>false,'error'=>'Invalid investment project.'],422);
if(!can_access_investment($projectId))investor_json(['ok'=>false,'error'=>'You do not have access to this investment.'],403);
if(investments_only_role()&&$action!=='list')investor_json(['ok'=>false,'error'=>'This account has read-only investor access.'],403);
$archiveCheck=db()->prepare("SELECT 1 FROM investment_opportunities WHERE id=? AND (status='archived' OR (status='' AND is_visible=0 AND accepting_inquiries=0))");$archiveCheck->execute([$projectId]);$archivedInvestment=(bool)$archiveCheck->fetchColumn();if($archivedInvestment&&$action!=='list')investor_json(['ok'=>false,'error'=>'Archived investments are view only.'],403);

try{
    $pdo=db();
    if(!investor_tables_ready($pdo))throw new RuntimeException('The Investor Structure upgrade has not been installed.');
    $project=$pdo->prepare('SELECT id FROM investment_opportunities WHERE id=?');
    $project->execute([$projectId]);
    if(!$project->fetchColumn())throw new RuntimeException('Investment project not found.');
    if(!investments_only_role()&&!$archivedInvestment){
        $initialize=$pdo->prepare('INSERT IGNORE INTO investment_investor_structures (investment_opportunity_id,lp_total_units,updated_by_admin_id) VALUES (?,2000000.00,?)');
        $initialize->execute([$projectId,admin_user()['id']??null]);
    }

    if($action==='list'){
        investor_json(['ok'=>true,'data'=>investor_payload($pdo,$projectId)]);
    }

    if($action==='add_gp'){
        $first=investor_name((string)($_POST['first_name']??''),'First name');
        $last=investor_name((string)($_POST['last_name']??''),'Last name');
        $percent=investor_number((string)($_POST['ownership_percent']??''),'Percentage');
        $investment=investor_number((string)($_POST['investment_amount']??''),'Investment amount',true);
        if($percent>100)throw new RuntimeException('Percentage cannot exceed 100%.');
        $pdo->beginTransaction();
        $lock=$pdo->prepare('SELECT lp_total_units FROM investment_investor_structures WHERE investment_opportunity_id=? FOR UPDATE');
        $lock->execute([$projectId]);
        if($lock->fetchColumn()===false)throw new RuntimeException('Investor structure is not initialized for this project.');
        $total=$pdo->prepare("SELECT COALESCE(SUM(ownership_percent),0) FROM investment_project_investors WHERE investment_opportunity_id=? AND investor_type='GP'");
        $total->execute([$projectId]);
        if((float)$total->fetchColumn()+$percent>100.00001)throw new RuntimeException('GP ownership cannot exceed 100%. Reduce the percentage and try again.');
        $insert=$pdo->prepare("INSERT INTO investment_project_investors (investment_opportunity_id,investor_type,first_name,last_name,ownership_percent,investment_amount,unit_amount,created_by_admin_id) VALUES (?,'GP',?,?,?,?,NULL,?)");
        $insert->execute([$projectId,$first,$last,$percent,$investment,admin_user()['id']??null]);
        $pdo->commit();
    }elseif($action==='add_lp'){
        $first=investor_name((string)($_POST['first_name']??''),'First name');
        $last=investor_name((string)($_POST['last_name']??''),'Last name');
        $units=investor_number((string)($_POST['unit_amount']??''),'Unit amount');
        $pdo->beginTransaction();
        $lock=$pdo->prepare('SELECT lp_total_units FROM investment_investor_structures WHERE investment_opportunity_id=? FOR UPDATE');
        $lock->execute([$projectId]);
        $totalUnits=$lock->fetchColumn();
        if($totalUnits===false)throw new RuntimeException('Investor structure is not initialized for this project.');
        $used=$pdo->prepare("SELECT COALESCE(SUM(unit_amount),0) FROM investment_project_investors WHERE investment_opportunity_id=? AND investor_type='LP'");
        $used->execute([$projectId]);
        if((float)$used->fetchColumn()+$units>(float)$totalUnits+.001)throw new RuntimeException('This subscription exceeds the LP units still available.');
        $insert=$pdo->prepare("INSERT INTO investment_project_investors (investment_opportunity_id,investor_type,first_name,last_name,ownership_percent,investment_amount,unit_amount,created_by_admin_id) VALUES (?,'LP',?,?,NULL,?,?,?)");
        $insert->execute([$projectId,$first,$last,$units,$units,admin_user()['id']??null]);
        $pdo->commit();
    }elseif($action==='update'){
        $investorId=(int)($_POST['investor_id']??0);
        if($investorId<1)throw new RuntimeException('Invalid investor record.');
        $first=investor_name((string)($_POST['first_name']??''),'First name');
        $last=investor_name((string)($_POST['last_name']??''),'Last name');
        $pdo->beginTransaction();
        $lock=$pdo->prepare('SELECT investor_type FROM investment_project_investors WHERE id=? AND investment_opportunity_id=? FOR UPDATE');
        $lock->execute([$investorId,$projectId]);
        $type=$lock->fetchColumn();
        if($type===false)throw new RuntimeException('Investor record not found.');
        $structure=$pdo->prepare('SELECT lp_total_units FROM investment_investor_structures WHERE investment_opportunity_id=? FOR UPDATE');
        $structure->execute([$projectId]);
        $totalUnits=$structure->fetchColumn();
        if($totalUnits===false)throw new RuntimeException('Investor structure is not initialized for this project.');
        if($type==='GP'){
            $percent=investor_number((string)($_POST['ownership_percent']??''),'Percentage');
            $investment=investor_number((string)($_POST['investment_amount']??''),'Investment amount',true);
            if($percent>100)throw new RuntimeException('Percentage cannot exceed 100%.');
            $total=$pdo->prepare("SELECT COALESCE(SUM(ownership_percent),0) FROM investment_project_investors WHERE investment_opportunity_id=? AND investor_type='GP' AND id<>?");
            $total->execute([$projectId,$investorId]);
            if((float)$total->fetchColumn()+$percent>100.00001)throw new RuntimeException('GP ownership cannot exceed 100%. Reduce the percentage and try again.');
            $update=$pdo->prepare("UPDATE investment_project_investors SET first_name=?,last_name=?,ownership_percent=?,investment_amount=?,unit_amount=NULL WHERE id=? AND investment_opportunity_id=? AND investor_type='GP'");
            $update->execute([$first,$last,$percent,$investment,$investorId,$projectId]);
        }else{
            $units=investor_number((string)($_POST['unit_amount']??''),'Unit amount');
            $used=$pdo->prepare("SELECT COALESCE(SUM(unit_amount),0) FROM investment_project_investors WHERE investment_opportunity_id=? AND investor_type='LP' AND id<>?");
            $used->execute([$projectId,$investorId]);
            if((float)$used->fetchColumn()+$units>(float)$totalUnits+.001)throw new RuntimeException('This subscription exceeds the LP units still available.');
            $update=$pdo->prepare("UPDATE investment_project_investors SET first_name=?,last_name=?,ownership_percent=NULL,investment_amount=?,unit_amount=? WHERE id=? AND investment_opportunity_id=? AND investor_type='LP'");
            $update->execute([$first,$last,$units,$units,$investorId,$projectId]);
        }
        $pdo->commit();
    }elseif($action==='update_units'){
        $units=investor_number((string)($_POST['lp_total_units']??''),'Total LP units');
        $pdo->beginTransaction();
        $lock=$pdo->prepare('SELECT lp_total_units FROM investment_investor_structures WHERE investment_opportunity_id=? FOR UPDATE');
        $lock->execute([$projectId]);
        if($lock->fetchColumn()===false)throw new RuntimeException('Investor structure is not initialized for this project.');
        $used=$pdo->prepare("SELECT COALESCE(SUM(unit_amount),0) FROM investment_project_investors WHERE investment_opportunity_id=? AND investor_type='LP'");
        $used->execute([$projectId]);
        if($units<(float)$used->fetchColumn()-.001)throw new RuntimeException('Total LP units cannot be lower than the units already subscribed.');
        $update=$pdo->prepare('UPDATE investment_investor_structures SET lp_total_units=?,updated_by_admin_id=? WHERE investment_opportunity_id=?');
        $update->execute([$units,admin_user()['id']??null,$projectId]);
        $pdo->commit();
    }elseif($action==='delete'){
        $investorId=(int)($_POST['investor_id']??0);
        if($investorId<1)throw new RuntimeException('Invalid investor record.');
        $delete=$pdo->prepare('DELETE FROM investment_project_investors WHERE id=? AND investment_opportunity_id=?');
        $delete->execute([$investorId,$projectId]);
        if($delete->rowCount()!==1)throw new RuntimeException('Investor record not found.');
    }else{
        throw new RuntimeException('Invalid investor action.');
    }

    investor_json(['ok'=>true,'message'=>'Saved','data'=>investor_payload($pdo,$projectId)]);
}catch(Throwable $exception){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('Investment investor action failed for project '.$projectId.': '.$exception->getMessage());
    investor_json(['ok'=>false,'error'=>$exception->getMessage()],422);
}
