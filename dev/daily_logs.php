<?php
require_once __DIR__.'/includes/bootstrap.php';
$requestedProjectId=(int)($_GET['project_id']??$_POST['project_id']??0);
if($requestedProjectId>0){$p=dev_require_project($requestedProjectId);$projectId=dev_active_project_id($requestedProjectId);}
else{$projectId=dev_active_project_id();$p=dev_require_project($projectId);}
$error='';
$warning='';

function znp_daily_column_exists($table,$column){
    static $cache=[];
    $key=$table.'.'.$column;
    if(array_key_exists($key,$cache)) return $cache[$key];
    try{
        $s=db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $s->execute([$table,$column]);
        return $cache[$key]=((int)$s->fetchColumn()>0);
    }catch(Throwable $e){ return $cache[$key]=false; }
}
function znp_daily_table_exists($table){
    static $cache=[];
    if(array_key_exists($table,$cache)) return $cache[$table];
    try{
        $s=db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $s->execute([$table]);
        return $cache[$table]=((int)$s->fetchColumn()>0);
    }catch(Throwable $e){ return $cache[$table]=false; }
}
function znp_daily_photo_url(string $path): string {
    $normalized=ltrim(str_replace('\\','/',$path),'/');
    if(strpos($normalized,'uploads/')===false)$normalized='uploads/dev/daily-logs/'.basename($normalized);
    return znp_storage_url($normalized);
}
function znp_daily_text_lines(string $value): array {
    $lines=preg_split('/\r?\n/',$value)?:[];
    return array_values(array_filter(array_map(static function(string $line):string{
        return trim((string)preg_replace('/^\s*[-*•]\s*/u','',$line));
    },$lines),static fn(string $line):bool=>$line!==''));
}
function znp_daily_log(int $id,int $projectId): ?array {
    $s=db()->prepare('SELECT * FROM construction_daily_logs WHERE id=? AND construction_project_id=? LIMIT 1');
    $s->execute([$id,$projectId]);
    $row=$s->fetch();
    return $row?:null;
}
function znp_daily_remove_file(string $relativePath): void {
    try{
        $storageKey=znp_storage_key($relativePath);
        if(strpos($storageKey,'uploads/dev/')!==0)return;
        znp_storage_delete($storageKey);
    }catch(Throwable $exception){
        error_log('Daily Log photo could not be removed from storage: '.$exception->getMessage());
    }
}

$hasWeatherJson=znp_daily_column_exists('construction_daily_logs','weather_json');
$hasPhotoTable=znp_daily_table_exists('construction_daily_log_photos');
$hasWorkforceTable=znp_daily_table_exists('construction_daily_log_workforce');
$hasWorkforceSourceKey=$hasWorkforceTable&&znp_daily_column_exists('construction_daily_log_workforce','source_key');
$editId=max(0,(int)($_GET['edit_id']??$_POST['log_id']??0));
$editLog=$editId?znp_daily_log($editId,$projectId):null;
if($editId && !$editLog){
    $error='That daily log could not be found or does not belong to this project.';
    $editId=0;
}

$workforceOptions=[];
try{
    $uq=db()->prepare("SELECT CONCAT('user_',au.id) option_key,'user' source_type,au.id source_id,au.full_name label,COALESCE(cpu.project_role,'Project Team') role_label FROM construction_project_users cpu JOIN admin_users au ON au.id=cpu.admin_user_id WHERE cpu.construction_project_id=? AND cpu.is_active=1 AND au.is_active=1 ORDER BY au.full_name");
    $uq->execute([$projectId]);
    foreach($uq->fetchAll() as $row){$row['source_key']=$row['option_key'];$workforceOptions[$row['option_key']]=$row;}
    $cq=db()->prepare("SELECT pc.id source_id,c.company_name,pc.trade_role,(SELECT GROUP_CONCAT(t.trade_name SEPARATOR '\n') FROM construction_company_trades ct JOIN construction_trades t ON t.id=ct.construction_trade_id AND t.is_active=1 WHERE ct.construction_company_id=c.id AND ct.archived_at IS NULL) active_trade_names FROM construction_project_companies pc JOIN construction_companies c ON c.id=pc.construction_company_id WHERE pc.construction_project_id=? AND c.is_active=1 AND NULLIF(TRIM(pc.trade_role),'') IS NOT NULL ORDER BY pc.trade_role,c.company_name,pc.id");
    $cq->execute([$projectId]);
    $companyTradeOptions=[];
    foreach($cq->fetchAll() as $row){
        $trades=preg_split('/\s*(?:,|;|\||\/|\r?\n)\s*/',(string)$row['trade_role'],-1,PREG_SPLIT_NO_EMPTY)?:[];
        $activeTradeNames=array_map('strtolower',preg_split('/\r?\n/',(string)($row['active_trade_names']??''),-1,PREG_SPLIT_NO_EMPTY)?:[]);
        foreach(array_values(array_unique(array_map('trim',$trades))) as $trade){
            if($trade===''||!in_array(strtolower($trade),$activeTradeNames,true))continue;
            $key='company_'.$row['source_id'].'_trade_'.substr(sha1(strtolower($trade)),0,12);
            $companyTradeOptions[$key]=['option_key'=>$key,'source_key'=>$key,'source_type'=>'company','source_id'=>(int)$row['source_id'],'company_name'=>$row['company_name'],'trade_name'=>$trade,'label'=>$row['company_name'].' — '.$trade,'legacy_label'=>$trade.' — '.$row['company_name'],'role_label'=>'Company + Trade'];
        }
    }
    uasort($companyTradeOptions,static fn($a,$b)=>strcasecmp((string)$a['trade_name'],(string)$b['trade_name'])?:strcasecmp((string)$a['company_name'],(string)$b['company_name']));
    foreach($companyTradeOptions as $key=>$option)$workforceOptions[$key]=$option;
}catch(Throwable $e){
    $warning='Project workforce options could not be loaded.';
}
$editWorkforce=[];
if($editId && $hasWorkforceTable){
    try{
        $wq=db()->prepare('SELECT * FROM construction_daily_log_workforce WHERE daily_log_id=? ORDER BY id');
        $wq->execute([$editId]);
        foreach($wq->fetchAll() as $row){
            $matched=false;
            foreach($workforceOptions as $key=>$option){
                if($hasWorkforceSourceKey&&trim((string)($row['source_key']??''))!==''&&hash_equals((string)$option['source_key'],(string)$row['source_key'])){$editWorkforce[$key]=(int)$row['worker_count'];$matched=true;break;}
                if(($option['source_type']??'')!==($row['source_type']??'')||(int)($option['source_id']??0)!==(int)($row['source_id']??0))continue;
                $savedLabel=trim((string)($row['display_label']??''));
                if($savedLabel===''||strcasecmp($savedLabel,(string)$option['label'])===0||strcasecmp($savedLabel,(string)($option['legacy_label']??''))===0){$editWorkforce[$key]=(int)$row['worker_count'];$matched=true;break;}
            }
            if(!$matched)$editWorkforce[$row['source_type'].'_'.$row['source_id']]=(int)$row['worker_count'];
        }
    }catch(Throwable $exception){error_log('Daily Log workforce edit data could not be loaded: '.$exception->getMessage());}
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!csrf_check((string)($_POST['csrf']??''))) throw new RuntimeException('Session expired. Please refresh and try again.');
        $action=(string)($_POST['action']??'create');

        if($action==='delete'){
            $deleteId=(int)($_POST['log_id']??0);
            $deleteLog=znp_daily_log($deleteId,$projectId);
            if(!$deleteLog) throw new RuntimeException('The daily log could not be found.');
            db()->beginTransaction();
            $paths=[];
            if($hasPhotoTable){
                $ps=db()->prepare('SELECT file_path FROM construction_daily_log_photos WHERE daily_log_id=? AND construction_project_id=?');
                $ps->execute([$deleteId,$projectId]);
                $paths=$ps->fetchAll(PDO::FETCH_COLUMN)?:[];
                $pd=db()->prepare('DELETE FROM construction_daily_log_photos WHERE daily_log_id=? AND construction_project_id=?');
                $pd->execute([$deleteId,$projectId]);
            }
            if($hasWorkforceTable){db()->prepare('DELETE FROM construction_daily_log_workforce WHERE daily_log_id=?')->execute([$deleteId]);}
            $d=db()->prepare('DELETE FROM construction_daily_logs WHERE id=? AND construction_project_id=?');
            $d->execute([$deleteId,$projectId]);
            db()->commit();
            foreach($paths as $path) znp_daily_remove_file((string)$path);
            try{ dev_activity($projectId,'daily_log_deleted','Daily log deleted for '.dev_date((string)$deleteLog['log_date']).'.','daily_log',$deleteId); }catch(Throwable $exception){error_log('Daily Log deletion activity write failed: '.$exception->getMessage());}
            header('Location: daily_logs.php?project_id='.$projectId.'&deleted=1');
            exit;
        }

        $logDate=(string)($_POST['log_date']??date('Y-m-d'));
        $weatherText=trim((string)($_POST['weather']??''));
        $workforceSelections=[];
        $workforce=0;
        foreach((array)($_POST['workforce_selected']??[]) as $key){
            $key=(string)$key;
            if(!isset($workforceOptions[$key])) continue;
            $row=$workforceOptions[$key];
            $workforceSelections[]=['source_key'=>(string)$row['source_key'],'source_type'=>$row['source_type'],'source_id'=>(int)$row['source_id'],'label'=>$row['label'],'worker_count'=>1];
            $workforce++;
        }
        if(!$hasWorkforceTable && !empty($workforceSelections)) throw new RuntimeException('Daily Log workforce is unavailable because the required database table is missing.');
        if($hasWorkforceTable&&!$hasWorkforceSourceKey&&$workforceSelections){$legacyKeys=array_map(static fn($row):string=>$row['source_type'].':'.$row['source_id'],$workforceSelections);if(count($legacyKeys)!==count(array_unique($legacyKeys)))throw new RuntimeException('The database does not support selecting multiple trades from one vendor. Contact the system administrator.');}
        $activities=trim((string)($_POST['work_performed']??''));
        $delays=trim((string)($_POST['delays']??''));
        $safety=trim((string)($_POST['safety_incidents']??''));
        $visitors=trim((string)($_POST['visitors']??''));
        if($activities==='') throw new RuntimeException("Today's Activities on Job is required.");

        $uploadedPaths=[];
        $removedPhotoPaths=[];
        db()->beginTransaction();

        if($action==='update'){
            $id=(int)($_POST['log_id']??0);
            $existing=znp_daily_log($id,$projectId);
            if(!$existing) throw new RuntimeException('The daily log could not be found.');
            if($hasWeatherJson){
                $s=db()->prepare('UPDATE construction_daily_logs SET log_date=?,weather=?,workforce_count=?,work_performed=?,delays=?,safety_incidents=?,visitors=?,weather_json=NULL WHERE id=? AND construction_project_id=?');
            }else{
                $s=db()->prepare('UPDATE construction_daily_logs SET log_date=?,weather=?,workforce_count=?,work_performed=?,delays=?,safety_incidents=?,visitors=? WHERE id=? AND construction_project_id=?');
            }
            $s->execute([$logDate,$weatherText,$workforce,$activities,$delays,$safety,$visitors,$id,$projectId]);

            if($hasPhotoTable && !empty($_POST['remove_photo']) && is_array($_POST['remove_photo'])){
                $removeIds=array_values(array_filter(array_map('intval',$_POST['remove_photo'])));
                if($removeIds){
                    $marks=implode(',',array_fill(0,count($removeIds),'?'));
                    $args=array_merge([$id,$projectId],$removeIds);
                    $ps=db()->prepare("SELECT id,file_path FROM construction_daily_log_photos WHERE daily_log_id=? AND construction_project_id=? AND id IN ($marks)");
                    $ps->execute($args);
                    $removeRows=$ps->fetchAll();
                    $pd=db()->prepare("DELETE FROM construction_daily_log_photos WHERE daily_log_id=? AND construction_project_id=? AND id IN ($marks)");
                    $pd->execute($args);
                    foreach($removeRows as $row) $removedPhotoPaths[]=(string)$row['file_path'];
                }
            }
            $event='daily_log_updated';
            $message='Daily log updated for '.dev_date($logDate).'.';
            $redirectFlag='updated=1';
        }else{
            $params=[$projectId,(int)$user['id'],$logDate,$weatherText,$workforce,$activities,$delays,$safety,$visitors];
            if($hasWeatherJson){
                $sql='INSERT INTO construction_daily_logs(construction_project_id,admin_user_id,log_date,weather,workforce_count,work_performed,delays,safety_incidents,visitors,weather_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,NULL,NOW())';
            }else{
                $sql='INSERT INTO construction_daily_logs(construction_project_id,admin_user_id,log_date,weather,workforce_count,work_performed,delays,safety_incidents,visitors,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())';
            }
            $s=db()->prepare($sql);
            $s->execute($params);
            $id=(int)db()->lastInsertId();
            $event='daily_log_created';
            $message='Daily log added for '.dev_date($logDate).'.';
            $redirectFlag='saved=1';
        }

        if($hasWorkforceTable){
            $wd=db()->prepare('DELETE FROM construction_daily_log_workforce WHERE daily_log_id=?');
            $wd->execute([$id]);
            if($workforceSelections){
                if($hasWorkforceSourceKey){$wi=db()->prepare('INSERT INTO construction_daily_log_workforce(daily_log_id,construction_project_id,source_type,source_id,source_key,display_label,worker_count,created_at) VALUES(?,?,?,?,?,?,?,NOW())');foreach($workforceSelections as $wf)$wi->execute([$id,$projectId,$wf['source_type'],$wf['source_id'],$wf['source_key'],$wf['label'],$wf['worker_count']]);}
                else{$wi=db()->prepare('INSERT INTO construction_daily_log_workforce(daily_log_id,construction_project_id,source_type,source_id,display_label,worker_count,created_at) VALUES(?,?,?,?,?,?,NOW())');foreach($workforceSelections as $wf)$wi->execute([$id,$projectId,$wf['source_type'],$wf['source_id'],$wf['label'],$wf['worker_count']]);}
            }
        }

        $hasFiles=!empty($_FILES['photos']['name']) && (is_array($_FILES['photos']['name'])?array_filter($_FILES['photos']['name']):true);
        if($hasFiles && !$hasPhotoTable){
            $warning='The daily log was saved, but photos were skipped because photo storage is unavailable.';
        }elseif($hasPhotoTable && $hasFiles){
            $uploads=dev_compress_images($_FILES['photos']??[],'daily-logs');
            if(!$uploads) throw new RuntimeException('The selected photos could not be processed. Please use JPG, PNG, WebP, HEIC, or HEIF files.');
            if($uploads){
                $uploadedPaths=array_column($uploads,'path');
                $ins=db()->prepare('INSERT INTO construction_daily_log_photos(daily_log_id,construction_project_id,file_path,original_name,mime_type,file_size,created_at) VALUES(?,?,?,?,?,?,NOW())');
                foreach($uploads as $f){
                    znp_storage_put_file((string)$f['path'],znp_storage_local_path((string)$f['path']),(string)$f['mime']);
                    $ins->execute([$id,$projectId,$f['path'],$f['name'],$f['mime'],$f['size']]);
                }
            }
        }
        db()->commit();
        if(znp_storage_uses_s4()){
            foreach($uploadedPaths as $uploadedPath){
                try{znp_storage_remove_local((string)$uploadedPath);}catch(Throwable $exception){error_log('Daily Log local upload cleanup failed: '.$exception->getMessage());}
            }
        }
        foreach($removedPhotoPaths as $removedPhotoPath) znp_daily_remove_file($removedPhotoPath);
        try{ dev_activity($projectId,$event,$message,'daily_log',$id); }catch(Throwable $exception){error_log('Daily Log activity write failed: '.$exception->getMessage());}
        header('Location: daily_logs.php?project_id='.$projectId.'&'.$redirectFlag.($warning?'&photo_upgrade=1':''));
        exit;
    }catch(Throwable $e){
        if(db()->inTransaction()) db()->rollBack();
        foreach(($uploadedPaths??[]) as $uploadedPath) znp_daily_remove_file((string)$uploadedPath);
        $error=$e->getMessage()?:'The daily log could not be saved. Please check the server error log.';
        if(isset($_POST['log_id'])){
            $editId=(int)$_POST['log_id'];
            $editLog=znp_daily_log($editId,$projectId);
        }
    }
}

try{
    $s=db()->prepare('SELECT dl.*,au.full_name FROM construction_daily_logs dl LEFT JOIN admin_users au ON au.id=dl.admin_user_id WHERE dl.construction_project_id=? ORDER BY dl.log_date DESC,dl.id DESC');
    $s->execute([$projectId]);
    $logs=$s->fetchAll();
}catch(Throwable $e){
    $logs=[];
    $error=$error?:$e->getMessage();
}
$editPhotos=[];
if($editId && $hasPhotoTable){
    try{
        $q=db()->prepare('SELECT * FROM construction_daily_log_photos WHERE daily_log_id=? AND construction_project_id=? ORDER BY id');
        $q->execute([$editId,$projectId]);
        $editPhotos=$q->fetchAll();
    }catch(Throwable $e){ $warning='The photos attached to this Daily Log could not be loaded for editing.'; }
}
$workforceByLog=[];
if($logs && $hasWorkforceTable){
    try{
        $ids=array_column($logs,'id');
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $wq=db()->prepare("SELECT * FROM construction_daily_log_workforce WHERE daily_log_id IN ($ph) ORDER BY display_label");
        $wq->execute($ids);
        foreach($wq->fetchAll() as $row) $workforceByLog[(int)$row['daily_log_id']][]=$row;
    }catch(Throwable $exception){error_log('Daily Log workforce display data could not be loaded: '.$exception->getMessage());}
}
if(isset($_GET['photo_upgrade'])) $warning='The daily log was saved, but photo attachments are not enabled.';
require __DIR__.'/includes/header.php';
$formSource=$_POST?:($editLog?:[]);
$isEditing=(bool)$editLog;
?>

<div class="znp-cluster-end"><a class="btn btn-secondary" href="construction_daily_logs_export.php?project_id=<?=$projectId?>">Export Daily Logs &amp; Photos</a></div>
<?php if(isset($_GET['saved'])):?><div class="card notice-success">Daily log saved successfully.</div><?php endif;?>
<?php if(isset($_GET['updated'])):?><div class="card notice-success">Daily log updated successfully.</div><?php endif;?>
<?php if(isset($_GET['deleted'])):?><div class="card notice-success">Daily log deleted successfully.</div><?php endif;?>
<?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<?php if($warning):?><div class="card notice-warning"><?=e($warning)?></div><?php endif;?>
<details class="card daily-log-compose" <?=$isEditing?'open':''?>><summary><span class="daily-log-summary"><strong><?=$isEditing?'Edit Daily Log':'Add Daily Log'?></strong><small><?=$isEditing?'Update this entry and its attachments.':'Create a new project field report.'?></small></span></summary><form id="daily-log-form" method="post" enctype="multipart/form-data" class="form-grid" data-heic-upload-form>
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="<?=$isEditing?'update':'create'?>"><?php if($isEditing):?><input type="hidden" name="log_id" value="<?=$editId?>"><?php endif;?>
<div class="form-full znp-cluster-end"><button type="button" class="daily-log-reset" id="daily-log-reset">Reset Draft</button><?php if($isEditing):?><a class="secondary" href="daily_logs.php?project_id=<?=$projectId?>">Cancel Edit</a><?php endif;?></div>
<div><label>Log Date</label><input type="date" name="log_date" required value="<?=e($formSource['log_date']??date('Y-m-d'))?>"></div>
<div class="form-full"><label>Project Team / Trades on Job</label>
<?php if(!$hasWorkforceTable):?><div class="notice-warning">Daily Log workforce is unavailable because the required database table is missing. Contact the system administrator.</div><?php elseif(!$hasWorkforceSourceKey):?><div class="notice-warning">The database does not support multiple trades from one vendor. Contact the system administrator.</div><?php endif;?>
<details class="workforce-panel" id="workforce-panel" open>
<summary><span>Select Team / Trades</span><span class="workforce-summary"><span id="workforce-selected-count"><?=e((string)count($editWorkforce))?></span> selected</span></summary>
<div class="workforce-panel-body">
<input type="search" id="workforce-search" class="workforce-search" placeholder="Search team or trades..." autocomplete="off">
<div id="workforce-picker">
<?php if(!$workforceOptions):?><div class="workforce-empty">No active project team members or trades are assigned to this project.</div><?php endif;?>
<?php foreach(['user'=>'Team Members','company'=>'Vendors / Trades'] as $groupType=>$groupTitle):
$groupOptions=array_filter($workforceOptions,fn($option)=>($option['source_type']??'')===$groupType); if(!$groupOptions) continue;?>
<section class="workforce-group" data-workforce-group>
<h4 class="workforce-group-title"><?=e($groupTitle)?></h4>
<div class="workforce-picker">
<?php foreach($groupOptions as $key=>$option):$selected=array_key_exists($key,$editWorkforce)||in_array($key,(array)($_POST['workforce_selected']??[]),true);?>
<div class="workforce-row" data-workforce-search="<?=e(strtolower($option['label'].' '.$option['role_label']))?>"><label class="workforce-choice"><input type="checkbox" name="workforce_selected[]" value="<?=e($key)?>" <?=$selected?'checked':''?>><span><strong><?=e($option['label'])?></strong><small><?=e($option['role_label'])?></small></span></label></div>
<?php endforeach;?>
</div>
</section>
<?php endforeach;?>
<div id="workforce-no-results" class="workforce-empty" hidden>No matching team members or trades.</div>
</div>
</div></details></div>
<div class="form-full"><label>Weather Conditions</label><textarea class="manual-weather" name="weather" placeholder="Example: Clear and warm in the morning; light rain after 2 PM."><?=e((string)($formSource['weather']??''))?></textarea></div>
<div class="form-full"><label>Visitors / Vendors on Site</label><input name="visitors" value="<?=e((string)($formSource['visitors']??''))?>"></div>
<div class="form-full"><label>Today's Activities on Job</label><textarea id="daily-activities" name="work_performed" required><?=e((string)($formSource['work_performed']??''))?></textarea></div>
<div class="form-full"><label><?=$isEditing?'Add More Photos':'Jobsite Photos'?></label><input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" data-heic-input><div class="heic-upload-status muted" data-heic-status aria-live="polite"></div></div>
<?php if($isEditing && $editPhotos):?><div class="form-full"><label>Existing Photos</label><div class="daily-photo-remove-grid"><?php foreach($editPhotos as $ph):?><label class="daily-photo-remove"><img src="<?=e(znp_daily_photo_url((string)$ph['file_path']))?>" alt="<?=e((string)($ph['original_name']??'Daily Log photo'))?>"><span><input type="checkbox" name="remove_photo[]" value="<?=(int)$ph['id']?>"> Remove photo</span></label><?php endforeach;?></div></div><?php endif;?>
<details class="form-full optional-section" <?=!empty($formSource['delays'])?'open':''?>><summary>Delays</summary><label>Delay Details</label><textarea name="delays"><?=e((string)($formSource['delays']??''))?></textarea></details>
<details class="form-full optional-section" <?=!empty($formSource['safety_incidents'])?'open':''?>><summary>Safety Incidents</summary><label>Incident Details</label><textarea name="safety_incidents"><?=e((string)($formSource['safety_incidents']??''))?></textarea></details>
<div class="form-full"><small class="draft-status" id="daily-draft-status" aria-live="polite"></small></div><div class="form-full"><button class="primary"><?=$isEditing?'Save Changes':'Add Daily Log'?></button></div></form></details>

<div id="daily-log-autosave-toast" class="dev-toast daily-log-save-toast" role="status" aria-live="polite" hidden>Daily Log changes saved</div>
<div class="grid znp-mt-5"><?php foreach($logs as $l):?><details class="card daily-log-card daily-log-entry" data-daily-log-entry data-log-id="<?=(int)$l['id']?>"><summary><span class="daily-log-summary"><strong><?=e(dev_date($l['log_date']))?></strong><small><?=e((string)$l['workforce_count'])?> trades/team · Logged by <?=e($l['full_name']?:'User')?></small></span></summary><div class="daily-log-entry-body"><div class="daily-log-sections">
<section class="daily-log-section"><h3>Trades on Site</h3><?php if(!empty($workforceByLog[(int)$l['id']])):?><div class="daily-log-lines"><?php foreach($workforceByLog[(int)$l['id']] as $wf):?><div><?=e($wf['display_label'])?></div><?php endforeach;?></div><?php else:?><p class="muted">No trades reported.</p><?php endif;?></section>
<section class="daily-log-section"><h3>Weather</h3><p><?=trim((string)($l['weather']??''))!==''?nl2br(e((string)$l['weather'])):'<span class="muted">No weather reported.</span>'?></p></section>
<section class="daily-log-section"><h3>Visitors</h3><p><?=trim((string)($l['visitors']??''))!==''?nl2br(e((string)$l['visitors'])):'<span class="muted">No visitors reported.</span>'?></p></section>
<section class="daily-log-section"><h3>Activities</h3><div class="daily-log-lines"><?php foreach(znp_daily_text_lines((string)$l['work_performed']) as $activity):?><div><?=e($activity)?></div><?php endforeach;?></div></section>
<section class="daily-log-section"><h3>Delays</h3><p><?=trim((string)($l['delays']??''))!==''?nl2br(e((string)$l['delays'])):'<span class="muted">No delays reported.</span>'?></p></section>
<section class="daily-log-section"><h3>Incidents</h3><p><?=trim((string)($l['safety_incidents']??''))!==''?nl2br(e((string)$l['safety_incidents'])):'<span class="muted">No incidents reported.</span>'?></p></section>
<section class="daily-log-section daily-log-section-photos"><h3>Photos</h3><?php if($hasPhotoTable):?><div class="daily-log-photo-slot" data-daily-log-photos data-endpoint="daily_log_photos.php?project_id=<?=$projectId?>&daily_log_id=<?=(int)$l['id']?>"><span class="muted">Photos load when this Daily Log is expanded.</span></div><?php else:?><p class="muted">Photos are unavailable.</p><?php endif;?></section>
</div>
<div class="daily-log-actions"><a class="btn btn-secondary" href="daily_logs.php?project_id=<?=$projectId?>&edit_id=<?=(int)$l['id']?>#daily-log-form">Edit</a><form method="post" onsubmit="return confirm('Delete this daily log and all attached photos? This cannot be undone.');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="log_id" value="<?=(int)$l['id']?>"><button class="danger" type="submit">Delete</button></form></div>
</div></details><?php endforeach;?></div>
<script>
(function(){
 const form=document.getElementById('daily-log-form');
 if(!form) return;
 const picker=document.getElementById('workforce-picker');
 const selectedCount=document.getElementById('workforce-selected-count');
 const workforceSearch=document.getElementById('workforce-search');
 const workforceNoResults=document.getElementById('workforce-no-results');
 const status=document.getElementById('daily-draft-status');
 const resetButton=document.getElementById('daily-log-reset');
 const userId=<?=json_encode((string)($user['id']??0))?>;
 const projectId=<?=json_encode((string)$projectId)?>;
 const editId=<?=json_encode((string)$editId)?>;
 const mode=<?=$isEditing?"'edit'":"'create'"?>;
 const draftKey='znp_daily_log_draft_'+userId+'_'+projectId+'_'+mode+'_'+editId;
 const hadPost=<?=$_SERVER['REQUEST_METHOD']==='POST'?'true':'false'?>;
 const success=(new URLSearchParams(location.search).has('saved')||new URLSearchParams(location.search).has('updated'));
 function show(text){if(status) status.textContent=text;}
 let toastTimer;
 function showSaveToast(){
   const toast=document.getElementById('daily-log-autosave-toast');
   if(!toast)return;
   clearTimeout(toastTimer);
   toast.textContent='Daily Log changes saved';
   toast.hidden=false;
   toast.style.setProperty('display','block','important');
   toastTimer=setTimeout(function(){toast.hidden=true;toast.style.removeProperty('display');},2200);
 }
 function updateSelected(){if(!selectedCount) return;selectedCount.textContent=String(form.querySelectorAll('input[name="workforce_selected[]"]:checked').length);}
 function filterWorkforce(){
   if(!picker||!workforceSearch) return;
   const q=workforceSearch.value.trim().toLowerCase();
   let shown=0;
   picker.querySelectorAll('.workforce-row').forEach(row=>{
     const match=!q||(row.dataset.workforceSearch||'').includes(q);
     row.hidden=!match;
     if(match) shown++;
   });
   picker.querySelectorAll('[data-workforce-group]').forEach(group=>{
     group.hidden=!group.querySelector('.workforce-row:not([hidden])');
   });
   if(workforceNoResults) workforceNoResults.hidden=shown!==0;
 }
 function serializable(el){
   if(!el.name||el.disabled) return false;
   if(['csrf','action','project_id','log_id','photos[]'].includes(el.name)) return false;
   if(el.type==='file'||el.type==='submit'||el.type==='button') return false;
   return true;
 }
 function capture(){
   const fields={};
   form.querySelectorAll('input,textarea,select').forEach(el=>{
     if(!serializable(el)) return;
     if(el.type==='checkbox'||el.type==='radio'){
       if(!fields[el.name]) fields[el.name]=[];
       if(el.checked) fields[el.name].push(el.value);
     }else fields[el.name]=el.value;
   });
   const openDetails=Array.from(form.querySelectorAll('details')).map((el,i)=>el.open?i:null).filter(v=>v!==null);
   return {fields:fields,openDetails:openDetails,savedAt:Date.now()};
 }
 function save(){
   try{localStorage.setItem(draftKey,JSON.stringify(capture()));show('All Daily Log fields saved automatically. Photo selections cannot be restored after a refresh.');showSaveToast();}
   catch(e){show('Draft could not be saved in this browser.');}
 }
 function restore(){
   if(hadPost) return;
   let draft=null;try{draft=JSON.parse(localStorage.getItem(draftKey)||'null');}catch(e){}
   if(!draft||!draft.fields) {show('All Daily Log fields save automatically in this browser.');return;}
   form.querySelectorAll('input,textarea,select').forEach(el=>{
     if(!serializable(el)||!(el.name in draft.fields)) return;
     const value=draft.fields[el.name];
     if(el.type==='checkbox'||el.type==='radio') el.checked=Array.isArray(value)&&value.includes(el.value);
     else el.value=value;
   });
   form.querySelectorAll('details').forEach((el,i)=>{el.open=Array.isArray(draft.openDetails)&&draft.openDetails.includes(i);});
   updateSelected();
   show('Unsubmitted Daily Log draft restored. Photo selections must be added again.');
 }
 if(success){
   const submitted=sessionStorage.getItem('znp_daily_log_submitted_key');
   if(submitted){localStorage.removeItem(submitted);sessionStorage.removeItem('znp_daily_log_submitted_key');}
 }
 let timer,autosaveReady=false;
 function queueSave(){if(!autosaveReady)return;clearTimeout(timer);timer=setTimeout(save,180);updateSelected();}
 form.addEventListener('input',queueSave);
 form.addEventListener('change',queueSave);
 form.querySelectorAll('details').forEach(el=>el.addEventListener('toggle',queueSave));
 form.addEventListener('submit',()=>{save();sessionStorage.setItem('znp_daily_log_submitted_key',draftKey);});
 if(picker) picker.addEventListener('change',updateSelected);
 if(workforceSearch) workforceSearch.addEventListener('input',filterWorkforce);
 if(resetButton) resetButton.addEventListener('click',()=>{
   if(!confirm('Clear this unsubmitted Daily Log draft? This will remove all autosaved fields and selections for this user and project. Submitted Daily Logs will not be affected.')) return;
   try{localStorage.removeItem(draftKey);}catch(e){}
   sessionStorage.removeItem('znp_daily_log_submitted_key');
   location.reload();
 });
 restore();
 updateSelected();
 setTimeout(function(){autosaveReady=true;},0);
})();
</script>
<script>
(function(){
 const entries=document.querySelectorAll('[data-daily-log-entry]');
 function loading(slot){
   const box=document.createElement('div');box.className='daily-photo-loading';
   const spinner=document.createElement('span');spinner.className='daily-photo-spinner';spinner.setAttribute('aria-hidden','true');
   const text=document.createElement('span');text.textContent='Loading Daily Log photos…';
   box.append(spinner,text);slot.replaceChildren(box);
 }
 async function loadPhotos(entry,force){
   const slot=entry.querySelector('[data-daily-log-photos]');
   if(!slot||slot.dataset.loaded==='1'||(slot.dataset.loading==='1'&&!force))return;
   slot.dataset.loading='1';loading(slot);
   try{
     const response=await fetch(slot.dataset.endpoint+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}});
     const body=await response.text();
     if(!response.ok)throw new Error(body||'Daily Log photos could not be loaded.');
     slot.innerHTML=body;
     slot.dataset.loaded='1';
   }catch(error){
     const box=document.createElement('div');box.className='daily-photo-error';
     const message=document.createElement('div');message.textContent=error.message||'Daily Log photos could not be loaded.';
     const retry=document.createElement('button');retry.type='button';retry.className='btn btn-secondary btn-small';retry.textContent='Retry';
     retry.addEventListener('click',function(){loadPhotos(entry,true);});
     box.append(message,retry);slot.replaceChildren(box);
   }finally{slot.dataset.loading='0';}
 }
 entries.forEach(function(entry){entry.addEventListener('toggle',function(){if(entry.open)loadPhotos(entry,false);});});
})();
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/heic2any/0.0.4/heic2any.min.js" defer></script>
<script src="assets/heic-upload.js?v=1.0.2" defer></script>
<div class="dev-modal" id="daily-photo-modal" hidden><div class="dev-modal-panel znp-modal-panel-wide daily-photo-gallery-modal"><button type="button" class="modal-close" data-close-modal aria-label="Close">×</button><div class="daily-photo-gallery-stage"><button class="daily-photo-gallery-arrow daily-photo-gallery-previous" type="button" aria-label="Previous image">‹</button><img id="daily-photo-modal-image" class="znp-modal-image" src="" alt=""><button class="daily-photo-gallery-arrow daily-photo-gallery-next" type="button" aria-label="Next image">›</button></div><p id="daily-photo-modal-caption" class="muted"></p><p id="daily-photo-modal-counter" class="daily-photo-gallery-counter" aria-live="polite"></p></div></div>
<script>
(function(){
 const modal=document.getElementById('daily-photo-modal'),image=document.getElementById('daily-photo-modal-image'),caption=document.getElementById('daily-photo-modal-caption'),counter=document.getElementById('daily-photo-modal-counter'),previous=modal.querySelector('.daily-photo-gallery-previous'),next=modal.querySelector('.daily-photo-gallery-next');
 let gallery=[],current=0;
 function show(index){
   if(!gallery.length)return;
   current=(index+gallery.length)%gallery.length;
   const link=gallery[current];
   image.src=link.href;image.alt=link.title||'Daily Log photo';caption.textContent=link.title||'';counter.textContent='Image '+(current+1)+' of '+gallery.length;
   modal.classList.toggle('is-single-image',gallery.length<2);
 }
 function closeGallery(hideModal){
   image.src='';gallery=[];current=0;counter.textContent='';
   if(hideModal){modal.hidden=true;modal.classList.remove('is-open');document.body.classList.remove('modal-open');}
 }
 document.addEventListener('click',function(event){
   const link=event.target.closest('[data-daily-photo-viewer]');if(!link)return;
   event.preventDefault();
   const entry=link.closest('[data-daily-log-entry]');
   gallery=Array.from((entry||document).querySelectorAll('[data-daily-photo-viewer]'));
   current=Math.max(0,gallery.indexOf(link));show(current);modal.hidden=false;modal.classList.add('is-open');document.body.classList.add('modal-open');
 });
 previous.addEventListener('click',function(){show(current-1);});
 next.addEventListener('click',function(){show(current+1);});
 modal.addEventListener('click',function(event){if(event.target===modal)closeGallery(false);});
 modal.querySelector('[data-close-modal]').addEventListener('click',function(){closeGallery(false);});
 document.addEventListener('keydown',function(event){
   if(modal.hidden)return;
   if(event.key==='ArrowLeft'){event.preventDefault();show(current-1);}
   if(event.key==='ArrowRight'){event.preventDefault();show(current+1);}
   if(event.key==='Escape')closeGallery(true);
 });
})();
</script>
<?php require __DIR__.'/includes/footer.php';?>
