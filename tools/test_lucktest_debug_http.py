"""Actual auth/Lobby/index HTTP checks against a disposable PHP copy and guarded MariaDB."""
import argparse, http.cookiejar, json, os, pathlib, re, socket, subprocess, time, urllib.error, urllib.parse, urllib.request
from test_auth_http import Browser, NoRedirect
ROOT=pathlib.Path(__file__).resolve().parents[1]

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--php',default='C:/OSPanel/modules/php/PHP_8.1/php.exe');parser.add_argument('--isolated',action='store_true');args=parser.parse_args()
    assert args.isolated and not (ROOT/'.git').exists(), 'Disposable copy required'
    for name in ['auth.local.php','db.php','battle_history.local.php']: assert not (ROOT/'config'/name).exists(), 'Refuse existing config'
    with socket.socket() as probe: assert probe.connect_ex(('127.0.0.1',33988))!=0, 'HTTP test port already occupied'
    server=None;stream=None;checks=[]
    def check(name,ok):
        checks.append({'test':name,'pass':bool(ok)})
        if not ok: raise AssertionError(name)
    def cli(*params):
        answer=subprocess.run([args.php,str(ROOT/'tools/test_lucktest_debug_http_fixture.php'),*map(str,params),'--isolated'],cwd=ROOT,env=os.environ,capture_output=True,text=True,encoding='utf-8')
        assert answer.returncode==0,answer.stdout+answer.stderr
        return json.loads(answer.stdout)
    def request(browser,path,data=None):
        req=urllib.request.Request('http://127.0.0.1:33988/'+path,data=None if data is None else urllib.parse.urlencode(data).encode())
        try: response=browser.opener.open(req,timeout=15)
        except urllib.error.HTTPError as exc: response=exc
        return response.code,response.read().decode('utf-8'),response.headers
    def login(browser,name):
        code,body,_=browser.post('login',name,'SyntheticDebugPassword1');check('login '+name,code==303)
    def inspect(game): return cli('inspect',game)
    try:
        fixture=cli('setup');(ROOT/'http-sessions').mkdir(exist_ok=True);stream=open(ROOT/'debug-http-server.log','wb');server=subprocess.Popen([args.php,'-d','opcache.enable_cli=0','-d','session.save_path='+str(ROOT/'http-sessions'),'-S','127.0.0.1:33988','-t',str(ROOT/'www')],cwd=ROOT,stdout=stream,stderr=subprocess.STDOUT,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        for _ in range(60):
            try:
                with socket.create_connection(('127.0.0.1',33988),timeout=.1): break
            except OSError: time.sleep(.1)
        ordinary=Browser(port=33988);host=Browser(port=33988);guest=Browser(port=33988)
        code,body,_=request(guest,'index.php?game='+str(fixture['settings']));check('unauthenticated direct gameplay denied',code==401)
        login(ordinary,'ordinary_user');login(host,'debug_host')
        code,body,_=request(host,'index.php?game='+str(fixture['settings']));check('authorized Settings textarea',code==200 and 'name="lucktest_scenario"' in body)
        token=re.search(r'data-csrf="([a-f0-9]{64})"',body).group(1);version=int(inspect(fixture['settings'])['version'])
        code,body,_=request(ordinary,'index.php?game='+str(fixture['settings']));check('ordinary opponent no Debug',code==200 and '>Debug<' not in body and 'lucktest_scenario' not in body)
        ordinary_token=re.search(r'data-csrf="([a-f0-9]{64})"',body).group(1)
        before=inspect(fixture['settings'])
        payload={'cmd':'set_lucktest_debug','csrf':ordinary_token,'prepare_version':version,'debug_roll':'6*','account_id':fixture['host'],'role':'host','debug_allowed':'1'}
        code,body,_=request(ordinary,'index.php?game='+str(fixture['settings']),payload);check('forged account/role/debug direct POST denied',code==403 and inspect(fixture['settings'])==before)
        code,_,_=request(host,'index.php?game='+str(fixture['settings']),{'cmd':'set_lucktest_debug','csrf':'bad','prepare_version':version,'debug_roll':'6,1'});check('debug POST requires CSRF',code==403)
        for key in ['debug=1','first=1','second=1']:
            code,_,_=request(host,'index.php?game='+str(fixture['settings'])+'&'+key);check('legacy GET rejected '+key,code==400)
        code,body,_=request(host,'index.php?game='+str(fixture['draft']));check('other mode no debug UI',code==200 and 'name="debug_roll"' not in body and '>Debug<' not in body)
        code,_,_=request(host,'index.php?game='+str(fixture['draft']),{'cmd':'set_lucktest_debug','csrf':token,'prepare_version':1,'debug_roll':'6*'});check('other mode direct debug denied',code==403)
        code,body,_=request(ordinary,'index.php?game='+str(fixture['battle']));check('ordinary Battle has no overlay or secret',code==200 and 'id="battle-debug"' not in body and 'data-menu-debug' not in body and 'HTTP_OPAQUE_SECRET' not in body)
        code,body,_=request(ordinary,'index.php?game='+str(fixture['battle'])+'&ajax=battle_sync&force_snapshot=1');check('ordinary AJAX has no debug or scenario',code==200 and 'HTTP_OPAQUE_SECRET' not in body and 'debug-panel' not in body and 'lucktest_debug' not in body)
        code,body,_=request(host,'index.php?game='+str(fixture['battle']));check('authorized Battle native Debug works',code==200 and 'data-menu-debug' in body and 'HTTP_OPAQUE_SECRET' in body)
        frozen=inspect(fixture['battle']);code,_,_=request(host,'index.php?game='+str(fixture['battle']),{'cmd':'set_lucktest_debug','csrf':token,'prepare_version':frozen['version'],'debug_roll':'6*'});check('started Battle cannot be edited',code==409 and inspect(fixture['battle'])==frozen)
        code,body,_=request(host,'lobby.php?new=1&mode=lucktest');check('Lobby authorized scenario input',code==200 and 'lucktest_scenario' in body)
        code,body,_=request(ordinary,'lobby.php?new=1&mode=lucktest');check('ordinary can create LuckTest without Debug',code==200 and 'Сформировать отряды' not in body and 'lucktest_scenario' not in body and 'Опубликовать игру' in body)
        code,_,_=request(ordinary,'lobby.php',{'action':'create','csrf':ordinary_token,'setup_mode':'lucktest','lucktest_scenario':'{"cards":[{"owner":"host","ukid":"army"}]}','account_id':fixture['host']});check('Lobby direct scenario privilege escalation denied',code==403)
        tester=Browser(port=33988);login(tester,'debug_second')
        code,body,_=request(tester,'index.php?game='+str(fixture['settings_second']));check('second tester has own Debug as player',code==200 and '>Debug<' in body and 'lucktest_scenario' not in body)
        second_token=re.search(r'data-csrf="([a-f0-9]{64})"',body).group(1);second_version=inspect(fixture['settings_second'])['version']
        code,_,_=request(tester,'index.php?game='+str(fixture['settings_second']),{'cmd':'set_lucktest_debug','csrf':second_token,'prepare_version':second_version,'debug_roll':'6*'});check('raw preparation roll field is no longer accepted',code==400)
        second_state=json.loads(inspect(fixture['settings_second'])['json']);check('rejected raw field stores no cursor',second_state['lucktest_debug']==[])
        code,_,_=request(tester,'index.php?game='+str(fixture['settings_second']),{'cmd':'set_lucktest_debug','csrf':second_token,'prepare_version':inspect(fixture['settings_second'])['version'],'lucktest_scenario':'{"cards":[{"owner":"host","ukid":"army"}]}'});check('permitted player cannot configure host scenario',code==403)
        check('native start pending survives HTTP reads',bool(json.loads(inspect(fixture['battle'])['json'])['battle'].get('pending_card_choice')))
        invalid_before=inspect(fixture['settings']);code,body,_=request(host,'index.php?game='+str(fixture['settings']),{'cmd':'confirm_settings','csrf':token,'prepare_version':version,'lucktest_quick_game':'0','lucktest_scenario':'{"cards":[{"owner":"host","ukid":"army"}]}'});check('manual scenario rejected through real HTTP',code==409 and inspect(fixture['settings'])==invalid_before)
        code,_,headers=request(ordinary,'lobby.php',{'action':'create','csrf':ordinary_token,'setup_mode':'lucktest','lucktest_quick_game':'1','lucktest_card_count':'3'});check('ordinary account creates LuckTest room',code==303)
        room_path=headers['Location'];room_id=urllib.parse.parse_qs(urllib.parse.urlparse(room_path).query)['room'][0]
        code,_,_=request(host,'lobby.php?room='+room_id,{'action':'open','csrf':token,'version':1});check('tester can join ordinary LuckTest',code==303)
        code,body,_=request(ordinary,'lobby.php?room='+room_id+'&api=1');room_data=json.loads(body)['room'];check('ordinary creator sees pending request',code==200 and room_data['state']=='awaiting_confirmation')
        code,_,_=request(ordinary,'lobby.php?room='+room_id,{'action':'accept','csrf':ordinary_token,'version':room_data['version'],'candidate_request_id':room_data['candidate_request_id']});check('ordinary creator accepts and launches LuckTest',code==303)
        code,body,_=request(ordinary,'lobby.php?room='+room_id+'&api=1');new_game=json.loads(body)['room']['game_id'];new_state=json.loads(inspect(new_game)['json']);check('ordinary Quick Game remains normal',new_state['status']=='battle' and new_state['lucktest_debug']==[] and len(new_state['cards'])==60)
        code,body,_=request(ordinary,'index.php?game='+str(new_game));check('ordinary host does not inherit tester opponent Debug',code==200 and 'data-menu-debug' not in body and 'id="battle-debug"' not in body)
        code,body,_=request(host,'index.php?game='+str(new_game));check('tester opponent retains own account Debug',code==200 and 'data-menu-debug' in body)
        # URL queues are enabled once in preparation, and changing them never advances the game.
        before=inspect(fixture['settings'])
        code,_,_=request(host,'index.php?game='+str(fixture['settings'])+'&debug_roll=6,1,4');check('default-off URL denied without mutation',code==403 and inspect(fixture['settings'])==before)
        code,_,_=request(host,'index.php?game='+str(fixture['settings']),{'cmd':'set_lucktest_debug','csrf':token,'prepare_version':before['version'],'lucktest_url_roll':'1'});check('host enables URL checkbox',code==303)
        code,_,headers=request(host,'index.php?game='+str(fixture['settings'])+'&debug_roll=6,1,4');check('URL queue saved with clean redirect',code==303 and 'debug_roll' not in headers.get('Location',''))
        queued=json.loads(inspect(fixture['settings'])['json']);check('URL queue belongs to account/game',queued['lucktest_debug']['rolls'][str(fixture['host'])]['game_id']==fixture['settings'])
        check('another game unchanged',json.loads(inspect(fixture['other'])['json'])['lucktest_debug']==[])
        before=inspect(fixture['settings']);code,_,_=request(ordinary,'index.php?game='+str(fixture['settings'])+'&debug_roll=6*');check('ordinary opponent denied even when enabled',code==403 and inspect(fixture['settings'])==before)
        code,_,_=request(host,'index.php?game='+str(fixture['settings'])+'&debug_roll[]=6');check('array URL rejected without mutation',code==400 and inspect(fixture['settings'])==before)
        code,_,_=request(host,'index.php?game='+str(fixture['settings'])+'&debug_roll=9');check('out of range URL rejected',code==400 and inspect(fixture['settings'])==before)
        scenario='{"cards":[{"owner":"host","ukid":"army","row":2,"col":3}]}'
        launch={'cmd':'confirm_settings','csrf':token,'prepare_version':inspect(fixture['settings'])['version'],'lucktest_quick_game':'1','lucktest_card_count':'3','lucktest_scenario':scenario}
        cursor_before_failure=inspect(fixture['settings']);cli('fail_write',fixture['settings'])
        code,body,_=request(host,'index.php?game='+str(fixture['settings'])+'&debug_roll=6,1,4');check('URL SQL failure returns generic error without PHP details',code==503 and 'SQLSTATE' not in body and 'Stack trace' not in body and '.php' not in body)
        check('HTTP SQL failure preserves durable cursor and scenario',inspect(fixture['settings'])==cursor_before_failure);cli('clear_failure')
        code,_,_=request(host,'index.php?game='+str(fixture['settings'])+'&debug_roll=6,1,4');check('URL retry after write failure succeeds',code==303)
        launch['prepare_version']=inspect(fixture['settings'])['version']
        code,_,_=request(host,'index.php?game='+str(fixture['settings']),launch);check('real HTTP launches required card scenario',code==303)
        launched=inspect(fixture['settings']);game=json.loads(launched['json']);pin=game['lucktest_debug']['bindings'][0];card=game['cards'][str(pin['instance_id'])];check('required cell and remaining cursor saved',card['row']==2 and card['col']==3 and game['lucktest_debug']['rolls'][str(fixture['host'])]['state']['queue']==[4])
        code,_,_=request(host,'index.php?game='+str(fixture['settings']),launch);check('repeat HTTP launch cannot modify battle',code==409 and inspect(fixture['settings'])==launched)
        pending_before=json.loads(inspect(fixture['battle'])['json'])
        code,_,_=request(host,'index.php?game='+str(fixture['battle'])+'&debug_roll=6*');check('disabled existing battle URL rejected before pending progress',code==403 and json.loads(inspect(fixture['battle'])['json'])==pending_before)
        before=json.loads(inspect(fixture['settings'])['json'])
        code,_,_=request(host,'index.php?game='+str(fixture['settings'])+'&debug_roll=2*');check('enabled battle URL accepted during battle',code==303)
        after=json.loads(inspect(fixture['settings'])['json']);check('URL configuration preserves cards/combat/clock',before['cards']==after['cards'] and before['battle']==after['battle'])
        code,_,_=request(host,'index.php?game='+str(fixture['settings'])+'&debug_roll=off');check('URL off clears own queue',code==303 and str(fixture['host']) not in json.loads(inspect(fixture['settings'])['json'])['lucktest_debug']['rolls'])
        code,_,_=request(host,'auth.php?page=logout',{'csrf':token});check('logout succeeds',code==303)
        login(host,'ordinary_user');code,body,_=request(host,'index.php?game='+str(fixture['battle']));check('account switch loses debug UI',code==200 and 'HTTP_OPAQUE_SECRET' not in body and 'data-menu-debug' not in body)
        # Revoke the private permission and verify a previously authenticated tester immediately loses HTML/AJAX access.
        login(ordinary,'debug_host');config=ROOT/'config/auth.local.php';text=config.read_text();text=re.sub(r"'debug_account_ids'\s*=>\s*array\s*\([^)]*\)","'debug_account_ids' => []",text);config.write_text(text)
        code,body,_=request(ordinary,'index.php?game='+str(fixture['battle'])+'&ajax=battle_sync&force_snapshot=1');check('revoked tester no AJAX debug',code==200 and 'HTTP_OPAQUE_SECRET' not in body and 'debug-panel' not in body)
        code,_,_=request(ordinary,'index.php?game='+str(fixture['settings']),{'cmd':'set_lucktest_debug','csrf':ordinary.token('account'),'prepare_version':inspect(fixture['settings'])['version'],'debug_roll':'6*'});check('revoked tester cannot mutate queue',code==403)
        # Registration never provisions a debug permission, and wrong credentials do not authenticate.
        newcomer=Browser(port=33988);code,_,_=newcomer.post('register','new_ordinary','SyntheticDebugPassword1');check('registration remains available',code==303)
        code,_,_=newcomer.post('login','new_ordinary','WrongPassword123');check('wrong password rejected',code in (200,422))
        code,_,_=newcomer.request('account');check('wrong password creates no authenticated principal',code==303)
        print('LuckTest Debug HTTP:',len(checks),'checks passed on actual auth/Lobby/index endpoints.')
    finally:
        if server is not None: server.terminate();server.wait(timeout=10)
        if stream is not None: stream.close()
        for name in ['auth.local.php','db.php']:
            path=ROOT/'config'/name
            if path.exists(): path.unlink()
        (ROOT/'debug-http-results.json').write_text(json.dumps(checks,indent=2),encoding='utf-8')

if __name__=='__main__': main()
