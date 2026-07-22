(function(){
 function closeModal(modal){if(!modal)return;modal.hidden=true;document.body.classList.remove('admin-modal-open');}
 document.addEventListener('click',function(e){
  var open=e.target.closest('[data-admin-modal-open]');
  if(open){var modal=document.getElementById(open.getAttribute('data-admin-modal-open'));if(modal){modal.hidden=false;document.body.classList.add('admin-modal-open');var close=modal.querySelector('[data-admin-modal-close]');if(close)close.focus();}return;}
  var close=e.target.closest('[data-admin-modal-close]');if(close){closeModal(close.closest('.admin-modal'));return;}
  if(e.target.classList.contains('admin-modal'))closeModal(e.target);
 });
 document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal(document.querySelector('.admin-modal:not([hidden])'));});
})();
