<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';
require_admin();
if(!super_admin_role()){
    http_response_code(403);
    exit('Only a Super Admin may run the contract importer.');
}
require_once __DIR__.'/../dev/includes/contracts.php';

function contract_import_normalize(string $value): string
{
    $value=strtolower(trim($value));
    $value=preg_replace('/\b(incorporated|corporation|company|construction|services|service|investments|investment|interiors|llc|inc|co)\b/',' ',$value)??$value;
    return preg_replace('/[^a-z0-9]+/','',$value)??'';
}

function contract_import_score(array $source,array $assignment): int
{
    $sourceCompany=contract_import_normalize((string)$source['company']);
    $teamCompany=contract_import_normalize((string)$assignment['company_name']);
    $sourceTrade=contract_import_normalize((string)$source['trade']);
    $teamTrade=contract_import_normalize((string)$assignment['trade_role']);
    $score=0;
    if($sourceCompany!==''&&$sourceCompany===$teamCompany)$score+=100;
    elseif($sourceCompany!==''&&$teamCompany!==''&&(str_contains($sourceCompany,$teamCompany)||str_contains($teamCompany,$sourceCompany)))$score+=60;
    if($sourceTrade!==''&&$sourceTrade===$teamTrade)$score+=60;
    elseif($sourceTrade!==''&&$teamTrade!==''&&(str_contains($sourceTrade,$teamTrade)||str_contains($teamTrade,$sourceTrade)))$score+=25;
    return $score;
}

function contract_import_best_assignment(array $source,array $assignments): int
{
    $bestId=0;$bestScore=0;$tie=false;
    foreach($assignments as $assignment){
        $score=contract_import_score($source,$assignment);
        if($score>$bestScore){$bestScore=$score;$bestId=(int)$assignment['id'];$tie=false;}
        elseif($score>0&&$score===$bestScore){$tie=true;}
    }
    return $bestScore>=100&&!$tie?$bestId:0;
}

$sourcePath=__DIR__.'/data/construction_contracts_first_project.json';
$source=json_decode((string)file_get_contents($sourcePath),true,512,JSON_THROW_ON_ERROR);
$projects=db()->query('SELECT id,project_name FROM construction_projects ORDER BY project_name')->fetchAll()?:[];
$projectId=max(0,(int)($_REQUEST['project_id']??0));
$assignments=$projectId?dev_contract_project_team($projectId):[];
$message='';$error='';$results=[];

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_check((string)($_POST['csrf_token']??'')))$error='Your session expired. Refresh and try again.';
    elseif($projectId<1)$error='Select the project receiving these contracts.';
    elseif((string)($_POST['confirm_import']??'')!=='yes')$error='Confirm that you reviewed the project-team matches.';
    else try{
        dev_ensure_contract_schema();
        $assignmentById=[];foreach($assignments as $assignment)$assignmentById[(int)$assignment['id']]=$assignment;
        $selected=[];$selectedAssignments=[];
        foreach($source as $index=>$contract){
            $assignmentId=(int)($_POST['assignment'][$index]??0);
            if(!$assignmentId)continue;
            if(!isset($assignmentById[$assignmentId]))throw new RuntimeException('One of the selected Project Team assignments is invalid.');
            if(isset($selectedAssignments[$assignmentId]))throw new RuntimeException('Each contract must link to its own Project Team trade. Review duplicate selections.');
            $selectedAssignments[$assignmentId]=true;
            $selected[]=['assignment_id'=>$assignmentId,'source_index'=>$index];
        }
        if(!$selected)throw new RuntimeException('Select at least one Project Team match.');
        $changeOrderDate=(string)($_POST['change_order_date']??date('Y-m-d'));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$changeOrderDate))throw new RuntimeException('Enter a valid default change-order date.');
        db()->beginTransaction();
        $created=0;$skipped=0;$paymentCount=0;$changeOrderCount=0;
        foreach($selected as $selection){
            $assignmentId=(int)$selection['assignment_id'];$index=(int)$selection['source_index'];
            $contract=$source[$index];
            $existing=db()->prepare('SELECT id FROM construction_contracts WHERE construction_project_id=? AND construction_project_company_id=? LIMIT 1');
            $existing->execute([$projectId,$assignmentId]);
            if($existing->fetchColumn()){$skipped++;$results[]=(string)$contract['trade'].' — '.(string)$contract['company'].' (skipped: linked Project Team trade already has a contract)';continue;}
            $insert=db()->prepare('INSERT INTO construction_contracts(construction_project_id,construction_project_company_id,original_contract_amount,notes,created_by_admin_user_id) VALUES(?,?,?,?,?)');
            $insert->execute([$projectId,$assignmentId,round((float)$contract['original'],2),trim((string)$contract['notes'])?:null,(int)(admin_user()['id']??0)]);
            $contractId=(int)db()->lastInsertId();
            $paymentInsert=db()->prepare('INSERT INTO construction_contract_payments(construction_contract_id,app_number,amount_requested,retainage,amount_paid,payment_method,entry_date,created_by_admin_user_id) VALUES(?,?,?,?,?,?,?,?)');
            foreach($contract['payments'] as $payment){
                $paymentInsert->execute([$contractId,mb_substr((string)$payment['app'],0,50),round((float)$payment['requested'],2),round((float)$payment['retainage'],2),round((float)$payment['paid'],2),mb_substr((string)$payment['method'],0,190),(string)$payment['date'],(int)(admin_user()['id']??0)]);$paymentCount++;
            }
            $changeOrderInsert=db()->prepare('INSERT INTO construction_contract_change_orders(construction_contract_id,change_order_number,description,amount,change_order_date,created_by_admin_user_id) VALUES(?,?,?,?,?,?)');
            foreach($contract['change_orders'] as $changeOrder){
                $changeOrderInsert->execute([$contractId,'CO '.mb_substr((string)$changeOrder['number'],0,47),mb_substr((string)$changeOrder['description'],0,500),round((float)$changeOrder['amount'],2),$changeOrderDate,(int)(admin_user()['id']??0)]);$changeOrderCount++;
            }
            $created++;$results[]=(string)$contract['trade'].' — '.(string)$contract['company'].' (imported)';
        }
        db()->commit();
        $message=number_format($created).' contracts, '.number_format($paymentCount).' payments, and '.number_format($changeOrderCount).' change orders imported.'.($skipped?' '.number_format($skipped).' existing contracts skipped.':'');
    }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();error_log('One-time construction contract import failed: '.$exception->getMessage());$error='Import failed: '.$exception->getMessage();}
}

require __DIR__.'/_header.php';
?>
<style>
.contract-import-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:18px 0}.contract-import-summary span{background:#f3f6fa;border:1px solid #dce4ee;border-radius:10px;padding:14px}.contract-import-summary small{display:block;color:#66758a;margin-bottom:4px}.contract-import-summary strong{font-size:1.25rem}.contract-import-table select{min-width:260px}.contract-import-table td{vertical-align:top}.contract-import-table .source-detail{display:block;color:#66758a;font-size:.85rem;margin-top:4px}.contract-import-notes{white-space:pre-line;max-width:260px}.contract-import-actions{display:flex;align-items:end;gap:18px;flex-wrap:wrap;margin-top:20px}.contract-import-actions label{display:grid;gap:7px}.contract-import-warning{border-left:4px solid #ba7a12;padding-left:14px}@media(max-width:900px){.contract-import-summary{grid-template-columns:1fr 1fr}}
</style>
<div class="admin-page-head"><div><h1>One-Time Construction Contract Import</h1><p>Import the first-project contract tracker without storing or uploading the source workbook.</p></div><a class="secondary" href="../dev/projects.php">Back to Construction</a></div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel">
  <h2>Select Project</h2>
  <form method="get" class="admin-form"><label>Construction Project</label><select name="project_id" required><option value="">Choose project</option><?php foreach($projects as $project):?><option value="<?=(int)$project['id']?>" <?=((int)$project['id']===$projectId)?'selected':''?>><?=e((string)$project['project_name'])?></option><?php endforeach;?></select><button class="secondary" type="submit">Preview Matches</button></form>
</section>
<?php if($projectId):
    $totals=['contracts'=>count($source),'payments'=>0,'change_orders'=>0,'notes'=>0];foreach($source as $contract){$totals['payments']+=count($contract['payments']);$totals['change_orders']+=count($contract['change_orders']);if(trim((string)$contract['notes'])!=='')$totals['notes']++;}
?>
<section class="admin-panel">
  <h2>Review Project Team Matches</h2>
  <p class="contract-import-warning">Each contract links to one Project Team trade assignment. The linked Project Team record is the source for the trade, company, and contact shown on the contract.</p>
  <div class="contract-import-summary"><span><small>Contracts</small><strong><?=$totals['contracts']?></strong></span><span><small>Payments</small><strong><?=$totals['payments']?></strong></span><span><small>Change Orders</small><strong><?=$totals['change_orders']?></strong></span><span><small>Contracts with Notes</small><strong><?=$totals['notes']?></strong></span></div>
  <form method="post" onsubmit="return confirm('Import the selected contracts into this project? Existing contracts will be skipped.');">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="project_id" value="<?=$projectId?>">
    <div class="table-scroll"><table class="contract-import-table"><thead><tr><th>Workbook Contract</th><th>Original Amount</th><th>Revised Amount</th><th>Payments</th><th>Change Orders</th><th>Side Notes</th><th>Project Team Match</th></tr></thead><tbody>
    <?php foreach($source as $index=>$contract):$best=contract_import_best_assignment($contract,$assignments);?>
      <tr><td><strong><?=e((string)$contract['trade'])?></strong><span class="source-detail"><?=e((string)$contract['company'])?> · <?=e((string)$contract['contact'])?></span></td><td><?=e('$'.number_format((float)$contract['original'],2))?></td><td><?=e('$'.number_format((float)$contract['revised'],2))?></td><td><?=count($contract['payments'])?></td><td><?=count($contract['change_orders'])?></td><td class="contract-import-notes"><?=trim((string)$contract['notes'])!==''?e((string)$contract['notes']):'—'?></td><td><select name="assignment[<?=$index?>]"><option value="">Do not import</option><?php foreach($assignments as $assignment):?><option value="<?=(int)$assignment['id']?>" <?=((int)$assignment['id']===$best)?'selected':''?>><?=e((string)$assignment['trade_role'].' — '.(string)$assignment['company_name'])?></option><?php endforeach;?></select><?php if(!$best):?><span class="source-detail">Review and select a match.</span><?php endif;?></td></tr>
    <?php endforeach;?></tbody></table></div>
    <div class="contract-import-actions"><label>Default date for change orders <input type="date" name="change_order_date" value="<?=e(date('Y-m-d'))?>" required></label><label class="admin-check-row"><input type="checkbox" name="confirm_import" value="yes" required> I reviewed every Project Team match.</label><button class="primary" type="submit">Import Selected Contracts</button></div>
  </form>
</section>
<?php endif;?>
<?php if($results):?><section class="admin-panel"><h2>Import Details</h2><ul><?php foreach($results as $result):?><li><?=e($result)?></li><?php endforeach;?></ul></section><?php endif;?>
<?php require __DIR__.'/_footer.php';?>
