<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
$message = isset($_GET['saved']) ? 'Investment saved successfully.' : '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(investments_only_role()){http_response_code(403);exit('This account has read-only investment access.');}
    if (!csrf_check($_POST['csrf_token'] ?? '')) $error = 'Your session expired. Refresh and try again.';
    else {
        $action = (string)($_POST['action'] ?? '');
        $opportunityId = (int)($_POST['opportunity_id'] ?? 0);
        require_investment_access($opportunityId);
        if ($action === 'archive' || $action === 'restore') {
            $newStatus = $action === 'archive' ? 'archived' : 'draft';
            $statusColumn=db()->query("SHOW COLUMNS FROM investment_opportunities LIKE 'status'")->fetch();
            if($action==='archive'&&stripos((string)($statusColumn['Type']??''),"'archived'")===false)$error='The database does not support archived investment status. Contact the system administrator.';
            else{db()->prepare('UPDATE investment_opportunities SET status=?, is_visible=0, accepting_inquiries=0 WHERE id=?')->execute([$newStatus,$opportunityId]);header('Location: opportunities.php?'.($action==='archive'?'archived':'restored').'=1');exit;}
        }
        if ($action === 'delete') {
            $statusQuery=db()->prepare('SELECT status,is_visible,accepting_inquiries,hero_image FROM investment_opportunities WHERE id=?');$statusQuery->execute([$opportunityId]);$deleteRow=$statusQuery->fetch();
            $legacyArchive=is_array($deleteRow)&&(string)$deleteRow['status']===''&&(int)$deleteRow['is_visible']===0&&(int)$deleteRow['accepting_inquiries']===0;
            if(!is_array($deleteRow)||((string)$deleteRow['status']!=='archived'&&!$legacyArchive)){$error='Only archived investments may be permanently deleted.';}
            if(!$error){
                try{
                    $cleanup=db()->prepare("DELETE cpi FROM contact_project_interests cpi LEFT JOIN leads l ON cpi.record_type='lead' AND l.id=cpi.record_id LEFT JOIN contact_inquiries ci ON cpi.record_type='inquiry' AND ci.id=cpi.record_id WHERE cpi.investment_opportunity_id=? AND ((cpi.record_type='lead' AND l.id IS NULL) OR (cpi.record_type='inquiry' AND ci.id IS NULL) OR cpi.record_type NOT IN ('lead','inquiry'))");
                    $cleanup->execute([$opportunityId]);
                }catch(Throwable $exception){error_log('Orphaned CRM relationship cleanup failed for investment '.$opportunityId.': '.$exception->getMessage());$error='The CRM relationships could not be verified, so the investment was not deleted.';}
            }
            $checks = [
                ['contact_project_interests','investment_opportunity_id'],
                ['document_templates','investment_opportunity_id'],
                ['contact_inquiries','investment_opportunity_id']
            ];
            $linked=0;$linkedByType=[];$dependencyLabels=['contact_project_interests'=>'CRM relationships','contact_inquiries'=>'inquiries','document_templates'=>'documents'];
            foreach($checks as [$table,$column]){try{$q=db()->prepare("SELECT COUNT(*) FROM $table WHERE $column=?");$q->execute([$opportunityId]);$count=(int)$q->fetchColumn();$linked+=$count;$linkedByType[$table]=$count;}catch(Throwable $e){error_log('Investment link check failed for '.$table.'.'.$column.': '.$e->getMessage());$error='The investment dependencies could not be verified, so it was not deleted.';}}
            if(!$error&&$linked>0){$parts=[];foreach($linkedByType as $table=>$count)if($count>0)$parts[]=$count.' '.$dependencyLabels[$table];$error='This investment cannot be permanently deleted. Linked records: '.implode(', ',$parts).'. Review the dependency counts on its archived card.';}
            elseif(!$error){if(investment_user_access_ready())db()->prepare('DELETE FROM investment_user_access WHERE investment_opportunity_id=?')->execute([$opportunityId]);db()->prepare('DELETE FROM investment_opportunities WHERE id=?')->execute([$opportunityId]);if(!empty($deleteRow['hero_image'])&&!app_delete_managed_file((string)$deleteRow['hero_image'],['assets/images/investments']))error_log('Deleted investment image could not be removed: '.$deleteRow['hero_image']);header('Location: opportunities.php?deleted=1');exit;}
        }
    }
}
if (isset($_GET['archived'])) $message='Investment archived.';
if (isset($_GET['restored'])) $message='Investment restored.';
if (isset($_GET['deleted'])) $message='Investment deleted.';
$archiveCondition="(status='archived' OR (status='' AND is_visible=0 AND accepting_inquiries=0))";
$accessJoin='';$accessArgs=[];
if(investments_only_role()){
    if(!investment_user_access_ready()){
        $error='Investment access has not been configured. Contact a Super Admin.';
        $accessJoin=' AND 1=0';
    }else{
        $accessJoin=' AND EXISTS (SELECT 1 FROM investment_user_access iua WHERE iua.investment_opportunity_id=investment_opportunities.id AND iua.admin_user_id=?)';
        $accessArgs[]=(int)(admin_user()['id']??0);
    }
}
$activeStatement=db()->prepare("SELECT * FROM investment_opportunities WHERE NOT $archiveCondition".$accessJoin.' ORDER BY display_order,project_name');
$activeStatement->execute($accessArgs);$activeRows=$activeStatement->fetchAll();
$archivedStatement=db()->prepare("SELECT * FROM investment_opportunities WHERE $archiveCondition".$accessJoin.' ORDER BY project_name');
$archivedStatement->execute($accessArgs);$archivedRows=$archivedStatement->fetchAll();
$dependencyCounts=[];
if($archivedRows){$archivedIds=array_map(static fn(array $row):int=>(int)$row['id'],$archivedRows);$marks=implode(',',array_fill(0,count($archivedIds),'?'));foreach(['inquiries'=>'contact_inquiries','documents'=>'document_templates'] as $key=>$table){try{$dependencyStatement=db()->prepare("SELECT investment_opportunity_id,COUNT(*) dependency_count FROM $table WHERE investment_opportunity_id IN ($marks) GROUP BY investment_opportunity_id");$dependencyStatement->execute($archivedIds);foreach($dependencyStatement->fetchAll() as $dependencyRow)$dependencyCounts[(int)$dependencyRow['investment_opportunity_id']][$key]=(int)$dependencyRow['dependency_count'];}catch(Throwable $exception){error_log('Archived investment dependency lookup failed for '.$table.': '.$exception->getMessage());}}try{$crmDependencyStatement=db()->prepare("SELECT cpi.investment_opportunity_id,COUNT(*) dependency_count FROM contact_project_interests cpi LEFT JOIN leads l ON cpi.record_type='lead' AND l.id=cpi.record_id LEFT JOIN contact_inquiries ci ON cpi.record_type='inquiry' AND ci.id=cpi.record_id WHERE cpi.investment_opportunity_id IN ($marks) AND ((cpi.record_type='lead' AND l.id IS NOT NULL) OR (cpi.record_type='inquiry' AND ci.id IS NOT NULL)) GROUP BY cpi.investment_opportunity_id");$crmDependencyStatement->execute($archivedIds);foreach($crmDependencyStatement->fetchAll() as $dependencyRow)$dependencyCounts[(int)$dependencyRow['investment_opportunity_id']]['crm']=(int)$dependencyRow['dependency_count'];}catch(Throwable $exception){error_log('Archived investment CRM dependency lookup failed: '.$exception->getMessage());}}

$statusLabels = [
    'draft' => 'Draft',
    'raising_capital' => 'Raising Capital',
    'funded' => 'Funded',
    'closed' => 'Closed',
    'archived' => 'Archived',
];
require __DIR__.'/_header.php';
?>
<div class="admin-page-head">
  <div>
    <h1>Investments</h1>
    
  </div>
  <?php if(!investments_only_role()):?><div class="admin-row-actions"><a class="primary" href="opportunity_edit.php">Add Investment</a></div><?php endif;?>
</div>

<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>

<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Status</th>
        <th>Location</th>
        <th>Structure</th>
        <th>Accepting Inquiries</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($activeRows as $row): ?>
        <?php
          $statusLabel = $statusLabels[$row['status']]
              ?? ucwords(str_replace('_', ' ', (string)$row['status']));
        ?>
        <tr>
          <td><strong><?= e($row['project_name']) ?></strong></td>
          <td>
            <span class="admin-investment-status status-<?= e($row['status']) ?>">
              <?= e($statusLabel) ?>
            </span>
          </td>
          <td><?= e($row['city'] . ', ' . $row['state']) ?></td>
          <td><?= e($row['investment_structure']) ?></td>
          <td><?= $row['accepting_inquiries'] ? 'Yes' : 'No' ?></td>
          <td>
            <div class="admin-row-actions"><a class="primary admin-small-button" href="opportunity.php?id=<?= (int)$row['id'] ?>">Open</a><?php if(!investments_only_role()):?><a class="secondary admin-small-button" href="opportunity_edit.php?id=<?= (int)$row['id'] ?>">Edit</a><?php endif;?><?php if(super_admin_role()):?><form method="post" onsubmit="return confirm('Archive this investment?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="opportunity_id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="archive"><button class="secondary admin-small-button">Archive</button></form><?php endif;?></div>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$activeRows): ?>
        <tr><td colspan="6">No investments found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if($archivedRows):?><section class="admin-archived-investments"><div class="admin-section-heading"><div><h2>Archived Projects</h2><p>Archived projects remain available for view-only access and stay separate from active investments.</p></div></div><div class="admin-archived-investment-grid"><?php foreach($archivedRows as $row):?><article class="admin-archived-investment-card"><div><span>Archived</span><h3><?=e($row['project_name'])?></h3><p><?=e(trim((string)($row['city']??'').((!empty($row['city'])&&!empty($row['state']))?', ':'').(string)($row['state']??'')))?></p></div><div class="admin-row-actions"><a class="primary admin-small-button" href="opportunity.php?id=<?=(int)$row['id']?>">View</a><?php if(!investments_only_role()):?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="opportunity_id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="restore"><button class="secondary admin-small-button">Restore</button></form><form method="post" onsubmit="return confirm('Permanently delete this investment? This cannot be undone.')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="opportunity_id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="delete"><button class="admin-danger-button admin-small-button">Delete</button></form><?php endif;?></div></article><?php endforeach;?></div></section><?php endif;?>

<?php if($archivedRows):$dependencyDisplay=[];foreach($archivedRows as $archivedRow){$counts=$dependencyCounts[(int)$archivedRow['id']]??[];$dependencyDisplay[]=['id'=>(int)$archivedRow['id'],'crm'=>(int)($counts['crm']??0),'inquiries'=>(int)($counts['inquiries']??0),'documents'=>(int)($counts['documents']??0)];}?>
<script>
(function(){var dependencies=<?=json_encode($dependencyDisplay,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;document.querySelectorAll('.admin-archived-investment-card').forEach(function(card,index){var item=dependencies[index];if(!item)return;var total=item.crm+item.inquiries+item.documents;var panel=document.createElement('div');panel.className='admin-archived-dependencies';var heading=document.createElement('strong');heading.textContent=total?'Deletion blocked by linked records':'Ready for permanent deletion';panel.appendChild(heading);[['crm',item.crm,'CRM relationship'+(item.crm===1?'':'s'),'contacts.php?project_id='+item.id],['inquiries',item.inquiries,'inquir'+(item.inquiries===1?'y':'ies'),'contacts.php?project_id='+item.id],['documents',item.documents,'document'+(item.documents===1?'':'s'),'opportunity.php?id='+item.id+'&tab=documents']].forEach(function(entry){var link=document.createElement('a');link.href=entry[3];link.textContent=entry[1]+' '+entry[2];panel.appendChild(link);});card.firstElementChild.appendChild(panel);});})();
</script><?php endif;?>
<?php require __DIR__ . '/_footer.php'; ?>
