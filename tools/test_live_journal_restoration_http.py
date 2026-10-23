"""Stage 4D.2.1: real multi-process HTTP, InnoDB races, rollback and browser UI.
No real gameplay config is read. All servers/configs/data stay below a disposable source copy.
"""
import argparse, concurrent.futures, http.cookiejar, json, os, pathlib, socket
import subprocess, sys, time, re, urllib.error, urllib.parse, urllib.request
sys.dont_write_bytecode = True
from test_auth_http import Browser, NoRedirect
from http.cookiejar import Cookie

ROOT = pathlib.Path(__file__).resolve().parents[1]
LAB = ROOT/'tmp/live-journal-restore-lab'
RESULTS = []
CONCURRENCY = {}

def check(name, ok):
    RESULTS.append({'test':name,'pass':bool(ok)})
    if not ok: raise AssertionError(name)

class Client(Browser):
    def __init__(self,port=33672):
        super().__init__(port)
        self.opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(self.jar),NoRedirect())
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
    assert not (ROOT/'.git').exists(),'Disposable source copy required'
    assert not (ROOT/'config/auth.local.php').exists() and not (ROOT/'config/db.php').exists() and not (ROOT/'config/battle_history.local.php').exists(),'Refusing to overwrite private configs'
    for port in range(33671,33678):
        with socket.socket() as s:assert s.connect_ex(('127.0.0.1',port))!=0,f'Port {port} already in use'
    LAB.mkdir(parents=True,exist_ok=True);(LAB/'sessions').mkdir(exist_ok=True)
    if not (LAB/'data/my.ini').exists():
        assert not (LAB/'data').exists(),'Refusing to initialize existing data directory'
        subprocess.run([str(pathlib.Path(args.db_bin)/'mariadb-install-db.exe'),f'--datadir={LAB / "data"}','--port=33671'],check=True,capture_output=True)
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
        p=subprocess.run([str(pathlib.Path(args.db_bin)/'mysql.exe'),'--no-defaults','-h127.0.0.1','-P33671','-uroot','--batch','--raw','--skip-column-names'],input=text,text=True,encoding='utf-8',capture_output=True)
        assert p.returncode==0,p.stderr
        return p.stdout.strip()
    try:
        spawn([str(pathlib.Path(args.db_bin)/'mysqld.exe'),'--no-defaults',f'--datadir={LAB / "data"}','--bind-address=127.0.0.1','--port=33671','--skip-log-bin','--console'],'db');ready(33671)
        port,directory=sql('SELECT @@port,@@datadir;').split('\t')
        check('exact isolated database identity',port=='33671' and directory.replace('\\','/').rstrip('/').lower()==str((LAB/'data').resolve()).replace('\\','/').lower())
        sql("CREATE DATABASE LAB_DB;USE LAB_DB;CREATE TABLE games(ind INT NOT NULL AUTO_INCREMENT PRIMARY KEY,host_id INT NOT NULL,player_id INT NOT NULL,status VARCHAR(32) NOT NULL,json LONGTEXT NOT NULL,version INT NOT NULL DEFAULT 0,date DATETIME NOT NULL,active INT NOT NULL) ENGINE=InnoDB;INSERT INTO games VALUES(1,1,2,'battle','{}',0,UTC_TIMESTAMP(),1);")
        for name in ['20261021_auth.sql','20261021_lobby_preflight.sql','20261021_lobby.sql','20261021_lobby_setup.sql','20261021_command_journal.sql','20261019_game_chat.sql','20261020_battle_history.sql']:
            sql('USE LAB_DB;'+(ROOT/'tools/migrations'/name).read_text())
        (LAB/'postchecks.txt').write_text(sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261021_lobby_postchecks.sql').read_text()),encoding='utf-8')
        sql("CREATE USER 'AUTH_USER'@'127.0.0.1' IDENTIFIED BY '';GRANT SELECT,INSERT ON LAB_DB.accounts TO 'AUTH_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.auth_login_attempts TO 'AUTH_USER'@'127.0.0.1';CREATE USER 'LOBBY_USER'@'127.0.0.1' IDENTIFIED BY '';GRANT SELECT ON LAB_DB.accounts TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.lobby_rooms TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.games TO 'LOBBY_USER'@'127.0.0.1';")
        check('runtime no TRIGGER or DDL privileges','TRIGGER' not in sql("SHOW GRANTS FOR 'LOBBY_USER'@'127.0.0.1';") and 'CREATE' not in sql("SHOW GRANTS FOR 'LOBBY_USER'@'127.0.0.1';"))
        db=lambda who:"['host'=>'127.0.0.1','port'=>33671,'name'=>'"+database+"','user'=>'"+who+"','pass'=>'']"
        config="<?php return ['db'=>"+db(authuser)+",'lobby_db'=>"+db(user)+",'limiter_secret'=>'"+os.urandom(32).hex()+"','allow_loopback_http'=>true];"
        (ROOT/'config/auth.local.php').write_text(config)
        (ROOT/'config/db.php').write_text("<?php return ['host'=>'127.0.0.1:33671','name'=>'"+database+"','user'=>'"+user+"','pass'=>''];")
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
        for port in range(33672,33676):spawn(php+['-S',f'127.0.0.1:{port}','-t',str(ROOT/'www'),str(router)],'http-'+str(port));ready(port)
        password='LobbyFixture123'
        names=['Creator','Candidate','Other','Fourth']
        clients=[Client(33672+i) for i in range(4)]
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
        history={'preview_enabled':False,'preview_game_ids':[], 'catalog':{}}
        def history_config():
            text=json.dumps(history,separators=(',',':'))
            (ROOT/'config/battle_history.local.php').write_text("<?php return json_decode('"+text+"',true);",encoding='utf-8')
        def match():
            sql("USE LAB_DB;INSERT INTO lobby_rooms(creator_account_id,state,version,created_at,updated_at)VALUES(1,'open',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6));")
            rid=int(sql('USE LAB_DB;SELECT MAX(id) FROM lobby_rooms;'))
            # No history configuration, room selection or ID prediction.
            room=a.lobby(rid)[1]['room'];assert b.act(room)[0]==200;w=a.lobby(room['id'])[1]['room'];assert a.act(w,'accept')[0]==200
            return a.lobby(room['id'])[1]['room']['game_id']
        game=match()
        check('every ordinary lobby game enrolled before preparation',sql(f'USE LAB_DB;SELECT has_initial FROM battle_history_matches WHERE game_id={game};')=='0')
        check('no external replay catalog and preview OFF',history['catalog']=={} and history['preview_enabled'] is False)
        def service(client,file,game,extra=''):
            req=urllib.request.Request(client.base.replace('auth.php',file)+'?game='+str(game)+extra)
            try:r=client.opener.open(req,timeout=20)
            except urllib.error.HTTPError as e:r=e
            return r.code,r.read().decode('utf-8')
        check('replay remains OFF',service(a,'replay.php',game,'&api=metadata')[0]==404)
        check('legacy replay ID collision denied',service(a,'replay.php',1,'&api=metadata')[0] in [403,404])
        check('journal initialized without local history config',not (ROOT/'config/battle_history.local.php').exists())
        history.update(recording_enabled=False,journal_enabled=False,record_game_ids=[],journal_game_ids=[],control_room_ids=[])
        token='a'*64
        history['journal_test_bindings']={token:{'game_id':game,'side':'host','expires_at':int(time.time())+3600}};history_config()
        legacy=Client();sid='c'*32
        (LAB/'sessions'/('sess_'+sid)).write_text('berserk_battle_journal_binding|s:64:"'+token+'";berserk_authenticated_user_id|i:1;')
        legacy.jar.set_cookie(Cookie(0,'PHPSESSID',sid,None,False,'127.0.0.1',False,False,'/',True,False,None,True,None,None,{}))
        check('valid legacy invitation cannot read account journal',service(legacy,'battle-journal.php',game)[0] in [401,403])
        check('legacy PHPSESSID cannot supply account principal',http(legacy,game)[0]==401)
        arbitrary=c.create();assert d.act(arbitrary)[0]==200;pending=c.lobby(arbitrary['id'])[1]['room'];assert c.act(pending,'accept')[0]==200
        arbitrary_game=c.lobby(arbitrary['id'])[1]['room']['game_id']
        check('arbitrary account IDs 3 and 4 mapped to host player',state(arbitrary_game)['host_id']==3 and state(arbitrary_game)['player_id']==4 and http(d,arbitrary_game)[0]==200 and http(a,arbitrary_game)[0]==403)
        check('every account match records despite stale disabled config',service(c,'battle-journal.php',arbitrary_game)[0]==200 and service(d,'battle-journal.php',arbitrary_game)[0]==200 and sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_matches WHERE game_id={arbitrary_game};')=='1')
        check('legacy match not backfilled',sql('USE LAB_DB;SELECT COUNT(*) FROM battle_history_matches WHERE game_id=1;')=='0')

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
        before_games=sql('USE LAB_DB;SELECT COUNT(*) FROM games;')
        denied_room=c.create();assert d.act(denied_room)[0]==200;denied_pending=c.lobby(denied_room['id'])[1]['room']
        sql("REVOKE INSERT ON LAB_DB.battle_history_matches FROM 'LOBBY_USER'@'127.0.0.1';")
        try:denied=c.act(denied_pending,'accept')
        finally:sql("GRANT INSERT ON LAB_DB.battle_history_matches TO 'LOBBY_USER'@'127.0.0.1';")
        check('metadata failure rolls back new game and room acceptance',denied[0]==503 and sql('USE LAB_DB;SELECT COUNT(*) FROM games;')==before_games and c.lobby(denied_room['id'])[1]['room']['state']!='started')
        # Deletion/corruption must not turn mandatory recording into a silent legacy bypass.
        sql(f'USE LAB_DB;CREATE TABLE saved_meta LIKE battle_history_matches;INSERT INTO saved_meta SELECT * FROM battle_history_matches WHERE game_id={game};DELETE FROM battle_history_matches WHERE game_id={game};')
        before=sql(f'USE LAB_DB;SELECT json,version FROM games WHERE ind={game};')
        failed=http(a,game,{'cmd':'choose_mode','mode':'system','prepare_version':version(game),'csrf':a.token('account')})
        check('missing mandatory metadata rejects command without changing state',failed[0]>=500 and sql(f'USE LAB_DB;SELECT json,version FROM games WHERE ind={game};')==before)
        sql('USE LAB_DB;INSERT INTO battle_history_matches SELECT * FROM saved_meta;DROP TABLE saved_meta;')
        prep(a,game,'choose_mode',mode='system',fault_recovery='lab');check('System existing lifecycle deck',state(game)['status']=='deck')
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
        check('placement creates neither text nor coverage gaps',sql(f"USE LAB_DB;SELECT COUNT(*) FROM battle_history_events e JOIN battle_command_journal j ON j.id=e.journal_id WHERE e.game_id={game} AND j.command_type IN ('place_card','unplace_card');")=='0')
        def page(client,g,after=0,limit=2):
            code,body=service(client,'battle-journal.php',g,f'&after={after}&limit={limit}');assert code==200,(code,body);return json.loads(body)
        def journal_entries(client,g):
            entries=[];cursor=0
            for _ in range(200):
                value=page(client,g,cursor);assert value['cursor']>=cursor
                assert set(value)=={'entries','cursor','has_more','recording'}
                assert all(set(e)=={'seq','kind','text'} for e in value['entries'])
                entries+=value['entries'];cursor=value['cursor']
                if not value['has_more']:return entries,cursor
            raise AssertionError('cursor stalled')

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
        hidden=state(game)
        for x in hidden['cards'].values():
            if x['instance_id']==cid:x['revealed']=False
        set_fixture(game,hidden)

        code,res,data=battle(actor,game,'move',card_id=cid,row=card['row'],col=2)
        check('ordinary move journalled',code==200 and res.get('ok') is True)
        owner_rows,owner_cursor=journal_entries(actor,game);opponent_rows,_=journal_entries(other,game)
        check('actual card movement text with empty replay catalog',any('перемещается' in e['text'] and 'Fixture' in e['text'] for e in owner_rows))
        check('hidden movement has distinct historical projections',not any('перемещается' in e['text'] for e in opponent_rows))
        old_event=sql(f"USE LAB_DB;SELECT e.host_json,e.player_json FROM battle_history_events e WHERE game_id={game} AND event_type='move.started' ORDER BY event_seq LIMIT 1;")
        before_events=sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id={game};')
        original_ukid=card['ukid']
        sql("USE LAB_DB;UPDATE cards SET name='Fixture renamed after first move' WHERE ukid='"+original_ukid+"';")
        check('catalog rename does not change historical text',journal_entries(actor,game)[0]==owner_rows)


        code,body,_=http(actor,game,data,'&ajax=battle_command');check('command retry does not reapply',json.loads(body).get('command_status')=='committed')
        check('committed retry creates no extra events',sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id={game};')==before_events)
        # A mandatory history failure must roll back the actual successful move.
        before_game=sql(f'USE LAB_DB;SELECT json,version FROM games WHERE ind={game};')
        sql(f'USE LAB_DB;ALTER TABLE battle_history_events ADD CONSTRAINT restore_fault CHECK (game_id<>{game} OR event_seq<={int(before_events)});')
        try:
            bad_code,bad_result,_=battle(actor,game,'move',card_id=cid,row=card['row'],col=3)
            check('history fault not reported as committed',bad_code>=400 or not bad_result.get('ok'))
        finally:sql('USE LAB_DB;ALTER TABLE battle_history_events DROP CONSTRAINT restore_fault;')
        check('rollback leaves state and events unchanged',sql(f'USE LAB_DB;SELECT json,version FROM games WHERE ind={game};')==before_game and sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id={game};')==before_events)

        code,body,_=http(other,game,query='&ajax=battle_command_status&command_id='+data['command_id'])
        check('command status scoped to server actor',json.loads(body)['command_status']=='unknown' and 'before_version' not in json.loads(body))
        data['command_id']='conflict-new';data['cmd']='end_turn'
        code,body,_=http(actor,game,data,'&ajax=battle_command');check('stale CAS rejected',code==409)
        before=state(game)['battle']['active'];code,res,_=battle(other,game,'end_turn');check('membership does not grant foreign turn',state(game)['battle']['active']==before and not res.get('ok'))
        check('rejected command has no confirmed events',sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id={game};')==before_events)
        revealed=state(game)
        for x in revealed['cards'].values():
            if x['instance_id']==cid:x['revealed']=True
        set_fixture(game,revealed)

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

        final_state=state(game)
        for client in [a,b]:
            rows,cursor=journal_entries(client,game)
            text=' '.join(e['text'] for e in rows)
            check('basic combat and terminal text '+('host' if client is a else 'player'),all(t in text for t in ['атакует','Бросок','Итог атаки','Бой завершён']))
            check('poll at final cursor returns no duplicate '+('host' if client is a else 'player'),page(client,game,cursor)['entries']==[])
            check('page reload recovers same full history '+('host' if client is a else 'player'),journal_entries(client,game)[0]==rows)
        check('saved projection is not rewritten by later reveal',sql(f"USE LAB_DB;SELECT e.host_json,e.player_json FROM battle_history_events e WHERE game_id={game} AND event_type='move.started' ORDER BY event_seq LIMIT 1;")==old_event)
        read_before=sql(f'USE LAB_DB;SELECT json,version FROM games WHERE ind={game};SELECT last_event_seq,last_step_seq,completeness FROM battle_history_matches WHERE game_id={game};')
        journal_entries(a,game);journal_entries(b,game)
        check('reading does not change state or history metadata',sql(f'USE LAB_DB;SELECT json,version FROM games WHERE ind={game};SELECT last_event_seq,last_step_seq,completeness FROM battle_history_matches WHERE game_id={game};')==read_before)
        check('new structured projections contain frozen names',int(sql(f"USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id={game} AND event_type='move.started' AND payload_json LIKE '%Fixture%';"))>=1)
        (LAB/'projections.json').write_text(json.dumps({'host':journal_entries(a,game)[0],'player':journal_entries(b,game)[0]},ensure_ascii=False,indent=2),encoding='utf-8')
        # Presentation remains cosmetic; it cannot bypass recording for a battle action.
        before=state(arbitrary_game);count_events=sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id={arbitrary_game};')
        code,_,_=http(c,arbitrary_game,{'csrf':c.token('account'),'playmat_id':'mat-003'},'&ajax=playmat')
        expected=json.loads(json.dumps(before));expected['players']['host']['playmat_id']='mat-003'
        check('mandatory match playmat saves only presentation',code==200 and state(arbitrary_game)==expected and sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id={arbitrary_game};')==count_events)
        code,_,_=http(c,arbitrary_game,{'csrf':c.token('account'),'sound_enabled':'0'},'&ajax=preparation_preference')
        check('mandatory preparation sound remains available',code==200 and state(arbitrary_game)['players']['host']['sound_enabled'] is False)
        # Real configured grid and Solomon rooms, manual timeout through the locked persistence adapter.
        for draft_type in ['grid','solomon']:
            code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'draft' if draft_type=='grid' else 'solomon'})
            assert code==200,(code,data);room=data['room'];assert b.act(room)[0]==200
            pending=a.lobby(room['id'])[1]['room'];assert a.act(pending,'accept')[0]==200
            dg=a.lobby(room['id'])[1]['room']['game_id'];current=state(dg)
            check('mandatory '+draft_type+' room starts with history',current['status']=='draft' and sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_matches WHERE game_id={dg};')=='1')
            before=sql(f'USE LAB_DB;SELECT json,version FROM games WHERE ind={dg};');count_events=sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id={dg};')
            code,body,_=http(a,dg,{'cmd':'draft_sync','csrf':a.token('account')})
            check('mandatory '+draft_type+' no-progress polling has no confirmed mutation',code==200 and before==sql(f'USE LAB_DB;SELECT json,version FROM games WHERE ind={dg};') and sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id={dg};')==count_events)
            current=state(dg);clock=current['draft']['timer'];clock['elapsed']={'host':clock['total_limit'],'player':clock['total_limit']};clock['action_started_at']=int(time.time())-1000
            set_fixture(dg,current)
            code,body,_=http(a,dg,{'cmd':'draft_sync','csrf':a.token('account')})
            check('mandatory '+draft_type+' timeout persists through history transaction',code==200 and json.loads(body)['ok'] and state(dg)['status']=='view' and int(sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_batches WHERE game_id={dg};'))==1 and sql(f'USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id={dg};')==count_events)
        sql('USE LAB_DB;UPDATE cards SET price=7;')
        # Non-quick LuckTest must reach placement without the quick-start assertion.
        code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'lucktest','lucktest_card_count':'3','lucktest_quick_game':'0'})
        assert code==200,(code,data);room=data['room'];assert b.act(room)[0]==200;pending=a.lobby(room['id'])[1]['room'];assert a.act(pending,'accept')[0]==200
        normal=a.lobby(room['id'])[1]['room']['game_id']
        check('non-quick LuckTest records preparation without quick-only assertion',state(normal)['status']=='turn' and sql(f'USE LAB_DB;SELECT has_initial FROM battle_history_matches WHERE game_id={normal};')=='0')
        # A selected room must also enroll an atomic quick game before its first battle event.
        sql('USE LAB_DB;UPDATE cards SET price=7;')
        code,quick_room=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'lucktest','lucktest_card_count':'3','lucktest_quick_game':'1'})
        assert code==200,(code,quick_room);quick_room=quick_room['room']
        # Quick games follow the same mandatory lifecycle.
        assert b.act(quick_room)[0]==200;pending=a.lobby(quick_room['id'])[1]['room'];accepted=a.act(pending,'accept');assert accepted[0]==200,accepted
        quick_game=a.lobby(quick_room['id'])[1]['room']['game_id']
        check('every quick game enrolled and initial capture committed',state(quick_game)['status']=='battle' and sql(f'USE LAB_DB;SELECT has_initial FROM battle_history_matches WHERE game_id={quick_game};')=='1')

        sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
        live_env=dict(os.environ,INTEGRATION_URL='http://127.0.0.1:33672',JOURNAL_GAME=str(quick_game),JOURNAL_ACTIVE=state(quick_game)['battle']['active'],PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser,JOURNAL_EVIDENCE=str(LAB))
        live=subprocess.run([args.node,str(ROOT/'tools/test_live_journal_restoration_live_browser.cjs')],env=live_env,capture_output=True,text=True,encoding='utf-8',timeout=90)
        (LAB/'live-browser-output.txt').write_text(live.stdout+live.stderr,encoding='utf-8');check('two authenticated browsers receive new live events without reload',live.returncode==0)
        quick=state(quick_game);side=quick['battle']['active'];attacker=a if side=='host' else b;defender=b if side=='host' else a
        source=next(x for x in quick['cards'].values() if x['owner']==side and x['zone']=='field');target=next(x for x in quick['cards'].values() if x['owner']!=side and x['zone']=='field')
        for fixture_card in quick['cards'].values():
            if fixture_card['instance_id'] not in [source['instance_id'],target['instance_id']]:fixture_card.update(zone='graveyard',row=None,col=None,hp=0)
        source.update(row=3,col=3,closed=False,revealed=True);source['prop']={'unanswer':True}
        target.update(row=3,col=4,closed=True,revealed=True,hp=1)
        set_fixture(quick_game,quick)
        code,res,_=battle(attacker,quick_game,'strike',card_id=source['instance_id'],target_id=target['instance_id']);assert res.get('ok'),res
        for _ in range(20):
            current=state(quick_game);strike=current['battle'].get('strike')
            if not strike or current['status']=='game_over':break
            phase=strike['state']
            if phase=='waiting_defender':battle(defender,quick_game,'choose_defender',defender_id=0)
            elif phase=='waiting_choice':battle(attacker if strike['choice_winner']=='attack' else defender,quick_game,'choose_strike_mode',mode='normal')
            elif phase=='results':
                for client,key in [(a,'host'),(b,'player')]:
                    current=state(quick_game);strike=current['battle'].get('strike')
                    if strike and key not in strike.get('confirmed',[]):battle(client,quick_game,'confirm_strike',wait_id=current['battle'].get('informational_wait',{}).get('wait_id',''))
            else:raise AssertionError('unexpected quick combat '+phase)
        death_rows,_=journal_entries(attacker,quick_game)
        check('actual wounds death and natural terminal text',state(quick_game)['status']=='game_over' and all(any(t in e['text'] for e in death_rows) for t in ['получает','погибает','Бой завершён']))
        # Failure linking a selected room must roll back both game creation and enrollment.
        rollback_room=a.create();assert b.act(rollback_room)[0]==200
        pending=a.lobby(rollback_room['id'])[1]['room'];before_games=sql('USE LAB_DB;SELECT COUNT(*) FROM games;');before_meta=sql('USE LAB_DB;SELECT COUNT(*) FROM battle_history_matches;')
        sql("REVOKE UPDATE ON LAB_DB.lobby_rooms FROM 'LOBBY_USER'@'127.0.0.1';")
        try: failed=a.act(pending,'accept')
        finally:sql("GRANT UPDATE ON LAB_DB.lobby_rooms TO 'LOBBY_USER'@'127.0.0.1';")
        check('failed lobby acceptance rolls enrollment back',failed[0]==503 and sql('USE LAB_DB;SELECT COUNT(*) FROM games;')==before_games and sql('USE LAB_DB;SELECT COUNT(*) FROM battle_history_matches;')==before_meta)
        metadata_check=subprocess.run(php+['-r','require $argv[1]."/src/Core/Autoloader.php"; Berserk\\Core\\Autoloader::register(); Berserk\\Core\\Autoloader::addNamespace("Berserk\\\\",$argv[1]."/src/"); $db=new Berserk\\Core\\Db(require $argv[1]."/config/db.php"); $p=$db->fetchOne("SELECT @@port p,DATABASE() d"); if ((int)$p["p"]!==33671 || !preg_match("/^lobby_[0-9]+$/D",$p["d"])) exit(2); $names=new Berserk\\Core\\BattleHistory\\JournalCardNames($db); $card=new Berserk\\Core\\CardInstance(instanceId:999,ukid:"missing_definition_for_journal",owner:"host"); if ($names->name($card)!==null) exit(3); echo "PASS";',str(ROOT)],capture_output=True,text=True)
        check('missing database card definition safely returns no name',metadata_check.returncode==0 and metadata_check.stdout=='PASS')
        creation_check=subprocess.run(php+['-r', 'require $argv[1]."/src/Core/Autoloader.php"; Berserk\\Core\\Autoloader::register(); Berserk\\Core\\Autoloader::addNamespace("Berserk\\\\",$argv[1]."/src/"); $db=new Berserk\\Core\\Db(require $argv[1]."/config/db.php"); if((int)$db->fetchOne("SELECT @@port p")["p"]!==33671)exit(2); $repo=new Berserk\\Core\\GameRepository($db); $s=$repo->create(3,4); $denied=false; try{$repo->save($s);}catch(LogicException){$denied=true;} if(!$denied)exit(4); if($s->journalSchema!==1 || !$db->fetchOne("SELECT game_id FROM battle_history_matches WHERE game_id=".$s->gameId))exit(3); echo "PASS";', str(ROOT)],capture_output=True,text=True)
        check('standalone GameRepository creation initializes mandatory history',creation_check.returncode==0 and creation_check.stdout=='PASS')
        # A browser verification of the REAL account API and DOM, while lab servers are running.
        sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
        env=dict(os.environ,INTEGRATION_URL='http://127.0.0.1:33672',JOURNAL_GAME=str(game),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser,JOURNAL_EVIDENCE=str(LAB))
        ui=subprocess.run([args.node,str(ROOT/'tools/test_live_journal_restoration_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',timeout=90)
        (LAB/'browser-output.txt').write_text(ui.stdout+ui.stderr,encoding='utf-8');check('two authenticated browsers actual journal',ui.returncode==0)
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
