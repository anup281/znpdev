(function () {
  'use strict';
  function ready(fn){if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fn,{once:true});}else{fn();}}
  ready(function(){
    document.addEventListener('click',function(event){
      var confirmLink=event.target.closest('[data-confirm]');
      if(confirmLink && !window.confirm(confirmLink.getAttribute('data-confirm')||'Are you sure?')){event.preventDefault();return;}
      var opener=event.target.closest('[data-open-modal]');
      if(opener){var modal=document.getElementById(opener.getAttribute('data-open-modal'));if(modal){event.preventDefault();modal.hidden=false;modal.classList.add('is-open');document.body.classList.add('modal-open');}}
      var closer=event.target.closest('[data-close-modal]');
      if(closer){var parent=closer.closest('.dev-modal');if(parent){event.preventDefault();parent.hidden=true;parent.classList.remove('is-open');document.body.classList.remove('modal-open');}}
    });
    document.querySelectorAll('.dev-modal').forEach(function(modal){modal.addEventListener('click',function(event){if(event.target===modal){modal.hidden=true;modal.classList.remove('is-open');document.body.classList.remove('modal-open');}});});
    document.addEventListener('click',function(event){var button=event.target.closest('[data-toggle-completed]');if(!button)return;var list=button.nextElementSibling;if(!list)return;var opening=list.hasAttribute('hidden');if(opening)list.removeAttribute('hidden');else list.setAttribute('hidden','');button.setAttribute('aria-expanded',opening?'true':'false');var arrow=button.querySelector('.toggle-arrow');if(arrow)arrow.textContent=opening?'▾':'▸';});
    document.querySelectorAll('table').forEach(function(table){var heads=Array.prototype.map.call(table.querySelectorAll('thead th'),function(th){return th.textContent.trim();});table.querySelectorAll('tbody tr').forEach(function(row){Array.prototype.forEach.call(row.children,function(cell,i){if(!cell.dataset.label&&heads[i])cell.dataset.label=heads[i];});});});
    if(document.body.classList.contains('dev-documents-page')){
      document.querySelectorAll('input.znp-checkbox[name="document_ids[]"]').forEach(function(input){
        var label=document.createElement('label'),text=document.createElement('span');
        label.className='document-select-button';
        text.textContent=input.checked?'Selected':'Select Document';
        input.parentNode.insertBefore(label,input);
        label.append(input,text);
        input.addEventListener('change',function(){text.textContent=input.checked?'Selected':'Select Document';});
      });
      var uploadForm=document.querySelector('#upload-modal form');
      if(uploadForm){
        ['folder_name','revision','document_date','description'].forEach(function(name){
          var field=uploadForm.elements.namedItem(name);
          if(field)field.disabled=true;
        });
        var documentName=uploadForm.elements.namedItem('document_name');
        if(documentName){
          var nameLabel=documentName.closest('label');
          if(nameLabel&&nameLabel.firstChild)nameLabel.firstChild.nodeValue='File Name';
        }
      }
    }
    document.addEventListener('click',function(event){document.querySelectorAll('details[open]').forEach(function(detail){if(!detail.contains(event.target))detail.removeAttribute('open');});});
  });
})();
