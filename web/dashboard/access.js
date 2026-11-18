"use strict";
let recoveryStep='request',resendAt=0,recoveryBusy=false;
function recoveryView(open){
  $('#login-form').hidden=open;$('#recovery-form').hidden=!open;
  if(open){$('#recovery-form').elements.username.value=$('#login-form').elements.username.value;$('#recovery-form').elements.username.focus();}
}
$('#forgot-password').onclick=()=>recoveryView(true);
$('#back-to-login').onclick=()=>{recoveryView(false);$('#login-form').elements.password.focus();};
async function requestRecovery(){
  const form=$('#recovery-form');
  const result=await api('/admin/password/request',{method:'POST',body:JSON.stringify({username:form.elements.username.value})});
  recoveryStep='reset';resendAt=Date.now()+Math.max(60,Number(result.retry_after)||60)*1000;
  $('#recovery-code-fields').hidden=false;$('#resend-code').hidden=false;
  for(const name of ['code','password','confirm'])form.elements[name].required=true;
  $('#recovery-submit').textContent='Update password';$('#recovery-message').textContent=result.message;
  form.elements.code.focus();
}
function updateRecoveryButtons(){
  const seconds=Math.max(0,Math.ceil((resendAt-Date.now())/1000));
  $('#recovery-submit').disabled=recoveryBusy;
  $('#resend-code').textContent=seconds?`Resend in ${seconds}s`:'Resend code';
  $('#resend-code').disabled=recoveryBusy||seconds>0;
  $('#recovery-form').elements.username.readOnly=recoveryBusy;
}
async function handleRecovery(event){
  event.preventDefault();if(recoveryBusy)return;
  const form=$('#recovery-form'),sendCode=recoveryStep==='request'||event.currentTarget===$('#resend-code');
  if(sendCode&&Date.now()<resendAt)return;
  recoveryBusy=true;updateRecoveryButtons();
  $('#recovery-message').textContent=sendCode?'Sending code…':'Updating password…';
  try{
    if(sendCode)await requestRecovery();
    else{
      if(form.elements.password.value!==form.elements.confirm.value)throw Error('Passwords do not match.');
      const result=await api('/admin/password/reset',{method:'POST',body:JSON.stringify({username:form.elements.username.value,code:form.elements.code.value,password:form.elements.password.value})});
      $('#login-form').elements.username.value=form.elements.username.value;
      form.reset();recoveryStep='request';$('#recovery-code-fields').hidden=true;$('#resend-code').hidden=true;
      for(const name of ['code','password','confirm'])form.elements[name].required=false;
      $('#recovery-submit').textContent='Send code';recoveryView(false);$('#login-error').textContent=result.message;
    }
  }catch(error){$('#recovery-message').textContent=error.message;}
  finally{recoveryBusy=false;updateRecoveryButtons();}
}
$('#recovery-form').onsubmit=handleRecovery;
$('#resend-code').onclick=handleRecovery;
setInterval(updateRecoveryButtons,1000);

