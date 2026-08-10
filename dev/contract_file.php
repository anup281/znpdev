<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/contracts.php';
$id=(int)($_GET['id']??0);dev_ensure_contract_schema();$q=db()->prepare('SELECT d.*,ct.construction_project_id FROM construction_contract_documents d JOIN construction_contracts ct ON ct.id=d.construction_contract_id WHERE d.id=?');$q->execute([$id]);$file=$q->fetch();if(!$file||!dev_can_access((int)$file['construction_project_id'])){http_response_code(404);exit('File not found.');}znp_storage_send_file((string)$file['file_path'],(string)$file['original_name'],(string)$file['mime_type'],'inline');
