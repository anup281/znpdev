<?php
declare(strict_types=1);
function dev_is_super(): bool {
    $u = admin_user();
    if (!$u) return false;
    $role = strtolower(trim((string)($u['role'] ?? '')));
    $role = str_replace(['_', '-'], ' ', $role);
    $role = preg_replace('/\s+/', ' ', $role);
    return in_array($role, ['super admin', 'super administrator', 'administrator', 'admin'], true);
}
function dev_is_construction_only(): bool {
    $u = admin_user();
    return $u && (int)($u['construction_only'] ?? 0) === 1;
}
function dev_can_access(int $projectId): bool {
    if(dev_is_super()) return true;
    $u=admin_user(); if(!$u) return false;
    $s=db()->prepare('SELECT 1 FROM construction_project_users WHERE construction_project_id=? AND admin_user_id=? AND is_active=1');
    $s->execute([$projectId,(int)$u['id']]); return (bool)$s->fetchColumn();
}
function dev_projects(bool $archived=false): array {
    $u=admin_user();
    $archiveValue=$archived?1:0;
    if(dev_is_super()) {
        $s=db()->prepare('SELECT cp.*,p.project_name AS public_project_name FROM construction_projects cp LEFT JOIN projects p ON p.id=cp.public_project_id WHERE cp.is_archived=? ORDER BY cp.updated_at DESC,cp.project_name');
        $s->execute([$archiveValue]); return $s->fetchAll();
    }
    $s=db()->prepare('SELECT cp.*,p.project_name AS public_project_name FROM construction_projects cp JOIN construction_project_users cpu ON cpu.construction_project_id=cp.id AND cpu.admin_user_id=? AND cpu.is_active=1 LEFT JOIN projects p ON p.id=cp.public_project_id WHERE cp.is_archived=? ORDER BY cp.updated_at DESC,cp.project_name');
    $s->execute([(int)$u['id'],$archiveValue]); return $s->fetchAll();
}
function dev_project(int $id): ?array { $s=db()->prepare('SELECT cp.*,p.project_name AS public_project_name FROM construction_projects cp LEFT JOIN projects p ON p.id=cp.public_project_id WHERE cp.id=?');$s->execute([$id]);$r=$s->fetch();return $r?:null; }
function dev_project_statuses(bool $includeArchived=true): array {
    $statuses=['Planning','Preconstruction','Under Construction','Nearing Completion','Lease-Up','Completed'];
    if($includeArchived)$statuses[]='Archived';
    $statuses[]='Cancelled';
    return $statuses;
}
function dev_activity(int $projectId,string $type,string $description,?string $entityType=null,?int $entityId=null): void {
    $u=admin_user();$s=db()->prepare('INSERT INTO construction_activity(construction_project_id,admin_user_id,event_type,description,entity_type,entity_id,created_at) VALUES(?,?,?,?,?,?,NOW())');$s->execute([$projectId,$u['id']??null,$type,$description,$entityType,$entityId]);
}
function dev_filesize(int $bytes): string {if($bytes>=1073741824)return number_format($bytes/1073741824,2).' GB';if($bytes>=1048576)return number_format($bytes/1048576,2).' MB';if($bytes>=1024)return number_format($bytes/1024,1).' KB';return $bytes.' B';}
function dev_date(?string $v): string { return $v?date('M j, Y',strtotime($v)):'—'; }
function dev_datetime(?string $v): string { return $v?date('M j, Y · g:i A',strtotime($v)):'—'; }
function dev_status_class(string $s): string { return 'status-'.preg_replace('/[^a-z0-9]+/','-',strtolower($s)); }
function dev_upload(array $file,string $folder,array $allowed,int $max=15728640): ?array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Upload failed.');
    if((int)($file['size']??0)>$max)throw new RuntimeException('File exceeds the upload limit.');
    $ext=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));
    if(!in_array($ext,$allowed,true))throw new RuntimeException('Unsupported file type.');
    $mime='application/octet-stream';
    if(class_exists('finfo')){$finfo=new finfo(FILEINFO_MIME_TYPE);$detected=$finfo->file((string)($file['tmp_name']??''));if(is_string($detected)&&$detected!=='')$mime=$detected;}
    $valid=[
        'pdf'=>['application/pdf'],
        'doc'=>['application/msword','application/CDFV2','application/x-ole-storage'],
        'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'],
        'xls'=>['application/vnd.ms-excel','application/CDFV2','application/x-ole-storage'],
        'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'],
        'zip'=>['application/zip','application/x-zip-compressed'],
        'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp']
    ];
    if(isset($valid[$ext])&&!in_array($mime,$valid[$ext],true))throw new RuntimeException('The uploaded file content does not match its file extension.');
    $base=__DIR__.'/../../uploads/dev/'.$folder;
    if(!is_dir($base)&&!mkdir($base,0775,true))throw new RuntimeException('Upload folder is not writable.');
    $safe=bin2hex(random_bytes(12)).'.'.$ext;$path=$base.'/'.$safe;
    if(!move_uploaded_file((string)$file['tmp_name'],$path))throw new RuntimeException('Could not save the uploaded file.');
    return ['path'=>'uploads/dev/'.$folder.'/'.$safe,'name'=>(string)$file['name'],'mime'=>$mime,'size'=>(int)$file['size']];
}
function dev_upload_many(array $files,string $folder,array $allowed,int $max=15728640): array {$out=[]; $names=$files['name']??[];if(!is_array($names)) { $one=dev_upload($files,$folder,$allowed,$max); return $one?[$one]:[]; }foreach($names as $i=>$name){$f=['name'=>$name,'type'=>$files['type'][$i]??'','tmp_name'=>$files['tmp_name'][$i]??'','error'=>$files['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$files['size'][$i]??0];$one=dev_upload($f,$folder,$allowed,$max); if($one)$out[]=$one;}return $out;}
function dev_schedule_template_items(string $scope): array {
    $scope = ucfirst(strtolower(trim($scope)));
    if (!in_array($scope, ['Project', 'Building'], true)) {
        throw new InvalidArgumentException('Unknown schedule scope.');
    }
    $sql = "SELECT i.activity_name, i.default_duration_days, i.default_trade
            FROM construction_schedule_templates t
            INNER JOIN construction_schedule_template_items i ON i.template_id=t.id
            WHERE t.schedule_scope=? AND t.is_active=1
            ORDER BY t.is_default DESC, t.id, i.sequence_no, i.id";
    $stmt = db()->prepare($sql);
    $stmt->execute([$scope]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        throw new RuntimeException('No active '.$scope.' schedule template is configured. Open Schedule Templates in Construction Settings.');
    }
    return $rows;
}
function dev_master_schedule_template(): array {
    return array_column(dev_schedule_template_items('Project'), 'activity_name');
}
function dev_building_schedule_template(): array {
    return array_column(dev_schedule_template_items('Building'), 'activity_name');
}
function dev_initialize_schedule(int $projectId, ?int $buildingId, string $scope, ?string $startDate=null): void {
    $check=db()->prepare('SELECT COUNT(*) FROM construction_schedule_items WHERE construction_project_id=? AND schedule_scope=? AND '.($buildingId?'construction_building_id=?':'construction_building_id IS NULL'));
    $params=[$projectId,$scope]; if($buildingId)$params[]=$buildingId; $check->execute($params); if((int)$check->fetchColumn()>0)return;
    $items=dev_schedule_template_items($scope);
    $insert=db()->prepare('INSERT INTO construction_schedule_items(construction_project_id,construction_building_id,schedule_scope,sequence_no,activity_name,planned_start_date,planned_finish_date,status,percent_complete,created_by_admin_user_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
    $cursor=$startDate?new DateTimeImmutable($startDate):null;$u=admin_user();
    foreach($items as $i=>$item){
        $name=(string)$item['activity_name'];
        $duration=max(1,(int)($item['default_duration_days']??1));
        $ps=$cursor?$cursor->format('Y-m-d'):null;
        $pf=$cursor?$cursor->modify('+'.($duration-1).' days')->format('Y-m-d'):null;
        $insert->execute([$projectId,$buildingId,$scope,$i+1,$name,$ps,$pf,'Not Started',0,$u['id']??null]);
        if($cursor)$cursor=$cursor->modify('+'.$duration.' days');
    }
}
function dev_schedule_progress(int $projectId, ?int $buildingId=null): int {$sql='SELECT COUNT(*) total, SUM(CASE WHEN status=\'Complete\' THEN 1 ELSE 0 END) done FROM construction_schedule_items WHERE construction_project_id=? AND '.($buildingId?'construction_building_id=?':'construction_building_id IS NULL');$s=db()->prepare($sql);$args=[$projectId];if($buildingId)$args[]=$buildingId;$s->execute($args);$r=$s->fetch();$total=(int)($r['total']??0);return $total?(int)round(((int)$r['done']/$total)*100):0;}
function dev_schedule_phase(int $projectId,int $buildingId): string {$s=db()->prepare("SELECT activity_name FROM construction_schedule_items WHERE construction_project_id=? AND construction_building_id=? AND status<>'Complete' ORDER BY CASE WHEN status='In Progress' THEN 0 WHEN status='Inspection' THEN 1 ELSE 2 END,sequence_no,id LIMIT 1");$s->execute([$projectId,$buildingId]);$v=$s->fetchColumn();return $v?(string)$v:'Complete';}
function dev_schedule_status(int $projectId,int $buildingId): string {$s=db()->prepare("SELECT COUNT(*) total,SUM(status='Complete') done,SUM(status IN ('In Progress','Inspection')) active,SUM(status<>'Complete' AND planned_finish_date IS NOT NULL AND planned_finish_date<CURDATE()) late FROM construction_schedule_items WHERE construction_project_id=? AND construction_building_id=?");$s->execute([$projectId,$buildingId]);$r=$s->fetch();if((int)$r['total']>0&&(int)$r['done']===(int)$r['total'])return 'Complete';if((int)$r['late']>0)return 'Delayed';if((int)$r['active']>0||(int)$r['done']>0)return 'In Progress';return 'Not Started';}

function dev_compress_image(array $file,string $folder='daily-logs',int $maxBytes=1572864,int $maxDimension=2200): ?array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('One of the images could not be uploaded.');
    $ext=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));
    if(!in_array($ext,['jpg','jpeg','png','webp','heic','heif'],true))throw new RuntimeException('Unsupported image type: '.$file['name']);
    $base=__DIR__.'/../../uploads/dev/'.$folder;if(!is_dir($base)&&!mkdir($base,0775,true))throw new RuntimeException('Upload folder is not writable.');
    $dest=$base.'/'.bin2hex(random_bytes(12)).'.jpg';$ok=false;
    if(class_exists('Imagick')){
        try{$im=new Imagick();$im->readImage($file['tmp_name']);$im->setIteratorIndex(0);if(method_exists($im,'autoOrient'))$im->autoOrient();$im->setImageFormat('jpeg');$im->setImageColorspace(Imagick::COLORSPACE_SRGB);$w=$im->getImageWidth();$h=$im->getImageHeight();if(max($w,$h)>$maxDimension)$im->thumbnailImage($maxDimension,$maxDimension,true,true);for($q=82;$q>=45;$q-=7){$im->setImageCompression(Imagick::COMPRESSION_JPEG);$im->setImageCompressionQuality($q);$im->stripImage();$im->writeImage($dest);if(filesize($dest)<=$maxBytes)break;}$im->clear();$ok=is_file($dest);}catch(Throwable $e){$ok=false;}
    }
    if(!$ok && in_array($ext,['jpg','jpeg','png','webp'],true) && function_exists('imagecreatefromstring')){
        $raw=@file_get_contents($file['tmp_name']);$src=$raw!==false?@imagecreatefromstring($raw):false;if($src){$w=imagesx($src);$h=imagesy($src);$scale=min(1,$maxDimension/max($w,$h));$nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));$dst=imagecreatetruecolor($nw,$nh);$white=imagecolorallocate($dst,255,255,255);imagefill($dst,0,0,$white);imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);for($q=82;$q>=45;$q-=7){imagejpeg($dst,$dest,$q);if(filesize($dest)<=$maxBytes)break;}imagedestroy($src);imagedestroy($dst);$ok=is_file($dest);}
    }
    if(!$ok)throw new RuntimeException(in_array($ext,['heic','heif'],true)?'HEIC conversion is unavailable on this server. Enable ImageMagick with HEIC support.':'The image could not be processed.');
    return ['path'=>'uploads/dev/'.$folder.'/'.basename($dest),'name'=>(string)$file['name'],'mime'=>'image/jpeg','size'=>(int)filesize($dest)];
}
function dev_compress_images(array $files,string $folder='daily-logs'): array {$out=[];$names=$files['name']??[];if(!is_array($names)){$one=dev_compress_image($files,$folder);return $one?[$one]:[];}foreach($names as $i=>$name){$f=['name'=>$name,'type'=>$files['type'][$i]??'','tmp_name'=>$files['tmp_name'][$i]??'','error'=>$files['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$files['size'][$i]??0];$one=dev_compress_image($f,$folder);if($one)$out[]=$one;}return $out;}
function dev_weather_snapshots(array $project,string $logDate,?string $createdAt=null): array {
    $city=trim((string)($project['city']??''));$state=trim((string)($project['state']??''));if($city==='')return [];
    $cutoff=$createdAt?strtotime($createdAt):time();$today=date('Y-m-d',$cutoff);$hours=[8,12,15];$eligible=[];foreach($hours as $h){$ts=strtotime($logDate.' '.sprintf('%02d:00:00',$h));if($logDate<$today||$ts<=$cutoff)$eligible[]=$h;}if(!$eligible)return [];
    $ctx=stream_context_create(['http'=>['timeout'=>8,'user_agent'=>'ZNP Construction Portal']]);
    $geoUrl='https://geocoding-api.open-meteo.com/v1/search?count=1&language=en&format=json&name='.rawurlencode($city.', '.$state);$geo=@file_get_contents($geoUrl,false,$ctx);$g=$geo?json_decode($geo,true):null;if(empty($g['results'][0]))return [];$lat=$g['results'][0]['latitude'];$lon=$g['results'][0]['longitude'];
    $isPast=$logDate<date('Y-m-d');$base=$isPast?'https://archive-api.open-meteo.com/v1/archive':'https://api.open-meteo.com/v1/forecast';$url=$base.'?latitude='.$lat.'&longitude='.$lon.'&start_date='.$logDate.'&end_date='.$logDate.'&hourly=temperature_2m,relative_humidity_2m,precipitation,weather_code,wind_speed_10m&temperature_unit=fahrenheit&wind_speed_unit=mph&timezone=auto';$raw=@file_get_contents($url,false,$ctx);$data=$raw?json_decode($raw,true):null;if(empty($data['hourly']['time']))return [];
    $out=[];foreach($eligible as $h){$needle=$logDate.'T'.sprintf('%02d:00',$h);$idx=array_search($needle,$data['hourly']['time'],true);if($idx===false)continue;$code=(int)($data['hourly']['weather_code'][$idx]??0);$labels=[0=>'Clear',1=>'Mostly Clear',2=>'Partly Cloudy',3=>'Cloudy',45=>'Fog',48=>'Fog',51=>'Light Drizzle',53=>'Drizzle',55=>'Heavy Drizzle',61=>'Light Rain',63=>'Rain',65=>'Heavy Rain',71=>'Light Snow',73=>'Snow',75=>'Heavy Snow',80=>'Rain Showers',81=>'Rain Showers',82=>'Heavy Showers',95=>'Thunderstorm',96=>'Thunderstorm',99=>'Severe Thunderstorm'];$out[]=['time'=>date('g A',strtotime($needle)),'temperature'=>$data['hourly']['temperature_2m'][$idx]??null,'condition'=>$labels[$code]??'Weather','humidity'=>$data['hourly']['relative_humidity_2m'][$idx]??null,'precipitation'=>$data['hourly']['precipitation'][$idx]??null,'wind'=>$data['hourly']['wind_speed_10m'][$idx]??null];}return $out;
}


function dev_active_project_id(int $candidate=0): int {
    if ($candidate > 0 && dev_can_access($candidate)) {
        $_SESSION['dev_active_project_id'] = $candidate;
        return $candidate;
    }
    $stored = (int)($_SESSION['dev_active_project_id'] ?? 0);
    if ($stored > 0 && dev_can_access($stored)) return $stored;
    $projects = dev_projects();
    $fallback = !empty($projects) ? (int)$projects[0]['id'] : 0;
    if ($fallback > 0) $_SESSION['dev_active_project_id'] = $fallback;
    return $fallback;
}
function dev_company_trade_names(int $companyId): string {
    $s=db()->prepare("SELECT GROUP_CONCAT(t.trade_name ORDER BY t.display_order,t.trade_name SEPARATOR ', ') FROM construction_company_trades ct JOIN construction_trades t ON t.id=ct.construction_trade_id AND t.is_active=1 WHERE ct.construction_company_id=? AND ct.archived_at IS NULL");
    $s->execute([$companyId]);
    $v=trim((string)$s->fetchColumn());
    if($v!=='') return $v;
    $s=db()->prepare('SELECT primary_trade FROM construction_companies WHERE id=?');$s->execute([$companyId]);
    return trim((string)$s->fetchColumn());
}
