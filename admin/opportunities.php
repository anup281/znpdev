<?php
require __DIR__ . '/_header.php';

$view = (string)($_GET['view'] ?? 'active');
if (!in_array($view, ['active','archived'], true)) $view = 'active';
$message = isset($_GET['saved']) ? 'Investment opportunity saved successfully.' : '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) $error = 'Your session expired. Refresh and try again.';
    else {
        $action = (string)($_POST['action'] ?? '');
        $opportunityId = (int)($_POST['opportunity_id'] ?? 0);
        if ($action === 'archive' || $action === 'restore') {
            $newStatus = $action === 'archive' ? 'archived' : 'draft';
            db()->prepare('UPDATE investment_opportunities SET status=?, is_visible=0, accepting_inquiries=0 WHERE id=?')->execute([$newStatus, $opportunityId]);
            header('Location: opportunities.php?view='.($action === 'archive' ? 'active' : 'archived').'&'.($action === 'archive' ? 'archived' : 'restored').'=1'); exit;
        }
        if ($action === 'delete') {
            $checks = [
                ['contact_project_interests','investment_opportunity_id'],
                ['document_templates','investment_opportunity_id'],
                ['contact_inquiries','investment_opportunity_id']
            ];
            $linked = 0;
            foreach ($checks as [$table,$column]) { try { $q=db()->prepare("SELECT COUNT(*) FROM $table WHERE $column=?"); $q->execute([$opportunityId]); $linked += (int)$q->fetchColumn(); } catch (Throwable $e) {} }
            if ($linked > 0) $error = 'This opportunity has linked contacts, inquiries, or documents. Archive it instead of deleting it.';
            else { db()->prepare('DELETE FROM investment_opportunities WHERE id=?')->execute([$opportunityId]); header('Location: opportunities.php?deleted=1'); exit; }
        }
    }
}
if (isset($_GET['archived'])) $message='Investment opportunity archived.';
if (isset($_GET['restored'])) $message='Investment opportunity restored.';
if (isset($_GET['deleted'])) $message='Investment opportunity deleted.';
$where = $view === 'archived' ? "status='archived'" : "status<>'archived'";
$rows = db()->query("SELECT * FROM investment_opportunities WHERE $where ORDER BY display_order, project_name")->fetchAll();

$statusLabels = [
    'draft' => 'Draft',
    'raising_capital' => 'Raising Capital',
    'funded' => 'Funded',
    'closed' => 'Closed',
    'archived' => 'Archived',
];
?>
<div class="admin-page-head">
  <div>
    <h1>Investment Opportunities</h1>
    
  </div>
  <div class="admin-row-actions"><a class="secondary" href="opportunities.php?view=<?=$view==='archived'?'active':'archived'?>"><?=$view==='archived'?'Active Opportunities':'Archived Opportunities'?></a><?php if($view==='active'):?><a class="primary" href="opportunity_edit.php">Add Opportunity</a><?php endif;?></div>
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
      <?php foreach ($rows as $row): ?>
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
            <div class="admin-row-actions"><a class="primary admin-small-button" href="opportunity.php?id=<?= (int)$row['id'] ?>">Open</a><a class="secondary admin-small-button" href="opportunity_edit.php?id=<?= (int)$row['id'] ?>">Edit</a><?php if($view==='active'):?><form method="post" onsubmit="return confirm('Archive this investment opportunity?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="opportunity_id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="archive"><button class="secondary admin-small-button">Archive</button></form><?php else:?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="opportunity_id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="restore"><button class="secondary admin-small-button">Restore</button></form><form method="post" onsubmit="return confirm('Permanently delete this investment opportunity? This cannot be undone.')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="opportunity_id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="delete"><button class="admin-danger-button admin-small-button">Delete</button></form><?php endif;?></div>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$rows): ?>
        <tr><td colspan="6">No investment opportunities found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
