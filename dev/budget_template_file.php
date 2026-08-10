<?php
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/budget.php';
if(!dev_is_super()){http_response_code(403);exit('Budget access denied.');}
$projectId=dev_active_project_id((int)($_GET['project_id']??0));
dev_require_project($projectId);
$path=__DIR__.'/templates/construction-budget-template.xlsx';
if(!is_file($path)){http_response_code(404);exit('Reference template not found.');}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Length: '.filesize($path));
header('Content-Disposition: attachment; filename="construction-budget-template.xlsx"');
readfile($path);exit;
