document.addEventListener('DOMContentLoaded',()=>{
  const menu=document.querySelector('[data-menu]');
  menu?.addEventListener('click',()=>{const open=document.body.classList.toggle('menu-open');menu.setAttribute('aria-expanded',String(open));});
  document.querySelector('[data-close-menu]')?.addEventListener('click',()=>{document.body.classList.remove('menu-open');menu?.setAttribute('aria-expanded','false');});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.body.classList.remove('menu-open');menu?.setAttribute('aria-expanded','false');}});
  let pending=null,submitter=null;const dialog=document.querySelector('#confirmation-dialog');
  document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',e=>{
    if(form.dataset.confirmed==='yes'){delete form.dataset.confirmed;return;}
    if(!form.reportValidity())return;e.preventDefault();pending=form;submitter=e.submitter;
    document.querySelector('#confirmation-message').textContent=form.dataset.confirm;dialog.showModal();
  }));
  document.querySelector('[data-confirm-no]')?.addEventListener('click',()=>{dialog.close();pending=null;});
  document.querySelector('[data-confirm-yes]')?.addEventListener('click',()=>{dialog.close();if(pending){pending.dataset.confirmed='yes';pending.requestSubmit(submitter);pending=null;}});
  document.querySelectorAll('[data-filter-target]').forEach(select=>{
    const target=document.getElementById(select.dataset.filterTarget);if(!target)return;
    const filter=()=>{for(const option of target.options){if(!option.value)continue;option.hidden=!!select.value&&option.dataset.parent!==select.value;option.disabled=option.hidden;}if(target.selectedOptions[0]?.disabled)target.value='';target.dispatchEvent(new Event('change'));};
    select.addEventListener('change',filter);filter();
  });
  const sessionType=document.querySelector('[data-session-type]');
  if(sessionType){const toggle=()=>document.querySelectorAll('[data-session-for]').forEach(el=>{const enabled=el.dataset.sessionFor===sessionType.value;el.hidden=!enabled;el.querySelectorAll('select,input').forEach(input=>{input.disabled=!enabled;});});sessionType.addEventListener('change',toggle);toggle();}
  const grant=document.querySelector('[data-grant-access]');if(grant){const toggle=()=>{document.querySelector('[data-grant-fields]').hidden=!grant.checked;};grant.addEventListener('change',toggle);toggle();}
  const publication=document.querySelector('[data-publication-status]');if(publication){const toggle=()=>{document.querySelector('[data-publication-date]').hidden=publication.value!=='scheduled';};publication.addEventListener('change',toggle);toggle();}
  const proof=document.querySelector('[data-file-preview]');proof?.addEventListener('change',()=>{const image=document.querySelector('[data-preview-image]');const file=proof.files[0];if(image&&file&&file.type.startsWith('image/')){if(image.dataset.previewUrl)URL.revokeObjectURL(image.dataset.previewUrl);image.dataset.previewUrl=URL.createObjectURL(file);image.src=image.dataset.previewUrl;image.hidden=false;}});
  const reviewForm=document.querySelector('[data-payment-review]');reviewForm?.querySelectorAll('button[name=decision]').forEach(button=>button.addEventListener('click',()=>{reviewForm.querySelector('[name=rejection_reason]').required=button.value==='reject';}));
  const addQuestion=document.querySelector('[data-add-question]');addQuestion?.addEventListener('click',()=>{const template=document.querySelector('#question-template');const index=Number(addQuestion.dataset.nextIndex||0);const html=template.innerHTML.replaceAll('__INDEX__',String(index));document.querySelector('#questions').insertAdjacentHTML('beforeend',html);addQuestion.dataset.nextIndex=String(index+1);});
  document.addEventListener('click',e=>{const button=e.target.closest('[data-remove-new-question]');if(button)button.closest('.question-card').remove();});
  document.querySelectorAll('[data-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
  document.querySelectorAll('[data-reset-form]').forEach(button=>button.addEventListener('click',()=>button.closest('form').reset()));
});
