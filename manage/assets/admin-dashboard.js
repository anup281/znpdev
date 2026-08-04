(function(){
 const calendar=document.querySelector('[data-admin-bank-calendar]');if(!calendar)return;
 const monthSelect=calendar.querySelector('[data-admin-bank-month]'),yearSelect=calendar.querySelector('[data-admin-bank-year]'),days=calendar.querySelector('[data-admin-bank-days]'),title=document.getElementById('adminBankCalendarTitle');
 const monthNames=['January','February','March','April','May','June','July','August','September','October','November','December'],today=calendar.dataset.today,startDate=calendar.dataset.startDate;
 let properties=[],records={};try{properties=JSON.parse(calendar.dataset.properties||'[]');records=JSON.parse(calendar.dataset.records||'{}');}catch(error){}
 const dateValue=(year,month,day)=>year+'-'+String(month).padStart(2,'0')+'-'+String(day).padStart(2,'0');
 function propertyStatus(value,propertyId){if(value>today)return'not-due';return(records[value]||[]).includes(propertyId)?'completed':'incomplete';}
 function render(){
  const year=Number(yearSelect.value),month=Number(monthSelect.value),first=new Date(year,month-1,1).getDay(),count=new Date(year,month,0).getDate(),previousCount=new Date(year,month-1,0).getDate();title.textContent=monthNames[month-1]+' '+year;days.replaceChildren();
  for(let cellIndex=0;cellIndex<42;cellIndex++){
   const offset=cellIndex-first+1,cell=document.createElement('article');cell.className='manage-admin-bank-day';cell.setAttribute('role','gridcell');let cellYear=year,cellMonth=month,day=offset;
   if(offset<1){cellMonth--;if(cellMonth<1){cellMonth=12;cellYear--;}day=previousCount+offset;cell.classList.add('outside-month');}else if(offset>count){cellMonth++;if(cellMonth>12){cellMonth=1;cellYear++;}day=offset-count;cell.classList.add('outside-month');}
   const value=dateValue(cellYear,cellMonth,day),available=value>=startDate;if(!available)cell.classList.add('before-start');if(value===today)cell.classList.add('today');
   const heading=document.createElement('header'),number=document.createElement('span');number.className='manage-admin-bank-day-number';number.textContent=String(day);heading.append(number);
   if(available){const completed=properties.filter(property=>(records[value]||[]).includes(property.id)).length,summary=document.createElement('span');summary.className='manage-admin-bank-day-count';summary.textContent=value>today?'Not due':completed+'/'+properties.length+' complete';heading.append(summary);}
   cell.append(heading);
   if(available){const list=document.createElement('div');list.className='manage-admin-bank-properties';properties.forEach(property=>{const status=propertyStatus(value,property.id),row=document.createElement('div');row.className='manage-admin-bank-property '+status;row.title=property.name+' — '+(status==='not-due'?'Not due':status.charAt(0).toUpperCase()+status.slice(1));const icon=document.createElement('i');icon.className=status==='completed'?'fa-solid fa-check':status==='incomplete'?'fa-solid fa-xmark':'fa-regular fa-clock';icon.setAttribute('aria-hidden','true');const name=document.createElement('span');name.textContent=property.name;row.append(icon,name);list.append(row);});cell.append(list);}
   days.append(cell);
  }
 }
 monthSelect.addEventListener('change',render);yearSelect.addEventListener('change',render);monthSelect.value=calendar.dataset.initialMonth;yearSelect.value=calendar.dataset.initialYear;render();
})();

(function(){
 const calendars=document.querySelectorAll('[data-admin-month-calendar]');if(!calendars.length)return;
 const monthNames=['January','February','March','April','May','June','July','August','September','October','November','December'];
 calendars.forEach(calendar=>{
  const yearSelect=calendar.querySelector('[data-admin-month-year]'),grid=calendar.querySelector('[data-admin-month-grid]'),title=calendar.querySelector('[data-admin-month-title]'),calendarTitle=calendar.dataset.calendarTitle,currentYear=Number(calendar.dataset.currentYear),currentMonth=Number(calendar.dataset.currentMonth),startYear=Number(calendar.dataset.startYear),startMonth=Number(calendar.dataset.startMonth);
  let properties=[],records={};try{properties=JSON.parse(calendar.dataset.properties||'[]');records=JSON.parse(calendar.dataset.records||'{}');}catch(error){}
  function render(){
   const year=Number(yearSelect.value);title.textContent=year+' '+calendarTitle;grid.replaceChildren();
   monthNames.forEach((monthName,index)=>{
    const month=index+1,key=year+'-'+String(month).padStart(2,'0'),available=year>startYear||(year===startYear&&month>=startMonth),completedIds=records[key]||[],monthCard=document.createElement('article');monthCard.className='manage-admin-month-card';
    if(!available)monthCard.classList.add('unavailable');
    const heading=document.createElement('header'),name=document.createElement('h4');name.textContent=monthName;heading.append(name);
    if(available){const completed=properties.filter(property=>completedIds.includes(property.id)).length,summary=document.createElement('span'),periodNotDue=year>currentYear||(year===currentYear&&month>=currentMonth);summary.textContent=periodNotDue&&completed===0?'Not due':completed+'/'+properties.length+' complete';heading.append(summary);}
    monthCard.append(heading);
    if(available){const list=document.createElement('div');list.className='manage-admin-month-properties';properties.forEach(property=>{const isComplete=completedIds.includes(property.id),periodNotDue=year>currentYear||(year===currentYear&&month>=currentMonth),status=isComplete?'completed':periodNotDue?'not-due':'incomplete',row=document.createElement('div');row.className='manage-admin-month-property '+status;row.title=property.name+' — '+(status==='not-due'?'Not due':status.charAt(0).toUpperCase()+status.slice(1));const icon=document.createElement('i');icon.className=status==='completed'?'fa-solid fa-check':status==='incomplete'?'fa-solid fa-xmark':'fa-regular fa-clock';icon.setAttribute('aria-hidden','true');const propertyName=document.createElement('span');propertyName.textContent=property.name;row.append(icon,propertyName);list.append(row);});monthCard.append(list);}
    grid.append(monthCard);
   });
  }
  yearSelect.value=calendar.dataset.initialYear;yearSelect.addEventListener('change',render);render();
 });
})();
