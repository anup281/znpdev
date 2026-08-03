<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';manage_require_admin();$id=(int)($_GET['id']??0);$q=db()->prepare('SELECT f.*,r.management_property_id FROM management_fee_record_files f JOIN management_fee_records r ON r.id=f.management_fee_record_id WHERE f.id=?');$q->execute([$id]);$file=$q->fetch();if(!$file||!manage_can_access_property((int)$file['management_property_id'])){http_response_code(404);exit('File not found.');}znp_storage_send_file((string)$file['file_path'],(string)$file['original_name'],(string)$file['mime_type'],'attachment');
