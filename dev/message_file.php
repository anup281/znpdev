<?php
require_once __DIR__.'/includes/bootstrap.php';
$id=(int)($_GET['id']??0);
$s=db()->prepare('SELECT a.*,m.construction_project_id FROM construction_message_attachments a JOIN construction_messages m ON m.id=a.message_id WHERE a.id=?');
$s->execute([$id]);
$f=$s->fetch();
if(!$f||!dev_can_access((int)$f['construction_project_id'])){
    http_response_code(404);
    exit('File not found.');
}
$localFile=znp_storage_local_file((string)$f['file_path']);
if($localFile!==null){
    header('Content-Type: '.((string)$f['mime_type']?:'application/octet-stream'));
    header('Content-Length: '.filesize($localFile));
    header('Content-Disposition: inline; filename="'.str_replace(["\r","\n",'"'],'',basename((string)$f['original_name'])).'"');
    readfile($localFile);
    exit;
}
znp_storage_send_file((string)$f['file_path'],(string)$f['original_name'],(string)($f['mime_type']?:'application/octet-stream'),'inline');
