<?php
require __DIR__ . '/_header.php';
date_default_timezone_set('America/Chicago');
$userName=trim((string)($user['name']??$user['full_name']??'Administrator'));$hour=(int)date('G');$greeting=$hour<12?'Good Morning':($hour<17?'Good Afternoon':'Good Evening');
$perPage=25;$all=[];
foreach(db()->query("SELECT 'Inquiry' record_type,id,full_name,email,phone,status,submitted_at,investment_amount,investment_opportunity_id FROM contact_inquiries WHERE status='new'")->fetchAll() as $r)$all[]=$r;
try{
  $leadSql="SELECT 'Lead' record_type,l.id,l.full_name,l.email,l.phone,l.status,l.submitted_at,cef.investment_amount,NULL investment_opportunity_id FROM leads l LEFT JOIN contact_extended_fields cef ON cef.record_type='lead' AND cef.record_id=l.id WHERE l.status='new'";
  foreach(db()->query($leadSql)->fetchAll() as $r)$all[]=$r;
}catch(Throwable $e){
  try{foreach(db()->query("SELECT 'Lead' record_type,id,full_name,email,phone,status,submitted_at,NULL investment_amount,NULL investment_opportunity_id FROM leads WHERE status='new'")->fetchAll() as $r)$all[]=$r;}catch(Throwable $fallbackError){error_log('Admin dashboard legacy lead query failed: '.$fallbackError->getMessage());}
}
usort($all,fn($a,$b)=>strcmp((string)$b['submitted_at'],(string)$a['submitted_at']));$total=count($all);$recent=$all;
$projectNames=[];
try{
  $rows=db()->query("SELECT cpi.record_type,cpi.record_id,o.project_name FROM contact_project_interests cpi JOIN investment_opportunities o ON o.id=cpi.investment_opportunity_id ORDER BY o.display_order,o.project_name")->fetchAll();
  foreach($rows as $pr)$projectNames[$pr['record_type'].':'.$pr['record_id']][]=$pr['project_name'];
}catch(Throwable $e){error_log('Admin dashboard project-interest lookup failed: '.$e->getMessage());}
$oppNames=[];try{foreach(db()->query('SELECT id,project_name FROM investment_opportunities')->fetchAll() as $o)$oppNames[(int)$o['id']]=$o['project_name'];}catch(Throwable $e){error_log('Admin dashboard investment lookup failed: '.$e->getMessage());}
?>
<div class="admin-page-head admin-greeting-head"><div><h1><?=e($greeting.', '.$userName)?></h1><p class="admin-dashboard-meta"><span data-admin-clock><?=e(date('g:i A'))?></span></p></div></div>
<section class="admin-dashboard-section"><div class="admin-section-heading"><div><h2>Inquiries in New Status</h2></div><div class="admin-inline-links"><a href="contacts.php">View All Contacts</a></div></div>
<div class="admin-table-wrap"><table class="admin-table admin-dashboard-activity-table"><thead><tr><th>Date</th><th>Name</th><th>Projects</th><th>Investment</th><th>Email</th><th>Phone</th></tr></thead><tbody>
<?php foreach($recent as $row):$isLead=$row['record_type']==='Lead';$recordType=$isLead?'lead':'inquiry';$href='contacts.php?expand=contact-'.$recordType.'-'.(int)$row['id'];$key=$recordType.':'.(int)$row['id'];$names=$projectNames[$key]??[];if(!$names&&!$isLead&&!empty($row['investment_opportunity_id'])&&isset($oppNames[(int)$row['investment_opportunity_id']]))$names=[$oppNames[(int)$row['investment_opportunity_id']]];?>
<tr class="admin-dashboard-row"><td class="admin-dashboard-date"><span><?=e(date('M j, Y',strtotime($row['submitted_at'])))?></span><small><?=e(date('g:i A',strtotime($row['submitted_at'])))?></small></td><td><a href="<?=e($href)?>"><strong><?=e($row['full_name'])?></strong></a></td><td><?php if($names):?><div class="admin-project-badges"><?php foreach($names as $name):?><span><?=e($name)?></span><?php endforeach;?></div><?php else:?>—<?php endif;?></td><td><strong><?=!empty($row['investment_amount'])?'$'.number_format((int)$row['investment_amount']):'TBD'?></strong></td><td><?=email_link($row['email'])?></td><td><?=phone_link($row['phone'])?></td></tr>
<?php endforeach;if(!$recent):?><tr><td colspan="6">No inquiries are currently in New status.</td></tr><?php endif;?></tbody></table></div>
<div class="admin-pagination-footer"><span id="dashboard-range"></span><nav class="admin-pagination" id="dashboard-pagination" aria-label="Dashboard records"></nav></div></section>
<script>(function(){const rows=[...document.querySelectorAll('.admin-dashboard-row')],perPage=25,pager=document.getElementById('dashboard-pagination'),range=document.getElementById('dashboard-range');let page=1;function render(){const pages=Math.max(1,Math.ceil(rows.length/perPage));page=Math.min(page,pages);rows.forEach((r,i)=>r.hidden=!(i>=(page-1)*perPage&&i<page*perPage));pager.innerHTML='';[['Previous',page-1,page===1],...Array.from({length:pages},(_,i)=>[String(i+1),i+1,false]),['Next',page+1,page===pages]].forEach(([label,target,disabled])=>{const b=document.createElement('button');b.type='button';b.textContent=label;b.disabled=disabled;if(target===page)b.classList.add('active');b.onclick=()=>{page=target;render()};pager.appendChild(b)});const start=rows.length?(page-1)*perPage+1:0,end=Math.min(page*perPage,rows.length);range.textContent='Showing '+start+'–'+end+' of '+rows.length;}render();const el=document.querySelector('[data-admin-clock]');if(!el)return;const tick=()=>el.textContent=new Intl.DateTimeFormat('en-US',{hour:'numeric',minute:'2-digit',second:'2-digit'}).format(new Date());tick();setInterval(tick,1000)})();</script>
<?php require __DIR__ . '/_footer.php'; ?>
