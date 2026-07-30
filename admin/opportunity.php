<?php
require_once __DIR__.'/../includes/auth.php';require_admin();
$investmentViewer=investments_only_role();$id=(int)($_GET['id']??$_POST['entity_id']??0);$tab=(string)($_GET['tab']??($investmentViewer?'model':'overview'));$allowedTabs=$investmentViewer?['model','investors']:['overview','documents','budget','tasks','model','model-settings','investors'];if(!in_array($tab,$allowedTabs,true)){http_response_code(403);exit('This account may only access the Model and Investors tabs.');}
require_investment_access($id);
$stmt=db()->prepare('SELECT * FROM investment_opportunities WHERE id=?');$stmt->execute([$id]);$opportunity=$stmt->fetch();if(!$opportunity){http_response_code(404);exit('Investment not found.');}
$requestMethod=(string)($_SERVER['REQUEST_METHOD']??'GET');if($investmentViewer&&$requestMethod!=='GET'){http_response_code(403);exit('This account has read-only investment access.');}
$message='';$error='';$templateId=(int)($_GET['template']??0);$isNew=isset($_GET['new']);$template=$templateId?document_template_by_id($templateId,false):null;
if($template&&(int)$template['investment_opportunity_id']!==$id)$template=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!csrf_check($_POST['csrf_token']??''))$error='Your session expired.';else{
  $action=(string)($_POST['action']??'save');$templateId=(int)($_POST['template_id']??0);
  if($action==='delete'){
   $old=document_template_by_id($templateId,false);if(!$old||(int)$old['investment_opportunity_id']!==$id)$error='Document template not found.';else{db()->prepare('DELETE FROM document_templates WHERE id=?')->execute([$templateId]);if(!empty($old['template_path'])&&!app_delete_managed_file((string)$old['template_path'],['data/documents/projects']))error_log('Deleted investment document file could not be removed: '.$old['template_path']);header('Location:opportunity.php?id='.$id.'&tab=documents&deleted=1');exit;}
  }else{
   $name=trim((string)($_POST['document_name']??''));$type=trim((string)($_POST['document_type']??'document'));$subject=trim((string)($_POST['email_subject']??''));$body=trim((string)($_POST['email_body']??''));$version=trim((string)($_POST['template_version']??'1.0'));$active=isset($_POST['is_active'])?1:0;$requires=isset($_POST['requires_acceptance'])?1:0;
   $old=$templateId?document_template_by_id($templateId,false):null;if($old&&(int)$old['investment_opportunity_id']!==$id)$old=null;$path=$old['template_path']??null;$mime=$old['attachment_mime']??null;
   if($name===''||$subject===''||$body==='')$error='Document name, email subject, and email message are required.';
   $upload=uploaded_attachment('template_file',20971520);if(!$upload['ok'])$error=$upload['error'];
   if(!$error&&$upload['attachment']){$a=$upload['attachment'];if($requires&&$a['type']!=='application/pdf')$error='Documents requiring acceptance must use a PDF attachment.';else{$dir=__DIR__.'/../data/documents/projects';if(!is_dir($dir)&&!mkdir($dir,0775,true))$error='The document folder could not be created.';else{$ext=strtolower(pathinfo($a['name'],PATHINFO_EXTENSION));$filename='opportunity-'.$id.'-'.date('YmdHis').'-'.bin2hex(random_bytes(3)).'.'.$ext;if(file_put_contents($dir.'/'.$filename,$a['data'])===false)$error='The file could not be saved.';else{$path='data/documents/projects/'.$filename;$mime=$a['type'];}}}}
   if(!$error&&$requires&&$path&&$mime!=='application/pdf')$error='Documents requiring acceptance must use a PDF attachment.';
   if(!$error){
    if($templateId&&$old){db()->prepare('UPDATE document_templates SET document_type=?,document_name=?,template_path=?,attachment_mime=?,email_subject=?,email_body=?,template_version=?,requires_acceptance=?,is_active=? WHERE id=?')->execute([$type,$name,$path,$mime,$subject,$body,$version,$requires,$active,$templateId]);if(!empty($old['template_path'])&&$path!==$old['template_path']&&!app_delete_managed_file((string)$old['template_path'],['data/documents/projects']))error_log('Replaced investment document file could not be removed: '.$old['template_path']);}
    else{db()->prepare('INSERT INTO document_templates(project_id,investment_opportunity_id,document_type,document_name,template_path,attachment_mime,email_subject,email_body,template_version,requires_acceptance,is_active) VALUES(NULL,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$type,$name,$path,$mime,$subject,$body,$version,$requires,$active]);$templateId=(int)db()->lastInsertId();}
    header('Location:opportunity.php?id='.$id.'&tab=documents&saved=1&template='.$templateId);exit;
   }
  }
 }
}
if(isset($_GET['saved']))$message='Document saved successfully.';elseif(isset($_GET['deleted']))$message='Document deleted.';
$templates=document_templates_for_entity('opportunity',$id,false);if(!$template&&$templateId)$template=document_template_by_id($templateId,false);
$modelSettings=[];$modelSettingsReady=false;$constructionTimelineReady=false;$monthlyOccupancyReady=false;if(in_array($tab,['model','model-settings','investors'],true)){try{$modelSettingsReady=(bool)db()->query("SHOW TABLES LIKE 'investment_model_settings'")->fetchColumn();if($modelSettingsReady){$settingsStmt=db()->prepare('SELECT * FROM investment_model_settings WHERE investment_opportunity_id=?');$settingsStmt->execute([$id]);$modelSettings=$settingsStmt->fetch()?:[];}$constructionTimelineReady=(bool)db()->query("SHOW TABLES LIKE 'investment_construction_draw_settings'")->fetchColumn();if($constructionTimelineReady){$timelineStmt=db()->prepare('SELECT month_number,equity_spent_percent,loan_drawn_percent FROM investment_construction_draw_settings WHERE investment_opportunity_id=? ORDER BY month_number');$timelineStmt->execute([$id]);foreach($timelineStmt->fetchAll() as $timelineRow){$month=(int)$timelineRow['month_number'];$modelSettings['construction_equity_spent_month_'.$month]=$timelineRow['equity_spent_percent'];$modelSettings['construction_loan_drawn_month_'.$month]=$timelineRow['loan_drawn_percent'];}}$monthlyOccupancyReady=(bool)db()->query("SHOW TABLES LIKE 'investment_monthly_occupancy_settings'")->fetchColumn();if($monthlyOccupancyReady){$occupancyStmt=db()->prepare('SELECT year_number,month_number,occupancy_percent FROM investment_monthly_occupancy_settings WHERE investment_opportunity_id=? ORDER BY year_number,month_number');$occupancyStmt->execute([$id]);foreach($occupancyStmt->fetchAll() as $occupancyRow)$modelSettings['occupancy_year_'.(int)$occupancyRow['year_number'].'_month_'.(int)$occupancyRow['month_number']]=$occupancyRow['occupancy_percent'];}}catch(Throwable $exception){error_log('Investment model settings check failed for opportunity '.$id.': '.$exception->getMessage());}}
$usage=[];if($templates){$ids=array_map(static fn($r)=>(int)$r['id'],$templates);$marks=implode(',',array_fill(0,count($ids),'?'));$u=db()->prepare("SELECT template_id,COUNT(*) sent_count,SUM(status='accepted') accepted_count,SUM(status='sent') pending_count,MAX(sent_at) last_sent_at FROM document_deliveries WHERE template_id IN ($marks) GROUP BY template_id");$u->execute($ids);foreach($u->fetchAll() as $row)$usage[(int)$row['template_id']]=$row;}
require __DIR__.'/_header.php';
$statusLabels=['draft'=>'Draft','raising_capital'=>'Raising Capital','funded'=>'Funded','closed'=>'Closed','archived'=>'Archived'];
?>
<div class="admin-page-head"><div><h1><?=e($opportunity['project_name'])?></h1><p><?=e(trim(($opportunity['city']??'').', '.($opportunity['state']??''),', '))?> · <?=e($statusLabels[$opportunity['status']]??ucwords(str_replace('_',' ',(string)$opportunity['status'])))?></p></div><div class="admin-row-actions"><a class="secondary" href="opportunities.php">Back to Investments</a><?php if(!$investmentViewer):?><a class="secondary" href="opportunity_edit.php?id=<?=$id?>">Edit Project</a><?php endif;?></div></div>
<nav class="admin-project-tabs" aria-label="Investment sections"><?php if(!$investmentViewer):?><a class="<?=$tab==='overview'?'active':''?>" href="opportunity.php?id=<?=$id?>&tab=overview">Overview</a><a class="<?=$tab==='documents'?'active':''?>" href="opportunity.php?id=<?=$id?>&tab=documents">Documents</a><a class="<?=$tab==='budget'?'active':''?>" href="opportunity.php?id=<?=$id?>&tab=budget">Budget</a><a class="<?=$tab==='tasks'?'active':''?>" href="opportunity.php?id=<?=$id?>&tab=tasks">Tasks</a><?php endif;?><a class="<?=$tab==='model'?'active':''?>" href="opportunity.php?id=<?=$id?>&tab=model">Model</a><?php if(!$investmentViewer):?><a class="<?=$tab==='model-settings'?'active':''?>" href="opportunity.php?id=<?=$id?>&tab=model-settings">Model Settings</a><?php endif;?><a class="<?=$tab==='investors'?'active':''?>" href="opportunity.php?id=<?=$id?>&tab=investors">Investors</a></nav>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<?php if($tab==='overview'):?>
<section class="admin-project-overview-grid"><article class="admin-project-overview-card"><h2>Project Overview</h2><dl><div><dt>Asset Type</dt><dd><?=e((string)($opportunity['asset_type']?:'TBD'))?></dd></div><div><dt>Investment Structure</dt><dd><?=e((string)($opportunity['investment_structure']?:'TBD'))?></dd></div><div><dt><?=e((string)($opportunity['unit_label']?:'Units'))?></dt><dd><?=e((string)($opportunity['unit_count_text']?:'TBD'))?></dd></div><div><dt>Accepting Inquiries</dt><dd><?=$opportunity['accepting_inquiries']?'Yes':'No'?></dd></div></dl></article><article class="admin-project-overview-card"><h2>Workspace</h2><p>Use the tabs above to manage this investment. Documents, Model, Model Settings, and Investors are active now; Budget and Tasks are reserved for future releases.</p><a class="primary admin-small-button" href="opportunity.php?id=<?=$id?>&tab=model">Open Model</a></article></section>
<?php elseif($tab==='documents'):?>
<section class="admin-document-workspace admin-document-workspace-embedded">
<div class="admin-document-toolbar admin-document-toolbar-above"><a class="primary admin-new-template-button" href="?id=<?=$id?>&tab=documents&new=1">+ New File</a></div>
<?php if($templates):?><div class="admin-template-list"><?php foreach($templates as $row):$stat=$usage[(int)$row['id']]??[];$ext=strtolower(pathinfo((string)($row['template_path']??''),PATHINFO_EXTENSION));$icon=in_array($ext,['ppt','pptx'],true)?'📊':(in_array($ext,['xls','xlsx'],true)?'📈':(in_array($ext,['doc','docx'],true)?'📝':'📄'));?><a class="<?=($template&&(int)$template['id']===(int)$row['id'])?'active':''?>" href="?id=<?=$id?>&tab=documents&template=<?=(int)$row['id']?>"><strong><?=$icon?> <?=e($row['document_name'])?></strong><span>v<?=e($row['template_version'])?> · <?=((int)$row['is_active']===1)?'Active':'Inactive'?> · Sent <?=number_format((int)($stat['sent_count']??0))?><?php if((int)($row['requires_acceptance']??0)===1):?> · Accepted <?=number_format((int)($stat['accepted_count']??0))?> · Pending <?=number_format((int)($stat['pending_count']??0))?><?php endif;?></span></a><?php endforeach;?></div><?php else:?><div class="admin-empty-state">No documents have been added to this project yet.</div><?php endif;?>
<?php if($template||$isNew):?><form method="post" enctype="multipart/form-data" class="admin-form admin-document-template-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="entity_id" value="<?=$id?>"><input type="hidden" name="template_id" value="<?=(int)($template['id']??0)?>"><h3><?=$template?'Edit File':'New File'?></h3><label>Template Name</label><input name="document_name" value="<?=e($template['document_name']??'')?>" placeholder="Non-Disclosure Agreement or Investor Presentation" required><label>Document Type</label><select name="document_type"><option value="nda" <?=($template['document_type']??'')==='nda'?'selected':''?>>Non-Disclosure Agreement</option><option value="presentation" <?=($template['document_type']??'')==='presentation'?'selected':''?>>Investor Presentation</option><option value="project_overview" <?=($template['document_type']??'')==='project_overview'?'selected':''?>>Project Overview</option><option value="document" <?=($template['document_type']??'document')==='document'?'selected':''?>>Other Document</option></select><label>Template Version</label><input name="template_version" value="<?=e($template['template_version']??'1.0')?>" required><label>Email Subject</label><input name="email_subject" value="<?=e($template['email_subject']??($opportunity['project_name'].' - Project Information'))?>" required><label>Email Message</label><textarea name="email_body" rows="9" required><?=e($template['email_body']??"Hi {{name}},\n\nThank you for your interest in {{project}}. Please find the requested document attached.\n\nThank you,\nZNP Development")?></textarea><p class="admin-help-text">Available fields: <code>{{name}}</code>, <code>{{project}}</code>, and for acceptance documents <code>{{accept_link}}</code>.</p><label>Attachment</label><input type="file" name="template_file" accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx"><?php if(!empty($template['template_path'])):$fileName=basename((string)$template['template_path']);?><div class="admin-current-file"><span>Current file: <strong><?=e($fileName)?></strong></span><div><a class="secondary admin-small-button" href="document_file.php?id=<?=(int)$template['id']?>&mode=inline" target="_blank" rel="noopener">View</a><a class="secondary admin-small-button" href="document_file.php?id=<?=(int)$template['id']?>&mode=download">Download</a><button type="button" class="secondary admin-small-button" onclick="this.closest('form').querySelector('input[name=template_file]').click()">Replace</button></div></div><?php endif;?><label class="admin-check-row"><input type="checkbox" name="requires_acceptance" value="1" <?=((int)($template['requires_acceptance']??0)===1)?'checked':''?>> Include secure Accept Agreement link and track acceptance</label><label class="admin-check-row"><input type="checkbox" name="is_active" value="1" <?=!$template||(int)$template['is_active']===1?'checked':''?>> Active and available to send</label><button class="primary admin-save-button">Save Document</button></form><?php if($template):?><form method="post" class="admin-template-delete" onsubmit="return confirm('Delete this document template? Existing delivery history will remain.')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="entity_id" value="<?=$id?>"><input type="hidden" name="template_id" value="<?=(int)$template['id']?>"><button class="admin-danger-button">Delete Document</button></form><?php endif;?><?php endif;?></section>
<?php elseif($tab==='model'):?><section class="admin-investment-model"><?php if(!$modelSettingsReady):?><div class="status error">Model Settings are unavailable because the required database table is missing. Contact the system administrator.</div><?php endif;?><div class="waterfall-calculator" data-waterfall-calculator data-model-settings="<?=e(json_encode($modelSettings,JSON_UNESCAPED_SLASHES))?>"><p class="investor-model-loading">Loading investment model…</p></div></section>
<?php elseif($tab==='model-settings'):
$groups=[
 'waterfall'=>['title'=>'Waterfall','eyebrow'=>'Distribution Structure','description'=>'Set the preferred return and promote tiers used by the investor waterfall.','fields'=>[['cumulative_preferred_return_percent','Cumulative Preferred Return','%'],['tier1_lp_split','Tier 1 Investor Split','%'],['tier1_gp_split','Tier 1 GP Promote','%'],['tier2_lp_irr_hurdle','Tier 2 Investor IRR Hurdle','%'],['tier2_lp_split','Tier 2 Investor Split','%'],['tier2_gp_split','Tier 2 GP Promote','%']]],
 'project-costs'=>['title'=>'Project Costs','eyebrow'=>'Development Budget','description'=>'Define the base development cost, contingency, and project-level fees.','fields'=>[['total_development_costs','Total Development Costs','$'],['contingency_percent','Contingency','%'],['contingency_expected_use_percent','Expected Contingency Use','%'],['development_fee_percent','Development Fee','%'],['capital_fee_percent','Capital Raise Fee','%']]],
 'construction-loan'=>['title'=>'Construction Loan','eyebrow'=>'Capital Stack','description'=>'Configure leverage, pricing, amortization, and the interest-only period.','fields'=>[['bank_loan_percent','Bank Loan','%'],['bank_loan_equity_percent','Investor Equity','%'],['bank_loan_prime_rate','Prime Rate','%'],['bank_loan_spread','Loan Spread','%'],['bank_loan_amortization_years','Amortization','years'],['bank_loan_interest_only_months','Interest-Only Period','months']]],
 'operations'=>['title'=>'Operations','eyebrow'=>'Hold Assumptions','description'=>'Monthly gross operating income equals units × market rent, less vacancy loss on rent only, plus other income. Occupancy and annual rent growth are applied by the model.','fields'=>[['number_of_units','Number of Units','units'],['market_rent_monthly','Monthly Market Rent','$'],['other_income_monthly','Monthly Other Income','$'],['vacancy_loss_percent','Vacancy Loss on Rent','%'],['annual_rent_growth_percent','Annual Rent Growth','%'],['targeted_operating_expense_percent','Operating Expense Ratio','%'],['starting_cash_month','Starting Cash Month','month'],['starting_cash_amount','Starting Cash Amount','$'],['hold_period_years','Hold Period','years'],['first_distribution_month','First Distribution Month','month'],['holdback_start_month','Holdback Start Month','month'],['minimum_cash_reserve','Minimum Cash Reserve','$'],['year3_occupancy_percent','Year 3 Occupancy','%'],['year4_occupancy_percent','Year 4 Occupancy','%'],['year5_occupancy_percent','Year 5 Occupancy','%'],['year6_occupancy_percent','Year 6 Occupancy','%']]],
 'refinance'=>['title'=>'Refinance','eyebrow'=>'Permanent Financing','description'=>'Control the timing, proceeds, costs, pricing, and terms of the refinance.','fields'=>[['refinance_month','Refinance Month','month'],['refinance_loan_amount','Loan Amount','$'],['refinance_cost','Refinance Cost','$'],['refinance_interest_rate','Interest Rate','%'],['refinance_amortization_years','Amortization','years'],['refinance_interest_only_months','Interest-Only Period','months'],['refinance_term_years','Loan Term','years']]],
 'selling'=>['title'=>'Selling','eyebrow'=>'Exit Assumptions','description'=>'Set the capitalization rate and transaction cost used at disposition.','fields'=>[['exit_cap_rate','Exit Cap Rate','%'],['commission_percent','Sale Commission','%']]]
];
$equityDefaults=[25,50,75,100,100,100,100,100,100,100,100,100];
$loanDefaults=[0,0,0,0,12.5,25,37.5,50,62.5,75,87.5,100];
?>
<section class="admin-model-settings" data-model-settings data-project-id="<?=$id?>" data-csrf="<?=e(csrf_token())?>">
 <header class="admin-model-settings-hero">
  <div>
   <span class="admin-model-settings-kicker">Investment Model</span>
   <h2>Model Settings</h2>
   <p>Manage the assumptions unique to <strong><?=e($opportunity['project_name'])?></strong>. Changes save automatically.</p>
  </div>
  <div class="admin-model-settings-hero-actions">
   <span class="admin-model-autosave-pill"><i aria-hidden="true"></i>Autosave enabled</span>
   <a class="secondary admin-small-button" href="opportunity.php?id=<?=$id?>&tab=model">Open Model</a>
  </div>
 </header>
 <?php if(!$modelSettingsReady):?><div class="status error">Model Settings are unavailable because the required database table is missing. Contact the system administrator.</div><?php endif;?>
 <?php if(!$constructionTimelineReady):?><div class="status error">Construction timeline values are using the example defaults. A Super Admin must run the Construction Timeline installer before these values can be saved.</div><?php endif;?>
 <nav class="admin-model-settings-nav" aria-label="Model Settings sections">
  <a href="#settings-waterfall">Waterfall</a>
  <a href="#settings-project-costs">Project Costs</a>
  <a href="#settings-construction-loan">Construction Loan</a>
  <a href="#settings-operations">Operations</a>
  <a href="#settings-refinance">Refinance</a>
  <a href="#settings-selling">Selling</a>
  <a href="#settings-occupancy">Occupancy</a>
  <a href="#settings-construction">Timeline</a>
 </nav>
 <div class="admin-model-settings-grid">
  <?php $sectionNumber=0;foreach($groups as $groupId=>$group):$sectionNumber++;?>
  <section class="admin-model-settings-card" id="settings-<?=e($groupId)?>" <?=$modelSettingsReady?'':'inert'?>>
   <header class="admin-model-settings-card-head">
    <span><?=str_pad((string)$sectionNumber,2,'0',STR_PAD_LEFT)?></span>
    <div><small><?=e($group['eyebrow'])?></small><h3><?=e($group['title'])?></h3><p><?=e($group['description'])?></p></div>
   </header>
   <div class="admin-model-settings-fields">
    <?php foreach($group['fields'] as [$name,$label,$suffix]):$whole=in_array($name,['bank_loan_interest_only_months','number_of_units','starting_cash_month','first_distribution_month','holdback_start_month','refinance_month','refinance_interest_only_months'],true);?>
    <label class="admin-model-setting">
     <span><?=e($label)?></span>
     <span class="admin-model-input-wrap"><?php if($suffix==='$'):?><b>$</b><?php endif;?><input data-autosave type="number" min="0" <?=$whole?'step="1"':'step="0.01"'?> name="<?=e($name)?>" value="<?=e(isset($modelSettings[$name])?(string)$modelSettings[$name]:'')?>" autocomplete="off" <?=$modelSettingsReady?'':'disabled'?>><?php if($suffix!=='$'):?><em><?=e($suffix)?></em><?php endif;?></span>
     <small class="admin-model-save-status" data-save-status></small>
    </label>
    <?php endforeach;?>
   </div>
  </section>
 <?php endforeach;?>
 </div>
 <?php if(!$monthlyOccupancyReady):?><div class="status error admin-model-settings-upgrade-note">Monthly Year 1 and Year 2 occupancy values currently fall back to the existing annual percentages. A Super Admin must run the Monthly Occupancy installer before monthly values can be saved.</div><?php endif;?>
 <section class="admin-model-settings-card admin-occupancy-settings" id="settings-occupancy" <?=$monthlyOccupancyReady?'':'inert'?>>
  <header class="admin-model-settings-card-head">
   <span>07</span>
   <div><small>24-Month Lease-Up</small><h3>Monthly Occupancy Schedule</h3><p>Monthly gross income equals the 100%-occupancy gross income assumption multiplied by each month’s occupancy.</p></div>
  </header>
  <div class="admin-occupancy-years">
   <?php for($occupancyYear=1;$occupancyYear<=2;$occupancyYear++):$annualDefault=(float)($modelSettings['year'.$occupancyYear.'_occupancy_percent']??($occupancyYear===1?50:95));?>
   <section class="admin-occupancy-year">
    <header><span>Lease-Up</span><h4>Year <?=$occupancyYear?></h4></header>
    <div class="admin-occupancy-months">
     <?php for($occupancyMonth=1;$occupancyMonth<=12;$occupancyMonth++):$occupancyName='occupancy_year_'.$occupancyYear.'_month_'.$occupancyMonth;?>
     <label class="admin-model-setting"><span>Month <?=$occupancyMonth?></span><span class="admin-model-input-wrap"><input data-autosave type="number" min="0" max="100" step="0.01" name="<?=e($occupancyName)?>" value="<?=e(isset($modelSettings[$occupancyName])?(string)$modelSettings[$occupancyName]:(string)$annualDefault)?>" autocomplete="off" <?=$monthlyOccupancyReady?'':'disabled'?>><em>%</em></span><small class="admin-model-save-status" data-save-status></small></label>
     <?php endfor;?>
    </div>
   </section>
   <?php endfor;?>
  </div>
 </section>
 <section class="admin-model-settings-card admin-construction-settings" id="settings-construction" <?=$constructionTimelineReady?'':'inert'?>>
  <header class="admin-model-settings-card-head admin-construction-settings-head">
   <span>08</span>
   <div><small>12-Month Draw Schedule</small><h3>Construction Timeline</h3><p>Enter cumulative equity spent and construction loan drawn through each month.</p></div>
  </header>
  <div class="admin-construction-summary" aria-label="Construction timeline summary"><span><strong>Month 4</strong>Equity fully deployed</span><span><strong>Month 5</strong>Loan draws begin</span><span><strong>Month 12</strong>Loan fully drawn</span></div>
  <div class="admin-construction-months">
   <?php for($month=1;$month<=12;$month++):$equityName='construction_equity_spent_month_'.$month;$loanName='construction_loan_drawn_month_'.$month;?>
   <article class="admin-construction-month">
    <header><strong>Month <?=$month?></strong><span class="<?=$month<=4?'equity':'debt'?>"><?=$month<=4?'Equity phase':'Debt phase'?></span></header>
    <?php foreach([[$equityName,'Equity spent',$equityDefaults[$month-1]],[$loanName,'Loan drawn',$loanDefaults[$month-1]]] as [$name,$label,$default]):?>
    <label class="admin-model-setting"><span><?=e($label)?></span><span class="admin-model-input-wrap"><input data-autosave type="number" min="0" max="100" step="0.01" name="<?=e($name)?>" value="<?=e(isset($modelSettings[$name])?(string)$modelSettings[$name]:(string)$default)?>" autocomplete="off" <?=$constructionTimelineReady?'':'disabled'?>><em>%</em></span><small class="admin-model-save-status" data-save-status></small></label>
    <?php endforeach;?>
   </article>
   <?php endfor;?>
  </div>
 </section>
</section>
<?php elseif($tab==='investors'):?>
<section class="admin-investor-structure" data-investor-structure data-project-id="<?=$id?>" data-csrf="<?=e(csrf_token())?>" <?=$investmentViewer?'data-read-only="1"':''?>>
 <header class="admin-investor-hero">
  <div><span>Project Capitalization</span><h2>Investors &amp; Structure</h2><p><?=$investmentViewer?'View':'Manage'?> the GP ownership structure and LP unit subscriptions for <?=e($opportunity['project_name'])?>.</p></div>
 </header>
 <div class="admin-investor-alert" data-investor-alert hidden></div>
 <div class="admin-investor-metrics">
  <article><span>GP Ownership</span><strong data-gp-percent>—</strong><small>Allocated</small></article>
  <article><span>GP Remaining</span><strong data-gp-remaining>—</strong><small>Available to allocate</small></article>
  <article><span>Units Subscribed</span><strong data-lp-subscribed>—</strong><small>LP commitments</small></article>
  <article><span>Units Still to Raise</span><strong data-lp-remaining>—</strong><small>Remaining LP raise</small></article>
 </div>
 <div class="admin-investor-columns">
  <section class="admin-investor-panel">
   <header><div><span>General Partner</span><h3>GP Ownership</h3><p>Ownership percentages across all GP members must total no more than 100%.</p></div><?php if(!$investmentViewer):?><button class="primary admin-small-button" type="button" data-add-investor="GP">+ Add GP</button><?php endif;?></header>
   <div class="admin-investor-progress"><i data-gp-progress></i></div>
   <div class="admin-investor-list" data-gp-list><div class="admin-investor-empty">Loading GP structure…</div></div>
  </section>
  <section class="admin-investor-panel">
   <header><div><span>Limited Partners</span><h3>LP Unit Subscriptions</h3><p>One LP unit represents one dollar of subscribed investment.</p></div><?php if(!$investmentViewer):?><button class="primary admin-small-button" type="button" data-add-investor="LP">+ Add LP</button><?php endif;?></header>
   <label class="admin-investor-unit-setting"><span>Total LP Units Available</span><span><b>$</b><input type="number" min="0.01" step="0.01" data-lp-total-units autocomplete="off" <?=$investmentViewer?'disabled':''?>><?php if(!$investmentViewer):?><button class="secondary admin-small-button" type="button" data-save-units>Save</button><?php endif;?></span><small data-unit-status></small></label>
   <div class="admin-investor-list" data-lp-list><div class="admin-investor-empty">Loading LP subscriptions…</div></div>
  </section>
 </div>
 <dialog class="admin-investor-dialog" data-investor-dialog>
  <form data-investor-form>
   <header><div><span data-dialog-eyebrow>Investor</span><h3 data-dialog-title>Add Investor</h3></div><button type="button" aria-label="Close" data-close-investor>×</button></header>
   <div class="admin-investor-form-error" data-investor-form-error hidden></div>
   <input type="hidden" name="investor_type" data-investor-type>
   <input type="hidden" name="investor_id" data-investor-id>
   <div class="admin-investor-form-grid">
    <label><span>First Name</span><input name="first_name" maxlength="100" required autocomplete="given-name"></label>
    <label><span>Last Name</span><input name="last_name" maxlength="100" required autocomplete="family-name"></label>
    <label data-gp-field><span>GP Ownership Percentage</span><span class="admin-investor-input-unit"><input type="number" name="ownership_percent" min="0.0001" max="100" step="0.0001"><em>%</em></span></label>
    <label data-gp-field><span>Investment Amount</span><span class="admin-investor-input-unit"><b>$</b><input type="number" name="investment_amount" min="0" step="0.01"></span></label>
    <label data-lp-field hidden><span>Unit Amount</span><span class="admin-investor-input-unit"><b>$</b><input type="number" name="unit_amount" min="0.01" step="0.01"></span><small>One unit equals one dollar.</small></label>
   </div>
   <footer><button class="secondary" type="button" data-close-investor>Cancel</button><button class="primary" type="submit" data-submit-investor>Add Investor</button></footer>
  </form>
 </dialog>
 <div data-waterfall-projection-engine data-model-settings="<?=e(json_encode($modelSettings,JSON_UNESCAPED_SLASHES))?>" hidden></div>
 <dialog class="admin-investor-projection-dialog" data-investor-projection-dialog>
  <header><div><span data-projection-eyebrow>Investor Projection</span><h3 data-projection-title>Year-by-Year Plan</h3><p data-projection-subtitle></p></div><button type="button" aria-label="Close projection" data-close-projection>×</button></header>
  <div data-projection-content></div>
  <footer><button class="secondary" type="button" data-close-projection>Close</button></footer>
 </dialog>
</section>
<?php else:?><section class="admin-coming-soon"><span><?=e(ucfirst($tab))?></span><h2><?=e(ucfirst($tab))?> workspace</h2><p>This section is reserved for a future update.</p></section><?php endif;?>
<?php if(in_array($tab,['model','investors'],true)):?><script src="../assets/admin-waterfall-calculator.js?v=20260723-604"></script><?php endif;?>
<?php if($tab==='model-settings'):?><script src="../assets/admin-model-settings.js?v=20260723-536"></script><?php endif;?>
<?php if($tab==='investors'):?><script src="../assets/admin-investors.js?v=20260723-600"></script><?php endif;?>
<?php require __DIR__.'/_footer.php';?>
