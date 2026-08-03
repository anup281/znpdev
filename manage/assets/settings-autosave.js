(function(){
 'use strict';
 const form=document.querySelector('[data-cpa-autosave]');if(!form)return;
 let timer,controller;
 const save=async()=>{
  controller?.abort();controller=new AbortController();
  try{
   const response=await fetch(form.dataset.endpoint,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},signal:controller.signal});
   const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'Unable to save CPA settings.');
   window.manageToast?.('Saved','success');
  }catch(error){if(error.name!=='AbortError')window.manageToast?.(error.message,'error');}
 };
 form.addEventListener('input',()=>{clearTimeout(timer);timer=window.setTimeout(save,650);});
 form.addEventListener('change',()=>{clearTimeout(timer);save();});
})();
