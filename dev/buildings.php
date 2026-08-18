<?php
require __DIR__.'/includes/bootstrap.php';
$projectId=dev_active_project_id((int)($_GET['project_id']??$_POST['project_id']??0));
$p=dev_require_project($projectId);
$canManage=dev_is_super();
$error='';

if(!$canManage && ($_SERVER['REQUEST_METHOD']==='POST' || isset($_GET['new']) || isset($_GET['edit']))){
    http_response_code(403);
    exit('Administrator access required.');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!$canManage)throw new RuntimeException('Administrator access required.');
        if(!csrf_check((string)($_POST['csrf']??'')))throw new RuntimeException('Your session expired.');
        $action=(string)($_POST['action']??'save');
        if($action==='delete'){
            $id=(int)($_POST['id']??0);
            if($id<1)throw new RuntimeException('Building not found.');
            $buildingQuery=db()->prepare('SELECT building_name FROM construction_buildings WHERE id=? AND construction_project_id=?');
            $buildingQuery->execute([$id,$projectId]);
            $buildingName=$buildingQuery->fetchColumn();
            if($buildingName===false)throw new RuntimeException('Building not found.');
            $itemQuery=db()->prepare('SELECT id FROM construction_schedule_items WHERE construction_project_id=? AND construction_building_id=?');
            $itemQuery->execute([$projectId,$id]);
            $itemIds=array_map('intval',$itemQuery->fetchAll(PDO::FETCH_COLUMN));
            $attachmentPaths=[];
            if($itemIds){
                $marks=implode(',',array_fill(0,count($itemIds),'?'));
                $attachmentQuery=db()->prepare("SELECT file_path FROM construction_schedule_attachments WHERE schedule_item_id IN ($marks)");
                $attachmentQuery->execute($itemIds);
                $attachmentPaths=array_values(array_unique(array_filter(array_map('strval',$attachmentQuery->fetchAll(PDO::FETCH_COLUMN)))));
            }
            db()->beginTransaction();
            try{
                if($itemIds){
                    $marks=implode(',',array_fill(0,count($itemIds),'?'));
                    db()->prepare("DELETE FROM construction_schedule_attachments WHERE schedule_item_id IN ($marks)")->execute($itemIds);
                }
                db()->prepare('DELETE FROM construction_schedule_items WHERE construction_project_id=? AND construction_building_id=?')->execute([$projectId,$id]);
                $deleteBuilding=db()->prepare('DELETE FROM construction_buildings WHERE id=? AND construction_project_id=?');
                $deleteBuilding->execute([$id,$projectId]);
                if($deleteBuilding->rowCount()<1)throw new RuntimeException('Building not found.');
                dev_activity($projectId,'building_deleted','Building permanently deleted: '.$buildingName,'building',$id);
                db()->commit();
            }catch(Throwable $deleteError){
                if(db()->inTransaction())db()->rollBack();
                throw $deleteError;
            }
            $cleanupFailed=false;
            foreach($attachmentPaths as $attachmentPath){
                try{znp_storage_delete($attachmentPath);}catch(Throwable $storageError){$cleanupFailed=true;error_log('Deleted building attachment cleanup failed: '.$storageError->getMessage());}
            }
            header('Location: buildings.php?project_id='.$projectId.'&deleted=1'.($cleanupFailed?'&cleanup_warning=1':''));exit;
        }
        if(in_array($action,['archive','restore'],true)){
            $id=(int)($_POST['id']??0);
            if($id<1)throw new RuntimeException('Building not found.');
            $archived=$action==='archive'?1:0;
            $s=db()->prepare('UPDATE construction_buildings SET is_archived=?,updated_at=NOW() WHERE id=? AND construction_project_id=?');
            $s->execute([$archived,$id,$projectId]);
            if($s->rowCount()<1)throw new RuntimeException('Building not found.');
            dev_activity($projectId,'building_'.$action,'Building '.$action.'d.','building',$id);
            header('Location: buildings.php?project_id='.$projectId.'&'.$action.'d=1');exit;
        }
        if($action!=='save')throw new RuntimeException('Unsupported building action.');
        $id=(int)($_POST['id']??0);
        $name=trim((string)($_POST['building_name']??''));
        if($name==='')throw new RuntimeException('Building name is required.');
        $values=[$projectId,$name,trim((string)($_POST['building_type']??'')),max(0,(int)($_POST['unit_count']??0)),($_POST['start_date']??'')?:null,($_POST['target_completion_date']??'')?:null,trim((string)($_POST['notes']??''))];
        if($id){
            $values[]=$id;
            $s=db()->prepare('UPDATE construction_buildings SET construction_project_id=?,building_name=?,building_type=?,unit_count=?,start_date=?,target_completion_date=?,notes=?,updated_at=NOW() WHERE id=? AND construction_project_id=?');
            $values[]=$projectId;
            $s->execute($values);
            dev_activity($projectId,'building_updated','Building updated: '.$name,'building',$id);
        }else{
            db()->prepare("INSERT INTO construction_buildings(construction_project_id,building_name,building_type,unit_count,start_date,target_completion_date,notes,building_code,site_location,current_phase,percent_complete,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'','','',0,'Planned',NOW(),NOW())")->execute($values);
            $id=(int)db()->lastInsertId();
            dev_initialize_schedule($projectId,$id,'Building',$values[4]);
            dev_activity($projectId,'building_created','Building created with default multifamily workflow: '.$name,'building',$id);
        }
        header('Location: buildings.php?project_id='.$projectId.'&saved=1');exit;
    }catch(Throwable $exception){$error=$exception->getMessage();}
}

$edit=null;
if($canManage && isset($_GET['edit'])){
    $s=db()->prepare('SELECT * FROM construction_buildings WHERE id=? AND construction_project_id=?');
    $s->execute([(int)$_GET['edit'],$projectId]);
    $edit=$s->fetch()?:null;
}
$s=db()->prepare('SELECT * FROM construction_buildings WHERE construction_project_id=? AND is_archived=0 ORDER BY building_name');$s->execute([$projectId]);$buildings=$s->fetchAll();
$archivedBuildings=[];
if($canManage){$s=db()->prepare('SELECT * FROM construction_buildings WHERE construction_project_id=? AND is_archived=1 ORDER BY building_name');$s->execute([$projectId]);$archivedBuildings=$s->fetchAll();}
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>Buildings</h1><p class="muted"><?=e($p['project_name'])?> · Progress and phase are calculated automatically from each workflow.</p></div><?php if($canManage):?><a class="btn btn-primary" href="buildings.php?project_id=<?=$projectId?>&new=1">Add Building</a><?php endif;?></div>
<?php if(isset($_GET['saved'])):?><div class="card notice-success">Building saved.</div><?php endif;?>
<?php if(isset($_GET['archived'])):?><div class="card notice-success">Building archived.</div><?php endif;?>
<?php if(isset($_GET['restored'])):?><div class="card notice-success">Building restored.</div><?php endif;?>
<?php if(isset($_GET['deleted'])):?><div class="card notice-success">Building and its workflow were permanently deleted.</div><?php endif;?>
<?php if(isset($_GET['cleanup_warning'])):?><div class="card notice-error">The building was deleted, but one or more attachment files could not be removed from storage. Check the server error log.</div><?php endif;?>
<?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<?php if($canManage && (isset($_GET['new'])||$edit)):?><form method="post" class="card form-grid schedule-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e((string)($edit['id']??0))?>"><div><label>Building Name / Number</label><input name="building_name" required value="<?=e($edit['building_name']??'')?>"></div><div><label>Building Type</label><input name="building_type" placeholder="4-Plex, Type A, Type B" value="<?=e($edit['building_type']??'')?>"></div><div><label>Unit Count</label><input type="number" min="0" name="unit_count" value="<?=e((string)($edit['unit_count']??0))?>"></div><div><label>Building Start Date</label><input type="date" name="start_date" value="<?=e($edit['start_date']??'')?>"></div><div><label>Target Completion Date</label><input type="date" name="target_completion_date" value="<?=e($edit['target_completion_date']??'')?>"></div><div class="form-full"><label>Notes</label><textarea name="notes"><?=e($edit['notes']??'')?></textarea></div><div class="form-full actions"><button class="primary">Save Building</button><a class="btn btn-secondary" href="buildings.php?project_id=<?=$projectId?>">Cancel</a></div></form><?php endif;?>
<div class="grid grid-3"><?php foreach($buildings as $building):$buildingId=(int)$building['id'];$progress=dev_schedule_progress($projectId,$buildingId);$phase=dev_schedule_phase($projectId,$buildingId);$status=dev_schedule_status($projectId,$buildingId);?><article class="card building-card"><div class="building-card-head"><span class="badge <?=e(dev_status_class($status))?>"><?=e($status)?></span><strong><?=$progress?>%</strong></div><h2><?=e($building['building_name'])?></h2><p class="muted"><?=e($building['building_type']?:'Building')?> · <?=e((string)$building['unit_count'])?> units</p><progress class="znp-progress" value="<?=$progress?>" max="100" aria-label="<?=e($building['building_name'])?> progress"><?=$progress?>%</progress><dl class="compact-details"><dt>Current Phase</dt><dd><?=e($phase)?></dd><dt>Target</dt><dd><?=e(dev_date($building['target_completion_date']))?></dd></dl><div class="actions"><a class="btn btn-primary" href="progress.php?project_id=<?=$projectId?>">Open Progress</a><?php if($canManage):?><a class="btn btn-secondary" href="buildings.php?project_id=<?=$projectId?>&edit=<?=$buildingId?>">Edit</a><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?=$buildingId?>"><button class="btn btn-secondary" data-confirm="Archive this building?">Archive</button></form><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$buildingId?>"><button class="btn btn-danger" data-confirm="Permanently delete <?=e($building['building_name'])?> and all of its workflow history and attachments? This cannot be undone.">Delete</button></form><?php endif;?></div></article><?php endforeach;?></div>
<?php if(!$buildings):?><div class="card empty">No active buildings have been added yet.</div><?php endif;?>
<?php if($canManage && $archivedBuildings):?><section class="card znp-mt-6"><h2>Archived Buildings</h2><?php foreach($archivedBuildings as $building):?><div class="progress-building-row"><span><strong><?=e($building['building_name'])?></strong><small><?=e($building['building_type']?:'Building')?> · <?=e((string)$building['unit_count'])?> units</small></span><div class="actions no-top"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?=(int)$building['id']?>"><button class="btn btn-secondary">Restore</button></form><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$building['id']?>"><button class="btn btn-danger" data-confirm="Permanently delete <?=e($building['building_name'])?> and all of its workflow history and attachments? This cannot be undone.">Delete</button></form></div></div><?php endforeach;?></section><?php endif;?>
<?php require __DIR__.'/includes/footer.php';?>
