<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
$message=isset($_GET['deleted'])?'Newsletter campaign and its delivery history were deleted.':'';
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'&&(string)($_POST['action']??'')==='delete'){
    if(!super_admin_role()){http_response_code(403);exit('Only a Super Admin may delete newsletter campaigns.');}
    if(!csrf_check((string)($_POST['csrf_token']??'')))$error='Your session expired. Refresh and try again.';
    else try{
        $campaignId=(int)($_POST['campaign_id']??0);
        $check=db()->prepare('SELECT id FROM newsletter_campaigns WHERE id=?');$check->execute([$campaignId]);
        if(!$check->fetchColumn())throw new RuntimeException('Campaign not found.');
        db()->beginTransaction();
        db()->prepare('DELETE FROM newsletter_deliveries WHERE campaign_id=?')->execute([$campaignId]);
        db()->prepare('DELETE FROM newsletter_campaigns WHERE id=?')->execute([$campaignId]);
        db()->commit();
        header('Location: newsletter_campaigns.php?deleted=1');exit;
    }catch(Throwable $exception){
        if(db()->inTransaction())db()->rollBack();
        error_log('Newsletter campaign deletion failed: '.$exception->getMessage());
        $error='The campaign could not be deleted: '.$exception->getMessage();
    }
}
$rows=db()->query("SELECT c.*,COUNT(d.id) total,SUM(d.status='accepted') accepted,SUM(d.status='failed') failed,SUM(d.opened_at IS NOT NULL) opened,SUM(d.clicked_at IS NOT NULL) clicked FROM newsletter_campaigns c LEFT JOIN newsletter_deliveries d ON d.campaign_id=c.id GROUP BY c.id ORDER BY c.created_at DESC")->fetchAll();
require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><h1>Newsletter Campaigns</h1><a class="primary button-link" href="newsletter_campaign_edit.php">New Campaign</a></div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<div class="settings-card"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Campaign</th><th>Status</th><th>Recipients</th><th>Accepted</th><th>Failed</th><th>Opened</th><th>Clicked</th><th></th></tr></thead><tbody>
<?php foreach($rows as $row):?><tr><td><strong><?=e($row['name'])?></strong><br><small><?=e($row['subject'])?></small></td><td><?=e(ucfirst($row['status']))?></td><td><?=(int)$row['total']?></td><td><?=(int)$row['accepted']?></td><td><?=(int)$row['failed']?></td><td><?=(int)$row['opened']?></td><td><?=(int)$row['clicked']?></td><td><div class="admin-row-actions"><a class="secondary button-link" href="newsletter_campaign_edit.php?id=<?=(int)$row['id']?>">Open</a><?php if(super_admin_role()):?><form method="post" onsubmit="return confirm('Permanently delete this campaign and all of its delivery history? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="campaign_id" value="<?=(int)$row['id']?>"><button class="admin-danger-button admin-small-button" type="submit">Delete</button></form><?php endif;?></div></td></tr><?php endforeach;?>
</tbody></table></div><?php if(!$rows):?><p>No campaigns yet.</p><?php endif;?></div>
<?php require __DIR__.'/_footer.php';?>
