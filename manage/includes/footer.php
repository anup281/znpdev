</main>
<?php
znp_workspace_footer_styles();
znp_render_workspace_footer('ZNP Management', znp_application_version_label(), (string)($managementUser['name'] ?? $managementUser['full_name'] ?? ''));
?>
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
<?php znp_workspace_document_end(); ?>
