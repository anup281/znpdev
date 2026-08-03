(function(){
  const form=document.querySelector('.manage-month-end-form');
  if(!form)return;
  const endpoint=form.dataset.autosaveUrl;
  const status=document.getElementById('monthEndAutosaveStatus');
  const fileInput=form.querySelector('input[name="report_files[]"]');
  const fileList=document.getElementById('monthEndFileList');
  const selectedMonth=document.querySelector('.manage-calendar-month.selected');
  let timer;

  function setStatus(message,state){
    status.textContent=message;
    status.dataset.state=state||'';
    if(state==='saved')window.manageToast?.('Saved','success');
    else if(state==='error')window.manageToast?.(message,'error');
  }
  function baseData(action){
    const data=new FormData();
    data.append('action',action);
    ['csrf_token','property_id','report_year','report_month'].forEach(name=>data.append(name,form.elements[name].value));
    return data;
  }
  async function send(data){
    const response=await fetch(endpoint,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
    const result=await response.json();
    if(!response.ok||!result.ok)throw new Error(result.message||'Unable to save.');
    return result;
  }
  function markSaved(periodStatus){
    if(!selectedMonth)return;
    const label=selectedMonth.querySelector('small');
    const normalized=!periodStatus||periodStatus==='draft'?'blank':periodStatus;
    if(label)label.textContent=normalized.charAt(0).toUpperCase()+normalized.slice(1);
    selectedMonth.classList.remove('blank','draft','submitted','finalized');
    selectedMonth.classList.add(normalized);
    const icon=selectedMonth.querySelector('.manage-calendar-status-icon i');
    if(icon)icon.className='fa-solid '+(normalized==='finalized'?'fa-arrow-up':(normalized==='submitted'?'fa-check':'fa-circle'));
  }
  function autosave(){
    clearTimeout(timer);timer=setTimeout(async()=>{
      const data=baseData('save_fields');
      form.querySelectorAll('.manage-money-grid input[name]:not([readonly]), input[name="sales_tax"], input[name="total_revenue"]').forEach(input=>data.append(input.name,input.value));
      setStatus('Saving…','saving');
      try{const result=await send(data);setStatus('All changes saved','saved');markSaved(result.period_status);}
      catch(error){setStatus(error.message,'error');}
    },650);
  }
  function numericValue(value){
    return parseFloat(String(value||'0').replace(/[$,]/g,''))||0;
  }
  function isCurrencyInput(input){
    return input.matches('.manage-money-input input:not([readonly])')&&!input.closest('.manage-room-count-input');
  }
  function formatCurrencyInput(input,fixed){
    let raw=input.value.replace(/[^0-9.]/g,'');
    const firstDot=raw.indexOf('.');
    if(firstDot>=0)raw=raw.slice(0,firstDot+1)+raw.slice(firstDot+1).replace(/\./g,'');
    let [whole='',decimal='']=raw.split('.');
    whole=whole.replace(/^0+(?=\d)/,'');
    const formattedWhole=whole===''?'':Number(whole).toLocaleString('en-US',{maximumFractionDigits:0});
    if(raw===''){input.value=fixed?'0.00':'';return;}
    if(fixed){input.value=(numericValue(raw)).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});return;}
    input.value=formattedWhole+(firstDot>=0?'.'+decimal.slice(0,2):'');
  }
  function updateCalculations(){
    document.querySelectorAll('[data-tax-collected]').forEach(output=>{
      const tax=numericValue(document.getElementById(output.dataset.taxInputId)?.value);
      const adjustment=numericValue(document.getElementById(output.dataset.adjustmentInputId)?.value);
      output.value=(tax-adjustment).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    });
    document.querySelectorAll('[data-calculation-sources]').forEach(output=>{
      const sources=output.dataset.calculationSources.split(',');
      const rate=parseFloat(output.dataset.calculationRate)||1;
      const values=sources.map(name=>{
        const negative=name.startsWith('-');
        const value=numericValue(form.elements[negative?name.slice(1):name]?.value);
        return negative?-value:value;
      });
      const amount=values.reduce((sum,value)=>sum+value,0);
      const mode=output.dataset.calculationMode;
      const result=mode==='multiply'?amount*rate:(mode==='difference'?(values[0]||0)-values.slice(1).reduce((sum,value)=>sum+value,0)/rate:amount/rate);
      output.value=result.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    });
  }
  document.querySelectorAll('[data-drop-upload]').forEach(zone=>{
    const input=zone.querySelector('input[type="file"]');const selection=zone.querySelector('.manage-upload-selection');
    if(!input)return;
    function showSelection(){const count=input.files?.length||0;if(selection)selection.textContent=count?count+(count===1?' file selected':' files selected'):'No files selected';}
    ['dragenter','dragover'].forEach(type=>zone.addEventListener(type,event=>{event.preventDefault();event.stopPropagation();if(!input.disabled)zone.classList.add('is-dragging');}));
    ['dragleave','drop'].forEach(type=>zone.addEventListener(type,event=>{event.preventDefault();event.stopPropagation();zone.classList.remove('is-dragging');}));
    zone.addEventListener('drop',event=>{
      if(input.disabled||!event.dataTransfer?.files.length)return;
      try{input.files=event.dataTransfer.files;}catch(error){return;}
      showSelection();input.dispatchEvent(new Event('change',{bubbles:true}));
    });
    input.addEventListener('change',showSelection);
  });
  form.addEventListener('input',event=>{
    if(!event.target.matches('.manage-money-grid input'))return;
    if(isCurrencyInput(event.target))formatCurrencyInput(event.target,false);
    updateCalculations();
    autosave();
  });
  form.addEventListener('focusin',event=>{
    if(isCurrencyInput(event.target)&&numericValue(event.target.value)===0)event.target.value='';
  });
  form.addEventListener('focusout',event=>{
    if(!isCurrencyInput(event.target))return;
    formatCurrencyInput(event.target,true);
    updateCalculations();
    autosave();
  });
  form.querySelectorAll('.manage-money-input input:not([readonly])').forEach(input=>{if(isCurrencyInput(input))formatCurrencyInput(input,true);});
  updateCalculations();
  fileInput?.addEventListener('change',async function(){
    if(!this.files.length)return;
    const data=baseData('upload');Array.from(this.files).forEach(file=>data.append('report_files[]',file));
    setStatus('Uploading files…','saving');this.disabled=true;
    try{
      const result=await send(data);
      (result.files||[]).forEach(file=>{
        const row=document.createElement('span');row.className='manage-file-item';row.dataset.fileId=file.id;
        const link=document.createElement('a');link.href=file.url;link.innerHTML='<i class="fa-solid fa-paperclip"></i> ';link.append(document.createTextNode(file.name));
        const button=document.createElement('button');button.type='button';button.className='manage-file-delete';button.dataset.fileId=file.id;button.setAttribute('aria-label','Delete '+file.name);button.innerHTML='<i class="fa-solid fa-trash"></i>';
        row.append(link,button);fileList.append(row);
      });
      this.value='';this.dispatchEvent(new Event('change',{bubbles:false}));setStatus(result.message,'saved');markSaved(result.period_status);
    }catch(error){setStatus(error.message,'error');}
    finally{this.disabled=false;}
  });
  fileList?.addEventListener('click',async event=>{
    const button=event.target.closest('.manage-file-delete');if(!button)return;
    if(!window.confirm('Delete this file? This cannot be undone.'))return;
    const data=baseData('delete_file');data.append('file_id',button.dataset.fileId);button.disabled=true;setStatus('Deleting file…','saving');
    try{const result=await send(data);button.closest('.manage-file-item').remove();setStatus(result.message,'saved');markSaved(result.period_status);}
    catch(error){button.disabled=false;setStatus(error.message,'error');}
  });
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-reset-month-end]');if(!button)return;const label=button.dataset.monthLabel||'this month';
    if(!window.confirm('Reset '+label+'? This will permanently remove all Month End values, uploaded files, and tax statuses for this month.'))return;
    if(!window.confirm('Final confirmation: permanently reset '+label+' to Blank? This cannot be undone.'))return;
    const data=baseData('reset_month');button.disabled=true;setStatus('Resetting month…','saving');
    try{const result=await send(data);setStatus(result.message,'saved');const resetUrl=new URL(window.location.href);resetUrl.search='';resetUrl.searchParams.set('property_id',form.elements.property_id.value);resetUrl.searchParams.set('year',form.elements.report_year.value);resetUrl.searchParams.set('month',form.elements.report_month.value);resetUrl.searchParams.set('reset',Date.now().toString());window.setTimeout(()=>window.location.replace(resetUrl.toString()),450);}
    catch(error){button.disabled=false;setStatus(error.message,'error');}
  });
  document.querySelector('.manage-finalize-calculations')?.addEventListener('click',async event=>{
    const button=event.target.closest('.manage-tax-status-button');if(!button||button.disabled)return;
    const data=baseData('toggle_tax_status');data.append('tax_key',button.dataset.taxKey);button.disabled=true;
    try{
      const result=await send(data);const submitted=result.tax_status==='submitted';
      button.classList.toggle('submitted',submitted);button.classList.toggle('unsubmitted',!submitted);
      button.setAttribute('aria-pressed',submitted?'true':'false');button.querySelector('.manage-tax-status-label').textContent=submitted?'Submitted':'Unsubmitted';
    }catch(error){setStatus(error.message,'error');}
    finally{button.disabled=false;}
  });
})();
