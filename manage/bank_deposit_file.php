<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';$id=(int)($_GET['id']??0);$query=db()->prepare('SELECT * FROM management_bank_deposit_files WHERE id=?');$query->execute([$id]);$file=$query->fetch();if(!$file||!manage_can_access_property((int)$file['management_property_id'])){http_response_code(404);exit('File not found.');}$disposition=isset($_GET['preview'])?'inline':'attachment';znp_storage_send_file((string)$file['file_path'],(string)$file['original_name'],(string)$file['mime_type'],$disposition);
