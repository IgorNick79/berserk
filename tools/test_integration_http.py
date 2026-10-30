"""Stage 5B: real multi-process HTTP, InnoDB races, rollback and browser UI.
No gameplay config is read. Creates only a new lab datadir below tmp/integration-lab.
"""
import argparse, concurrent.futures, http.cookiejar, json, os, pathlib, socket
import subprocess, sys, time, re, urllib.error, urllib.parse, urllib.request
sys.dont_write_bytecode = True
from test_auth_http import Browser
from http.cookiejar import Cookie

ROOT = pathlib.Path(__file__).resolve().parents[1]
LAB = ROOT/'tmp/integration-lab'
RESULTS = []
CONCURRENCY = {}

def check(name, ok):
    RESULTS.append({'test':name,'pass':bool(ok)})
    if not ok: raise AssertionError(name)

class Client(Browser):
    def __init__(self,port=33572): super().__init__(port)
    def lobby(self,room=None,data=None,api=True):
        url=self.base.replace('auth.php','lobby.php')+('?' if api or room is not None else '')
        url+=('api=1' if api else '')+('&' if api and room is not None else '')+('room='+str(room) if room is not None else '')
        req=urllib.request.Request(url,data=urllib.parse.urlencode(data).encode() if data is not None else None)
        try:r=self.opener.open(req,timeout=30)
        except urllib.error.HTTPError as e:r=e
        text=r.read().decode('utf-8');return r.code,json.loads(text) if api else text
    def create(self):
        code,data=self.lobby(data={'action':'create','csrf':self.token('account'),'setup_mode':'system'});assert code==200,(code,data);return data['room']
    def act(self,room,action='open',version=None,request=None,csrf=None):
        data={'action':action,'csrf':csrf or self.token('account'),'version':version or room['version']}
        if request is not None:data['candidate_request_id']=request
        elif room.get('candidate_request_id'):data['candidate_request_id']=room['candidate_request_id']
        return self.lobby(room['id'],data)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--php',default='C:/OSPanel/modules/php/PHP_8.1/php.exe')
    parser.add_argument('--db-bin',default='C:/OSPanel/modules/database/MariaDB-10.8-Win10/bin')
    parser.add_argument('--node',default='C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe')
    parser.add_argument('--playwright',default='C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')
    parser.add_argument('--browser',default='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
    args=parser.parse_args()
    assert not (ROOT/'config/auth.local.php').exists() and not (ROOT/'config/db.php').exists() and not (ROOT/'config/battle_history.local.php').exists(),'Refusing to overwrite private configs'
    for port in range(33571,33578):
        with socket.socket() as s:assert s.connect_ex(('127.0.0.1',port))!=0,f'Port {port} already in use'
    LAB.mkdir(parents=True,exist_ok=True);(LAB/'sessions').mkdir(exist_ok=True)
    if not (LAB/'data/my.ini').exists():
        assert not (LAB/'data').exists(),'Refusing to initialize existing data directory'
        subprocess.run([str(pathlib.Path(args.db_bin)/'mariadb-install-db.exe'),f'--datadir={LAB / "data"}','--port=33571'],check=True,capture_output=True)
    procs=[];logs=[];run=str(time.time_ns())[-12:];database='lobby_'+run;user='lobby_'+run;authuser='auth_'+run
    def spawn(command,name):
        stream=open(LAB/(name+'.log'),'wb');logs.append(stream)
        p=subprocess.Popen(command,cwd=ROOT,stdout=stream,stderr=subprocess.STDOUT,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0));procs.append(p);return p
    def ready(port):
        for _ in range(150):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):return
            except OSError:time.sleep(.1)
        raise AssertionError('Server not ready '+str(port))
    def sql(text):
        text=text.replace('LAB_DB',database).replace('LOBBY_USER',user).replace('AUTH_USER',authuser)
        p=subprocess.run([str(pathlib.Path(args.db_bin)/'mysql.exe'),'--no-defaults','-h127.0.0.1','-P33571','-uroot','--batch','--raw','--skip-column-names'],input=text,text=True,encoding='utf-8',capture_output=True)
        assert p.returncode==0,p.stderr
        return p.stdout.strip()
    try:
        spawn([str(pathlib.Path(args.db_bin)/'mysqld.exe'),'--no-defaults',f'--datadir={LAB / "data"}','--bind-address=127.0.0.1','--port=33571','--skip-log-bin','--console'],'db');ready(33571)
        port,directory=sql('SELECT @@port,@@datadir;').split('\t')
        check('exact isolated database identity',port=='33571' and directory.replace('\\','/').rstrip('/').lower()==str((LAB/'data').resolve()).replace('\\','/').lower())
        sql("CREATE DATABASE LAB_DB;USE LAB_DB;CREATE TABLE games(ind INT NOT NULL AUTO_INCREMENT PRIMARY KEY,host_id INT NOT NULL,player_id INT NOT NULL,status VARCHAR(32) NOT NULL,json LONGTEXT NOT NULL,version INT NOT NULL DEFAULT 0,date DATETIME NOT NULL,active INT NOT NULL) ENGINE=InnoDB;INSERT INTO games VALUES(1,1,2,'battle','{}',0,UTC_TIMESTAMP(),1);")
        for name in ['20261021_auth.sql','20261021_lobby_preflight.sql','20261021_lobby.sql','20261021_lobby_setup.sql','20261021_command_journal.sql','20261019_game_chat.sql','20261020_battle_history.sql']:
            sql('USE LAB_DB;'+(ROOT/'tools/migrations'/name).read_text())
        (LAB/'postchecks.txt').write_text(sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261021_lobby_postchecks.sql').read_text()),encoding='utf-8')
        sql("CREATE USER 'AUTH_USER'@'127.0.0.1' IDENTIFIED BY '';GRANT SELECT,INSERT ON LAB_DB.accounts TO 'AUTH_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.auth_login_attempts TO 'AUTH_USER'@'127.0.0.1';CREATE USER 'LOBBY_USER'@'127.0.0.1' IDENTIFIED BY '';GRANT SELECT ON LAB_DB.accounts TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.lobby_rooms TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.games TO 'LOBBY_USER'@'127.0.0.1';")
        check('runtime no TRIGGER or DDL privileges','TRIGGER' not in sql("SHOW GRANTS FOR 'LOBBY_USER'@'127.0.0.1';") and 'CREATE' not in sql("SHOW GRANTS FOR 'LOBBY_USER'@'127.0.0.1';"))
        db=lambda who:"['host'=>'127.0.0.1','port'=>33571,'name'=>'"+database+"','user'=>'"+who+"','pass'=>'']"
        config="<?php return ['db'=>"+db(authuser)+",'lobby_db'=>"+db(user)+",'limiter_secret'=>'"+os.urandom(32).hex()+"','allow_loopback_http'=>true];"
        (ROOT/'config/auth.local.php').write_text(config)
        (ROOT/'config/db.php').write_text("<?php return ['host'=>'127.0.0.1:33571','name'=>'"+database+"','user'=>'"+user+"','pass'=>''];")
        sql("GRANT SELECT,INSERT,UPDATE ON LAB_DB.battle_command_journal TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.game_chat_messages TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.game_chat_reads TO 'LOBBY_USER'@'127.0.0.1';")
        for table in ['battle_history_matches','battle_history_batches','battle_history_actions','battle_history_events','battle_history_steps']:
            sql("GRANT SELECT,INSERT,UPDATE ON LAB_DB."+table+" TO 'LOBBY_USER'@'127.0.0.1';")
        sql("USE LAB_DB;CREATE TABLE elements(ind INT PRIMARY KEY,code VARCHAR(32),name VARCHAR(128)) ENGINE=InnoDB;INSERT INTO elements VALUES(1,'neutral','Neutral');CREATE TABLE cards(ind INT PRIMARY KEY,ukid VARCHAR(64),name VARCHAR(128),health INT,power INT DEFAULT 1,move INT,strike_weak INT,strike_medium INT,strike_strong INT,prop LONGTEXT,price INT DEFAULT 1,elite INT DEFAULT 0,single INT DEFAULT 0,element_id INT DEFAULT 1,descr TEXT,rarity VARCHAR(16) DEFAULT '',class VARCHAR(128) DEFAULT '',type VARCHAR(16) DEFAULT 'creature') ENGINE=InnoDB;CREATE TABLE decks(ind INT PRIMARY KEY,name VARCHAR(128),image TEXT,cards LONGTEXT,type VARCHAR(32),status VARCHAR(32)) ENGINE=InnoDB;")
        card_values=[]
        for i in range(1,101):
            rarity=['common','uncommon','rare','ultrarare'][(i-1)//25]
            card_values.append(f"({i},'card_{i}','Fixture {i}',10,2,1,2,3,'{{}}',1,'','{rarity}')")
        sql("USE LAB_DB;INSERT INTO cards(ind,ukid,name,health,move,strike_weak,strike_medium,strike_strong,prop,price,descr,rarity) VALUES"+','.join(card_values)+';')
        deck=json.dumps([{'ukid':'card_'+str(i),'count':3} for i in range(1,11)])
        sql("USE LAB_DB;INSERT INTO decks VALUES(1,'Fixture deck','','"+deck+"','official','active');GRANT SELECT ON LAB_DB.cards TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT ON LAB_DB.decks TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT ON LAB_DB.elements TO 'LOBBY_USER'@'127.0.0.1';")
        router=LAB/'router.php'
        router.write_text("<?php $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if($p==='/')$p='/index.php';if(in_array($p,['/auth.php','/lobby.php','/index.php','/battle-journal.php','/replay.php','/journal-access.php'],true)){require '"+str(ROOT/'www').replace('\\','/')+"'.$p;return true;}if(str_starts_with($p,'/assets/'))return false;http_response_code(404);")
        php=[args.php,'-n','-d',f'extension_dir={pathlib.Path(args.php).parent / "ext"}','-d','extension=mysqli','-d',f'session.save_path={LAB / "sessions"}','-d','display_errors=0','-d','log_errors=1']
        for port in range(33572,33576):spawn(php+['-S',f'127.0.0.1:{port}','-t',str(ROOT/'www'),str(router)],'http-'+str(port));ready(port)
        password='LobbyFixture123'
        names=['Creator','Candidate','Other','Fourth']
        clients=[Client(33572+i) for i in range(4)]
        for c,name in zip(clients,names):
            assert c.post('register',name,password)[0]==303
            assert c.post('login',name,password)[0]==303
        a,b,c,d=clients
        def http(client,game,post=None,query=''):
            req=urllib.request.Request(client.base.replace('auth.php','index.php')+'?game='+str(game)+query,data=urllib.parse.urlencode(post,doseq=True).encode() if post is not None else None)
            try:r=client.opener.open(req,timeout=30)
            except urllib.error.HTTPError as e:r=e
            text=r.read().decode('utf-8');return r.code,text,r.headers
        def state(game):return json.loads(sql(f'USE LAB_DB;SELECT json FROM games WHERE ind={game};'))
        def set_fixture(game, data):
            raw=json.dumps(data,ensure_ascii=True).replace('\\','\\\\').replace("'","''")
            sql(f"USE LAB_DB;UPDATE games SET json='{raw}',version=version+1 WHERE ind={game};")
        def version(game):return int(sql(f'USE LAB_DB;SELECT version FROM games WHERE ind={game};'))
        def prep(client,game,cmd,**payload):
            data={'cmd':cmd,'csrf':client.token('account'),'prepare_version':version(game),**payload}
            code,body,_=http(client,game,data)
            assert code==303,(cmd,code,re.findall(r'class="[^"]*flash[^"]*"[^>]*>(.*?)<',body,re.S)[:3]);return state(game)
        def match():
            # Pre-existing legacy room fixture: verify the old lifecycle is preserved after upgrade.
            sql("USE LAB_DB;INSERT INTO lobby_rooms(creator_account_id,state,version,created_at,updated_at)VALUES(1,'open',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6));")
            rid=int(sql('USE LAB_DB;SELECT MAX(id) FROM lobby_rooms;'));room=a.lobby(rid)[1]['room'];assert b.act(room)[0]==200;w=a.lobby(room['id'])[1]['room'];assert a.act(w,'accept')[0]==200
            return a.lobby(room['id'])[1]['room']['game_id']
        game=match()
        catalog={'card_'+str(i):{'revision':'integration-v1','name':'Fixture '+str(i),'image':'','description':'Synthetic test','type':'creature','element':'neutral','classes':[],'price':1} for i in range(1,101)}
        history={'recording_enabled':True,'record_game_ids':[game],'journal_enabled':True,'journal_game_ids':[game],'preview_enabled':True,'preview_game_ids':[game],'catalog':catalog}
        def history_config():
            text=json.dumps(history,separators=(',',':'))
            (ROOT/'config/battle_history.local.php').write_text("<?php return json_decode('"+text+"',true);",encoding='utf-8')
        history_config()
        def service(client,file,game,extra=''):
            req=urllib.request.Request(client.base.replace('auth.php',file)+'?game='+str(game)+extra)
            try:r=client.opener.open(req,timeout=20)
            except urllib.error.HTTPError as e:r=e
            return r.code,r.read().decode('utf-8')
        check('active replay prohibited',service(a,'replay.php',game,'&api=metadata')[0]==409)
        check('legacy replay ID collision denied',service(a,'replay.php',1,'&api=metadata')[0] in [403,404])
        token='a'*64
        history['journal_test_bindings']={token:{'game_id':game,'side':'host','expires_at':int(time.time())+3600}};history_config()
        legacy=Client();sid='c'*32
        (LAB/'sessions'/('sess_'+sid)).write_text('berserk_battle_journal_binding|s:64:"'+token+'";berserk_authenticated_user_id|i:1;')
        legacy.jar.set_cookie(Cookie(0,'PHPSESSID',sid,None,False,'127.0.0.1',False,False,'/',True,False,None,True,None,None,{}))
        check('valid legacy invitation cannot read account journal',service(legacy,'battle-journal.php',game)[0]==403)
        check('legacy PHPSESSID cannot supply account principal',http(legacy,game)[0]==401)
        arbitrary=c.create();assert d.act(arbitrary)[0]==200;pending=c.lobby(arbitrary['id'])[1]['room'];assert c.act(pending,'accept')[0]==200
        arbitrary_game=c.lobby(arbitrary['id'])[1]['room']['game_id']
        check('arbitrary account IDs 3 and 4 mapped to host player',state(arbitrary_game)['host_id']==3 and state(arbitrary_game)['player_id']==4 and http(d,arbitrary_game)[0]==200 and http(a,arbitrary_game)[0]==403)
        check('game HTML both account actors'  ,http(a,game)[0]==http(b,game)[0]==200)
        check('guest forbidden before any progress',http(Client(),game)[0]==401)
        check('outsider forbidden',http(c,game)[0]==403)
        sql(f'USE LAB_DB;UPDATE games SET host_id=99 WHERE ind={game};')
        check('SQL room JSON mismatch fails closed',http(a,game)[0]==403)
        sql(f'USE LAB_DB;UPDATE games SET host_id=1 WHERE ind={game};')
        check('legacy numeric IDs not ownership',http(a,1)[0]==403)
        check('prototype flags rejected',http(b,game,query='&first=1')[0]==400)
        check('GET command rejected',http(a,game,query='&cmd=choose_mode&mode=system')[0]==405 and state(game)['status']=='mode')
        check('POST CSRF rejected',http(a,game,{'cmd':'choose_mode','mode':'system','prepare_version':1,'csrf':'bad'})[0]==403)
        old_token=a.token('account');assert a.request('logout',{'csrf':old_token})[0]==303
        check('logout revokes gameplay session',http(a,game)[0]==401)
        assert a.post('login','Other',password)[0]==303
        check('switching account cannot inherit cached role',http(a,game)[0]==403)
        assert a.post('login','Creator',password)[0]==303
        check('relogin continues same persisted game',http(a,game)[0]==200 and state(game)['status']=='mode')
        snap=sql(f'USE LAB_DB;SELECT json,version FROM games WHERE ind={game};')
        code,body,_=http(a,game,query='&ajax=prepare_sync')
        check('prepare sync read only',code==200 and sql(f'USE LAB_DB;SELECT json,version FROM games WHERE ind={game};')==snap)
        before=version(game);http(b,game,{'cmd':'choose_mode','mode':'system','prepare_version':before,'csrf':b.token('account'),'actor':'host'});check('actor spoof cannot choose host mode',version(game)==before and state(game)['status']=='mode')
        html=http(a,game)[1]
        check('transport strips role inputs and uses POST', 'name="first"' not in html and 'name="second"' not in html and 'method="post" class="mode-actions"' in html)
        prep(a,game,'choose_mode',mode='system');check('System existing lifecycle deck',state(game)['status']=='deck')
        prep(a,game,'select_deck',deck_id=1,other_deck_id=1)
        check('two accounts view own preparation',state(game)['status']=='view' and http(b,game)[0]==200)
        for client in [a,b]:prep(client,game,'confirm_view')
        for client in [a,b]:prep(client,game,'confirm_turn')
        choose=a if state(game)['first_player']=='host' else b
        prep(choose,game,'choose_side',side=1)
        for client,key in [(a,'host'),(b,'player')]:
            cards=state(game)['cards'];card=next(x for x in cards.values() if x['owner']==key and x['zone']=='hand')
            prep(client,game,'pick_card',ukid=card['ukid'])
            prep(client,game,'confirm_deal')
        check('actual HTTP setup reaches place',state(game)['status']=='place')
        for client,key,row in [(a,'host',3),(b,'player',4)]:
            card=next(x for x in state(game)['cards'].values() if x['owner']==key and x['zone']=='squad')
            prep(client,game,'place_card',card_id=card['id'] if 'id' in card else card['instance_id'],row=row,col=3)
            prep(client,game,'confirm_place')
        check('real HTTP match starts battle',state(game)['status']=='battle')
        check('raw battle POST cannot bypass command journal',http(a,game,{'cmd':'resign','csrf':a.token('account'),'prepare_version':version(game)})[0]==405 and state(game)['status']=='battle')
        seq=0
        def battle(client,game,cmd,**payload):
            nonlocal seq
            seq+=1;data={'cmd':cmd,'csrf':client.token('account'),'expected_version':version(game),'command_id':'integration-'+str(seq),**payload}
            code,body,_=http(client,game,data,'&ajax=battle_command');result=json.loads(body)
            return code,result,data
        check('reload and sync authorized',http(a,game,query='&ajax=battle_sync')[0]==200 and http(b,game,query='&ajax=battle_sync')[0]==200)
        active=state(game)['battle']['active'];actor=a if active=='host' else b;other=b if active=='host' else a
        card=next(x for x in state(game)['cards'].values() if x['owner']==active and x['zone']=='field');cid=card.get('instance_id',card.get('id'))
        code,res,data=battle(actor,game,'move',card_id=cid,row=card['row'],col=2)
        check('ordinary move journalled',code==200 and res.get('ok') is True)
        code,body,_=http(actor,game,data,'&ajax=battle_command');check('command retry does not reapply',json.loads(body).get('command_status')=='committed')
        code,body,_=http(other,game,query='&ajax=battle_command_status&command_id='+data['command_id'])
        check('command status scoped to server actor',json.loads(body)['command_status']=='unknown' and 'before_version' not in json.loads(body))
        data['command_id']='conflict-new';data['cmd']='end_turn'
        code,body,_=http(actor,game,data,'&ajax=battle_command');check('stale CAS rejected',code==409)
        before=state(game)['battle']['active'];code,res,_=battle(other,game,'end_turn');check('membership does not grant foreign turn',state(game)['battle']['active']==before and not res.get('ok'))
        code,res,_=battle(actor,game,'move',card_id=cid,row=card['row'],col=3);assert res.get('ok'),res
        target=next(x for x in state(game)['cards'].values() if x['owner']!=active and x['zone']=='field');tid=target.get('instance_id',target.get('id'))
        code,res,_=battle(actor,game,'strike',card_id=cid,target_id=tid)
        check('attack accepted',res.get('ok') is True)
        # Resolve existing combat continuations with the appropriate confirmed actor.
        automatic_ack_tested=False
        for _ in range(12):
            current=state(game);strike=current['battle'].get('strike')
            if not strike:break
            phase=strike.get('state')
            if phase=='waiting_defender':battle(other,game,'choose_defender',defender_id=0)
            elif phase=='waiting_instant':
                battle(a,game,'combat_instant_pass');battle(b,game,'combat_instant_pass')
            elif phase=='waiting_choice':battle(actor if strike.get('choice_winner')=='attack' else other,game,'choose_strike_mode',mode='normal')
            elif phase=='results':
                wait=current['battle'].get('informational_wait',{}).get('wait_id','')
                before=version(game);code,res,_=battle(actor,game,'confirm_strike',wait_id='stale')
                check('stale informational wait token rejected',not res.get('ok') and version(game)==before)
                battle(actor,game,'confirm_strike',wait_id=wait)
                current=state(game);record=current['battle'].get('informational_wait')
                if record:
                    record['started_at']=int(time.time())-11;record['deadline_at']=int(time.time())-1
                    set_fixture(game,current);before=version(game)
                    check('outsider cannot trigger automatic pending ACK',http(c,game,query='&ajax=battle_sync')[0]==403 and version(game)==before)
                    code,body,_=http(actor,game,query='&ajax=battle_sync')
                    automatic_ack_tested=code==200 and not state(game)['battle'].get('strike')
                break
            else:break
        if state(game)['battle'].get('strike'): print(json.dumps(state(game)['battle'],ensure_ascii=False,indent=2))
        check('existing informational automatic ACK via authorized sync',automatic_ack_tested)
        check('pending combat continuation completes' ,not state(game)['battle'].get('strike'))
        # Chat has its own identity, cannot execute a gameplay command or select a side.
        msg={'csrf':a.token('account'),'message':'Account-only fixture message','request_id':'integration-chat-0001'}
        code,body,_=http(a,game,msg,'&ajax=chat_send');check('chat sender from account',code==200 and json.loads(body)['message']['sender_id']==1)
        check('chat visible only participants',http(b,game,query='&ajax=chat_messages')[0]==200 and http(c,game,query='&ajax=chat_messages')[0]==403)
        check('journal host authorized',service(a,'battle-journal.php',game)[0]==200)
        check('journal player authorized',service(b,'battle-journal.php',game)[0]==200)
        check('journal outsider denied',service(c,'battle-journal.php',game)[0]==403)
        check('client cannot select other historical projection',service(a,'battle-journal.php',game,'&side=player')[0]==400)
        timer=state(game)['battle'].get('turn_timer')
        check('existing turn timer initialized',timer is not None and timer['period_id']>=1)
        # Let the existing automatic timeout consume a genuinely expired test clock.
        current=state(game);owner=current['battle']['active'];prior_turn=current['battle']['turn']
        timer=current['battle']['turn_timer'];timer.update(status='running',remaining_seconds=1,anchor_at=int(time.time())-5,pause_reason=None)
        set_fixture(game,current);before=version(game)
        check('unauthorized sync cannot fire timeout',http(c,game,query='&ajax=battle_sync')[0]==403 and version(game)==before)
        code,body,_=http(a,game,query='&ajax=battle_sync')
        check('authorized sync executes existing automatic timeout',code==200 and state(game)['battle']['active']!=owner)
        code,res,_=battle(a,game,'resign');check('resign completes existing match',res.get('ok') and state(game)['status']=='game_over')
        check('game over omitted from active lobby',int(sql(f'USE LAB_DB;SELECT id FROM lobby_rooms WHERE game_id={game};')) not in [r['id'] for r in a.lobby()[1]['rooms']])
        # Existing completeness and SHA checks remain authoritative.
        sha=sql(f'USE LAB_DB;SELECT state_hash FROM battle_history_steps WHERE game_id={game} ORDER BY step_seq LIMIT 1;')
        sql(f"USE LAB_DB;UPDATE battle_history_steps SET state_hash=REPEAT('0',64) WHERE game_id={game} ORDER BY step_seq LIMIT 1;")
        check('replay SHA corruption rejected',service(a,'replay.php',game,'&api=metadata')[0]==409)
        sql(f"USE LAB_DB;UPDATE battle_history_steps SET state_hash='{sha}' WHERE game_id={game} ORDER BY step_seq LIMIT 1;")
        completeness=sql(f'USE LAB_DB;SELECT completeness FROM battle_history_matches WHERE game_id={game};')
        sql(f"USE LAB_DB;UPDATE battle_history_matches SET completeness='incomplete' WHERE game_id={game};")
        check('experimental preview preserves partial completeness metadata',json.loads(service(a,'replay.php',game,'&api=metadata')[1])['completeness']=='incomplete')
        sql(f"USE LAB_DB;UPDATE battle_history_matches SET completeness='{completeness}' WHERE game_id={game};")
        replay_code,replay_body=service(a,'replay.php',game,'&api=metadata')
        check('completed replay participant authorized',replay_code==200)
        history['admin_user_ids']=[3];history_config()
        check('configured numeric admin cannot bypass membership',service(c,'replay.php',game,'&api=metadata')[0]==403)
        check('completed replay outsider denied',service(c,'replay.php',game,'&api=metadata')[0]==403)
        sql(f'USE LAB_DB;UPDATE battle_history_matches SET has_initial=0 WHERE game_id={game};')
        check('replay missing initial capture denied',service(a,'replay.php',game,'&api=metadata')[0]==409)
        sql(f'USE LAB_DB;UPDATE battle_history_matches SET has_initial=1 WHERE game_id={game};')
        def finish_preparation(g):
            # Sealed pools are not automatically valid final decks: use the existing sideboard actions.
            for client,key in [(a,'host'),(b,'player')]:
                from collections import Counter
                while True:
                    deck=[x for x in state(g)['cards'].values() if x['owner']==key and x['zone']=='deck'];counts=Counter(x['ukid'] for x in deck)
                    extra=next((x for x in deck if counts[x['ukid']]>3),None)
                    if extra is None and len(deck)>50:extra=deck[-1]
                    if extra is None:break
                    prep(client,g,'view_to_sideboard',card_id=extra.get('instance_id',extra.get('id')))
                prep(client,g,'confirm_view')
            for client in [a,b]:prep(client,g,'confirm_turn')
            prep(a if state(g)['first_player']=='host' else b,g,'choose_side',side=1)
            for client,key in [(a,'host'),(b,'player')]:
                card=next(x for x in state(g)['cards'].values() if x['owner']==key and x['zone']=='hand')
                prep(client,g,'pick_card',ukid=card['ukid']);prep(client,g,'confirm_deal')
            for client,key,row in [(a,'host',3),(b,'player',4)]:
                card=next(x for x in state(g)['cards'].values() if x['owner']==key and x['zone']=='squad')
                prep(client,g,'place_card',card_id=card.get('instance_id',card.get('id')),row=row,col=3);prep(client,g,'confirm_place')
        # Exercise both existing manual draft state machines over real HTTP, not fixture mutation.
        for draft_type in ['grid','solomon']:
            g=match();prep(a,g,'choose_mode',mode='draft',draft_type=draft_type)
            prep(a,g,'confirm_settings',draft_type=draft_type,draft_timer_mode='default')
            check('HTTP '+draft_type+' draft starts',state(g)['status']=='draft' and http(b,g)[0]==200)
            code,body,_=http(a,g,{'cmd':'draft_sync','csrf':a.token('account')});check('HTTP '+draft_type+' sync POST',code==200 and json.loads(body)['status']=='draft')
            # Two real manual decisions, one per actor, then expiry of both clocks exercises existing auto-draft.
            import itertools
            for _ in range(2):
                current=state(g);who=a if current['draft']['turn']=='host' else b
                selections=([('draft_row',{'row':i}) for i in range(1,4)] if draft_type=='grid' else [('draft_confirm',{'positions[]':list(x),'decision_id':current['draft'].get('decision_id',1),'expected_version':current['version']}) for x in itertools.combinations(range(len(current['draft']['grid'])),len(current['draft']['grid'])//2)])
                success=False
                for cmd,payload in selections:
                    post={'cmd':cmd,'csrf':who.token('account'),'prepare_version':version(g),**payload}
                    code,body,_=http(who,g,post)
                    if code==303:success=True;break
                assert success,'No valid manual draft choice'
            check('HTTP '+draft_type+' both actors manual decisions',len(state(g)['draft'].get('history',[]))>=2)
            if draft_type=='grid':
                # Stage5D.1: real HTTP error/rollback on a saved impossible prefix, then frozen input.
                saved=json.loads((ROOT/'docs/evidence/stage5d/grid-timeout-runs.json').read_text(encoding='utf-8-sig'))
                failed=next(x for x in saved['runs'] if x['scenario']=='failing_seed')
                current=state(g);current['draft']=failed['intermediate']['draft'];set_fixture(g,current)
                snapshot=sql(f'USE LAB_DB;SELECT version,json FROM games WHERE ind={g};')
                replies=[http(client,g,{'cmd':'draft_sync','csrf':client.token('account')}) for client in [a,b]]
                check('HTTP Grid impossible allocation explicit for both actors',all(code==200 and json.loads(body).get('ok') is False and 'полного распределения' in json.loads(body).get('error','') for code,body,_ in replies))
                check('HTTP Grid impossible allocation no persisted mutation',snapshot==sql(f'USE LAB_DB;SELECT version,json FROM games WHERE ind={g};'))
                frozen=json.loads((ROOT/'docs/evidence/stage5c/grid-timeout-fixture.json').read_text())
                current=state(g);current['draft']=frozen['state']['draft'];set_fixture(g,current)
            current=state(g);clock=current['draft']['timer'];clock['elapsed']={'host':clock['total_limit'],'player':clock['total_limit']};clock['action_started_at']=int(time.time())-1000
            set_fixture(g,current)
            code,body,_=http(a,g,{'cmd':'draft_sync','csrf':a.token('account')})
            if code!=200 or state(g)['status']!='view':
                current=state(g);print('DRAFT_TIMEOUT_DIAGNOSTIC',draft_type,code,body[:900],{k:len(v) for k,v in (current.get('draft') or {}).get('picked',{}).items()})
            check('HTTP '+draft_type+' timeout existing auto completion',code==200 and state(g)['status']=='view')
            if draft_type=='grid':
                from collections import Counter
                stock=Counter(x for x in current['draft']['pool']+current['draft']['grid']+current['draft'].get('recycle',[]) if x is not None)
                for picks in current['draft']['picked'].values():stock.update(picks)
                materialized=Counter(card['ukid'] for card in state(g)['cards'].values())
                check('HTTP frozen Grid complete inventory conservation',materialized==stock)
            finish_preparation(g);code,res,_=battle(a,g,'resign');check('HTTP '+draft_type+' through battle terminal',res.get('ok') and state(g)['status']=='game_over')
        sealed=match();prep(a,sealed,'choose_mode',mode='sealed');prep(a,sealed,'confirm_settings')
        check('HTTP Sealed existing pool initializes',state(sealed)['status']=='view' and len(state(sealed)['cards'])>0 and http(b,sealed)[0]==200)
        finish_preparation(sealed)
        current=state(sealed);owner=current['battle']['active'];actor=a if owner=='host' else b;opponent=b if owner=='host' else a
        attacker=next(x for x in current['cards'].values() if x['owner']==owner and x['zone']=='field')
        target=next(x for x in current['cards'].values() if x['owner']!=owner and x['zone']=='field');target['hp']=1;target['closed']=True
        set_fixture(sealed,current)
        code,res,_=battle(actor,sealed,'strike',card_id=attacker.get('instance_id',attacker.get('id')),target_id=target.get('instance_id',target.get('id')))
        for _ in range(8):
            current=state(sealed)
            if current['status']=='game_over':break
            strike=current['battle'].get('strike') or {}
            if strike.get('state')=='waiting_defender':battle(opponent,sealed,'choose_defender',defender_id=0)
            elif strike.get('state')=='waiting_instant':battle(a,sealed,'combat_instant_pass');battle(b,sealed,'combat_instant_pass')
            elif strike.get('state')=='waiting_choice':battle(actor if strike.get('choice_winner')=='attack' else opponent,sealed,'choose_strike_mode',mode='normal')
            elif strike.get('state')=='results':
                wait=current['battle'].get('informational_wait',{}).get('wait_id','')
                for client in [a,b]:battle(client,sealed,'confirm_strike',wait_id=wait)
            else:break
        check('HTTP Sealed battle natural victory',state(sealed)['status']=='game_over' and state(sealed)['winner']==owner)
        import shutil
        regression=LAB/'regression'
        for folder in ['src','tools','templates']:
            shutil.copytree(ROOT/folder,regression/folder,dirs_exist_ok=True)
        shutil.copytree(ROOT/'docs/cards',regression/'docs/cards',dirs_exist_ok=True)
        (regression/'config').mkdir(parents=True,exist_ok=True)
        shutil.copyfile(ROOT/'config/db.php',regression/'config/db.php')
        env=dict(os.environ,BERSERK_STAGE31_ISOLATED='1',BERSERK_STAGE4D_ISOLATED='1')
        suites=['test_repository_optimistic_locking.php','test_command_journal_repository.php','test_turn_timer.php','test_pending_wait_timer.php','test_informational_wait.php','test_turn_timeout.php','test_match_pause.php','test_settings_flow.php','test_sealed_flow.php','test_solomon_draft.php','test_live_battle_journal.php']
        # New room setup: configuration is persisted before visibility; gameplay starts only on accept.
        before_rooms=sql('USE LAB_DB;SELECT COUNT(*) FROM lobby_rooms;')
        check('new room requires explicit type',a.lobby(data={'action':'create','csrf':a.token('account')})[0]==400)
        code,_=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'solomon','stack_size':'7'})
        check('invalid room settings rejected before publication',code==400 and sql('USE LAB_DB;SELECT COUNT(*) FROM lobby_rooms;')==before_rooms)
        for kind,stage in [('system','deck'),('draft','draft'),('solomon','draft'),('sealed','view')]:
            count=sql('USE LAB_DB;SELECT COUNT(*) FROM games;')
            code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':kind})
            assert code==200,(kind,code,data)
            room=data['room'];rid=room['id']
            check('configured '+kind+' publication without GameState',room['state']=='open' and room['game_type']==kind and room['created_at'].endswith('Z') and sql('USE LAB_DB;SELECT COUNT(*) FROM games;')==count)
            assert b.act(room)[0]==200
            waiting=a.lobby(rid)[1]['room']
            check('configured '+kind+' pending no gameplay',sql('USE LAB_DB;SELECT COUNT(*) FROM games;')==count)
            if kind=='draft':
                sql("REVOKE SELECT ON LAB_DB.cards FROM 'LOBBY_USER'@'127.0.0.1';")
                failed,_=a.act(waiting,'accept')
                sql("GRANT SELECT ON LAB_DB.cards TO 'LOBBY_USER'@'127.0.0.1';")
                check('configured generation failure rolls back game and room',failed in [400,503] and sql('USE LAB_DB;SELECT COUNT(*) FROM games;')==count and a.lobby(rid)[1]['room']['state']=='awaiting_confirmation')
            code,data=a.act(waiting,'accept');assert code==200,(kind,code,data)
            current=a.lobby(rid)[1]['room'];g=current['game_id'];saved=state(g)
            check('configured '+kind+' accept uses existing lifecycle',current['state']=='started' and saved['status']==stage and saved['mode']==('draft' if kind=='solomon' else kind) and int(sql(f'USE LAB_DB;SELECT COUNT(*) FROM games WHERE ind={g};'))==1)
            check('configured '+kind+' SQL JSON participants match',saved['host_id']==1 and saved['player_id']==2 and saved['players']['host']['user_id']==1 and saved['players']['player']['user_id']==2 and sql(f'USE LAB_DB;SELECT status FROM games WHERE ind={g};')==stage)
            check('configured '+kind+' repeated accept denied',a.act(waiting,'accept')[0]==409)
            before=state(g)
            for retired in ['playmat','preparation_preference']:
                code,_,_=http(a,g,{'csrf':a.token('account')},'&ajax='+retired)
                check('retired '+retired+' cannot mutate game',code==400 and state(g)==before)
            if kind in ['draft','solomon']:check('configured '+kind+' correct draft subtype',saved['settings']['draft']['type']==('solomon' if kind=='solomon' else 'grid'))
        check('public guest list exposes types and dates',Client().lobby()[0]==200 and any(r['game_type']=='sealed' and r['created_at'] for r in Client().lobby()[1]['rooms']))
        check('guest still cannot create',Client().lobby(data={'action':'create','setup_mode':'system','csrf':'invalid'})[0]==401)
        regressions=[]
        for test in suites:
            result=subprocess.run(php+[str(regression/'tools'/test)],cwd=regression,env=env,capture_output=True,text=True,encoding='utf-8',errors='replace')
            (LAB/(test+'.txt')).write_text(result.stdout+result.stderr,encoding='utf-8')
            regressions.append({'test':test,'exit':result.returncode,'tail':(result.stdout+result.stderr)[-500:]})
        (LAB/'regressions.json').write_text(json.dumps(regressions,ensure_ascii=False,indent=2),encoding='utf-8')
        regression_success=all(x['exit']==0 for x in regressions)
        # Real browser E2E validates decorated preparation forms and automatic room redirects.
        sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
        env=dict(os.environ,INTEGRATION_URL='http://127.0.0.1:33572',PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser,INTEGRATION_EVIDENCE=str(LAB))
        ui=subprocess.run([args.node,str(ROOT/'tools/test_integration_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
        (LAB/'browser-output.txt').write_text(ui.stdout+ui.stderr,encoding='utf-8');check('two account browser E2E',ui.returncode==0)
        check('existing gameplay and storage regressions',regression_success)
        print(json.dumps({'passed':len(RESULTS),'results':RESULTS},ensure_ascii=False,indent=2))
    finally:
        (LAB/'results.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')
        for name in ['auth.local.php','db.php','battle_history.local.php']:(ROOT/'config'/name).unlink(missing_ok=True)
        for p in reversed(procs):p.terminate()
        for p in procs:
            try:p.wait(timeout=10)
            except subprocess.TimeoutExpired:p.kill()
        for f in logs:f.close()

if __name__=='__main__':main()
