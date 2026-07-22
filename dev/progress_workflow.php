<?php
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/progress_workflow.php';
$projectId=dev_active_project_id((int)($_GET['project_id']??0));
$p=dev_require_project($projectId);
$buildingId=max(0,(int)($_GET['building_id']??0));
header('Content-Type: text/html; charset=utf-8');
dev_render_progress_workflow($projectId,$buildingId,$p);
