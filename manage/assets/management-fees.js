(function(){
 const form=document.querySelector('[data-management-fee-form]');if(!form)return;
 const totalRevenue=form.elements.total_revenue;
 const number=value=>parseFloat(String(value||'0').replace(/[$,]/g,''))||0;
 let baselines={};try{baselines=JSON.parse(form.dataset.feeBaselines||'{}');}catch(error){}
 const update=()=>{const year=form.elements.report_year.value,quarter=form.elements.quarter.value,baseline=number(baselines[year]?.[quarter]),feeRevenue=number(totalRevenue.value)-baseline;const formatted=feeRevenue.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});const basisOutput=form.querySelector('[data-fee-basis-output]');if(basisOutput)basisOutput.value=formatted;form.querySelectorAll('[data-fee-output]').forEach(output=>{const fee=feeRevenue*number(output.dataset.percent)/100;output.value=fee.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});});};
 form.querySelectorAll('input[name="total_revenue"]').forEach(input=>input.addEventListener('focus',()=>{if(number(input.value)===0)input.value='';}));
 const dropZone=form.querySelector('[data-management-fee-drop]');
 const fileInput=dropZone?.querySelector('input[type="file"]');
 const selection=dropZone?.querySelector('.manage-upload-selection');
 if(dropZone&&fileInput){
  const showSelection=()=>{const count=fileInput.files?.length||0;if(selection)selection.textContent=count?count+(count===1?' file selected':' files selected'):'No files selected';};
  ['dragenter','dragover'].forEach(type=>dropZone.addEventListener(type,event=>{event.preventDefault();event.stopPropagation();dropZone.classList.add('is-dragging');}));
  ['dragleave','drop'].forEach(type=>dropZone.addEventListener(type,event=>{event.preventDefault();event.stopPropagation();dropZone.classList.remove('is-dragging');}));
  dropZone.addEventListener('drop',event=>{if(!event.dataTransfer?.files.length)return;try{fileInput.files=event.dataTransfer.files;}catch(error){return;}showSelection();});
  fileInput.addEventListener('change',showSelection);
 }
 totalRevenue.addEventListener('input',update);totalRevenue.addEventListener('change',update);form.elements.report_year.addEventListener('change',update);form.elements.quarter.addEventListener('change',update);update();
})();
