// Execute the real client in a small DOM adapter; optionally use real HTTP/InnoDB.
// Run with Node. MULTITARGET_TEST_URL + MULTITARGET_TEST_DATADIR enable integration.
const fs = require('node:fs');
const vm = require('node:vm');
const cp = require('node:child_process');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const app = process.cwd();
const php = process.env.TEST_PHP_BINARY || 'php';
const endpoint = process.env.MULTITARGET_TEST_URL || '';
const nativeFetch = global.fetch;
function bridge(...args) {
    return JSON.parse(cp.execFileSync(php, [path.join(app,'tools/test_ajax_multi_target.php'), ...args], {encoding:'utf8'}));
}
const fragmentKeys = ['field_html','fly_zones_html','panel_html','piles_html','pile_reveal_html','info_panel_html'];
async function client({targets=[2,3,4], kind='discharge', fault='', actor='first', stale=false, selected=false, cancelFirst=false}={}) {
    const real = !!endpoint;
    const fixture = real ? bridge('fixture',kind) : {game:1,version:7};
    const origin = real ? endpoint : 'http://localhost/index.php';
    const page = `${origin}?${actor}&game=${fixture.game}&target_ids[]=999&target_ids[]=998&target_ids=997&target_ids[0]=996&card_id=99`;
    const sync = `${origin}?${actor}&game=${fixture.game}&ajax=battle_sync`;
    const listeners = {}, timers = new Map(), requests = [], messages = [];
    let seq=0, active=0, syncFailures=0;
    const node = () => ({innerHTML:'', hidden:false, classList:{toggle(){}}});
    const message = node();
    Object.defineProperty(message,'textContent',{set(v){messages.push(v);}});
    const fragments = Object.fromEntries(fragmentKeys.map(k=>[k,node()]));
    const root = {querySelector(s){return s==='[data-battle-message]' ? message : fragments[s.match(/'([^']+)'/)[1]];},
        addEventListener(k,fn){(listeners[k]??=[]).push(fn);}, contains(){return true;}};
    const document = {currentScript:{dataset:{syncUrl:sync,version:real?'-1':String(fixture.version)}}, hidden:false,
        querySelector(){return root;}, addEventListener(){}, createElement(){return {innerHTML:'',content:{querySelector(){return node();}}};}};
    const pageUrl = new URL(page);
    const window = {location:{href:page,origin:pageUrl.origin,pathname:pageUrl.pathname},crypto:{randomUUID:()=>crypto.randomUUID()},
        addEventListener(){},setTimeout(fn,delay){timers.set(++seq,{fn,delay});return seq;},clearTimeout(id){timers.delete(id);}};
    const snapshot = {ok:true,type:'snapshot',sync_version:8,ui:{sel:0},fragments:Object.fromEntries(fragmentKeys.map(k=>[k,'<div></div>']))};
    const fetch = async (input, options={}) => {
        active++;
        try {
            const url = new URL(input);
            const route = url.searchParams.get('ajax');
            const request = {route,body:options.body||'',force:url.searchParams.has('force_snapshot')};
            requests.push(request);
            const primary = route==='battle_command' && requests.filter(r=>r.route==='battle_command').length===1;
            let status=200, data;
            if (real) {
                let body=options.body;
                if (primary && fault==='nested') {
                    const p=new URLSearchParams(body);p.delete('target_ids[]');p.set('target_ids[0][x]','2');body=p.toString();
                }
                if (primary && fault==='missing_version') {
                    const p=new URLSearchParams(body);p.delete('expected_version');body=p.toString();
                }
                if (primary && stale) {
                    const p=new URLSearchParams(body);p.set('expected_version',String(fixture.version-1));body=p.toString();
                }
                request.body=body||'';
                const response=await nativeFetch(url,{...options,body});status=response.status;
                data=await response.json();
                if (primary && fault==='lost') throw new Error('Reply lost after server commit');
            } else if (route==='battle_command') {
                request.payload=bridge('payload',options.body);
                if (fault==='early') {status=400;data={command_id:new URLSearchParams(options.body).get('command_id'),request_status:'rejected_before_registration',error:'invalid_command_request'};}
                else if (['lost','unknown','processing'].includes(fault)) throw new Error('lost');
                else if (fault==='invalid_json') return {status:500,json:async()=>{throw new Error('bad JSON');}};
                else data={command_id:new URLSearchParams(options.body).get('command_id'),command_status:'committed',terminal:true};
            } else if (route==='battle_command_status') {
                const statusName=fault==='processing' || fault==='invalid_json'?'processing':fault==='unknown'?'unknown':'committed';
                data={command_id:url.searchParams.get('command_id'),command_status:statusName,terminal:statusName==='committed'};
            } else {
                if (url.searchParams.has('force_snapshot') && fault==='snapshot_retry' && syncFailures++===0) throw new Error('snapshot unavailable');
                data=url.searchParams.has('force_snapshot')?snapshot:{ok:true,type:'no_change',sync_version:7};
            }
            request.status=status;request.data=data;
            return {ok:status<400,status,json:async()=>data};
        } finally {active--;}
    };
    class FormData {
        constructor(form){this.entries=form.entries;}
        forEach(fn){for (const [key,value] of this.entries) fn(value,key);}
    }
    vm.runInNewContext(fs.readFileSync(path.join(app,'www/assets/js/battle-sync.js'),'utf8'),
        {window,document,fetch,FormData,URL,URLSearchParams,AbortController});
    async function flush() {
        const deadline=Date.now()+10000;
        do {for(let i=0;i<15;i++)await new Promise(setImmediate);if(Date.now()>deadline)throw new Error('Client did not settle');} while(active);
    }
    await flush();
    if (real && actor==='first' && kind!=='broken') {
        const html=requests[0].data.fragments.info_panel_html;
        assert.match(html,/name="target_ids\[\]"/,'Real server checkbox form');
    }
    const cmd=kind==='heal'?'choose_multi_heal':kind==='row'?'choose_row_spell_targets':'choose_multi_discharge';
    const entries=[['first',''],['game',String(fixture.game)],['cmd',cmd],...targets.map(id=>['target_ids[]',String(id)])];
    if(selected)entries.push(['card_id','1']);
    const form={matches:()=>true,getAttribute:k=>k==='method'?'get':null,
        elements:[...entries.map(([name])=>({name})),{name:'target_ids[]'}],entries};
    if(cancelFirst) {
        const link={href:`${origin}?${actor}&game=${fixture.game}&cmd=cancel_pending`,target:''};
        for(const fn of listeners.click)fn({button:0,defaultPrevented:false,target:{closest:()=>link},preventDefault(){}});
    } else {
        for(const listener of listeners.submit)listener({target:form,defaultPrevented:false,submitter:null,preventDefault(){}});
    }
    await flush();
    // Bound recovery observation; never convert unknown into an outcome.
    for(let round=0;round<4;round++) {
        const timer=[...timers].find(([,t])=>t.delay<20000);
        if(!timer)break;timers.delete(timer[0]);timer[1].fn();await flush();
    }
    const command=requests.find(r=>r.route==='battle_command');
    const before=real?bridge('inspect',String(fixture.game)):null;
    return {fixture,command,requests,messages,before,async cancel() {
        const count=requests.filter(r=>r.route==='battle_command').length;
        const link={href:`${origin}?${actor}&game=${fixture.game}&cmd=cancel_pending`,target:''};
        for(const fn of listeners.click)fn({button:0,defaultPrevented:false,target:{closest:()=>link},preventDefault(){}});
        await flush();
        return requests.filter(r=>r.route==='battle_command').length>count;
    }};
}
async function main() {
    for(const targets of [[],[2],[2,3],[2,3,4],[2,3,4,5],[2,2,3]]) {
        const c=await client({targets,selected:true});
        const sent=new URLSearchParams(c.command.body);
        assert.deepEqual(sent.getAll('target_ids[]'),targets.map(String));
        assert.equal(sent.has('target_ids'),false,'No old scalar targets');
        assert.equal(sent.has('target_ids[0]'),false,'No old indexed targets');
        assert.deepEqual(sent.getAll('card_id'),['1'],'Single form field replaces old query value');
        if(endpoint) {
            const expected=targets.length>0 && new Set(targets).size<=3;
            assert.equal(c.command.data.command_status,expected?'committed':'rejected');
            assert.equal(c.before.journal.length,1);
            for(const id of [2,3,4,5,6])assert.equal(c.before.state.cards[id].hp,10-(expected&&targets.includes(id)?1:0));
            const replay=await nativeFetch(`${endpoint}?first&game=${c.fixture.game}&ajax=battle_command`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:c.command.body});
            assert.equal((await replay.json()).command_status,expected?'committed':'rejected');
            assert.deepEqual(bridge('inspect',String(c.fixture.game)),c.before,'Replay does not apply twice');
            const changed=new URLSearchParams(c.command.body);changed.set('target_ids[]','6');
            const mismatch=await nativeFetch(`${endpoint}?first&game=${c.fixture.game}&ajax=battle_command`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:changed});
            assert.equal(mismatch.status,409,'Reused ID with different payload is rejected');
            assert.deepEqual(bridge('inspect',String(c.fixture.game)),c.before,'Payload mismatch cannot change game/journal');
            const spectator=await nativeFetch(`${endpoint}?second&game=${c.fixture.game}&ajax=battle_sync&force_snapshot=1`);
            const spectatorData=await spectator.json();
            assert.equal(spectatorData.type,'snapshot','Opponent receives authoritative result');
            if(expected)for(const id of new Set(targets)) {
                assert.ok(c.command.data.fragments.info_panel_html.includes(`Target ${id} −1 HP`));
                assert.ok(spectatorData.fragments.info_panel_html.includes(`Target ${id} −1 HP`));
            }
        } else assert.deepEqual(c.command.payload.target_ids||[],targets.map(String));
    }
    if(endpoint) {
        const freshCancel=await client({cancelFirst:true});
        assert.equal(freshCancel.command.data.command_status,'committed','Cancel before any choice submission');
        assert.equal(freshCancel.before.state.battle.pending_multi_discharge,undefined);
        for(const options of [{targets:[1]},{targets:[999]},{actor:'second'},{stale:true},
            {kind:'heal'},{kind:'row'},{fault:'nested'},{fault:'missing_version'},{fault:'lost'}]) {
            const c=await client(options);
            const early=['nested','missing_version'].includes(options.fault);
            if(early) {assert.equal(c.command.data.request_status,'rejected_before_registration');assert.equal(c.before.journal.length,0);}
            else assert.equal(c.before.journal[0].status,options.stale?'conflict':options.actor||options.targets?'rejected':'committed');
            if(options.kind)for(const id of [2,3,4])assert.equal(c.before.state.cards[id].hp,9);
            if(early) {
                assert.equal(await c.cancel(),true,'Early refusal unlocks after snapshot');
                const afterCancel=bridge('inspect',String(c.fixture.game));
                assert.equal(afterCancel.journal[0].status,'committed');
                assert.equal(afterCancel.state.battle.pending_multi_discharge,undefined);
            }
        }
        const cancel=await client({targets:[]}); // rejected empty selection, then reload and cancel through Engine/journal
        const result=await nativeFetch(`${endpoint}?first&game=${cancel.fixture.game}&ajax=battle_command`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({cmd:'cancel_pending',command_id:crypto.randomUUID(),expected_version:String(cancel.before.version)})});
        assert.equal((await result.json()).command_status,'committed');
        assert.equal(bridge('inspect',String(cancel.fixture.game)).state.battle.pending_multi_discharge,undefined);
        const broken=await client({kind:'broken'});
        assert.equal(broken.command.status,500);
        assert.equal(broken.before.journal[0].status,'processing');
        assert.equal(await broken.cancel(),false,'Uncertain execution remains locked');
        const malformed=new URLSearchParams(broken.command.body);malformed.set('target_ids[0][x]','2');
        const reused=await nativeFetch(`${endpoint}?first&game=${broken.fixture.game}&ajax=battle_command`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:malformed});
        const reusedData=await reused.json();
        assert.equal(reusedData.command_status,'processing');assert.equal(reusedData.request_status,undefined,'Early error cannot override existing journal outcome');
    } else {
        for(const fault of ['early','unknown','processing','invalid_json','lost','snapshot_retry']) {
            const c=await client({fault});const locked=['unknown','processing','invalid_json'].includes(fault);
            assert.equal(await c.cancel(),!locked,`Lock invariant: ${fault}`);
            if(fault==='early')assert.equal(c.requests.some(r=>r.route==='battle_command_status'),false);
        }
    }
    console.log(`AJAX multi-target JS ${endpoint?'HTTP/InnoDB':'transport/recovery'} tests passed`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
