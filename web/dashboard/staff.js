"use strict";
titles.staff='Dashboard accounts';
const can=permission=>S.access?.permissions?.includes(permission)===true;
function applyStaffUI(){
  const required={teams:'teams',content:'content',setup:'rounds',audit:'audit',staff:'staff'};
  for(const [tab,permission] of Object.entries(required))document.querySelector(`[data-tab="${tab}"]`).hidden=!can(permission);
  if(required[S.tab]&&!can(required[S.tab]))S.tab='live';
}
function staff(){return '<section class="card" id="staff-card"><h2>Dashboard accounts</h2><p>Loading…</p></section>';}
async function loadStaff(){
  const card=$('#staff-card');if(!card)return;
  try{
    const result=await api('/admin/staff');if(!card.isConnected)return;
    S.accounts=result.accounts;
    card.innerHTML=`<div class="actions-row"><button class="primary" data-staff-add>Add account</button></div><p>Organizer: event controls. Volunteer: live progress and team map only.</p>${table(['Username','Access','Status','Recovery email',''],result.accounts.map(a=>`<tr><td>${esc(a.username)}</td><td>${esc(a.role)}</td><td>${a.active?'Active':'Disabled'}</td><td>${esc(a.email||'—')}</td><td>${a.username.toLowerCase()===S.access.username.toLowerCase()?'You':`<button class="secondary" data-staff-edit="${esc(a.username)}">Edit</button>`}</td></tr>`))}`;
  }catch(error){if(card.isConnected)card.innerHTML=`<p role="alert">${esc(error.message)}</p>`;}
}
function editStaff(username){
  const account=S.accounts?.find(a=>a.username===username)||{username:'',role:'volunteer',active:true,email:''};
  modal(username?'Edit dashboard account':'Add dashboard account',`<label>Username<input name="username" required pattern="[A-Za-z0-9_-]+" maxlength="80" value="${esc(account.username)}" ${username?'readonly':''} autocomplete="off"></label><label>${username?'New password (leave blank to keep)':'Password'}<input name="password" type="password" ${username?'':'required'} minlength="12" maxlength="200" autocomplete="new-password"></label><label>Access<select name="role">${['volunteer','organizer','owner'].map(role=>`<option value="${role}" ${account.role===role?'selected':''}>${role[0].toUpperCase()+role.slice(1)}</option>`).join('')}</select></label><label>Recovery email<input name="email" type="email" value="${esc(account.email)}" maxlength="254"></label><label class="check"><input type="checkbox" name="active" ${account.active?'checked':''}>Active</label>`,async form=>{
    const body=Object.fromEntries(new FormData(form));body.active=form.elements.active.checked;
    await api('/admin/staff'+(username?'/'+encodeURIComponent(username):''),{method:username?'PUT':'POST',body:JSON.stringify(body)});
    await loadStaff();notify(username?'Account updated. Previous sessions revoked.':'Account created. Share its login privately.');
  });
}
document.addEventListener('click',event=>{const button=event.target.closest('button');if(!button)return;if(button.hasAttribute('data-staff-add'))editStaff();else if(button.dataset.staffEdit)editStaff(button.dataset.staffEdit);else if(button.hasAttribute('data-reshuffle'))modal('Reshuffle assignments','<p>Reconnect all enrolled phones before starting. Printed station QR codes remain valid.</p>',async()=>{await api('/admin/shuffle',{method:'POST',body:'{}'});await refresh(true);notify('Assignments shuffled. Reconnect team phones before starting.');},'Reshuffle');});
