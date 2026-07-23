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
    $path=ltrim(str_replace('\\','/',$path),'/');
    if(strpos($path,'uploads/')===0) return '../'.$path;
    if(strpos($path,'dev/uploads/')===0) return '../'.substr($path,4);
    return '../uploads/dev/daily-logs/'.basename($path);
}
function znp_daily_log(int $id,int $projectId): ?array {
    $s=db()->prepare('SELECT * FROM construction_daily_logs WHERE id=? AND construction_project_id=? LIMIT 1');
    $s->execute([$id,$projectId]);
    $row=$s->fetch();
    return $row?:null;
}
function znp_daily_remove_file(string $relativePath): void {
    $relativePath=ltrim(str_replace('\\','/',$relativePath),'/');
    if(strpos($relativePath,'uploads/dev/')!==0) return;
    $root=realpath(__DIR__.'/..');
    $target=realpath(__DIR__.'/../'.$relativePath);
    if($root && $target && strpos($target,$root.DIRECTORY_SEPARATOR)===0 && is_file($target)) @unlink($target);
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
    }catch(Throwable $ignored){}
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
            try{ dev_activity($projectId,'daily_log_deleted','Daily log deleted for '.dev_date((string)$deleteLog['log_date']).'.','daily_log',$deleteId); }catch(Throwable $ignored){}
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
        if(!$hasWorkforceTable && !empty($workforceSelections)) throw new RuntimeException('The Daily Log workforce upgrade must be installed before saving team selections.');
        if($hasWorkforceTable&&!$hasWorkforceSourceKey&&$workforceSelections){$legacyKeys=array_map(static fn($row):string=>$row['source_type'].':'.$row['source_id'],$workforceSelections);if(count($legacyKeys)!==count(array_unique($legacyKeys)))throw new RuntimeException('The Company + Trade Daily Log upgrade must be installed before selecting multiple trades for one vendor.');}
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
            $warning='The daily log was saved, but photos were skipped because the Daily Log photo upgrade has not been installed.';
        }elseif($hasPhotoTable && $hasFiles){
            $uploads=dev_compress_images($_FILES['photos']??[],'daily-logs');
            if(!$uploads) throw new RuntimeException('The selected photos could not be processed. Please use JPG, PNG, WebP, HEIC, or HEIF files.');
            if($uploads){
                $uploadedPaths=array_column($uploads,'path');
                $ins=db()->prepare('INSERT INTO construction_daily_log_photos(daily_log_id,construction_project_id,file_path,original_name,mime_type,file_size,created_at) VALUES(?,?,?,?,?,?,NOW())');
                foreach($uploads as $f) $ins->execute([$id,$projectId,$f['path'],$f['name'],$f['mime'],$f['size']]);
            }
        }
        db()->commit();
        foreach($removedPhotoPaths as $removedPhotoPath) znp_daily_remove_file($removedPhotoPath);
        try{ dev_activity($projectId,$event,$message,'daily_log',$id); }catch(Throwable $ignored){}
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
    }catch(Throwable $ignored){}
}
if(isset($_GET['photo_upgrade'])) $warning='The daily log was saved, but photo attachments are not enabled.';
require __DIR__.'/includes/header.php';
$formSource=$_POST?:($editLog?:[]);
$isEditing=(bool)$editLog;
?>
<style>
.daily-log-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px}.daily-log-actions form{margin:0}.daily-log-actions .danger{background:#fff;color:#b42318;border:1px solid #f0b4ae}.daily-log-actions .danger:hover{background:#fff1f0}.daily-photo-remove-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin-top:10px}.daily-photo-remove{display:block;border:1px solid #dce3ea;border-radius:8px;padding:7px}.daily-photo-remove img{width:100%;height:100px;object-fit:cover;border-radius:6px;display:block;margin-bottom:6px}.daily-photo-remove span{display:flex;gap:6px;align-items:center;font-size:13px}.daily-log-compose,.daily-log-entry{padding:0!important;overflow:hidden}.daily-log-compose>summary,.daily-log-entry>summary{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 20px;cursor:pointer;list-style:none}.daily-log-compose>summary::-webkit-details-marker,.daily-log-entry>summary::-webkit-details-marker{display:none}.daily-log-compose>summary:after,.daily-log-entry>summary:after{content:'+';display:grid;place-items:center;width:30px;height:30px;flex:0 0 auto;border-radius:50%;background:var(--pale);color:var(--blue);font-size:21px;font-weight:700}.daily-log-compose[open]>summary:after,.daily-log-entry[open]>summary:after{content:'−'}.daily-log-compose>form,.daily-log-entry-body{padding:0 20px 20px;border-top:1px solid var(--line)}.daily-log-compose>form{padding-top:20px}.daily-log-summary strong,.daily-log-summary small{display:block}.daily-log-summary small{margin-top:4px;color:var(--muted)}.daily-log-photo-slot{margin-top:16px}.daily-photo-loading{display:flex;align-items:center;gap:10px;padding:16px;color:var(--muted)}.daily-photo-spinner{width:22px;height:22px;border:3px solid #d8e3ec;border-top-color:var(--blue);border-radius:50%;animation:daily-photo-spin .75s linear infinite}.daily-photo-error{padding:14px;border:1px solid #efc3bf;border-radius:8px;background:#fff6f5;color:#8d2921}.daily-photo-error .btn{margin-top:10px}.dev-toast.daily-log-save-toast{left:20px!important;right:auto!important;bottom:20px!important;background:#21824e!important;color:#fff!important;box-shadow:0 12px 30px rgba(20,65,40,.25)}@keyframes daily-photo-spin{to{transform:rotate(360deg)}}.manual-weather{min-height:78px}.workforce-panel{border:1px solid #d8e1e9;border-radius:10px;background:#fff!important;color:#173f68!important;overflow:hidden}.workforce-panel *{box-sizing:border-box}.workforce-panel>summary{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:44px;padding:9px 12px;cursor:pointer;color:#173f68!important;background:#f8fafc;font-weight:700;list-style:none}.workforce-panel>summary::-webkit-details-marker{display:none}.workforce-panel>summary:after{content:'+';font-size:20px;line-height:1;color:#2f6fa7}.workforce-panel[open]>summary:after{content:'−'}.workforce-summary{color:#64788b!important;font-size:12px;font-weight:600;white-space:nowrap}.workforce-panel-body{padding:10px;background:#fff!important;color:#173f68!important}.workforce-search{margin:0 0 9px!important;height:40px!important;color:#173f68!important;background:#fff!important;border:1px solid #cbd7e2!important}.workforce-group+.workforce-group{margin-top:12px;padding-top:12px;border-top:1px solid #e3e9ef}.workforce-group-title{margin:0 0 6px;padding:0 2px;color:#173f68!important;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.workforce-picker{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px}.workforce-row{border:1px solid #d8e1e9;border-radius:7px;background:#fff!important;color:#173f68!important;overflow:hidden;min-width:0}.workforce-row[hidden]{display:none!important}.workforce-choice{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:9px!important;margin:0!important;padding:6px 9px!important;min-height:38px!important;cursor:pointer;color:#173f68!important;-webkit-text-fill-color:#173f68!important;background:#fff!important;text-align:left!important}.workforce-choice:hover{background:#f2f7fb!important}.workforce-choice:has(input:checked){background:#eef5fb!important;box-shadow:inset 3px 0 0 #2f6fa7}.workforce-choice input[type=checkbox]{appearance:auto!important;-webkit-appearance:checkbox!important;display:block!important;width:18px!important;height:18px!important;min-width:18px!important;margin:0!important;flex:0 0 18px!important;accent-color:#2f6fa7!important}.workforce-choice,.workforce-choice span,.workforce-choice strong,.workforce-choice small{color:#173f68!important;-webkit-text-fill-color:#173f68!important;opacity:1!important;visibility:visible!important}.workforce-choice span{display:block!important;min-width:0!important;line-height:1.15!important;text-align:left!important}.workforce-choice strong{display:block!important;color:#173f68!important;font-size:13px!important;font-weight:700!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.workforce-choice small{display:block!important;color:#61778b!important;-webkit-text-fill-color:#61778b!important;font-size:11px!important;font-weight:500!important;margin-top:2px!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.workforce-empty{padding:10px;color:#64788b!important;font-size:13px}.draft-status{display:block;margin-top:8px;color:#64788b;font-size:12px}.daily-log-reset{background:#fff!important;color:#b42318!important;border:1px solid #e2a8a3!important;padding:8px 12px!important;border-radius:7px!important;font-size:12px!important;font-weight:700!important}.daily-log-reset:hover{background:#fff4f3!important}@media(max-width:620px){.daily-log-compose>summary,.daily-log-entry>summary{padding:14px}.daily-log-compose>form,.daily-log-entry-body{padding-left:14px;padding-right:14px}.workforce-panel>summary{min-height:42px;padding:8px 10px}.workforce-panel-body{padding:8px}.workforce-picker{grid-template-columns:1fr;gap:5px}.workforce-choice{min-height:38px;padding:6px 8px}.workforce-choice strong{font-size:12.5px}.workforce-choice small{font-size:10.5px}}
</style>

<?php if(isset($_GET['saved'])):?><div class="card notice-success">Daily log saved successfully.</div><?php endif;?>
<?php if(isset($_GET['updated'])):?><div class="card notice-success">Daily log updated successfully.</div><?php endif;?>
<?php if(isset($_GET['deleted'])):?><div class="card notice-success">Daily log deleted successfully.</div><?php endif;?>
<?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<?php if($warning):?><div class="card notice-warning"><?=e($warning)?></div><?php endif;?>
<?php if($hasPhotoTable && dev_is_super()):?><div class="daily-log-recovery-link"><a class="btn btn-secondary btn-small" href="photo_recovery.php?project_id=<?=$projectId?>">Photo Recovery</a></div><?php endif;?>
<details class="card daily-log-compose" <?=$isEditing?'open':''?>><summary><span class="daily-log-summary"><strong><?=$isEditing?'Edit Daily Log':'Add Daily Log'?></strong><small><?=$isEditing?'Update this entry and its attachments.':'Create a new project field report.'?></small></span></summary><form id="daily-log-form" method="post" enctype="multipart/form-data" class="form-grid" data-heic-upload-form>
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="<?=$isEditing?'update':'create'?>"><?php if($isEditing):?><input type="hidden" name="log_id" value="<?=$editId?>"><?php endif;?>
<div class="form-full" style="display:flex;justify-content:flex-end;gap:8px;align-items:center"><button type="button" class="daily-log-reset" id="daily-log-reset">Reset Draft</button><?php if($isEditing):?><a class="secondary" href="daily_logs.php?project_id=<?=$projectId?>">Cancel Edit</a><?php endif;?></div>
<div><label>Log Date</label><input type="date" name="log_date" required value="<?=e($formSource['log_date']??date('Y-m-d'))?>"></div>
<div class="form-full"><label>Project Team / Trades on Job</label>
<?php if(!$hasWorkforceTable):?><div class="notice-warning">Install the Daily Log workforce upgrade before using this field.</div><?php elseif(!$hasWorkforceSourceKey):?><div class="notice-warning">A Super Admin must run the Company + Trade Daily Log upgrade before multiple trades from one vendor can be saved.</div><?php endif;?>
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
<div class="grid" style="margin-top:20px"><?php foreach($logs as $l):?><details class="card daily-log-card daily-log-entry" data-daily-log-entry data-log-id="<?=(int)$l['id']?>"><summary><span class="daily-log-summary"><strong><?=e(dev_date($l['log_date']))?></strong><small><?=e((string)$l['workforce_count'])?> trades/team · Logged by <?=e($l['full_name']?:'User')?></small></span></summary><div class="daily-log-entry-body"><?php if(!empty($workforceByLog[(int)$l['id']])):?><details><summary>Team / Trades on Site</summary><ul><?php foreach($workforceByLog[(int)$l['id']] as $wf):?><li><?=e($wf['display_label'])?></li><?php endforeach;?></ul></details><?php endif;?>
<?php if(trim((string)($l['weather']??''))!==''):?><div class="weather-strip"><span><strong>Weather Conditions</strong><?=nl2br(e((string)$l['weather']))?></span></div><?php endif;?>
<?php if(trim((string)($l['visitors']??''))!==''):?><p><strong>Visitors / Vendors:</strong> <?=e((string)$l['visitors'])?></p><?php endif;?>
<h4>Today's Activities on Job</h4><p><?=nl2br(e($l['work_performed']))?></p><?php if($l['delays']):?><details><summary>Delays</summary><p><?=nl2br(e($l['delays']))?></p></details><?php endif;?><?php if($l['safety_incidents']):?><details><summary>Safety Incidents</summary><p><?=nl2br(e($l['safety_incidents']))?></p></details><?php endif;?>
<?php if($hasPhotoTable):?><div class="daily-log-photo-slot" data-daily-log-photos data-endpoint="daily_log_photos.php?project_id=<?=$projectId?>&daily_log_id=<?=(int)$l['id']?>"><span class="muted">Photos load when this Daily Log is expanded.</span></div><?php endif;?>
<div class="daily-log-actions"><a class="secondary" href="daily_logs.php?project_id=<?=$projectId?>&edit_id=<?=(int)$l['id']?>#daily-log-form">Edit</a><form method="post" onsubmit="return confirm('Delete this daily log and all attached photos? This cannot be undone.');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="log_id" value="<?=(int)$l['id']?>"><button class="danger" type="submit">Delete</button></form></div>
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
<script src="assets/heic-upload.js?v=1.0.1" defer></script>
<div class="dev-modal" id="daily-photo-modal" hidden><div class="dev-modal-panel" style="width:min(1100px,96vw);text-align:center"><button type="button" class="modal-close" data-close-modal aria-label="Close">×</button><img id="daily-photo-modal-image" src="" alt="" style="display:block;max-width:100%;max-height:82vh;width:auto;height:auto;margin:auto;border-radius:8px"><p id="daily-photo-modal-caption" class="muted"></p></div></div>
<script>
(function(){
 const modal=document.getElementById('daily-photo-modal'),image=document.getElementById('daily-photo-modal-image'),caption=document.getElementById('daily-photo-modal-caption');
 document.addEventListener('click',function(event){
   const link=event.target.closest('[data-daily-photo-viewer]');if(!link)return;
   event.preventDefault();image.src=link.href;image.alt=link.title||'Daily Log photo';caption.textContent=link.title||'';modal.hidden=false;modal.classList.add('is-open');document.body.classList.add('modal-open');
 });
 modal.addEventListener('click',function(event){if(event.target===modal){image.src='';}});
 modal.querySelector('[data-close-modal]').addEventListener('click',function(){image.src='';});
})();
</script>
<?php require __DIR__.'/includes/footer.php';?>
