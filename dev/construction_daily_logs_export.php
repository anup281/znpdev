<?php
declare(strict_types=1);

require_once __DIR__.'/includes/bootstrap.php';

$projectId=dev_active_project_id((int)($_GET['project_id']??0));
$project=dev_require_project($projectId);
if(!class_exists(ZipArchive::class)){
    http_response_code(500);
    exit('ZIP export is unavailable because the PHP ZIP extension is not installed.');
}

$safeName=trim((string)preg_replace('/[^a-z0-9]+/i','-',strtolower((string)$project['project_name'])),'-')?:'project';
$zipPath=znp_storage_temp_path('zip');
$zip=new ZipArchive();
$temporaryPhotoFiles=[];
if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){
    http_response_code(500);
    exit('The Daily Log export could not be created.');
}

try{
    $logsQuery=db()->prepare('SELECT dl.*,au.full_name author_name,au.email author_email FROM construction_daily_logs dl LEFT JOIN admin_users au ON au.id=dl.admin_user_id WHERE dl.construction_project_id=? ORDER BY dl.log_date,dl.id');
    $logsQuery->execute([$projectId]);
    $logs=$logsQuery->fetchAll()?:[];

    $workforceByLog=[];
    if($logs && znp_daily_export_table_exists('construction_daily_log_workforce')){
        $ids=array_map('intval',array_column($logs,'id'));
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $workforceQuery=db()->prepare("SELECT * FROM construction_daily_log_workforce WHERE daily_log_id IN ($marks) ORDER BY daily_log_id,id");
        $workforceQuery->execute($ids);
        foreach($workforceQuery->fetchAll()?:[] as $row)$workforceByLog[(int)$row['daily_log_id']][]=$row;
    }

    $userMap=[];
    try{foreach(db()->query('SELECT id,full_name,email FROM admin_users')->fetchAll()?:[] as $row)$userMap[(int)$row['id']]=$row;}catch(Throwable $exception){error_log('Daily Log export user lookup failed: '.$exception->getMessage());}
    $companyMap=[];
    try{
        $companyQuery=db()->prepare('SELECT pc.id,c.company_name,pc.trade_role FROM construction_project_companies pc JOIN construction_companies c ON c.id=pc.construction_company_id WHERE pc.construction_project_id=?');
        $companyQuery->execute([$projectId]);
        foreach($companyQuery->fetchAll()?:[] as $row)$companyMap[(int)$row['id']]=$row;
    }catch(Throwable $exception){error_log('Daily Log export company lookup failed: '.$exception->getMessage());}

    $photosByLog=[];
    if(znp_daily_export_table_exists('construction_daily_log_photos')){
        $photoQuery=db()->prepare('SELECT * FROM construction_daily_log_photos WHERE construction_project_id=? ORDER BY daily_log_id,id');
        $photoQuery->execute([$projectId]);
        foreach($photoQuery->fetchAll()?:[] as $row)$photosByLog[(int)$row['daily_log_id']][]=$row;
    }

    $manifestLogs=[];
    foreach($logs as $log){
        $workforce=[];
        foreach($workforceByLog[(int)$log['id']]??[] as $row){
            $sourceType=(string)($row['source_type']??'');
            $sourceId=(int)($row['source_id']??0);
            $userRow=$sourceType==='user'?($userMap[$sourceId]??null):null;
            $companyRow=$sourceType==='company'?($companyMap[$sourceId]??null):null;
            $workforce[]=[
                'id'=>(int)$row['id'],
                'source_type'=>$sourceType,
                'source_id'=>$sourceId,
                'source_key'=>(string)($row['source_key']??''),
                'display_label'=>(string)($row['display_label']??''),
                'worker_count'=>(int)($row['worker_count']??1),
                'email'=>(string)($userRow['email']??''),
                'company_name'=>(string)($companyRow['company_name']??''),
                'trade'=>(string)($companyRow['trade_role']??''),
            ];
        }
        $photos=[];
        foreach($photosByLog[(int)$log['id']]??[] as $photo){
            $archivePath=znp_daily_export_add_photo($zip,$photo,'photos/daily-logs/'.(int)$log['id'],$temporaryPhotoFiles);
            $photos[]=[
                'id'=>(int)$photo['id'],
                'archive_path'=>$archivePath,
                'source_path'=>(string)$photo['file_path'],
                'original_name'=>(string)($photo['original_name']?:basename((string)$photo['file_path'])),
                'mime_type'=>(string)($photo['mime_type']??'application/octet-stream'),
                'file_size'=>(int)($photo['file_size']??0),
                'created_at'=>(string)($photo['created_at']??''),
            ];
        }
        $manifestLogs[]=[
            'id'=>(int)$log['id'],
            'log_date'=>(string)$log['log_date'],
            'weather'=>(string)($log['weather']??''),
            'visitors'=>(string)($log['visitors']??''),
            'work_performed'=>(string)($log['work_performed']??''),
            'delays'=>(string)($log['delays']??''),
            'safety_incidents'=>(string)($log['safety_incidents']??''),
            'notes'=>(string)($log['notes']??''),
            'workforce_count'=>(int)($log['workforce_count']??count($workforce)),
            'author_name'=>(string)($log['author_name']??''),
            'author_email'=>(string)($log['author_email']??''),
            'created_at'=>(string)($log['created_at']??''),
            'updated_at'=>(string)($log['updated_at']??$log['created_at']??''),
            'workforce'=>$workforce,
            'photos'=>$photos,
        ];
    }

    $progressPhotos=[];
    if(znp_daily_export_table_exists('construction_photos')){
        $progressQuery=db()->prepare('SELECT ph.*,au.full_name uploader_name,au.email uploader_email FROM construction_photos ph LEFT JOIN admin_users au ON au.id=ph.admin_user_id WHERE ph.construction_project_id=? ORDER BY ph.taken_on,ph.id');
        $progressQuery->execute([$projectId]);
        foreach($progressQuery->fetchAll()?:[] as $photo){
            $archivePath=znp_daily_export_add_photo($zip,$photo,'photos/progress',$temporaryPhotoFiles);
            $progressPhotos[]=[
                'id'=>(int)$photo['id'],
                'archive_path'=>$archivePath,
                'source_path'=>(string)$photo['file_path'],
                'original_name'=>(string)($photo['original_name']?:basename((string)$photo['file_path'])),
                'mime_type'=>(string)($photo['mime_type']??'application/octet-stream'),
                'file_size'=>(int)($photo['file_size']??0),
                'caption'=>(string)($photo['caption']??''),
                'taken_on'=>(string)($photo['taken_on']??''),
                'uploader_name'=>(string)($photo['uploader_name']??''),
                'uploader_email'=>(string)($photo['uploader_email']??''),
                'created_at'=>(string)($photo['created_at']??''),
            ];
        }
    }

    $manifest=[
        'format'=>'znpdev-daily-logs-v1',
        'exported_at'=>gmdate('c'),
        'project'=>['id'=>$projectId,'name'=>(string)$project['project_name']],
        'daily_logs'=>$manifestLogs,
        'progress_photos'=>$progressPhotos,
    ];
    $json=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(!$zip->addFromString('manifest.json',$json))throw new RuntimeException('The Daily Log manifest could not be added to the archive.');
    $zip->addFromString('README.txt',"ZNP Development Daily Log migration archive\n\nUpload this ZIP from the Daily Logs page in Run Tower. The archive includes the structured Daily Log records and copies of every associated cloud photo.\n");
    if(!$zip->close())throw new RuntimeException('The Daily Log ZIP could not be finalized.');

    while(ob_get_level()>0)ob_end_clean();
    $filename=$safeName.'-daily-logs-'.date('Y-m-d').'.zip';
    header('Content-Type: application/zip');
    header('Content-Length: '.filesize($zipPath));
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, private');
    readfile($zipPath);
}catch(Throwable $exception){
    if($zip->status===ZipArchive::ER_OK)$zip->close();
    error_log('Daily Log export failed: '.$exception->getMessage());
    if(!headers_sent())http_response_code(500);
    echo 'The Daily Log export could not be completed. Every cloud photo must be available before the migration archive can be created.';
}finally{
    if(is_file($zipPath))@unlink($zipPath);
    foreach($temporaryPhotoFiles as $temporaryPhotoFile)if(is_file($temporaryPhotoFile))@unlink($temporaryPhotoFile);
}

function znp_daily_export_table_exists(string $table): bool
{
    try{$query=db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$query->execute([$table]);return (int)$query->fetchColumn()>0;}
    catch(Throwable){return false;}
}

/** @param array<string,mixed> $photo
 *  @param array<int,string> $temporaryPhotoFiles
 */
function znp_daily_export_add_photo(ZipArchive $zip,array $photo,string $folder,array &$temporaryPhotoFiles): string
{
    $original=(string)($photo['original_name']?:basename((string)$photo['file_path']));
    $extension=strtolower(pathinfo($original,PATHINFO_EXTENSION));
    if(!in_array($extension,['jpg','jpeg','png','webp'],true)){
        $extension=match((string)($photo['mime_type']??'')){'image/png'=>'png','image/webp'=>'webp',default=>'jpg'};
    }
    $base=trim((string)preg_replace('/[^a-z0-9]+/i','-',pathinfo($original,PATHINFO_FILENAME)),'-')?:'photo';
    $archivePath=trim($folder,'/').'/'.(int)$photo['id'].'-'.$base.'.'.$extension;
    $contents=znp_storage_contents((string)$photo['file_path']);
    $temporaryPhoto=znp_storage_temp_path($extension);
    if(file_put_contents($temporaryPhoto,$contents,LOCK_EX)===false)throw new RuntimeException('Could not stage '.$original.' for the migration archive.');
    unset($contents);
    $temporaryPhotoFiles[]=$temporaryPhoto;
    if(!$zip->addFile($temporaryPhoto,$archivePath))throw new RuntimeException('Could not add '.$original.' to the migration archive.');
    return $archivePath;
}
