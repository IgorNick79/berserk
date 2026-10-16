const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const sample = (patch={}) => ({period_id:1,owner:'host',status:'running',remaining_seconds:180,
    anchor_at:1000,pause_reason:null,server_now:1000,limit_seconds:180,...patch});
const keys=['field_html','fly_zones_html','panel_html','piles_html','pile_reveal_html','info_panel_html'];
function setup(boot=sample(),viewer='player') {
    let now=0,seq=0,sounds=0,current=boot,version=1,offline=false;
    const events={},windowEvents={},timeouts=new Map(),intervals=new Map(),queue=[],requests=[],held=new Map();
    const value={textContent:''},label={textContent:''};
    let timerNode;
    function replaceTimer(){timerNode={dataset:{turnTimerState:JSON.stringify(boot)},setAttribute(){},querySelector:s=>s==='[data-turn-timer-value]'?value:label};}
    replaceTimer();
    const fragment={innerHTML:''};
    const info={set innerHTML(v){replaceTimer();},get innerHTML(){return '';}};
    const root={contains:()=>true,addEventListener(k,fn){(events[k]??=[]).push(fn);},
        querySelector(s){if(s==='[data-turn-timer]')return timerNode;if(s==='[data-battle-message]')return null;return s.includes('info_panel_html')?info:fragment;}};
    const document={hidden:false,currentScript:{dataset:{syncUrl:'http://localhost/index.php?first&game=1&ajax=battle_sync',version:'1',viewerKey:viewer}},
        querySelector:()=>root,addEventListener(k,fn){(events[k]??=[]).push(fn);},
        removeEventListener(k,fn){events[k]=(events[k]||[]).filter(f=>f!==fn);},
        createElement:()=>({innerHTML:'',content:{querySelector:()=>fragment}})};
    class Context {
        constructor(){this.state='running';this.currentTime=0;this.destination={};}
        resume(){return Promise.resolve();}close(){return Promise.resolve();}
        createOscillator(){return {frequency:{value:0},connect(){},disconnect(){},start(){sounds++;},stop(){}};}
        createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){},disconnect(){}};}
    }
    const window={AudioContext:Context,crypto:{randomUUID:()=> 'timer-command'},location:{href:'http://localhost/index.php?first&game=1',origin:'http://localhost',pathname:'/index.php'},
        addEventListener(k,fn){windowEvents[k]=fn;},history:{pushState(){}},
        setInterval(fn,delay){intervals.set(++seq,fn);return seq;},clearInterval:id=>intervals.delete(id),
        setTimeout(fn,delay){timeouts.set(++seq,{fn,delay});return seq;},clearTimeout:id=>timeouts.delete(id)};
    const fetch=async(input,options={})=>{
        const url=new URL(input),route=url.searchParams.get('ajax');requests.push({route,options});
        if(offline)throw new Error('offline');
        if(held.has(route))return new Promise(resolve=>{held.get(route).resolve=(data,status=200)=>resolve({ok:status<400,status,json:async()=>data});});
        let data=queue.length?queue.shift():{ok:true,type:'no_change',sync_version:version,turn_timer:current};
        if(route==='battle_command')data=null;
        if(route==='battle_command_status')data={command_id:'timer-command',command_status:'unknown',terminal:false,sync_version:version,turn_timer:current};
        return {ok:true,status:200,json:async()=>data};
    };
    const context=vm.createContext({window,document,fetch,URL,URLSearchParams,AbortController,
        performance:{now:()=>now},Date:{now(){throw new Error('Wall clock must not drive countdown');}}});
    vm.runInContext(fs.readFileSync('www/assets/js/battle-turn-timer.js','utf8'),context);
    vm.runInContext(fs.readFileSync('www/assets/js/battle-sync.js','utf8'),context);
    const flush=async()=>{for(let i=0;i<30;i++)await Promise.resolve();};
    const fire=k=>(events[k]||[]).slice().forEach(fn=>fn());
    async function poll(){await flush();const timer=[...timeouts].find(([,t])=>t.delay<20000);if(timer){timeouts.delete(timer[0]);timer[1].fn();}await flush();}
    return {window,root,document,value,label,requests,intervals,timeouts,flush,fire,poll,
        get node(){return timerNode;},get sounds(){return sounds;},
        tick(ms){now+=ms;for(const fn of intervals.values())fn();},
        offline(on){offline=on;},
        hold(route){held.set(route,{});},
        response(route){return held.get(route).resolve;},
        release(route,data,status=200){const pending=held.get(route);held.delete(route);pending.resolve(data,status);},
        state(next,v){current=next;version=v;},
        async accept(next,v=version,full=false){current=next;version=v;
            queue.push(full?{ok:true,type:'snapshot',sync_version:v,turn_timer:next,ui:{},fragments:Object.fromEntries(keys.map(k=>[k,'']))}
                :{ok:true,type:'no_change',sync_version:v,turn_timer:next});await poll();},
        command(query='cmd=end_turn'){const link={href:'http://localhost/index.php?first&game=1&'+query,target:''};
            for(const fn of events.click||[])fn({button:0,defaultPrevented:false,target:{closest:()=>link},preventDefault(){}});},
        unload(){windowEvents.beforeunload();}};
}
async function main(){
    await edgeCases();
    // T3: a recovered user command may precede the server's subsequent auto-end.
    // The client follows sync_version/snapshot, never emits another end_turn.
    const timeout=setup(sample({status:'expired',remaining_seconds:0,anchor_at:null,server_now:1180}));await timeout.flush();
    timeout.hold('battle_command');timeout.command('cmd=confirm_strike&wait_id=1');await timeout.flush();
    timeout.document.hidden=true;timeout.fire('visibilitychange');timeout.tick(30000);
    const next=sample({period_id:2,owner:'player',server_now:1210});
    timeout.state(next,3);timeout.hold('battle_command_status');
    timeout.document.hidden=false;timeout.fire('visibilitychange');await timeout.poll();
    timeout.release('battle_command_status',{command_id:'timer-command',command_status:'committed',terminal:true,
        before_version:1,after_version:2,sync_version:3,turn_timer:next});await timeout.flush();
    assert.equal(timeout.node.dataset.mine,'1');assert.equal(timeout.value.textContent,'03:00','Recovery uses next server period after auto-end');
    assert.equal(timeout.requests.filter(r=>r.route==='battle_command').length,1,'Hidden tab recovery never posts auto-end');
    assert.equal(timeout.intervals.size,1);timeout.unload();
    const ack=setup();await ack.flush();
    await ack.accept(sample({server_now:1001,remaining_seconds:179}),9,true);
    ack.hold('battle_command');ack.command('cmd=confirm_strike&wait_id=old-screen-7');await ack.flush();
    const ackBody=new URLSearchParams(ack.requests.find(r=>r.route==='battle_command').options.body);
    assert.equal(ackBody.get('expected_version'),'9','ACK uses fresh sync version');
    assert.equal(ackBody.get('wait_id'),'old-screen-7','Fresh version must not replace the old HTML wait identity');
    assert.equal(ackBody.get('cmd'),'confirm_strike');ack.unload();
    const t=setup();await t.flush();
    assert.equal(t.value.textContent,'03:00');assert.equal(t.node.dataset.mine,'0');
    assert.equal(t.intervals.size,1);
    assert.equal(t.window.BerserkTurnTimer.create(t.root,'player',1,''),t.root.berserkTurnTimer,'Mount is idempotent');
    t.fire('pointerdown');assert.equal(t.sounds,0,'No initial/reload sound');
    t.tick(1000);assert.equal(t.value.textContent,'02:59');
    await t.accept(sample());assert.equal(t.value.textContent,'02:59','Duplicate second cannot refund elapsed time');
    await t.accept(sample({server_now:1002,remaining_seconds:178}),1,true);
    assert.equal(t.value.textContent,'02:58','Full fragment replacement keeps timer mounted');
    await t.accept(sample({status:'paused',pause_reason:'foreign_wait',remaining_seconds:120,anchor_at:null,server_now:1040}),2);
    t.tick(60000);assert.equal(t.value.textContent,'02:00');assert.match(t.label.textContent,/Пауза/);
    await t.accept(sample({remaining_seconds:120,server_now:1100}),3);
    t.tick(1000);assert.equal(t.value.textContent,'01:59');
    await t.accept(sample({remaining_seconds:180,server_now:1150}),2);
    assert.equal(t.value.textContent,'01:59','Stale state version ignored');
    await t.accept(sample({period_id:2,owner:'player',status:'paused',pause_reason:'start_phase',server_now:1101}),4);
    assert.equal(t.sounds,0,'Wait for server-confirmed start, not early active switch');
    await t.accept(sample({period_id:2,owner:'player',server_now:1102}),5);
    assert.equal(t.sounds,2,'One short two-tone notification for own new turn');
    assert.equal(t.node.dataset.mine,'1');
    await t.accept(sample({period_id:2,owner:'player',server_now:1103,remaining_seconds:179}),5);
    assert.equal(t.sounds,2,'Polling does not repeat sound');
    await t.accept(sample({period_id:2,owner:'player',status:'expired',remaining_seconds:0,anchor_at:null,server_now:1300}),5);
    t.tick(200000);assert.equal(t.value.textContent,'00:00');assert.equal(t.sounds,2,'No expiry notification or automatic command');
    assert.equal(t.requests.filter(r=>r.route==='battle_command').length,0);
    await t.accept(sample({period_id:1,server_now:9999}),6);
    assert.equal(t.value.textContent,'00:00','Older period ignored even at a newer version');
    await t.accept(sample({period_id:3,server_now:1400,remaining_seconds:100}),7);
    t.document.hidden=true;t.fire('visibilitychange');assert.equal(t.intervals.size,0);
    t.tick(30000);t.document.hidden=false;t.fire('visibilitychange');await t.flush();
    assert.equal(t.intervals.size,1,'Visibility restoration never duplicates interval');
    await t.accept(sample({period_id:3,server_now:1430,remaining_seconds:70}),7);
    assert.equal(t.value.textContent,'01:10','Visibility resync uses fresh no-change timer');
    t.offline(true);await t.poll();t.tick(20000);assert.equal(t.value.textContent,'00:50','Network loss does not reset clock');
    t.offline(false);await t.accept(sample({period_id:3,server_now:1450,remaining_seconds:50}),7);
    assert.equal(t.value.textContent,'00:50');
    t.command();await t.flush();await t.poll();
    assert.equal(t.requests.at(-1).route,'battle_command_status');
    await t.accept(sample({period_id:4,owner:'player',server_now:1500,remaining_seconds:160}),8);
    assert.equal(t.value.textContent,'02:40','Command recovery carries server timer without unlocking unknown');
    const sent=t.requests.filter(r=>r.route==='battle_command').length;t.command();await t.flush();
    assert.equal(t.requests.filter(r=>r.route==='battle_command').length,sent,'Unknown command remains locked');
    assert.equal(t.intervals.size,1);t.unload();assert.equal(t.intervals.size,0);assert.equal(t.timeouts.size,0);
    const reload=setup(sample({period_id:4,owner:'player',remaining_seconds:90,server_now:1500}));await reload.flush();
    reload.fire('pointerdown');assert.equal(reload.value.textContent,'01:30');assert.equal(reload.sounds,0,'Reload never resets budget or announces old turn');reload.unload();
    const legacy=setup(null);await legacy.flush();assert.equal(legacy.value.textContent,'—:—');legacy.unload();
    const zero=setup(sample({remaining_seconds:1}));await zero.flush();zero.tick(5000);assert.equal(zero.value.textContent,'00:00');zero.unload();
    const background=setup();await background.flush();background.fire('pointerdown');
    background.document.hidden=true;background.fire('visibilitychange');
    assert.equal([...background.timeouts.values()][0].delay,10000,'Background audio uses existing, slower sync');
    assert.equal(background.intervals.size,0);
    await background.accept(sample({period_id:2,owner:'player',server_now:1010}),2);
    assert.equal(background.sounds,2,'New own turn can alert an armed hidden tab');
    assert.equal(background.intervals.size,0,'Background sync cannot start another UI interval');background.unload();
    console.log('Turn timer monotonic countdown/AJAX/audio tests passed');
}
async function edgeCases(){
    const t=setup(sample({remaining_seconds:100,server_now:1100}));await t.flush();
    t.tick(2000);
    await t.accept(sample({remaining_seconds:120,server_now:1080}),2);
    assert.equal(t.value.textContent,'01:38','Newer persistence version cannot refund an older projection');
    await t.accept(sample({status:'paused',remaining_seconds:120,anchor_at:null,server_now:1090}),3);
    assert.equal(t.node.dataset.status,'running','Older projection cannot roll back status');
    await t.accept(sample({status:'paused',remaining_seconds:50,anchor_at:null,server_now:1110}),2);
    assert.equal(t.node.dataset.status,'running','Rejected temporal sample still advances version watermark');
    await t.accept(sample({remaining_seconds:100,server_now:1100}),3);
    assert.equal(t.value.textContent,'01:38','Newer version with identical sample cannot refund local elapsed time');
    await t.accept(sample({status:'paused',remaining_seconds:98,anchor_at:null,server_now:1100,pause_reason:'foreign_wait'}),4);
    t.tick(10000);assert.equal(t.value.textContent,'01:38','Same-second authoritative pause is accepted');
    await t.accept(sample({remaining_seconds:98,server_now:1100}),5);
    t.tick(1000);assert.equal(t.value.textContent,'01:37','Same-second authoritative resume is accepted');
    await t.accept(sample({status:'expired',remaining_seconds:0,server_now:1200,anchor_at:null}),6);
    await t.accept(sample({remaining_seconds:98,server_now:1101}),7);
    assert.equal(t.node.dataset.status,'expired');assert.equal(t.value.textContent,'00:00');
    await t.accept(sample({period_id:2,server_now:1000}),8);
    assert.equal(t.value.textContent,'03:00','A new period may have an earlier wall-clock timestamp');t.unload();

    for(const phase of ['sending','sending-rejected','recovering','syncing']){
        const c=setup();await c.flush();
        const sending=phase.startsWith('sending');
        const terminal=()=>({command_id:'timer-command',command_status:'committed',terminal:true,sync_version:2,
            turn_timer:sample()});
        if(sending)c.hold('battle_command');
        if(phase==='recovering')c.hold('battle_command_status');
        if(phase==='syncing'){c.hold('battle_command_status');c.hold('battle_sync');}
        c.command();await c.flush();
        if(!sending)await c.poll();
        if(phase==='syncing'){c.release('battle_command_status',terminal());await c.flush();}
        const staleResponse=sending?null:c.response(phase==='syncing'?'battle_sync':'battle_command_status');
        c.document.hidden=true;c.fire('visibilitychange');c.tick(30000);
        c.state(sample({remaining_seconds:150,server_now:1030}),2);
        c.document.hidden=false;c.fire('visibilitychange');
        if(phase==='syncing'){
            // Resolve the aborted request after its replacement to exercise generation guards.
            const old=c.requests.filter(r=>r.route==='battle_sync').at(-2);
            assert.equal(old.options.signal.aborted,true);
            c.release('battle_sync',{ok:true,type:'no_change',sync_version:2,turn_timer:sample({remaining_seconds:150,server_now:1030})});
        }else{
            await c.poll();
            if(phase==='recovering')c.release('battle_command_status',{...terminal(),turn_timer:sample({remaining_seconds:150,server_now:1030})});
        }
        await c.flush();
        if(sending)assert.equal(c.requests.at(-1).route,'battle_command_status','Returning while sending immediately reads journal without waiting for POST timeout');
        assert.equal(c.value.textContent,'02:30',phase+' refreshes timer on return');
        assert.equal(c.requests.filter(r=>r.route==='battle_command').length,1,phase+' never repeats POST');
        assert.equal(c.intervals.size,1);
        if(staleResponse){
            staleResponse({ok:true,type:'no_change',...terminal(),sync_version:9,turn_timer:sample({period_id:9,server_now:9999})});
            await c.flush();assert.equal(c.value.textContent,'02:30','Aborted generation cannot apply a late response');
        }
        if(sending){
            c.command();await c.flush();
            assert.equal(c.requests.filter(r=>r.route==='battle_command').length,1,'Unknown outcome remains locked');
            const syncCount=c.requests.filter(r=>r.route==='battle_sync').length;
            c.release('battle_command',phase==='sending-rejected'
                ?{command_id:'timer-command',request_status:'rejected_before_registration',error:'invalid_payload'}:terminal(),
                phase==='sending-rejected'?400:200);await c.flush();
            assert.equal(c.requests.filter(r=>r.route==='battle_sync').length,syncCount+1,'Late definitive POST response triggers authoritative sync');
        }
        c.unload();
    }
}
main().catch(e=>{console.error(e);process.exitCode=1;});
