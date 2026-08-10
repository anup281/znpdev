<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/expense_tracker.php';
header('Content-Type: application/json; charset=UTF-8');
try{
    if(!dev_is_super())throw new RuntimeException('Bank Draw access denied.');
    if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Session expired. Refresh and try again.');
    $projectId=dev_active_project_id((int)($_POST['project_id']??0));dev_require_project($projectId);dev_ensure_expense_tracker_schema();$groupId=(int)($_POST['group_id']??0);
    $submitted=trim((string)($_POST['date_submitted']??''));$funded=trim((string)($_POST['date_funded']??''));foreach([$submitted,$funded] as $date)if($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('Enter a valid draw date.');
    $amount=(float)str_replace([',','$'],'',(string)($_POST['amount_funded']??0));if($amount<0)throw new RuntimeException('Amount funded cannot be negative.');
    $stmt=db()->prepare("UPDATE construction_expense_groups SET date_submitted=?,date_funded=?,amount_funded=? WHERE id=? AND construction_project_id=? AND group_type='draw'");$stmt->execute([$submitted?:null,$funded?:null,$amount,$groupId,$projectId]);$check=db()->prepare("SELECT id FROM construction_expense_groups WHERE id=? AND construction_project_id=? AND group_type='draw'");$check->execute([$groupId,$projectId]);if(!$check->fetchColumn())throw new RuntimeException('Bank draw not found.');echo json_encode(['ok'=>true]);
}catch(Throwable $exception){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$exception->getMessage()?:'The draw could not be saved.']);}
