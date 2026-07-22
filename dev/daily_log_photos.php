<?php
require __DIR__.'/includes/bootstrap.php';
$projectId=(int)($_GET['project_id']??0);
$dailyLogId=(int)($_GET['daily_log_id']??0);
if($projectId<1||$dailyLogId<1){http_response_code(422);exit('A valid project and Daily Log are required.');}
dev_require_project($projectId);
$log=db()->prepare('SELECT id FROM construction_daily_logs WHERE id=? AND construction_project_id=? LIMIT 1');
$log->execute([$dailyLogId,$projectId]);
if(!$log->fetchColumn()){http_response_code(404);exit('Daily Log not found.');}
try{
    $photos=db()->prepare('SELECT id,file_path,original_name,mime_type,file_size,created_at FROM construction_daily_log_photos WHERE daily_log_id=? AND construction_project_id=? ORDER BY id');
    $photos->execute([$dailyLogId,$projectId]);
    $rows=$photos->fetchAll();
}catch(Throwable $exception){http_response_code(500);exit('Daily Log photos are temporarily unavailable.');}
header('Content-Type: text/html; charset=utf-8');
if(!$rows){echo '<p class="muted">No photos are attached to this Daily Log.</p>';exit;}
echo '<div class="daily-photo-grid">';
foreach($rows as $photo){
    $path=ltrim(str_replace('\\','/',(string)$photo['file_path']),'/');
    $url=strpos($path,'uploads/')===0?'../'.$path:(strpos($path,'dev/uploads/')===0?'../'.substr($path,4):'../uploads/dev/daily-logs/'.basename($path));
    $name=(string)($photo['original_name']?:'Daily Log photo');
    echo '<a href="'.e($url).'" data-daily-photo-viewer title="'.e($name).'"><img loading="lazy" decoding="async" src="'.e($url).'" alt="'.e($name).'"></a>';
}
echo '</div>';
