<?php
require_once __DIR__.'/includes/bootstrap.php';
$projectId=dev_active_project_id((int)($_GET['project_id']??$_POST['project_id']??0));
$p=dev_require_project($projectId);
$error='';
$success=isset($_GET['deleted'])?'Photo deleted successfully.':'';
$up=null;
if($_SERVER['REQUEST_METHOD']==='POST')try{
    if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Session expired.');
    $action=(string)($_POST['action']??'upload');
    if($action==='delete'){
        $photoId=(int)($_POST['photo_id']??0);
        $source=(string)($_POST['photo_source']??'progress');
        if($source==='daily_log'){
            $q=db()->prepare('SELECT id,file_path,original_name FROM construction_daily_log_photos WHERE id=? AND construction_project_id=?');
            $q->execute([$photoId,$projectId]);
            $photo=$q->fetch();
            if(!$photo)throw new RuntimeException('Photo not found.');
            db()->prepare('DELETE FROM construction_daily_log_photos WHERE id=? AND construction_project_id=?')->execute([$photoId,$projectId]);
        }else{
            $q=db()->prepare('SELECT id,file_path,original_name FROM construction_photos WHERE id=? AND construction_project_id=?');
            $q->execute([$photoId,$projectId]);
            $photo=$q->fetch();
            if(!$photo)throw new RuntimeException('Photo not found.');
            db()->prepare('DELETE FROM construction_photos WHERE id=? AND construction_project_id=?')->execute([$photoId,$projectId]);
        }
        try{
            znp_storage_delete((string)$photo['file_path']);
        }catch(Throwable $storageError){
            error_log('Deleted photo storage cleanup failed: '.$storageError->getMessage());
            throw new RuntimeException('The photo was removed from the project, but its S4 file could not be deleted. Check the server error log.');
        }
        try{dev_activity($projectId,'photo_deleted','Photo deleted: '.$photo['original_name'],'photo',$photoId);}catch(Throwable $activityError){error_log('Photo deletion activity failed: '.$activityError->getMessage());}
        header("Location: photos.php?project_id=$projectId&deleted=1");
        exit;
    }
    $up=dev_compress_image($_FILES['photo'],'photos');
    if(!$up)throw new RuntimeException('Choose a photo.');
    znp_storage_put_file((string)$up['path'],znp_storage_local_path((string)$up['path']),(string)$up['mime']);
    $s=db()->prepare('INSERT INTO construction_photos(construction_project_id,admin_user_id,file_path,original_name,caption,taken_on,created_at) VALUES(?,?,?,?,?,?,NOW())');
    $s->execute([$projectId,$user['id'],$up['path'],$up['name'],trim((string)$_POST['caption']),($_POST['taken_on']??'')?:date('Y-m-d')]);
    $id=(int)db()->lastInsertId();
    $savedUpload=$up;
    $up=null;
    if(znp_storage_uses_s4())try{znp_storage_remove_local((string)$savedUpload['path']);}catch(Throwable $cleanupError){error_log('Progress photo local cleanup failed: '.$cleanupError->getMessage());}
    dev_activity($projectId,'photo_uploaded','Progress photo uploaded: '.$savedUpload['name'],'photo',$id);
    header("Location: photos.php?project_id=$projectId");
    exit;
}catch(Throwable $e){
    if(is_array($up)&&!empty($up['path']))try{znp_storage_delete((string)$up['path']);}catch(Throwable $cleanupError){error_log('Progress photo cleanup failed: '.$cleanupError->getMessage());}
    $error=$e->getMessage();
}
$s=db()->prepare("SELECT ph.*,au.full_name,'progress' photo_source FROM construction_photos ph LEFT JOIN admin_users au ON au.id=ph.admin_user_id WHERE ph.construction_project_id=? ORDER BY ph.taken_on DESC,ph.id DESC");
$s->execute([$projectId]);
$rows=$s->fetchAll();
try{
    $d=db()->prepare("SELECT dlp.*,dl.log_date taken_on,CONCAT('Daily Log · ',DATE_FORMAT(dl.log_date,'%b %e, %Y')) caption,au.full_name,'daily_log' photo_source FROM construction_daily_log_photos dlp JOIN construction_daily_logs dl ON dl.id=dlp.daily_log_id LEFT JOIN admin_users au ON au.id=dl.admin_user_id WHERE dlp.construction_project_id=? ORDER BY dl.log_date DESC,dlp.id DESC");
    $d->execute([$projectId]);
    $rows=array_merge($rows,$d->fetchAll());
    usort($rows,fn($a,$b)=>strcmp((string)$b['taken_on'],(string)$a['taken_on']));
}catch(Throwable $e){error_log('Daily Log photos could not be included in Progress Photos: '.$e->getMessage());}
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>Progress Photos</h1><p class="muted"><?=e($p['project_name'])?> · Includes Daily Log photos automatically</p></div></div><?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?><?php if($success):?><div class="card notice-success"><?=e($success)?></div><?php endif;?>
<form method="post" enctype="multipart/form-data" class="card form-grid" data-heic-upload-form><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><div><label>Photo</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" required data-heic-input><small class="muted">HEIC/HEIF is converted to JPEG in your browser before upload.</small><div class="heic-upload-status muted" data-heic-status aria-live="polite"></div></div><div><label>Date Taken</label><input type="date" name="taken_on" value="<?=date('Y-m-d')?>"></div><div class="form-full"><label>Caption</label><input name="caption"></div><div class="form-full"><button class="primary">Upload Photo</button></div></form>
<div class="photo-grid znp-mt-5"><?php foreach($rows as $r):?><article class="card photo-card"><img src="<?=e(znp_storage_url((string)$r['file_path']))?>" alt="<?=e((string)($r['caption']?:$r['original_name']))?>"><h3><?=e($r['caption']?:$r['original_name'])?></h3><small><?=e(dev_date($r['taken_on']))?> · <?=e($r['full_name']?:'User')?></small><form method="post" class="photo-delete-form" onsubmit="return confirm('Delete this photo? It will also be permanently deleted from S4.');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="photo_id" value="<?=(int)$r['id']?>"><input type="hidden" name="photo_source" value="<?=e((string)$r['photo_source'])?>"><button type="submit" class="btn btn-danger btn-small">Delete</button></form></article><?php endforeach;?></div><script src="https://cdnjs.cloudflare.com/ajax/libs/heic2any/0.0.4/heic2any.min.js" defer></script><script src="assets/heic-upload.js?v=1.0.2" defer></script><?php require __DIR__.'/includes/footer.php';?>
