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
<script>
(function(){
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
<?php znp_workspace_document_end(); ?>
