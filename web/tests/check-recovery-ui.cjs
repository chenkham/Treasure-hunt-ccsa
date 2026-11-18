// Run with: node php-server/tests/check-recovery-ui.cjs
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

const field=()=>({value:'',required:false,focus(){}});
const elements=Object.fromEntries(['username','code','password','confirm'].map(name=>[name,field()]));
const login={elements:{username:field(),password:field()}};
const form={elements,reset(){for(const field of Object.values(elements))field.value='';}};
const nodes={'#login-form':login,'#recovery-form':form};
for(const id of ['forgot-password','back-to-login','recovery-code-fields','resend-code','recovery-submit','recovery-message','login-error'])nodes['#'+id]={hidden:false,disabled:false,textContent:''};
nodes['#resend-code'].hidden=true;
let now=0,tick;
const requests=[];
const context={titles:{},$:(id)=>nodes[id],Date:{now:()=>now},setInterval:fn=>{tick=fn;},api:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../dashboard/access.js'),'utf8'),context);
const submit=()=>form.onsubmit({preventDefault(){},currentTarget:form,target:form});
const resend=()=>nodes['#resend-code'].onclick({preventDefault(){},currentTarget:nodes['#resend-code']});
const disabled=()=>{tick();assert.equal(nodes['#resend-code'].disabled,true);assert.equal(nodes['#recovery-submit'].disabled,true);};

(async()=>{
  elements.username.value='Chenkham';
  const first=submit();disabled();await submit();await resend();assert.equal(requests.length,1);
  requests[0].resolve({message:'Code sent',retry_after:60});await first;
  assert.equal(nodes['#resend-code'].disabled,true);assert.equal(nodes['#recovery-submit'].disabled,false);
  now=59000;await resend();assert.equal(requests.length,1);
  now=60000;tick();assert.equal(nodes['#resend-code'].disabled,false);
  const second=resend();disabled();now=65000;disabled();await resend();await submit();assert.equal(requests.length,2);
  requests[1].resolve({message:'New code sent',retry_after:90});await second;
  now=125000;tick();assert.equal(nodes['#resend-code'].disabled,true);
  now=155000;tick();assert.equal(nodes['#resend-code'].disabled,false);
  const failed=resend();disabled();requests[2].reject(Error('Email could not be sent.'));await failed;
  assert.equal(nodes['#recovery-message'].textContent,'Email could not be sent.');
  assert.equal(nodes['#resend-code'].disabled,false);assert.equal(elements.username.readOnly,false);
  elements.password.value=elements.confirm.value='Test-New-Password-Only';elements.code.value='123456';
  const reset=submit();disabled();await resend();assert.equal(requests.length,4);
  assert.equal(requests[3].url,'/admin/password/reset');
  requests[3].resolve({message:'Password updated'});await reset;
  assert.equal(nodes['#resend-code'].hidden,true);assert.equal(nodes['#recovery-submit'].textContent,'Send code');
  assert.equal(nodes['#login-error'].textContent,'Password updated');
  console.log('Recovery UI checks passed: pending requests, cooldown, duplicate actions, delivery failure and reset.');
})().catch(error=>{console.error(error);process.exitCode=1;});
