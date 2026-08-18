function dashboardOpenProperty(propertyId,returnTo){const form=document.querySelector('[data-dashboard-property-nav]');if(!form)return;form.querySelector('[name="property_id"]').value=String(propertyId);form.querySelector('[name="return_to"]').value=returnTo;form.requestSubmit();}

(function(){
 const calendar=document.querySelector('[data-admin-bank-calendar]');if(!calendar)return;
 const section=calendar.closest('[data-dashboard-section]')||calendar,monthSelect=section.querySelector('[data-admin-bank-month]'),yearSelect=section.querySelector('[data-admin-bank-year]'),days=calendar.querySelector('[data-admin-bank-days]'),title=document.getElementById('adminBankCalendarTitle');
 const monthNames=['January','February','March','April','May','June','July','August','September','October','November','December'],today=calendar.dataset.today,startDate=calendar.dataset.startDate;
 let properties=[],records={};try{properties=JSON.parse(calendar.dataset.properties||'[]');records=JSON.parse(calendar.dataset.records||'{}');}catch(error){}
 const dateValue=(year,month,day)=>year+'-'+String(month).padStart(2,'0')+'-'+String(day).padStart(2,'0');
 function propertyStatus(value,propertyId){const status=(records[value]||{})[propertyId];return status==='finalized'?'finalized':(status==='submitted'?'submitted':'not-ready');}
 function render(){
  const year=Number(yearSelect.value),month=Number(monthSelect.value),first=new Date(year,month-1,1).getDay(),count=new Date(year,month,0).getDate(),previousCount=new Date(year,month-1,0).getDate();title.textContent=monthNames[month-1]+' '+year;days.replaceChildren();
  for(let cellIndex=0;cellIndex<42;cellIndex++){
   const offset=cellIndex-first+1,cell=document.createElement('article');cell.className='manage-admin-bank-day';cell.setAttribute('role','gridcell');let cellYear=year,cellMonth=month,day=offset;
   if(offset<1){cellMonth--;if(cellMonth<1){cellMonth=12;cellYear--;}day=previousCount+offset;cell.classList.add('outside-month');}else if(offset>count){cellMonth++;if(cellMonth>12){cellMonth=1;cellYear++;}day=offset-count;cell.classList.add('outside-month');}
   const value=dateValue(cellYear,cellMonth,day),afterStart=value>=startDate,future=value>today,available=afterStart&&!future;if(!afterStart)cell.classList.add('before-start');if(future)cell.classList.add('future');if(value===today)cell.classList.add('today');
   const heading=document.createElement('header'),number=document.createElement('span');number.className='manage-admin-bank-day-number';number.textContent=String(day);heading.append(number);
   if(available){const statuses=properties.map(property=>propertyStatus(value,property.id)),finalized=statuses.filter(status=>status==='finalized').length,submitted=statuses.filter(status=>status==='submitted').length,summary=document.createElement('span');summary.className='manage-admin-bank-day-count';summary.textContent=finalized+' finalized · '+submitted+' submitted';heading.append(summary);}
   cell.append(heading);
   if(available){const list=document.createElement('div');list.className='manage-admin-bank-properties';properties.forEach(property=>{const status=propertyStatus(value,property.id),label=status==='not-ready'?'Not Ready':status.charAt(0).toUpperCase()+status.slice(1),row=document.createElement('button');row.type='button';row.className='manage-admin-bank-property '+status;row.title=property.name+' — '+label;row.setAttribute('aria-label','Open Bank Deposits for '+property.name);row.addEventListener('click',()=>dashboardOpenProperty(property.id,'bank_deposits.php?year='+cellYear+'&month='+cellMonth));const icon=document.createElement('i');icon.className=status==='finalized'?'fa-solid fa-lock':status==='submitted'?'fa-solid fa-clock':'fa-regular fa-circle';icon.setAttribute('aria-hidden','true');const name=document.createElement('span');name.textContent=property.name;row.append(icon,name);list.append(row);});cell.append(list);}
   days.append(cell);
  }
 }
 monthSelect.addEventListener('change',render);yearSelect.addEventListener('change',render);monthSelect.value=calendar.dataset.initialMonth;yearSelect.value=calendar.dataset.initialYear;render();
})();

(function(){
 const calendars=document.querySelectorAll('[data-admin-month-calendar]');if(!calendars.length)return;
 const monthNames=['January','February','March','April','May','June','July','August','September','October','November','December'];
 calendars.forEach(calendar=>{
  const yearSelect=calendar.querySelector('[data-admin-month-year]'),grid=calendar.querySelector('[data-admin-month-grid]'),title=calendar.querySelector('[data-admin-month-title]'),calendarTitle=calendar.dataset.calendarTitle,workflow=calendar.dataset.workflow||'binary',currentYear=Number(calendar.dataset.currentYear),currentMonth=Number(calendar.dataset.currentMonth),startYear=Number(calendar.dataset.startYear),startMonth=Number(calendar.dataset.startMonth);
  let properties=[],records={};try{properties=JSON.parse(calendar.dataset.properties||'[]');records=JSON.parse(calendar.dataset.records||'{}');}catch(error){}
  function render(){
   const year=Number(yearSelect.value);title.textContent=year+' '+calendarTitle;grid.replaceChildren();
   monthNames.forEach((monthName,index)=>{
    const month=index+1,key=year+'-'+String(month).padStart(2,'0'),available=year>startYear||(year===startYear&&month>=startMonth),periodRecords=records[key]||[],monthCard=document.createElement('article');monthCard.className='manage-admin-month-card';
    if(!available)monthCard.classList.add('unavailable');
    const heading=document.createElement('header'),name=document.createElement('h4');name.textContent=monthName;heading.append(name);
    if(available){const states=properties.map(property=>workflow==='binary'?(periodRecords.includes(property.id)?'completed':'not-ready'):(periodRecords[property.id]||'not-ready')),summary=document.createElement('span');if(workflow==='month-end')summary.textContent=states.filter(state=>state==='finalized').length+' finalized · '+states.filter(state=>state==='submitted').length+' ready';else if(workflow==='receipts')summary.textContent=states.filter(state=>state==='finalized').length+' sent · '+states.filter(state=>state==='ready').length+' ready';else summary.textContent=states.filter(state=>state==='completed').length+'/'+properties.length+' complete';heading.append(summary);}
    monthCard.append(heading);
    if(available){const list=document.createElement('div');list.className='manage-admin-month-properties';properties.forEach(property=>{const status=workflow==='binary'?(periodRecords.includes(property.id)?'completed':'not-ready'):(periodRecords[property.id]||'not-ready'),labels={'not-ready':'Not Ready',submitted:'Files & Numbers Loaded',finalized:workflow==='receipts'?'Sent to CPA':'Finalized',incomplete:'Needs Categorization',ready:'Categories Complete',completed:'Completed'},row=document.createElement('div');row.className='manage-admin-month-property '+status;row.title=property.name+' — '+(labels[status]||status);const icon=document.createElement('i');icon.className=status==='finalized'?(workflow==='receipts'?'fa-solid fa-paper-plane':'fa-solid fa-lock'):status==='submitted'||status==='ready'?'fa-solid fa-check':status==='incomplete'?'fa-solid fa-triangle-exclamation':status==='completed'?'fa-solid fa-check':'fa-regular fa-circle';icon.setAttribute('aria-hidden','true');const propertyName=document.createElement('span');propertyName.textContent=property.name;row.append(icon,propertyName);list.append(row);});monthCard.append(list);}
    grid.append(monthCard);
   });
  }
  yearSelect.value=calendar.dataset.initialYear;yearSelect.addEventListener('change',render);render();
 });
})();

(function(){
 const calendar=document.querySelector('[data-admin-combined-calendar]');if(!calendar)return;
 const section=calendar.closest('[data-dashboard-section]')||calendar,yearSelect=section.querySelector('[data-admin-combined-year]'),grid=calendar.querySelector('[data-admin-combined-grid]'),currentYear=Number(calendar.dataset.currentYear),currentMonth=Number(calendar.dataset.currentMonth),monthNames=['January','February','March','April','May','June','July','August','September','October','November','December'];
 let properties=[],monthEndRecords={},receiptRecords={},receiptManagerFiles={},franchiseRecords={};
 try{properties=JSON.parse(calendar.dataset.properties||'[]');monthEndRecords=JSON.parse(calendar.dataset.monthEndRecords||'{}');receiptRecords=JSON.parse(calendar.dataset.receiptRecords||'{}');receiptManagerFiles=JSON.parse(calendar.dataset.receiptManagerFiles||'{}');franchiseRecords=JSON.parse(calendar.dataset.franchiseRecords||'{}');}catch(error){}
 const isAvailable=(year,month)=>year>2026||(year===2026&&month>=7);
 const workflowDetails=(workflow,status,available)=>{
  if(!available)return {label:'Not Available',tone:'unavailable',icon:'fa-solid fa-minus'};
  if(workflow==='month-end'){
   if(status==='finalized')return {label:'Finalized',tone:'finalized',icon:'fa-solid fa-lock'};
   if(status==='submitted')return {label:'Files & Numbers Loaded',tone:'submitted',icon:'fa-solid fa-clock'};
   return {label:'Not Ready',tone:'not-ready',icon:'fa-regular fa-circle'};
  }
  if(workflow==='receipts'){
   if(status==='finalized')return {label:'Sent to CPA',tone:'finalized',icon:'fa-solid fa-paper-plane'};
   if(status==='ready')return {label:'Categories Complete',tone:'submitted',icon:'fa-solid fa-clock'};
   if(status==='incomplete')return {label:'Needs Categorization',tone:'incomplete',icon:'fa-solid fa-triangle-exclamation'};
   return {label:'Not Ready',tone:'not-ready',icon:'fa-regular fa-circle'};
  }
  if(status==='skipped')return {label:'Completed - Skipped',tone:'finalized',icon:'fa-solid fa-forward'};
  if(status==='completed')return {label:'Completed',tone:'finalized',icon:'fa-solid fa-check'};
  return {label:'Not Started',tone:'not-ready',icon:'fa-regular fa-circle'};
 };
 function addWorkflow(list,name,workflow,status,available,receiptFilesMissing=false,propertyId=0,year=0,month=0){
  const details=workflowDetails(workflow,status,available),row=document.createElement('button'),label=document.createElement('span'),state=document.createElement('strong'),icon=document.createElement('i');
  if(workflow==='receipts'&&status==='ready'&&!receiptFilesMissing)details.label='Categories Complete - Ready for CPA';
  row.type='button';row.className='manage-admin-combined-workflow '+details.tone;row.disabled=!available;row.setAttribute('aria-label','Open '+name);if(available){const target=workflow==='month-end'?'month_end.php':workflow==='receipts'?'receipts.php':'franchise_fees.php';row.addEventListener('click',()=>dashboardOpenProperty(propertyId,target+'?year='+year+'&month='+month));}label.textContent=name;icon.className=details.icon;icon.setAttribute('aria-hidden','true');state.append(icon,document.createTextNode(details.label));if(workflow==='receipts'&&receiptFilesMissing){const warning=document.createElement('em');warning.className='manage-admin-combined-missing';warning.textContent=' | NO RECEIPTS FROM MANAGER';state.append(warning);}row.append(label,state);list.append(row);
  return details.tone;
 }
 function render(){
  const year=Number(yearSelect.value),firstVisibleMonth=year===currentYear?Math.max(year===2026?7:1,currentMonth-1):(year===2026?7:1),lastVisibleMonth=year===currentYear?currentMonth:12;grid.replaceChildren();
  monthNames.forEach((monthName,index)=>{
   const month=index+1;if(!isAvailable(year,month)||month<firstVisibleMonth||month>lastVisibleMonth)return;const key=year+'-'+String(month).padStart(2,'0'),monthEndAvailable=true,otherAvailable=true,monthEndPeriod=monthEndRecords[key]||{},receiptPeriod=receiptRecords[key]||{},managerReceiptPeriod=receiptManagerFiles[key]||[],franchisePeriod=franchiseRecords[key]||{},card=document.createElement('article');
   card.className='manage-admin-month-card manage-admin-combined-month-card';
   const heading=document.createElement('header'),name=document.createElement('h4'),summary=document.createElement('span');name.textContent=monthName;
   let completeProperties=0;
   if(monthEndAvailable||otherAvailable){properties.forEach(property=>{const monthEndComplete=!monthEndAvailable||monthEndPeriod[property.id]==='finalized',receiptsComplete=!otherAvailable||(receiptPeriod[property.id]==='finalized'&&managerReceiptPeriod.includes(property.id)),franchiseComplete=!otherAvailable||['completed','skipped'].includes(franchisePeriod[property.id]);if(monthEndComplete&&receiptsComplete&&franchiseComplete)completeProperties++;});summary.textContent=completeProperties+'/'+properties.length+' fully complete';heading.append(name,summary);}else heading.append(name);
   card.append(heading);
   if(monthEndAvailable||otherAvailable){const propertyList=document.createElement('div');propertyList.className='manage-admin-combined-properties';properties.forEach(property=>{const propertyCard=document.createElement('section'),propertyName=document.createElement('h5'),workflowList=document.createElement('div'),receiptStatus=receiptPeriod[property.id]||'not-ready',managerReceiptsMissing=(receiptStatus==='ready'||receiptStatus==='finalized')&&!managerReceiptPeriod.includes(property.id);propertyCard.className='manage-admin-combined-property';propertyName.textContent=property.name;workflowList.className='manage-admin-combined-workflows';addWorkflow(workflowList,'Month End','month-end',monthEndPeriod[property.id]||'not-ready',monthEndAvailable,false,property.id,year,month);addWorkflow(workflowList,'Receipts','receipts',receiptStatus,otherAvailable,managerReceiptsMissing,property.id,year,month);addWorkflow(workflowList,'Franchise Fees','franchise',franchisePeriod[property.id]||'not-ready',otherAvailable,false,property.id,year,month);propertyCard.append(propertyName,workflowList);propertyList.append(propertyCard);});card.append(propertyList);}
   grid.append(card);
  });
 }
 yearSelect.value=calendar.dataset.initialYear;yearSelect.addEventListener('change',render);render();
})();
