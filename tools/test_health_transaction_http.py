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
        probe=subprocess.run(php+[str(ROOT/'tools/health_transaction_probe.php')],capture_output=True,text=True,encoding='utf-8')
        print(probe.stdout);print(probe.stderr);check('health transaction atomicity and retry',probe.returncode==0)
    finally:
        (LAB/'results.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')
        for name in ['auth.local.php','db.php','battle_history.local.php']:(ROOT/'config'/name).unlink(missing_ok=True)
        for p in reversed(procs):p.terminate()
        for p in procs:
            try:p.wait(timeout=10)
            except subprocess.TimeoutExpired:p.kill()
        for f in logs:f.close()

if __name__=='__main__':main()
