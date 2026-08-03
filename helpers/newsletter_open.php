<?php
require __DIR__.'/../includes/db.php';$id=(int)($_GET['d']??0);if($id>0){$s=db()->prepare('UPDATE newsletter_deliveries SET opened_at=COALESCE(opened_at,NOW()) WHERE id=?');$s->execute([$id]);}
header('Content-Type: image/gif');echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
