<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
$message='';$error='';

if(isset($_GET['export'])){
    header('Content-Type:text/csv');
    header('Content-Disposition:attachment; filename="znp-newsletter-subscribers.csv"');
    $o=fopen('php://output','w');
    fputcsv($o,['Name','Email','Status','Source','Subscribed','Unsubscribed','Notes']);
    foreach(db()->query('SELECT * FROM newsletter_subscribers ORDER BY created_at DESC') as $r){
        fputcsv($o,[$r['full_name'],$r['email'],$r['status'],$r['source'],$r['consent_at'],$r['unsubscribed_at'],$r['notes']]);
    }
    fclose($o);exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'&&csrf_check((string)($_POST['csrf_token']??''))){
    $action=(string)($_POST['action']??'save');
    try{
        if($action==='delete'){
            db()->prepare('DELETE FROM newsletter_subscribers WHERE id=?')->execute([(int)$_POST['id']]);
            $message='Subscriber deleted.';
        } elseif($action==='toggle_status') {
            $id=(int)($_POST['id']??0);
            $nextStatus=(string)($_POST['next_status']??'unsubscribed');
            if(!in_array($nextStatus,['active','unsubscribed'],true))throw new RuntimeException('Invalid subscriber status.');
            db()->prepare('UPDATE newsletter_subscribers SET status=?,unsubscribed_at=IF(?="unsubscribed",COALESCE(unsubscribed_at,NOW()),NULL) WHERE id=?')
                ->execute([$nextStatus,$nextStatus,$id]);
            $message=$nextStatus==='active'?'Subscriber enabled.':'Subscriber disabled.';
        } else {
            $id=(int)($_POST['id']??0);
            $email=strtolower(trim((string)($_POST['email']??'')));
            if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
            $name=trim((string)($_POST['full_name']??''));
            $status=in_array($_POST['status']??'active',['active','unsubscribed','suppressed'],true)?(string)$_POST['status']:'active';
            $source=trim((string)($_POST['source']??'admin'))?:'admin';
            $notes=trim((string)($_POST['notes']??''));
            $duplicate=db()->prepare('SELECT id FROM newsletter_subscribers WHERE LOWER(email)=? AND id<>? LIMIT 1');
            $duplicate->execute([$email,$id]);
            if($duplicate->fetchColumn())throw new RuntimeException('That email address already belongs to another subscriber.');
            if($id){
                db()->prepare('UPDATE newsletter_subscribers SET full_name=?,email=?,status=?,source=?,notes=?,unsubscribed_at=IF(?="unsubscribed",COALESCE(unsubscribed_at,NOW()),NULL) WHERE id=?')
                    ->execute([$name,$email,$status,$source,$notes,$status,$id]);
                $message='Subscriber updated.';
            }else{
                db()->prepare('INSERT INTO newsletter_subscribers(full_name,email,status,source,consent_at,unsubscribe_token,notes) VALUES(?,?,?,?,NOW(),?,?)')
                    ->execute([$name,$email,$status,$source,newsletter_token(),$notes]);
                $message='Subscriber added.';
            }
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$q=trim((string)($_GET['q']??''));$status=(string)($_GET['status']??'');$page=max(1,(int)($_GET['page']??1));$per=100;$where=[];$params=[];
if($q!==''){$where[]='(full_name LIKE ? OR email LIKE ?)';$params[]="%$q%";$params[]="%$q%";}
if(in_array($status,['active','unsubscribed','suppressed'],true)){$where[]='status=?';$params[]=$status;}
$sqlWhere=$where?' WHERE '.implode(' AND ',$where):'';
$c=db()->prepare('SELECT COUNT(*) FROM newsletter_subscribers'.$sqlWhere);$c->execute($params);$total=(int)$c->fetchColumn();
$s=db()->prepare('SELECT * FROM newsletter_subscribers'.$sqlWhere.' ORDER BY created_at DESC LIMIT '.$per.' OFFSET '.(($page-1)*$per));$s->execute($params);$rows=$s->fetchAll();
require __DIR__.'/_header.php';
?>
<div class="admin-page-head">
  <h1>Newsletter Subscribers</h1>
  <div class="admin-page-actions">
    <button type="button" class="primary" data-admin-modal-open="add-subscriber-modal">Add Subscriber</button>
    <a class="secondary button-link" href="newsletter_subscribers.php?export=1">Export CSV</a>
  </div>
</div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>

<div class="newsletter-subscriber-layout">
  <section class="settings-card newsletter-subscriber-list-card">
    <form method="get" class="newsletter-filter-row">
      <input name="q" value="<?=e($q)?>" placeholder="Search name or email">
      <select name="status"><option value="">All statuses</option><?php foreach(['active'=>'Active','unsubscribed'=>'Disabled','suppressed'=>'Suppressed'] as $v=>$l):?><option value="<?=$v?>" <?=$status===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select>
      <button class="secondary">Filter</button>
    </form>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Subscriber</th><th>Status</th><th>Source</th><th>Subscribed</th><th>Actions</th></tr></thead><tbody>
    <?php foreach($rows as $r):?>
      <tr>
        <td><strong><?=e($r['full_name']?:'—')?></strong><br><?=email_link($r['email'])?></td>
        <td><span class="newsletter-status-badge newsletter-status-<?=e((string)$r['status'])?>"><?=e($r['status']==='unsubscribed'?'Disabled':ucfirst((string)$r['status']))?></span></td>
        <td><span class="newsletter-source-badge"><?=e(ucwords(str_replace(['_','-'],' ',(string)($r['source']?:'Admin'))))?></span></td>
        <td><?=e($r['consent_at']?:$r['created_at'])?></td>
        <td class="newsletter-row-actions">
          <button type="button" class="secondary newsletter-edit-trigger"
            data-admin-modal-open="edit-subscriber-modal"
            data-id="<?=(int)$r['id']?>"
            data-name="<?=e((string)$r['full_name'])?>"
            data-email="<?=e((string)$r['email'])?>"
            data-status="<?=e((string)$r['status'])?>"
            data-source="<?=e((string)$r['source'])?>"
            data-notes="<?=e((string)$r['notes'])?>">Edit</button>
          <form method="post" class="newsletter-status-form">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input type="hidden" name="next_status" value="<?=$r['status']==='active'?'unsubscribed':'active'?>">
            <button class="newsletter-status-toggle <?=$r['status']==='active'?'newsletter-disable-button':'newsletter-enable-button'?>" type="submit"><?=$r['status']==='active'?'Disable':'Enable'?></button>
          </form>
          <form method="post" class="newsletter-delete-form" onsubmit="return confirm('Delete this subscriber?')">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>">
            <button class="newsletter-delete-button" type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach;?></tbody></table></div>
    <?php if(!$rows):?><p>No subscribers found.</p><?php endif;?>
    <?php if($total>$per):?><div class="admin-pagination newsletter-pagination"><?php for($i=1;$i<=ceil($total/$per);$i++):?><a class="<?=$i===$page?'active':''?>" href="?<?=http_build_query(['q'=>$q,'status'=>$status,'page'=>$i])?>"><?=$i?></a><?php endfor;?></div><?php endif;?>
  </section>
</div>

<div class="admin-modal" id="add-subscriber-modal" hidden>
  <div class="admin-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="add-subscriber-title">
    <header><h2 id="add-subscriber-title">Add Subscriber</h2><button type="button" data-admin-modal-close aria-label="Close">&times;</button></header>
    <div class="admin-modal-body">
      <form method="post" class="admin-form newsletter-subscriber-modal-form">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <label>Name<input name="full_name" autocomplete="name"></label>
        <label>Email<input type="email" name="email" autocomplete="email" required></label>
        <label>Status<select name="status"><option value="active">Active</option><option value="unsubscribed">Disabled</option><option value="suppressed">Suppressed</option></select></label>
        <label>Source<input name="source" value="admin"></label>
        <label>Notes<textarea name="notes" rows="4"></textarea></label>
        <div class="admin-modal-actions"><button type="button" class="secondary" data-admin-modal-close>Cancel</button><button class="primary">Add Subscriber</button></div>
      </form>
    </div>
  </div>
</div>

<div class="admin-modal" id="edit-subscriber-modal" hidden>
  <div class="admin-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="edit-subscriber-title">
    <header><h2 id="edit-subscriber-title">Edit Subscriber</h2><button type="button" data-admin-modal-close aria-label="Close">&times;</button></header>
    <div class="admin-modal-body">
      <form method="post" class="admin-form newsletter-subscriber-modal-form" id="edit-subscriber-form">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="">
        <label>Name<input name="full_name" autocomplete="name"></label>
        <label>Email<input type="email" name="email" autocomplete="email" required></label>
        <label>Status<select name="status"><option value="active">Active</option><option value="unsubscribed">Disabled</option><option value="suppressed">Suppressed</option></select></label>
        <label>Source<input name="source"></label>
        <label>Notes<textarea name="notes" rows="4"></textarea></label>
        <div class="admin-modal-actions"><button type="button" class="secondary" data-admin-modal-close>Cancel</button><button class="primary">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('click',function(event){
  var button=event.target.closest('.newsletter-edit-trigger');
  if(!button)return;
  var form=document.getElementById('edit-subscriber-form');
  form.elements.id.value=button.dataset.id||'';
  form.elements.full_name.value=button.dataset.name||'';
  form.elements.email.value=button.dataset.email||'';
  form.elements.status.value=button.dataset.status||'active';
  form.elements.source.value=button.dataset.source||'';
  form.elements.notes.value=button.dataset.notes||'';
});
</script>
<?php require __DIR__.'/_footer.php';?>
