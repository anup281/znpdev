<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';

$projectId=dev_active_project_id((int)($_GET['project_id']??$_POST['project_id']??0));
$p=dev_require_project($projectId);
$error='';
$warnings=[];

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!csrf_check((string)($_POST['csrf']??''))) throw new RuntimeException('Session expired. Please try again.');
        $title=trim((string)($_POST['title']??''));
        if($title==='') throw new RuntimeException('Event title is required.');
        $startRaw=trim((string)($_POST['start_datetime']??''));
        if($startRaw==='') throw new RuntimeException('Start date and time are required.');
        $start=str_replace('T',' ',$startRaw);
        $endRaw=trim((string)($_POST['end_datetime']??''));
        $end=$endRaw!==''?str_replace('T',' ',$endRaw):null;
        $user=admin_user();
        $s=db()->prepare('INSERT INTO construction_calendar_events (construction_project_id,construction_building_id,title,event_type,start_datetime,end_datetime,location,description,created_by_admin_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())');
        $s->execute([
            $projectId,
            (($_POST['construction_building_id']??'')!=='')?(int)$_POST['construction_building_id']:null,
            $title,
            (string)($_POST['event_type']??'Project Event'),
            $start,
            $end,
            trim((string)($_POST['location']??'')),
            trim((string)($_POST['description']??'')),
            $user['id']??null
        ]);
        try { dev_activity($projectId,'calendar_event_created','Calendar event created: '.$title,'calendar_event',(int)db()->lastInsertId()); } catch(Throwable $ignored) {}
        header('Location: calendar.php?project_id='.$projectId.'&saved=1');
        exit;
    } catch(Throwable $e){
        $error=$e->getMessage();
    }
}

require __DIR__.'/includes/header.php';

$buildings=[];
try{
    $q=db()->prepare('SELECT id,building_name FROM construction_buildings WHERE construction_project_id=? AND (is_archived=0 OR is_archived IS NULL) ORDER BY building_name');
    $q->execute([$projectId]);
    $buildings=$q->fetchAll();
}catch(Throwable $e){
    try{
        $q=db()->prepare('SELECT id,building_name FROM construction_buildings WHERE construction_project_id=? ORDER BY building_name');
        $q->execute([$projectId]);
        $buildings=$q->fetchAll();
    }catch(Throwable $ignored){ $warnings[]='Buildings could not be loaded.'; }
}

$events=[];
$load=function(string $label,string $sql,array $params) use (&$events,&$warnings): void {
    try{
        $q=db()->prepare($sql);
        $q->execute($params);
        $rows=$q->fetchAll();
        if($rows) $events=array_merge($events,$rows);
    }catch(Throwable $e){
        error_log('Construction calendar '.$label.' query failed: '.$e->getMessage());
        $warnings[]=$label.' dates are temporarily unavailable.';
    }
};

$load('Schedule',"SELECT csi.id,CONCAT(IFNULL(CONCAT(cb.building_name,' — '),''),csi.activity_name) title,IF(csi.is_inspection=1,'Inspection','Schedule') event_type,csi.planned_start_date start_date,csi.planned_finish_date end_date,csi.status,cb.building_name,CONCAT('schedule.php?project_id=',csi.construction_project_id,IF(csi.construction_building_id IS NULL,'',CONCAT('&building_id=',csi.construction_building_id)),'&edit=',csi.id) url FROM construction_schedule_items csi LEFT JOIN construction_buildings cb ON cb.id=csi.construction_building_id WHERE csi.construction_project_id=? AND csi.planned_start_date IS NOT NULL",[$projectId]);

$load('Task',"SELECT t.id,CONCAT(IFNULL(CONCAT(cb.building_name,' — '),''),t.title) title,'Task' event_type,t.due_date start_date,t.due_date end_date,t.status,cb.building_name,CONCAT('tasks.php?project_id=',t.construction_project_id,'&view=',t.id) url FROM construction_tasks t LEFT JOIN construction_buildings cb ON cb.id=t.construction_building_id WHERE t.construction_project_id=? AND t.due_date IS NOT NULL",[$projectId]);

$load('Project event',"SELECT ce.id,CONCAT(IFNULL(CONCAT(cb.building_name,' — '),''),ce.title) title,ce.event_type,DATE(ce.start_datetime) start_date,DATE(COALESCE(ce.end_datetime,ce.start_datetime)) end_date,'Scheduled' status,cb.building_name,CONCAT('calendar.php?project_id=',ce.construction_project_id,'#event-',ce.id) url FROM construction_calendar_events ce LEFT JOIN construction_buildings cb ON cb.id=ce.construction_building_id WHERE ce.construction_project_id=?",[$projectId]);

$events=array_values(array_filter($events,static fn(array $ev): bool => !empty($ev['start_date'])));
usort($events,static fn(array $a,array $b): int => strcmp((string)$a['start_date'],(string)$b['start_date']));
?>
<div class="page-head"><div><h1>Project Calendar</h1><p class="muted"><?=e($p['project_name'])?> · schedule activities, inspection items, tasks and project events</p></div><button class="btn btn-primary" data-open-modal="event-modal">Add Project Event</button></div>
<?php if(isset($_GET['saved'])):?><div class="card notice-success">Project event added.</div><?php endif;?>
<?php if($error):?><div class="card notice-error"><?=e($error)?></div><?php endif;?>
<?php if($warnings):?><div class="card notice-warning"><?=e(implode(' ',array_unique($warnings)))?> The available calendar items are shown below.</div><?php endif;?>
<div class="calendar-toolbar card"><label>Filter <select id="event-filter"><option value="">All Events</option><option>Schedule</option><option>Task</option><option>Inspection</option><option>Project Event</option><option>Meeting</option><option>Delivery</option></select></label><label>Building <select id="building-filter"><option value="">All Buildings</option><?php foreach($buildings as $b):?><option><?=e($b['building_name'])?></option><?php endforeach;?></select></label></div>
<div class="card calendar-list" style="margin-top:18px"><?php if(!$events):?><div class="empty">No dated project activity yet.</div><?php endif;?><?php $last='';foreach($events as $ev):$stamp=strtotime((string)$ev['start_date']);if(!$stamp)continue;$month=date('F Y',$stamp);if($month!==$last):?><h2 class="calendar-month"><?=e($month)?></h2><?php $last=$month;endif;?><a class="calendar-event" data-type="<?=e((string)$ev['event_type'])?>" data-building="<?=e((string)($ev['building_name']??''))?>" href="<?=e((string)$ev['url'])?>"><span class="calendar-date"><strong><?=date('j',$stamp)?></strong><small><?=date('D',$stamp)?></small></span><span><strong><?=e((string)$ev['title'])?></strong><small><?=e((string)$ev['event_type'])?> · <?=e((string)($ev['status']??'Scheduled'))?><?=(!empty($ev['end_date'])&&$ev['end_date']!==$ev['start_date'])?' · through '.e(dev_date((string)$ev['end_date'])):''?></small></span></a><?php endforeach;?></div>
<div class="dev-modal" id="event-modal" hidden><div class="dev-modal-panel"><button class="modal-close" data-close-modal>×</button><h2>Add Project Event</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>"><div><label>Event Title</label><input name="title" required></div><div><label>Type</label><select name="event_type"><option>Project Event</option><option>Meeting</option><option>Delivery</option><option>Deadline</option><option>Site Work</option></select></div><div><label>Building</label><select name="construction_building_id"><option value="">Site-Wide</option><?php foreach($buildings as $b):?><option value="<?=$b['id']?>"><?=e($b['building_name'])?></option><?php endforeach;?></select></div><div><label>Location</label><input name="location"></div><div><label>Starts</label><input type="datetime-local" name="start_datetime" required></div><div><label>Ends</label><input type="datetime-local" name="end_datetime"></div><div class="form-full"><label>Description</label><textarea name="description"></textarea></div><div class="form-full"><button class="primary">Add Event</button></div></form></div></div>
<script>const filter=()=>{const t=document.getElementById('event-filter').value,b=document.getElementById('building-filter').value;document.querySelectorAll('.calendar-event').forEach(x=>x.hidden=!!((t&&x.dataset.type!==t)||(b&&x.dataset.building!==b)))};document.getElementById('event-filter').onchange=filter;document.getElementById('building-filter').onchange=filter;</script>
<?php require __DIR__.'/includes/footer.php'; ?>
