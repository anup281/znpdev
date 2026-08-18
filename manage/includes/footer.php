</main>
<?php
$footerManagementProperties=$managementProperties??manage_properties();
$footerUserLabel='Logged In as '.(string)($managementUser['name']??$managementUser['full_name']??'Administrator');
if(count($footerManagementProperties)===1){
    $footerUserLabel.=' for '.(string)$footerManagementProperties[0]['property_name'];
}
znp_render_workspace_footer('Management Portal',znp_application_version_label(),$footerUserLabel);
?>
<div id="manageToastTray" class="manage-toast-tray" aria-live="polite" aria-atomic="false"></div>
<div class="manage-file-preview-modal" data-manage-file-modal hidden>
 <section class="manage-file-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="manageFilePreviewTitle">
  <header><h2 id="manageFilePreviewTitle" data-manage-file-title>File Preview</h2><div><a class="manage-file-preview-download" data-manage-file-download href="#"><i class="fa-solid fa-download" aria-hidden="true"></i> Download</a><button type="button" class="manage-file-preview-close" data-manage-file-close aria-label="Close file preview"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div></header>
  <div class="manage-file-preview-body"><iframe data-manage-file-frame title="File preview" hidden></iframe><div class="manage-file-preview-fallback" data-manage-file-fallback hidden><i class="fa-regular fa-file" aria-hidden="true"></i><h3>Preview unavailable</h3><p>This file type cannot be displayed by the browser. Use Download to review it.</p></div><div class="manage-file-preview-loading" data-manage-file-loading><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>Loading preview…</span></div></div>
 </section>
</div>
<script>
(function(){
  const calendarHeading=document.querySelector('.manage-year-calendar .manage-calendar-heading>div:first-child');
  const calendarTitles={
    'month_end.php':'Month End Calendar',
    'receipts.php':'Receipts Calendar',
    'franchise_fees.php':'Franchise Fees Calendar'
  };
  const calendarTitle=calendarTitles[<?=json_encode((string)($currentManagementPage??''),JSON_UNESCAPED_SLASHES)?>];
  if(calendarHeading&&calendarTitle){
    const title=calendarHeading.querySelector('h2');
    if(title)title.textContent=calendarTitle;
    calendarHeading.querySelector('p')?.remove();
  }
  const button=document.querySelector('.management-menu-toggle');
  const nav=document.getElementById('management-nav');
  if(!button||!nav)return;
  button.addEventListener('click',()=>{
    const open=nav.classList.toggle('is-open');
    button.setAttribute('aria-expanded',open?'true':'false');
  });
})();
</script>
<script src="<?=manage_e(app_url('/manage/assets/manage-toast.js'))?>?v=<?=manage_e(znp_asset_version())?>-1" defer></script>
<script src="<?=manage_e(app_url('/manage/assets/file-preview.js'))?>?v=<?=manage_e(znp_asset_version())?>-excel-preview4" defer></script>
<?php znp_workspace_document_end(); ?>
