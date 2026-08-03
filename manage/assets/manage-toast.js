(function(){
 'use strict';
 window.manageToast=function(message,type){
  if(!message)return;
  const tray=document.getElementById('manageToastTray');if(!tray)return;
  const toast=document.createElement('div');toast.className='manage-toast '+(type==='error'?'error':'success');toast.setAttribute('role',type==='error'?'alert':'status');toast.textContent=message;tray.append(toast);
  window.setTimeout(()=>{toast.classList.add('leaving');window.setTimeout(()=>toast.remove(),250);},type==='error'?7000:3000);
 };
})();
