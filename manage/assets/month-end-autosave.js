(function(){
  const form=document.querySelector('.manage-month-end-form');
  if(!form)return;
  const endpoint=form.dataset.autosaveUrl;
  const status=document.getElementById('monthEndAutosaveStatus');
  const fileInput=form.querySelector('input[name="report_files[]"]');
  const fileList=document.getElementById('monthEndFileList');
  const calculationButton=form.querySelector('[data-find-calculations]');
  const calculationHelp=form.querySelector('[data-calculation-help]');
  const calculationWorkflow=form.dataset.calculationWorkflow||'manual';
  const requiredReports=parseInt(form.dataset.requiredReports||'2',10);
  const requiredExtension=(form.dataset.requiredExtension||'').replace(/^\./,'');
  const isAutomated=calculationWorkflow!=='manual';
  const workflowLabel=calculationWorkflow==='opera'?'Opera':(calculationWorkflow==='hotel_key'?'HotelKey':'Fossee');
  const selectedMonth=document.querySelector('.manage-calendar-month.selected');
  const calculationModal=document.querySelector('[data-calculation-modal]');
  const calculationDialog=calculationModal?.querySelector('[data-calculation-dialog]');
  const calculationModalTitle=calculationModal?.querySelector('[data-calculation-modal-title]');
  const calculationModalMessage=calculationModal?.querySelector('[data-calculation-modal-message]');
  const calculationModalDetail=calculationModal?.querySelector('[data-calculation-modal-detail]');
  const calculationProgress=calculationModal?.querySelector('[data-calculation-progress]');
  const calculationProgressTrack=calculationProgress?.parentElement;
  const calculationSpinner=calculationModal?.querySelector('[data-calculation-spinner]');
  const calculationResultIcon=calculationModal?.querySelector('[data-calculation-result-icon]');
  const calculationModalClose=calculationModal?.querySelector('[data-calculation-modal-close]');
  let pdfJsPromise;
  let tesseractPromise;
  let timer;

  async function copyBankValue(trigger){
    const field=trigger.closest('.manage-copy-bank-field,.manage-copy-money-field');
    const input=field?.querySelector('[data-copy-bank-value]');
    const button=field?.querySelector('[data-copy-bank-button]');
    const buttonLabel=button?.querySelector('span');
    const value=input?.value||'';
    if(!value){window.manageToast?.('Nothing to copy','error');return;}
    try{
      if(navigator.clipboard&&window.isSecureContext)await navigator.clipboard.writeText(value);
      else{
        input.focus();input.select();input.setSelectionRange(0,value.length);
        if(!document.execCommand('copy'))throw new Error('Copy failed');
        input.setSelectionRange(0,0);input.blur();
      }
      field.classList.add('is-copied');
      if(buttonLabel)buttonLabel.textContent='Copied';
      window.manageToast?.('Copied to clipboard','success');
      window.setTimeout(()=>{field.classList.remove('is-copied');if(buttonLabel)buttonLabel.textContent='Copy';},1400);
    }catch(error){window.manageToast?.('Unable to copy this value','error');}
  }

  function setStatus(message,state){
    status.textContent=message;
    status.dataset.state=state||'';
    if(state==='saved')window.manageToast?.('Saved','success');
    else if(state==='error')window.manageToast?.(message,'error');
  }
  function updateCalculationModal(message,progress,detail){
    if(!calculationModal)return;
    const normalizedProgress=Math.max(0,Math.min(100,Number(progress)||0));
    if(calculationModalMessage)calculationModalMessage.textContent=message;
    if(detail!==undefined&&calculationModalDetail)calculationModalDetail.textContent=detail;
    if(calculationProgress)calculationProgress.style.width=normalizedProgress+'%';
    if(calculationProgressTrack)calculationProgressTrack.setAttribute('aria-valuenow',String(Math.round(normalizedProgress)));
  }
  function showCalculationModal(message){
    if(!calculationModal)return;
    calculationModal.hidden=false;
    calculationDialog.dataset.state='running';
    calculationModalTitle.textContent='Calculating Month End';
    calculationSpinner.hidden=false;
    calculationResultIcon.hidden=true;
    calculationResultIcon.innerHTML='';
    calculationModalClose.hidden=true;
    document.body.classList.add('manage-calculation-modal-open');
    updateCalculationModal(message,3,'Please keep this page open. The first scan can take a minute.');
  }
  function finishCalculationModal(success,message){
    if(!calculationModal)return;
    calculationDialog.dataset.state=success?'success':'error';
    calculationModalTitle.textContent=success?'Calculations Complete':'Calculations Could Not Finish';
    calculationSpinner.hidden=true;
    calculationResultIcon.hidden=false;
    calculationResultIcon.innerHTML='<i class="fa-solid '+(success?'fa-check':'fa-triangle-exclamation')+'"></i>';
    calculationModalClose.hidden=success;
    updateCalculationModal(message,success?100:Number(calculationProgressTrack?.getAttribute('aria-valuenow')||0),success?'Refreshing the completed Month End…':'Close this message, correct the reports, and try again.');
  }
  function closeCalculationModal(){
    if(!calculationModal||calculationModalClose?.hidden)return;
    calculationModal.hidden=true;
    document.body.classList.remove('manage-calculation-modal-open');
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
    if(label)label.textContent=normalized==='finalized'?'Finalized':(normalized==='submitted'?'Files & Numbers Loaded':'Not Ready');
    selectedMonth.classList.remove('blank','draft','submitted','finalized');
    selectedMonth.classList.add(normalized);
    const icon=selectedMonth.querySelector('.manage-calendar-status-icon i');
    if(icon)icon.className='fa-solid '+(normalized==='finalized'?'fa-lock':(normalized==='submitted'?'fa-file-circle-check':'fa-circle'));
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
    const salesTax=form.querySelector('[data-suite-shop-sales-tax]');
    if(salesTax)salesTax.value=(numericValue(form.elements.suite_shop_revenue?.value)*0.0825).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
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
  function refreshCalculationButton(){
    if(!calculationButton)return;const extensionPattern=new RegExp('\\.'+requiredExtension+'$','i');const reportCount=Array.from(fileList?.querySelectorAll('a')||[]).filter(link=>extensionPattern.test((link.textContent||'').trim())).length;calculationButton.disabled=reportCount<requiredReports;if(calculationHelp)calculationHelp.textContent=reportCount<requiredReports?'Upload all '+requiredReports+' required reports to enable calculations.':'All required '+workflowLabel+' reports are ready to validate.';
  }
  function loadScript(source){
    return new Promise((resolve,reject)=>{const existing=document.querySelector('script[data-dynamic-source="'+source+'"]');if(existing){if(existing.dataset.loaded==='1')resolve();else{existing.addEventListener('load',resolve,{once:true});existing.addEventListener('error',reject,{once:true});}return;}const script=document.createElement('script');script.src=source;script.async=true;script.dataset.dynamicSource=source;script.addEventListener('load',()=>{script.dataset.loaded='1';resolve();},{once:true});script.addEventListener('error',()=>reject(new Error('Fossee OCR could not start. Contact Anup for Support.')),{once:true});document.head.append(script);});
  }
  function loadPdfJs(){
    if(!pdfJsPromise)pdfJsPromise=import('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.min.mjs').then(pdfjs=>{pdfjs.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.worker.min.mjs';return pdfjs;});return pdfJsPromise;
  }
  function loadTesseract(){
    if(!tesseractPromise)tesseractPromise=loadScript('https://cdn.jsdelivr.net/npm/tesseract.js@5.0.0/dist/tesseract.min.js').then(()=>{if(!window.Tesseract)throw new Error('Fossee OCR could not start. Contact Anup for Support.');return window.Tesseract;});return tesseractPromise;
  }
  async function collectFosseeOcrReports(){
    const rows=Array.from(fileList?.querySelectorAll('.manage-file-item[data-file-id]')||[]).filter(row=>/\.pdf$/i.test((row.querySelector('a')?.textContent||'').trim())).slice(-requiredReports);if(rows.length<requiredReports)throw new Error('Upload all four required Fossee PDF reports first.');setStatus('Preparing Fossee scan reader…','saving');updateCalculationModal('Preparing the Fossee scan reader…',5,'Loading the tools needed to read the four uploaded reports.');
    const [pdfjs,Tesseract]=await Promise.all([loadPdfJs(),loadTesseract()]);let reportIndex=0;const worker=await Tesseract.createWorker('eng',1,{workerPath:'https://cdn.jsdelivr.net/npm/tesseract.js@5.0.0/dist/worker.min.js',langPath:'https://tessdata.projectnaptha.com/4.0.0',corePath:'https://cdn.jsdelivr.net/npm/tesseract.js-core@5.0.0',logger:message=>{if(message.status==='recognizing text'){const reportProgress=Math.round((message.progress||0)*100);const overallProgress=10+((reportIndex+(message.progress||0))/rows.length)*78;const progressMessage='Reading report '+(reportIndex+1)+' of '+rows.length+' — '+reportProgress+'%';setStatus(progressMessage,'saving');updateCalculationModal(progressMessage,overallProgress,'Reading the scanned report and locating Month End totals.');}}});const reports=[];
    try{
      await worker.setParameters({tessedit_pageseg_mode:'6',preserve_interword_spaces:'1'});
      for(reportIndex=0;reportIndex<rows.length;reportIndex++){
        const row=rows[reportIndex];const fileId=row.dataset.fileId;updateCalculationModal('Opening report '+(reportIndex+1)+' of '+rows.length+'…',10+(reportIndex/rows.length)*78,'Preparing this report for scan recognition.');const response=await fetch('month_end_ocr_file.php?id='+encodeURIComponent(fileId),{credentials:'same-origin'});if(!response.ok)throw new Error('A Fossee PDF could not be opened. Contact Anup for Support.');const pdf=await pdfjs.getDocument({data:new Uint8Array(await response.arrayBuffer())}).promise;const page=await pdf.getPage(1);const viewport=page.getViewport({scale:3});const canvas=document.createElement('canvas');canvas.width=Math.ceil(viewport.width);canvas.height=Math.ceil(viewport.height);const context=canvas.getContext('2d',{alpha:false});context.fillStyle='#fff';context.fillRect(0,0,canvas.width,canvas.height);await page.render({canvasContext:context,viewport}).promise;const result=await worker.recognize(canvas);const text=result?.data?.text||'';canvas.width=1;canvas.height=1;await pdf.destroy();reports.push({file_id:Number(fileId),text});
      }
      return reports;
    }finally{await worker.terminate();}
  }
  async function runReportCalculations(automatic){
    if(!calculationButton||calculationButton.disabled)return false;
    const calculationAction=calculationWorkflow==='opera'?'find_opera_calculations':(calculationWorkflow==='hotel_key'?'find_hotelkey_calculations':'find_fossee_calculations');const data=baseData(calculationAction);calculationButton.disabled=true;setStatus(automatic?'Reports uploaded. Finding '+workflowLabel+' calculations…':'Finding '+workflowLabel+' calculations…','saving');if(calculationWorkflow==='fossee')showCalculationModal('Preparing the four Fossee reports…');
    try{
      if(calculationWorkflow==='fossee')data.append('ocr_reports',JSON.stringify(await collectFosseeOcrReports()));
      if(calculationWorkflow==='fossee')updateCalculationModal('Validating dates, totals, and saving calculations…',92,'Checking all four reports against the selected month.');const result=await send(data);Object.entries(result.values||{}).forEach(([name,value])=>{if(!form.elements[name])return;form.elements[name].value=['rooms_available','rooms_occupied'].includes(name)?String(Math.round(Number(value))):Number(value).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});});updateCalculations();setStatus(result.message,'saved');markSaved(result.period_status);if(calculationHelp)calculationHelp.textContent='Validated against the required '+workflowLabel+' reports.';if(calculationWorkflow==='fossee')finishCalculationModal(true,'The four Fossee reports were calculated and saved successfully.');window.setTimeout(()=>window.location.reload(),calculationWorkflow==='fossee'?1200:500);return true;
    }catch(error){const actionable=error.message.includes('Contact Anup for Support')||error.message.includes('better scanned copies')||error.message.includes('complete selected month');const displayMessage=actionable?error.message:'The '+workflowLabel+' reports could not be validated. Contact Anup for Support.';setStatus(displayMessage,'error');if(calculationWorkflow==='fossee')finishCalculationModal(false,displayMessage);return false;}
    finally{refreshCalculationButton();}
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
    if(isAutomated){form.querySelector('.manage-month-end-values')?.setAttribute('hidden','');document.querySelector('.manage-finalize-panel')?.setAttribute('hidden','');}
    setStatus('Uploading files…','saving');this.disabled=true;
    try{
      const result=await send(data);
      (result.files||[]).forEach(file=>{
        const row=document.createElement('span');row.className='manage-file-item';row.dataset.fileId=file.id;
        const link=document.createElement('a');link.href=file.url;link.innerHTML='<i class="fa-solid fa-paperclip"></i> ';link.append(document.createTextNode(file.name));
        const button=document.createElement('button');button.type='button';button.className='manage-file-delete';button.dataset.fileId=file.id;button.setAttribute('aria-label','Delete '+file.name);button.innerHTML='<i class="fa-solid fa-trash"></i>';
        row.append(link,button);fileList.append(row);
      });
      this.value='';this.dispatchEvent(new Event('change',{bubbles:false}));refreshCalculationButton();setStatus(result.message,'saved');markSaved(result.period_status);
      if(isAutomated&&calculationButton&&!calculationButton.disabled){await runReportCalculations(true);return;}
      if(isAutomated){window.setTimeout(()=>window.location.reload(),350);return;}
    }catch(error){setStatus(error.message,'error');}
    finally{this.disabled=false;}
  });
  fileList?.addEventListener('click',async event=>{
    const button=event.target.closest('.manage-file-delete');if(!button)return;
    if(!window.confirm('Delete this file? This cannot be undone.'))return;
    const data=baseData('delete_file');data.append('file_id',button.dataset.fileId);button.disabled=true;setStatus('Deleting file…','saving');
    try{const result=await send(data);button.closest('.manage-file-item').remove();refreshCalculationButton();setStatus(result.message,'saved');markSaved(result.period_status);if(isAutomated)window.setTimeout(()=>window.location.reload(),350);}
    catch(error){button.disabled=false;setStatus(error.message,'error');}
  });
  calculationButton?.addEventListener('click',()=>runReportCalculations(false));
  calculationModalClose?.addEventListener('click',closeCalculationModal);
  document.addEventListener('keydown',event=>{if(event.key==='Escape')closeCalculationModal();});
  refreshCalculationButton();
  document.addEventListener('click',async event=>{
    const copyTrigger=event.target.closest('[data-copy-bank-value],[data-copy-bank-button]');
    if(copyTrigger){event.preventDefault();copyBankValue(copyTrigger);return;}
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
