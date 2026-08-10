<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/expense_tracker.php';
$id=(int)($_GET['id']??0);$query=db()->prepare('SELECT a.*,e.construction_project_id FROM construction_expense_attachments a JOIN construction_project_expenses e ON e.id=a.construction_project_expense_id WHERE a.id=?');$query->execute([$id]);$file=$query->fetch();if(!$file||!dev_can_access((int)$file['construction_project_id'])){http_response_code(404);exit('File not found.');}znp_storage_send_file((string)$file['file_path'],(string)$file['original_name'],(string)$file['mime_type'],'inline');
