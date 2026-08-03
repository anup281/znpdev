<?php
require_once __DIR__.'/includes/bootstrap.php';
if(!dev_is_super()){
    http_response_code(403);
    exit('Expenses access denied.');
}
require __DIR__.'/includes/header.php';
$p=dev_require_project($projectId);
$section=(string)($_GET['section']??'budget');
$allowed=['budget','draws','conditional','unconditional'];
if(!in_array($section,$allowed,true))$section='budget';
$titles=['budget'=>'Budget','draws'=>'Draws','conditional'=>'Conditional Waivers','unconditional'=>'Unconditional Waivers'];
?>
<div class="expense-shell"><nav class="expense-nav"><a class="<?=$section==='budget'?'active':''?>" href="expenses.php?project_id=<?=$projectId?>&section=budget">Budget</a><a class="<?=$section==='draws'?'active':''?>" href="expenses.php?project_id=<?=$projectId?>&section=draws">Draws</a><span>Waivers</span><a class="sub <?=$section==='conditional'?'active':''?>" href="expenses.php?project_id=<?=$projectId?>&section=conditional">Conditional</a><a class="sub <?=$section==='unconditional'?'active':''?>" href="expenses.php?project_id=<?=$projectId?>&section=unconditional">Unconditional</a></nav><section class="card expense-placeholder"><span class="badge">Future Release</span><h1><?=e($titles[$section])?></h1><p>This section is reserved for the future project expenses and draw-management module.</p></section></div>
<?php require __DIR__.'/includes/footer.php';?>
