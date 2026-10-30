const assert=require('assert'),vm=require('vm'),fs=require('fs'),path=require('path');
const source=fs.readFileSync(path.join(__dirname,'../www/assets/js/battle-debug.js'),'utf8');let checks=0;
for(const authorized of [false,true]){
 const handlers={},trigger=authorized?{disabled:false,setAttribute(){},focus(){}}:null;
 let overlay={hidden:true,querySelector(){return {};},focus(){},contains(){return true;}};
 const host={querySelector(){return overlay;}},root={dataset:{},querySelector(){return host;},contains(){return true;}};
 const document={querySelector(){return trigger;},addEventListener(k,f){handlers[k]=f;},removeEventListener(k){delete handlers[k];}};
 const window={BerserkMenu:{close(){}}};vm.runInNewContext(source,{window,document,location:{hash:''},MutationObserver:class{observe(){}disconnect(){}}});
 const controller=window.BerserkBattleDebug.create(root);
 if(!authorized){assert.equal(controller,null);checks++;continue;}
 assert(controller);checks++;handlers.click({target:{closest(s){return s==='[data-menu-debug]'?trigger:null;}},preventDefault(){}});assert.equal(overlay.hidden,false);checks++;
 root.dataset.matchPaused='1';controller.render();assert.equal(trigger.disabled,true);assert.equal(overlay.hidden,true);checks++;
 root.dataset.matchPaused='0';controller.render();assert.equal(trigger.disabled,false);checks++;
 overlay=null;controller.render();assert.equal(trigger.disabled,true);checks++;controller.destroy();
}
console.log('Battle Debug adapter: '+checks+' checks passed');
