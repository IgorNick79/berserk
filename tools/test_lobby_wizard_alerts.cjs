const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/../www/assets/js/lobby-wizard.js','utf8');
const scenario='{"cards":[{"owner":"host","ukid":"s2_24"}]}';
function setup(fetchImpl){
 const handlers={},alerts=[],redirects=[],submit={disabled:false},error={textContent:''};
 const smart={disabled:false},random={checked:false};
 const form={elements:{lucktest_scenario:{value:scenario,addEventListener(){}}},hasAttribute:()=>true,
  addEventListener:(name,fn)=>handlers[name]=fn,
  querySelector:s=>s==='[type=submit]'?submit:s==='[data-wizard-error]'?error:s.includes('value=smart')?smart:random};
 const context={document:{querySelector:()=>form,querySelectorAll:()=>[],addEventListener(){}},window:{BerserkDialogs:{alert:msg=>alerts.push(msg)}},
  location:{assign:url=>redirects.push(url)},fetch:fetchImpl,URLSearchParams,FormData:class{constructor(){return [['lucktest_scenario',scenario]];}}};
 vm.runInNewContext(code,context);
 return {send:()=>handlers.submit({preventDefault(){}}),alerts,redirects,submit,error,smart,random};
}
(async()=>{
 let sent;let a=setup(async(url,options)=>{sent=options.body.get('lucktest_scenario');return {ok:false,json:async()=>({error:'Сценарий LuckTest: неверный JSON или превышена глубина'})};});
 await a.send();assert.equal(a.alerts.length,1);assert.match(a.alerts[0],/^Ошибка: Сценарий LuckTest/);assert.equal(a.error.textContent,'');assert.equal(a.submit.disabled,false);assert.equal(sent,scenario);assert.equal(a.smart.disabled,true);assert.equal(a.random.checked,true);
 a=setup(async()=>{throw Error('network failure');});await a.send();assert.equal(a.alerts[0],'Ошибка: network failure');assert.equal(a.submit.disabled,false);
 a=setup(async()=>({ok:true,json:async()=>({url:'index.php?game=123'})}));await a.send();assert.equal(a.alerts.length,0);assert.deepEqual(a.redirects,['index.php?game=123']);
 let release,calls=0;a=setup(()=>{calls++;return new Promise(resolve=>release=resolve);});const pending=a.send();await a.send();assert.equal(calls,1);release({ok:false,json:async()=>({error:'rejected'})});await pending;
 console.log('PASS bot wizard: server/network errors use existing alert, no inline error, unchanged JSON, retry, success, duplicate-submit guard');
})().catch(e=>{console.error(e);process.exitCode=1;});
