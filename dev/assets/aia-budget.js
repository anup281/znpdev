document.addEventListener('DOMContentLoaded',()=>{
  const table=document.querySelector('[data-aia-budget-table]');
  if(!table)return;
  const toast=document.getElementById('dev-toast');let toastTimer;
  const showToast=(message,error=false)=>{if(!toast)return;clearTimeout(toastTimer);toast.classList.add('budget-save-toast');toast.classList.toggle('is-error',error);toast.textContent=error?message:'Budget reallocation saved';toast.hidden=false;toast.style.setProperty('display','block','important');if(!error)toastTimer=setTimeout(()=>{toast.hidden=true;toast.style.removeProperty('display');},2200);};

  const money=value=>{
    const amount=Math.abs(Number(value)||0),sign=Number(value)<0?'-$':'$';
    return `${sign}${amount.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
  };
  const signedMoney=value=>{
    const amount=Number(value)||0;
    return `${amount>0?'+':amount<0?'-':''}$${Math.abs(amount).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
  };
  const number=value=>{
    const normalized=String(value??'').replace(/,/g,'').replace(/\$/g,'').replace(/\s/g,'').replace(/^\+/, '').replace(/^-\$/,'-');
    return Number.isFinite(Number(normalized))?Number(normalized):0;
  };
  const setAmount=(element,value)=>{
    if(!element)return;
    element.textContent=money(value);
    element.classList.toggle('is-over-budget',value<0);
  };
  const recalculate=()=>{
    let originalTotal=0,reallocationTotal=0,spentTotal=0,contractRemainingTotal=0;
    table.querySelectorAll('[data-aia-budget-row]').forEach(row=>{
      const original=number(row.dataset.originalBudget),input=row.querySelector('[data-budget-reallocation]'),hasReallocation=String(input?.value??'').trim()!=='',reallocation=hasReallocation?number(input.value):0,spent=number(row.dataset.totalSpent),contractRemaining=number(row.dataset.contractRemaining),newBudget=hasReallocation?original+reallocation:original;
      originalTotal+=original;reallocationTotal+=reallocation;spentTotal+=spent;contractRemainingTotal+=contractRemaining;
      setAmount(row.querySelector('[data-new-budget]'),newBudget);
      setAmount(row.querySelector('[data-allowance-left]'),newBudget-spent);
      setAmount(row.querySelector('[data-allowance-after-contract]'),newBudget-spent-contractRemaining);
    });
    const newBudgetTotal=originalTotal+reallocationTotal;
    const reallocationFooter=table.querySelector('[data-reallocation-total]');if(reallocationFooter)reallocationFooter.textContent=money(reallocationTotal);
    setAmount(table.querySelector('[data-new-budget-total]'),newBudgetTotal);
    setAmount(table.querySelector('[data-allowance-left-total]'),newBudgetTotal-spentTotal);
    setAmount(table.querySelector('[data-allowance-after-contract-total]'),newBudgetTotal-spentTotal-contractRemainingTotal);
    const balance=document.querySelector('[data-reallocation-balance]'),summary=document.querySelector('[data-new-budget-summary]'),box=document.querySelector('[data-reallocation-balance-box]'),message=document.querySelector('[data-reallocation-message]'),balanced=Math.abs(reallocationTotal)<0.005;
    if(balance)balance.textContent=signedMoney(reallocationTotal);
    if(summary)summary.textContent=money(newBudgetTotal);
    if(box){box.classList.toggle('is-balanced',balanced);box.classList.toggle('is-unbalanced',!balanced);}
    if(message)message.textContent=balanced?'Balanced — total project budget is unchanged.':'Keep adjusting until this reaches $0.00.';
  };
  let saveTimer;
  table.querySelectorAll('[data-budget-reallocation]').forEach(input=>{
    input.addEventListener('focus',()=>{if(input.value.trim()!=='')input.value=number(input.value).toFixed(2);input.select();});
    input.addEventListener('input',()=>{recalculate();clearTimeout(saveTimer);saveTimer=setTimeout(async()=>{
      const body=new URLSearchParams({csrf:table.dataset.csrf||'',project_id:table.dataset.projectId||'',action:'update_reallocation',id:input.dataset.budgetItemId||'0',amount:String(number(input.value))});
      input.classList.add('is-saving');
      try{const response=await fetch('budget_autosave.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'The reallocation could not be saved.');input.classList.add('is-saved');showToast('Saved');setTimeout(()=>input.classList.remove('is-saved'),900);}
      catch(error){showToast(error.message||'The reallocation could not be saved.',true);}
      finally{input.classList.remove('is-saving');}
    },500);});
    input.addEventListener('blur',()=>{const amount=number(input.value);input.value=Math.abs(amount)<0.005?'':money(amount);recalculate();});
  });
  const noteModal=document.querySelector('[data-reallocation-note-modal]'),noteInput=noteModal?.querySelector('[data-reallocation-note-input]'),noteItem=noteModal?.querySelector('[data-reallocation-note-item]');let activeNoteButton=null,noteSaveTimer;
  const closeNoteModal=()=>{if(!noteModal)return;noteModal.hidden=true;noteModal.classList.remove('is-open');document.body.classList.remove('modal-open');activeNoteButton=null;};
  table.querySelectorAll('[data-reallocation-note-button]').forEach(button=>button.addEventListener('click',()=>{if(!noteModal||!noteInput)return;activeNoteButton=button;noteInput.value=button.dataset.note||'';if(noteItem)noteItem.textContent=button.dataset.budgetItemName||'';noteModal.hidden=false;noteModal.classList.add('is-open');document.body.classList.add('modal-open');setTimeout(()=>noteInput.focus(),0);}));
  noteModal?.addEventListener('click',event=>{if(event.target===noteModal||event.target.closest('[data-close-reallocation-note]'))closeNoteModal();});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&noteModal&&!noteModal.hidden)closeNoteModal();});
  noteInput?.addEventListener('input',()=>{if(!activeNoteButton)return;const button=activeNoteButton,note=noteInput.value;button.dataset.note=note;button.classList.toggle('has-note',note.trim()!=='');clearTimeout(noteSaveTimer);noteSaveTimer=setTimeout(async()=>{const body=new URLSearchParams({csrf:table.dataset.csrf||'',project_id:table.dataset.projectId||'',action:'update_reallocation_note',id:button.dataset.budgetItemId||'0',note});try{const response=await fetch('budget_autosave.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body}),result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'The reallocation reason could not be saved.');showToast('Saved');}catch(error){showToast(error.message||'The reallocation reason could not be saved.',true);}},500);});
  table.querySelectorAll('[data-budget-complete]').forEach(button=>button.addEventListener('click',async()=>{const row=button.closest('[data-aia-budget-row]'),completed=!button.classList.contains('is-complete');button.disabled=true;try{const body=new URLSearchParams({csrf:table.dataset.csrf||'',project_id:table.dataset.projectId||'',action:'update_completion',id:button.dataset.budgetItemId||'0',completed:completed?'1':'0'}),response=await fetch('budget_autosave.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body}),result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'The completion status could not be saved.');button.classList.toggle('is-complete',completed);button.setAttribute('aria-pressed',completed?'true':'false');button.title=completed?'Mark row incomplete':'Mark row complete';row?.classList.toggle('is-budget-row-complete',completed);showToast('Saved');}catch(error){showToast(error.message||'The completion status could not be saved.',true);}finally{button.disabled=table.dataset.budgetLocked==='1';}}));
  recalculate();

  const headers=[...table.querySelectorAll('thead th')],storageKey=`aia-budget-widths:v3:${table.dataset.projectId||'0'}`;
  const colgroup=document.createElement('colgroup'),columns=headers.map(()=>document.createElement('col'));
  columns.forEach(column=>colgroup.append(column));table.prepend(colgroup);
  const measured=headers.map(header=>Math.ceil(header.getBoundingClientRect().width));let saved=[];
  try{saved=JSON.parse(localStorage.getItem(storageKey)||'[]');}catch(error){saved=[];}
  const minimum=index=>index<2?140:115,widths=measured.map((width,index)=>Math.max(minimum(index),Number(saved[index])||width));
  const applyWidths=()=>{widths.forEach((width,index)=>columns[index].style.width=`${width}px`);table.style.setProperty('--aia-first-column-width',`${widths[0]}px`);const available=table.closest('.table-scroll')?.clientWidth||0;table.style.width=`${Math.max(available,widths.reduce((sum,width)=>sum+width,0))}px`;};
  const saveWidths=()=>localStorage.setItem(storageKey,JSON.stringify(widths.map(Math.round)));
  headers.forEach((header,index)=>{const handle=document.createElement('span');handle.className='aia-column-resizer';handle.title='Drag to resize · Double-click to reset';handle.setAttribute('aria-hidden','true');handle.addEventListener('pointerdown',event=>{event.preventDefault();handle.setPointerCapture(event.pointerId);const startX=event.clientX,startWidth=widths[index];document.body.classList.add('is-resizing-aia-column');const move=moveEvent=>{widths[index]=Math.max(minimum(index),startWidth+moveEvent.clientX-startX);applyWidths();};const finish=()=>{handle.removeEventListener('pointermove',move);handle.removeEventListener('pointerup',finish);handle.removeEventListener('pointercancel',finish);document.body.classList.remove('is-resizing-aia-column');saveWidths();};handle.addEventListener('pointermove',move);handle.addEventListener('pointerup',finish);handle.addEventListener('pointercancel',finish);});handle.addEventListener('dblclick',event=>{event.preventDefault();widths[index]=Math.max(minimum(index),measured[index]);applyWidths();saveWidths();});header.append(handle);});
  applyWidths();window.addEventListener('resize',applyWidths);
});
