<?php
require_once __DIR__.'/includes/bootstrap.php';
if(!dev_is_super()){ http_response_code(403); exit('Super Admin access required.'); }
$projectId=(int)($_GET['project_id']??$_POST['project_id']??0);
$p=dev_require_project($projectId);
$error='';
$success='';
function recovery_relative(string $name): string { return 'uploads/dev/daily-logs/'.basename($name); }
function recovery_orphans(): array {
    $known=[];
    try{$known=db()->query('SELECT file_path FROM construction_daily_log_photos')->fetchAll(PDO::FETCH_COLUMN)?:[];}catch(Throwable $e){return [];}
    $known=array_flip(array_map(fn($v)=>ltrim(str_replace('\\','/',(string)$v),'/'),$known));
    $out=[];
    foreach(glob(__DIR__.'/../uploads/dev/daily-logs/*')?:[] as $path){
        if(!is_file($path)) continue;
        $relative=recovery_relative($path);
        if(isset($known[$relative])) continue;
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path)?:'application/octet-stream';
        if(strpos($mime,'image/')!==0) continue;
        $out[]=['name'=>basename($path),'path'=>$relative,'mime'=>$mime,'size'=>filesize($path)?:0,'modified'=>filemtime($path)?:0];
    }
    usort($out,fn($a,$b)=>$b['modified']<=>$a['modified']);
    return $out;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!csrf_check((string)($_POST['csrf']??''))) throw new RuntimeException('Session expired. Please refresh and try again.');
        $file=basename((string)($_POST['file_name']??''));
        $logId=(int)($_POST['daily_log_id']??0);
        if($file===''||$logId<1) throw new RuntimeException('Choose a photo and a Daily Log.');
        $full=__DIR__.'/../uploads/dev/daily-logs/'.$file;
        if(!is_file($full)) throw new RuntimeException('The selected photo no longer exists.');
        $relative=recovery_relative($file);
        $q=db()->prepare('SELECT id FROM construction_daily_logs WHERE id=? AND construction_project_id=?');$q->execute([$logId,$projectId]);
        if(!$q->fetchColumn()) throw new RuntimeException('The selected Daily Log is not part of this project.');
        $q=db()->prepare('SELECT id FROM construction_daily_log_photos WHERE file_path=? LIMIT 1');$q->execute([$relative]);
        if($q->fetchColumn()) throw new RuntimeException('That photo has already been attached.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($full)?:'application/octet-stream';
        if(strpos($mime,'image/')!==0) throw new RuntimeException('The selected file is not a supported image.');
        $s=db()->prepare('INSERT INTO construction_daily_log_photos(daily_log_id,construction_project_id,file_path,original_name,mime_type,file_size,created_at) VALUES(?,?,?,?,?,?,NOW())');
        $s->execute([$logId,$projectId,$relative,$file,$mime,filesize($full)?:0]);
        try{dev_activity($projectId,'daily_log_photo_recovered','An unlinked photo was attached to a Daily Log.','daily_log',$logId);}catch(Throwable $ignored){}
        header('Location: photo_recovery.php?project_id='.$projectId.'&recovered=1');exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
$s=db()->prepare('SELECT id,log_date,work_performed FROM construction_daily_logs WHERE construction_project_id=? ORDER BY log_date DESC,id DESC');$s->execute([$projectId]);$logs=$s->fetchAll();
$orphans=recovery_orphans();
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>Daily Log Photo Recovery</h1><p class="muted"><?=e($p['project_name'])?> · Attach server files that are not linked to a Daily Log.</p></div><a class="btn btn-secondary" href="daily_logs.php?project_id=<?=$projectId?>">Back to Daily Logs</a></div>
<?php if(isset($_GET['recovered'])):?><div class="card notice-success">Photo attached successfully.</div><?php endif;?>
<?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<?php if(!$orphans):?><div class="card empty">No unlinked Daily Log photos were found.</div><?php else:?><div class="recovery-grid"><?php foreach($orphans as $photo):?><article class="card recovery-card"><a href="../<?=e($photo['path'])?>" target="_blank"><img src="../<?=e($photo['path'])?>" alt="Unlinked Daily Log photo"></a><div><strong><?=e($photo['name'])?></strong><small><?=e(date('M j, Y g:i A',$photo['modified']))?> · <?=e(dev_filesize((int)$photo['size']))?></small></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="file_name" value="<?=e($photo['name'])?>"><label>Attach to Daily Log</label><select name="daily_log_id" required><option value="">Choose a Daily Log</option><?php foreach($logs as $log):?><option value="<?=(int)$log['id']?>"><?=e(dev_date((string)$log['log_date']))?> — <?=e(strlen(trim((string)$log['work_performed']))>70?substr(trim((string)$log['work_performed']),0,67).'…':trim((string)$log['work_performed']))?></option><?php endforeach;?></select><button class="primary" type="submit">Attach Photo</button></form></article><?php endforeach;?></div><?php endif;?>
<style>.recovery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px}.recovery-card{display:grid;gap:13px}.recovery-card img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:9px}.recovery-card>div{display:grid;gap:4px}.recovery-card small{color:var(--muted)}.recovery-card form{display:grid;gap:9px}.recovery-card button{width:100%}</style>
<?php require __DIR__.'/includes/footer.php';?>
