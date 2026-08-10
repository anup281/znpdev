<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/budget.php';
header('Content-Type: application/json; charset=UTF-8');
try{
    if(!dev_is_super())throw new RuntimeException('Budget access denied.');
    if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Session expired. Refresh and try again.');
    $projectId=dev_active_project_id((int)($_POST['project_id']??0));dev_require_project($projectId);dev_ensure_budget_schema();
    $budget=dev_budget_for_project($projectId,(int)($user['id']??0));if(!empty($budget['is_locked']))throw new RuntimeException('This budget is locked. Unlock it before making changes.');$budgetId=(int)$budget['id'];$action=(string)($_POST['action']??'');$response=[];
    if($action==='update_completion'){
        $id=(int)($_POST['id']??0);$completed=!empty($_POST['completed'])?1:0;
        if($id===0)db()->prepare('UPDATE construction_project_budgets SET contingency_is_completed=?,updated_by_admin_user_id=? WHERE id=?')->execute([$completed,$user['id']??null,$budgetId]);
        else{$stmt=db()->prepare('UPDATE construction_budget_items SET is_completed=? WHERE id=? AND construction_project_budget_id=?');$stmt->execute([$completed,$id,$budgetId]);if(!$stmt->rowCount()){$check=db()->prepare('SELECT id FROM construction_budget_items WHERE id=? AND construction_project_budget_id=?');$check->execute([$id,$budgetId]);if(!$check->fetchColumn())throw new RuntimeException('Budget item not found.');}}
    }elseif($action==='update_reallocation_note'){
        $id=(int)($_POST['id']??0);$note=mb_substr(trim((string)($_POST['note']??'')),0,1000);
        if($id===0)db()->prepare('UPDATE construction_project_budgets SET contingency_reallocation_note=?,updated_by_admin_user_id=? WHERE id=?')->execute([$note,$user['id']??null,$budgetId]);
        else{$stmt=db()->prepare('UPDATE construction_budget_items SET reallocation_note=? WHERE id=? AND construction_project_budget_id=?');$stmt->execute([$note,$id,$budgetId]);if(!$stmt->rowCount()){$check=db()->prepare('SELECT id FROM construction_budget_items WHERE id=? AND construction_project_budget_id=?');$check->execute([$id,$budgetId]);if(!$check->fetchColumn())throw new RuntimeException('Budget item not found.');}}
    }elseif($action==='update_reallocation'){
        $id=(int)($_POST['id']??0);$raw=str_replace([',','$',' '],'',(string)($_POST['amount']??0));if($raw===''||!is_numeric($raw))throw new RuntimeException('Enter a valid reallocation amount.');$amount=round((float)$raw,2);
        if($id===0)db()->prepare('UPDATE construction_project_budgets SET contingency_reallocated_amount=?,updated_by_admin_user_id=? WHERE id=?')->execute([$amount,$user['id']??null,$budgetId]);
        else{$stmt=db()->prepare('UPDATE construction_budget_items SET reallocated_amount=? WHERE id=? AND construction_project_budget_id=?');$stmt->execute([$amount,$id,$budgetId]);if(!$stmt->rowCount()){$check=db()->prepare('SELECT id FROM construction_budget_items WHERE id=? AND construction_project_budget_id=?');$check->execute([$id,$budgetId]);if(!$check->fetchColumn())throw new RuntimeException('Budget item not found.');}}
        $itemTotal=(float)db()->query('SELECT COALESCE(SUM(reallocated_amount),0) FROM construction_budget_items WHERE construction_project_budget_id='.(int)$budgetId)->fetchColumn();
        $contingencyTotal=$id===0?$amount:(float)($budget['contingency_reallocated_amount']??0);$response['reallocation_total']=$itemTotal+$contingencyTotal;
    }elseif($action==='update_item'){
        $id=(int)($_POST['id']??0);$name=trim((string)($_POST['item_name']??''));if($name==='')throw new RuntimeException('Item name is required.');
        $amount=max(0,(float)str_replace([',','$'],'',(string)($_POST['amount']??0)));$notes=mb_substr(trim((string)($_POST['notes']??'')),0,500);$companyId=(int)($_POST['project_company_id']??0);
        if($companyId){$check=db()->prepare('SELECT id FROM construction_project_companies WHERE id=? AND construction_project_id=?');$check->execute([$companyId,$projectId]);if(!$check->fetchColumn())throw new RuntimeException('The selected contractor is not assigned to this project.');}
        $stmt=db()->prepare('UPDATE construction_budget_items SET item_name=?,amount=?,notes=?,construction_project_company_id=? WHERE id=? AND construction_project_budget_id=?');$stmt->execute([$name,$amount,$notes,$companyId?:null,$id,$budgetId]);if(!$stmt->rowCount()){$check=db()->prepare('SELECT id FROM construction_budget_items WHERE id=? AND construction_project_budget_id=?');$check->execute([$id,$budgetId]);if(!$check->fetchColumn())throw new RuntimeException('Budget item not found.');}
    }elseif($action==='add_item'){
        $categoryKey=trim((string)($_POST['category_key']??''));$categoryName=trim((string)($_POST['category_name']??''));if($categoryKey===''||$categoryName==='')throw new RuntimeException('Budget category is required.');
        $order=(int)db()->query('SELECT COALESCE(MAX(display_order),0)+1 FROM construction_budget_items WHERE construction_project_budget_id='.(int)$budgetId)->fetchColumn();
        db()->prepare('INSERT INTO construction_budget_items(construction_project_budget_id,category_key,category_name,item_name,amount,notes,display_order) VALUES(?,?,?,?,0,?,?)')->execute([$budgetId,$categoryKey,$categoryName,'New Item','',$order]);$response['id']=(int)db()->lastInsertId();
    }elseif($action==='delete_item'){
        $stmt=db()->prepare('DELETE FROM construction_budget_items WHERE id=? AND construction_project_budget_id=?');$stmt->execute([(int)($_POST['id']??0),$budgetId]);if(!$stmt->rowCount())throw new RuntimeException('Budget item not found.');
    }elseif($action==='update_assumptions'){
        $contingency=max(0,min(100,(float)($_POST['contingency_percent']??5)));$management=max(0,min(100,(float)($_POST['management_fee_percent']??5)));$partnership=max(0,min(100,(float)($_POST['partnership_percent']??30)));$bank=max(0,min(100,(float)($_POST['bank_percent']??70)));if(abs($partnership+$bank-100)>0.0001)throw new RuntimeException('Partnership and Bank must total 100%.');
        db()->prepare('UPDATE construction_project_budgets SET contingency_percent=?,management_fee_percent=?,partnership_percent=?,bank_percent=?,updated_by_admin_user_id=? WHERE id=?')->execute([$contingency,$management,$partnership,$bank,$user['id']??null,$budgetId]);
    }elseif($action==='add_equity'){
        $order=(int)db()->query('SELECT COALESCE(MAX(display_order),0)+1 FROM construction_budget_equity_sources WHERE construction_project_budget_id='.(int)$budgetId)->fetchColumn();db()->prepare('INSERT INTO construction_budget_equity_sources(construction_project_budget_id,display_order) VALUES(?,?)')->execute([$budgetId,$order]);$response['id']=(int)db()->lastInsertId();
    }elseif($action==='update_equity'){
        $id=(int)($_POST['id']??0);$name=mb_substr(trim((string)($_POST['source_name']??'')),0,190);$date=trim((string)($_POST['source_date']??''));if($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('Enter a valid equity date.');$amount=max(0,(float)str_replace([',','$'],'',(string)($_POST['amount']??0)));$method=mb_substr(trim((string)($_POST['method']??'')),0,100);
        $stmt=db()->prepare('UPDATE construction_budget_equity_sources SET source_name=?,source_date=?,amount=?,method=? WHERE id=? AND construction_project_budget_id=?');$stmt->execute([$name,$date?:null,$amount,$method,$id,$budgetId]);
    }elseif($action==='delete_equity'){
        $stmt=db()->prepare('DELETE FROM construction_budget_equity_sources WHERE id=? AND construction_project_budget_id=?');$stmt->execute([(int)($_POST['id']??0),$budgetId]);if(!$stmt->rowCount())throw new RuntimeException('Equity source not found.');
    }else throw new RuntimeException('Unknown budget action.');
    echo json_encode(['ok'=>true]+$response);
}catch(Throwable $exception){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$exception->getMessage()?:'The change could not be saved.']);}
