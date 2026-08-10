<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/expense_tracker.php';
header('Content-Type: application/json; charset=UTF-8');
try{
    if(!dev_is_super())throw new RuntimeException('Expense access denied.');
    $projectId=dev_active_project_id((int)($_REQUEST['project_id']??0));dev_require_project($projectId);dev_ensure_expense_tracker_schema();$expenseId=(int)($_REQUEST['expense_id']??0);$expenseQuery=db()->prepare('SELECT e.id,g.group_name FROM construction_project_expenses e JOIN construction_expense_groups g ON g.id=e.expense_group_id WHERE e.id=? AND e.construction_project_id=?');$expenseQuery->execute([$expenseId,$projectId]);$expense=$expenseQuery->fetch();if(!$expense)throw new RuntimeException('Expense not found.');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Session expired. Refresh and try again.');
        $uploaded=[];$fileInput=$_FILES['files']??[];$fileNames=$fileInput['name']??[];$fileEntries=[];
        if(is_array($fileNames))foreach($fileNames as $index=>$fileName)$fileEntries[]=['name'=>(string)$fileName,'type'=>(string)($fileInput['type'][$index]??''),'tmp_name'=>(string)($fileInput['tmp_name'][$index]??''),'error'=>(int)($fileInput['error'][$index]??UPLOAD_ERR_NO_FILE),'size'=>(int)($fileInput['size'][$index]??0)];
        elseif($fileInput)$fileEntries[]=['name'=>(string)$fileNames,'type'=>(string)($fileInput['type']??''),'tmp_name'=>(string)($fileInput['tmp_name']??''),'error'=>(int)($fileInput['error']??UPLOAD_ERR_NO_FILE),'size'=>(int)($fileInput['size']??0)];
        foreach($fileEntries as $file){
            $stored=dev_expense_upload_invoice($file,$projectId,(string)$expense['group_name']);if(!$stored)continue;
            try{db()->prepare('INSERT INTO construction_expense_attachments(construction_project_expense_id,file_path,original_name,mime_type,file_size,created_by_admin_user_id) VALUES(?,?,?,?,?,?)')->execute([$expenseId,$stored['path'],$stored['name'],$stored['mime'],$stored['size'],$user['id']??null]);$uploaded[]=$stored;}
            catch(Throwable $exception){try{znp_storage_delete((string)$stored['path']);}catch(Throwable $cleanupError){error_log('Expense file cleanup failed: '.$cleanupError->getMessage());}throw $exception;}
        }
        if(!$uploaded)throw new RuntimeException('Select at least one file to upload.');
    }
    $filesQuery=db()->prepare('SELECT id,original_name,mime_type,file_size,created_at FROM construction_expense_attachments WHERE construction_project_expense_id=? ORDER BY created_at,id');$filesQuery->execute([$expenseId]);$files=array_map(static fn(array $file):array=>['id'=>(int)$file['id'],'name'=>(string)$file['original_name'],'mime'=>(string)$file['mime_type'],'size'=>(int)$file['file_size'],'created_at'=>(string)$file['created_at'],'url'=>'expense_file.php?id='.(int)$file['id']],$filesQuery->fetchAll()?:[]);echo json_encode(['ok'=>true,'files'=>$files]);
}catch(Throwable $exception){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$exception->getMessage()?:'Expense files could not be loaded.']);}
