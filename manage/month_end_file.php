<?php
require_once __DIR__.'/includes/bootstrap.php';
$id=(int)($_GET['id']??0);
$query=db()->prepare('SELECT f.*,s.management_property_id FROM management_month_end_files f JOIN management_month_end_submissions s ON s.id=f.management_month_end_submission_id WHERE f.id=?');
$query->execute([$id]);$file=$query->fetch();
if(!$file||!manage_can_access_property((int)$file['management_property_id'])){http_response_code(404);exit('File not found.');}
znp_storage_send_file((string)$file['file_path'],(string)$file['original_name'],(string)$file['mime_type'],'attachment');
