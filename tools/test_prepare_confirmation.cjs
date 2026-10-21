'use strict';
// Exercise the actual capture-phase transport: cancelling must never submit a POST.
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../www/assets/js/game-transport.js'),'utf8');
const results=[];
function scenario(kind,cmd,accept,submitter=false){
 const handlers={},calls=[],posts=[];
 const document={currentScript:{dataset:{game:'1',csrf:'synthetic',stage:'deal',version:'4'}},
  addEventListener:(name,fn)=>handlers[name]=fn,
  createElement:tag=>tag==='form'?{append(){},submit(){posts.push(true);}}:{},body:{append(){}}};
 class FormData extends Map {constructor(form){super(form.fields);}}
 const context={document,window:{confirm:message=>{calls.push(message);return accept;}},location:new URL('https://example.test/index.php?game=1'),URL,URLSearchParams,FormData,setTimeout:()=>1,clearTimeout(){}};
 vm.runInNewContext(source,context);
 const event={preventDefault(){},stopImmediatePropagation(){}};
 if(kind==='link'){
  const a={href:'https://example.test/index.php?game=1&cmd='+cmd,dataset:{}};
  event.target={closest:()=>a};handlers.click(event);
 }else{
  event.target={querySelector:()=>({}),dataset:{},fields:submitter?[]:[['cmd',cmd]]};
  if(submitter)event.submitter={name:'cmd',value:cmd};
  handlers.submit(event);
 }
 assert.equal(calls.length,1);assert.equal(posts.length,accept?1:0);
 results.push({test:`${kind} ${submitter?'submitter ':''}${cmd} ${accept?'accept':'cancel'}`,pass:true});
}
for(const cmd of ['confirm_deal','reshuffle','finish_draft'])for(const accept of [false,true])scenario('link',cmd,accept);
for(const submitter of [false,true])for(const accept of [false,true])scenario('form','confirm_deal',accept,submitter);
console.log(JSON.stringify({checks:results.length,results},null,2));
