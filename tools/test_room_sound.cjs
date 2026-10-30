const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert/strict');
const code=fs.readFileSync(path.join(__dirname,'../www/assets/js/room-sound.js'),'utf8');
let checks=0;const check=(value,label)=>{checks++;assert(value,label);};
function boot(storage=new Map(),preferences={sound_enabled:true}){
 const events={},frequencies=[];
 const window={BerserkPreferences:{current:preferences},AudioContext:class{
  constructor(){this.state='running';this.currentTime=0;this.destination={};}
  resume(){return Promise.resolve();}
  createOscillator(){const node={frequency:{value:0},connect(){},disconnect(){},start(){frequencies.push(node.frequency.value);},stop(){}};return node;}
  createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){},disconnect(){}};}
 }};
 const scope={window,document:{addEventListener(name,fn){(events[name]??=[]).push(fn);}},sessionStorage:{getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)}};
 vm.runInNewContext(code,scope);
 return {window,scope,storage,frequencies,emit:()=>events['berserk:preferences'].forEach(fn=>fn())};
}
const first=boot();first.window.BerserkRoomSound.notify('request:one');check(first.frequencies.length===1,'one event produces one tone');
first.window.BerserkRoomSound.notify('request:one');check(first.frequencies.length===1,'repeated event remains silent');
const api=first.window.BerserkRoomSound;vm.runInNewContext(code,first.scope);check(first.window.BerserkRoomSound===api,'repeated script load keeps one controller');
api.notify('decision:one');check(first.frequencies.length===2,'new decision produces exactly one tone');
first.window.BerserkPreferences.current.sound_enabled=false;first.emit();api.notify('withdrawal:muted');check(first.frequencies.length===2,'muted event remains silent');
first.window.BerserkPreferences.current.sound_enabled=true;first.emit();check(first.frequencies.length===2,'unmute does not replay muted event');
api.notify('decision:navigation',true);check(first.frequencies.length===2,'navigation notification deferred');
const second=boot(first.storage,null);check(second.frequencies.length===0,'destination waits for account preference');
second.window.BerserkPreferences.current={sound_enabled:true};second.emit();check(second.frequencies.length===1,'destination plays one tone');second.emit();check(second.frequencies.length===1,'refresh does not repeat destination tone');
check(boot(first.storage).frequencies.length===0,'reload does not replay delivered notification');
const expired=new Map([['berserk-room-sound-pending',JSON.stringify({at:Date.now()-6000})]]);check(boot(expired).frequencies.length===0,'expired notification not replayed');
console.log('Room notification audio: '+checks+' checks passed');
