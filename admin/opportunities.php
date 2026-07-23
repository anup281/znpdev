<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
$message = isset($_GET['saved']) ? 'Investment saved successfully.' : '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) $error = 'Your session expired. Refresh and try again.';
    else {
        $action = (string)($_POST['action'] ?? '');
        $opportunityId = (int)($_POST['opportunity_id'] ?? 0);
        if ($action === 'archive' || $action === 'restore') {
            $newStatus = $action === 'archive' ? 'archived' : 'draft';
            $statusColumn=db()->query("SHOW COLUMNS FROM investment_opportunities LIKE 'status'")->fetch();
            if($action==='archive'&&stripos((string)($statusColumn['Type']??''),"'archived'")===false)$error='Run the Investment Archive database upgrade before archiving another project.';
            else{db()->prepare('UPDATE investment_opportunities SET status=?, is_visible=0, accepting_inquiries=0 WHERE id=?')->execute([$newStatus,$opportunityId]);header('Location: opportunities.php?'.($action==='archive'?'archived':'restored').'=1');exit;}
        }
        if ($action === 'delete') {
            $statusQuery=db()->prepare('SELECT status,is_visible,accepting_inquiries FROM investment_opportunities WHERE id=?');$statusQuery->execute([$opportunityId]);$deleteRow=$statusQuery->fetch();
            $legacyArchive=is_array($deleteRow)&&(string)$deleteRow['status']===''&&(int)$deleteRow['is_visible']===0&&(int)$deleteRow['accepting_inquiries']===0;
            if(!is_array($deleteRow)||((string)$deleteRow['status']!=='archived'&&!$legacyArchive)){$error='Only archived investments may be permanently deleted.';}
            $checks = [
                ['contact_project_interests','investment_opportunity_id'],
                ['document_templates','investment_opportunity_id'],
                ['contact_inquiries','investment_opportunity_id']
            ];
            $linked = 0;
            foreach ($checks as [$table,$column]) { try { $q=db()->prepare("SELECT COUNT(*) FROM $table WHERE $column=?"); $q->execute([$opportunityId]); $linked += (int)$q->fetchColumn(); } catch (Throwable $e) {} }
            if(!$error&&$linked > 0)$error = 'This investment has linked contacts, inquiries, or documents. It cannot be permanently deleted.';
            elseif(!$error){db()->prepare('DELETE FROM investment_opportunities WHERE id=?')->execute([$opportunityId]);header('Location: opportunities.php?deleted=1');exit;}
        }
    }
}
if (isset($_GET['archived'])) $message='Investment archived.';
if (isset($_GET['restored'])) $message='Investment restored.';
if (isset($_GET['deleted'])) $message='Investment deleted.';
$archiveCondition="(status='archived' OR (status='' AND is_visible=0 AND accepting_inquiries=0))";
$activeRows=db()->query("SELECT * FROM investment_opportunities WHERE NOT $archiveCondition ORDER BY display_order,project_name")->fetchAll();
$archivedRows=db()->query("SELECT * FROM investment_opportunities WHERE $archiveCondition ORDER BY project_name")->fetchAll();

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
  <div class="admin-row-actions"><a class="primary" href="opportunity_edit.php">Add Investment</a></div>
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
            <div class="admin-row-actions"><a class="primary admin-small-button" href="opportunity.php?id=<?= (int)$row['id'] ?>">Open</a><a class="secondary admin-small-button" href="opportunity_edit.php?id=<?= (int)$row['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Archive this investment?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="opportunity_id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="archive"><button class="secondary admin-small-button">Archive</button></form></div>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$activeRows): ?>
        <tr><td colspan="6">No investments found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if($archivedRows):?><section class="admin-archived-investments"><div class="admin-section-heading"><div><h2>Archived Projects</h2><p>Archived projects are hidden from the public website and active investment workspace.</p></div></div><div class="admin-archived-investment-grid"><?php foreach($archivedRows as $row):?><article class="admin-archived-investment-card"><div><span>Archived</span><h3><?=e($row['project_name'])?></h3><p><?=e(trim((string)($row['city']??'').((!empty($row['city'])&&!empty($row['state']))?', ':'').(string)($row['state']??'')))?></p></div><div class="admin-row-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="opportunity_id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="restore"><button class="secondary admin-small-button">Restore</button></form><form method="post" onsubmit="return confirm('Permanently delete this investment? This cannot be undone.')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="opportunity_id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="delete"><button class="admin-danger-button admin-small-button">Delete</button></form></div></article><?php endforeach;?></div></section><?php endif;?>

<?php require __DIR__ . '/_footer.php'; ?>
