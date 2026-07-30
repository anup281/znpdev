<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image_optimizer.php';
require_admin();
$id=(int)($_GET['id']??0);$error='';
$row=['project_name'=>'','portfolio_category'=>'under_development','city'=>'','state'=>'Texas','status'=>'Under Development','project_value'=>'','asset_type'=>'','project_type'=>'','year_completed_or_expected'=>'','units_or_keys'=>'','primary_image'=>'','display_order'=>0,'is_visible'=>1,'is_homepage_featured'=>0,'portfolio_status'=>''];
$categoryOptions=['under_development'=>'Under Development','commercial'=>'Commercial','residential'=>'Residential'];
$portfolioStatusOptions=[''=>'Select status','Owned and Managed'=>'Owned and Managed','Owned and Third Party Managed'=>'Owned and Third Party Managed','Sold'=>'Sold','Under Construction'=>'Under Construction','Under Management'=>'Under Management'];
$statusOptions=['Planned'=>'Planned','Under Development'=>'Under Development','Completed'=>'Completed','On Hold'=>'On Hold','Cancelled'=>'Cancelled'];
$assetOptions=['Hotel','Multifamily','Townhome Community','Residential','Commercial','Mixed Use','Land Development'];$projectTypeOptions=['Ground-Up Development','Renovation','Brand Conversion','Acquisition','Redevelopment'];try{foreach(db()->query("SELECT DISTINCT asset_type FROM projects WHERE asset_type IS NOT NULL AND asset_type<>'' ORDER BY asset_type")->fetchAll(PDO::FETCH_COLUMN) as $v)if(!in_array($v,$assetOptions,true))$assetOptions[]=$v;foreach(db()->query("SELECT DISTINCT project_type FROM projects WHERE project_type IS NOT NULL AND project_type<>'' ORDER BY project_type")->fetchAll(PDO::FETCH_COLUMN) as $v)if(!in_array($v,$projectTypeOptions,true))$projectTypeOptions[]=$v;}catch(Throwable $e){error_log('Project option lookup failed: '.$e->getMessage());}
if($id){$st=db()->prepare('SELECT * FROM projects WHERE id=?');$st->execute([$id]);$row=$st->fetch()?:$row;}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!csrf_check($_POST['csrf_token']??'')){$error='Your session expired. Refresh and try again.';}else{
  $name=trim((string)($_POST['project_name']??''));$cat=(string)($_POST['portfolio_category']??'');$status=trim((string)($_POST['status']??''));$img=trim((string)($_POST['existing_primary_image']??''));$oldImg=trim((string)($row['primary_image']??''));
  if($name==='')$error='Project name is required.'; elseif(!isset($categoryOptions[$cat]))$error='Select a valid project category.'; elseif(!isset($statusOptions[$status]))$error='Select a valid project status.';
  if(!$error&&isset($_FILES['primary_image'])&&$_FILES['primary_image']['error']!==UPLOAD_ERR_NO_FILE){
   try {
    $folder=$cat==='under_development'?'under-development':$cat;
    $relativeDir='assets/images/projects/'.$folder;
    $img=znp_save_optimized_image($_FILES['primary_image'], __DIR__.'/../'.$relativeDir, $relativeDir, $name, 1920);
   } catch (Throwable $uploadError) {
    $error=$uploadError->getMessage();
   }
  }
  if(!$error){$val=preg_replace('/[^0-9.]/','',(string)($_POST['project_value']??''));$val=$val===''?null:(float)$val;$portfolioStatus=trim((string)($_POST['portfolio_status']??''));
   $vals=[$name,$cat,trim((string)($_POST['city']??'')),trim((string)($_POST['state']??'')),$status,$val,trim((string)($_POST['asset_type']??'')),trim((string)($_POST['project_type']??'')),trim((string)($_POST['year_completed_or_expected']??'')),trim((string)($_POST['units_or_keys']??'')),$portfolioStatus?:null,$img?:null,(int)($_POST['display_order']??0),isset($_POST['is_visible'])?1:0,isset($_POST['is_homepage_featured'])?1:0];
   if($id){$vals[]=$id;$sql='UPDATE projects SET project_name=?,portfolio_category=?,city=?,state=?,status=?,project_value=?,asset_type=?,project_type=?,year_completed_or_expected=?,units_or_keys=?,portfolio_status=?,primary_image=?,display_order=?,is_visible=?,is_homepage_featured=? WHERE id=?';}
   else{$sql='INSERT INTO projects (project_name,portfolio_category,city,state,status,project_value,asset_type,project_type,year_completed_or_expected,units_or_keys,portfolio_status,primary_image,display_order,is_visible,is_homepage_featured) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';}
   db()->prepare($sql)->execute($vals);if($oldImg!==''&&$img!==$oldImg&&!app_delete_managed_file($oldImg,['assets/images/projects']))error_log('Replaced project image could not be removed: '.$oldImg);header('Location: projects.php?saved=1'); exit;
  }
 }
}
require __DIR__ . '/_header.php';
?>
<div class="admin-page-head"><div><h1><?=$id?'Edit':'Add'?> Project</h1><p>Update project details and replace the public project image.</p></div><a class="secondary" href="projects.php">Back to Projects</a></div>
<?php if($error): ?><div class="status error"><?=e($error)?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="admin-form admin-form-wide"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="existing_primary_image" value="<?=e((string)$row['primary_image'])?>">
<div class="admin-form-grid"><div><label>Project Name *</label><input name="project_name" required value="<?=e((string)$row['project_name'])?>"></div><div><label>Category *</label><select name="portfolio_category"><?php foreach($categoryOptions as $v=>$l):?><option value="<?=e($v)?>" <?=$row['portfolio_category']===$v?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div></div>
<div class="admin-form-grid"><div><label>Project Status *</label><select name="status" required><?php foreach($statusOptions as $v=>$l):?><option value="<?=e($v)?>" <?=$row['status']===$v?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div><div><label>City</label><input name="city" value="<?=e((string)$row['city'])?>"></div></div><div class="admin-form-grid"><div><label>State</label><input name="state" value="<?=e((string)$row['state'])?>"></div><div></div></div>
<label>Value</label><input class="admin-currency-input" name="project_value" inputmode="numeric" value="<?=!empty($row['project_value'])?e('$'.number_format((float)$row['project_value'],0,'.',',')):''?>" placeholder="$10,500,000">
<div class="admin-form-grid"><div><label>Asset Type</label><select name="asset_type"><option value="">Select asset type</option><?php foreach($assetOptions as $v):?><option value="<?=e($v)?>" <?=$row['asset_type']===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></div><div><label>Project Type</label><select name="project_type"><option value="">Select project type</option><?php foreach($projectTypeOptions as $v):?><option value="<?=e($v)?>" <?=$row['project_type']===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></div></div>
<div class="admin-form-grid"><div><label>Portfolio Status</label><select name="portfolio_status"><?php foreach($portfolioStatusOptions as $v=>$l):?><option value="<?=e($v)?>" <?=($row['portfolio_status']??'')===$v?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div><div></div></div>
<div class="admin-form-grid"><div><label>Completed / Expected Year</label><input name="year_completed_or_expected" value="<?=e((string)$row['year_completed_or_expected'])?>"></div><div><label>Units / Keys</label><input name="units_or_keys" value="<?=e((string)$row['units_or_keys'])?>"></div></div>
<div class="admin-form-grid"><div><label>Replace Project Photo</label><input type="file" name="primary_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><small>JPG, PNG, or WebP. Images are automatically resized to a maximum of 1920px and optimized for the web.</small></div><div></div></div>
<?php if(!empty($row['primary_image'])):?><div class="admin-current-photo admin-current-photo-wide"><span>Current Project Photo</span><img src="../<?=e((string)$row['primary_image'])?>" alt=""></div><?php endif;?>
<label class="admin-checkbox"><input type="checkbox" name="is_visible" <?=$row['is_visible']?'checked':''?>> Visible on the public website</label>
<fieldset class="admin-homepage-settings"><legend>Homepage Slider</legend><label class="admin-checkbox"><input type="checkbox" name="is_homepage_featured" <?=$row['is_homepage_featured']?'checked':''?>> Show on Homepage Slider</label><label>Slider Order</label><input type="number" min="0" name="display_order" value="<?=e((string)$row['display_order'])?>"><small>Lower numbers appear first. This order is also used as the general display-order fallback.</small></fieldset>
<button class="primary admin-save-button" type="submit">Save Project</button></form>
<script>document.querySelectorAll('.admin-currency-input').forEach(function(input){function f(){var d=input.value.replace(/[^0-9]/g,'');input.value=d?'$'+Number(d).toLocaleString('en-US'):'';}input.addEventListener('focus',function(){input.value=input.value.replace(/[^0-9]/g,'')});input.addEventListener('blur',f);f();});</script>
<?php require __DIR__ . '/_footer.php'; ?>
