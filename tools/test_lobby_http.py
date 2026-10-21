"""Stage 5B: real multi-process HTTP, InnoDB races, rollback and browser UI.
No gameplay config is read. Creates only a new lab datadir below tmp/lobby-lab.
"""
import argparse, concurrent.futures, http.cookiejar, json, os, pathlib, socket
import subprocess, sys, time, urllib.error, urllib.parse, urllib.request
sys.dont_write_bytecode = True
from test_auth_http import Browser

ROOT = pathlib.Path(__file__).resolve().parents[1]
LAB = ROOT/'tmp/lobby-lab'
RESULTS = []
CONCURRENCY = {}

def check(name, ok):
    RESULTS.append({'test':name,'pass':bool(ok)})
    if not ok: raise AssertionError(name)

class Client(Browser):
    def __init__(self,port=33562): super().__init__(port)
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
    assert not (ROOT/'config/auth.local.php').exists(),'Refusing to overwrite private config'
    for port in range(33561,33568):
        with socket.socket() as s:assert s.connect_ex(('127.0.0.1',port))!=0,f'Port {port} already in use'
    LAB.mkdir(parents=True,exist_ok=True);(LAB/'sessions').mkdir(exist_ok=True)
    if not (LAB/'data/my.ini').exists():
        assert not (LAB/'data').exists(),'Refusing to initialize existing data directory'
        subprocess.run([str(pathlib.Path(args.db_bin)/'mariadb-install-db.exe'),f'--datadir={LAB / "data"}','--port=33561'],check=True,capture_output=True)
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
        p=subprocess.run([str(pathlib.Path(args.db_bin)/'mysql.exe'),'--no-defaults','-h127.0.0.1','-P33561','-uroot','--batch','--raw','--skip-column-names'],input=text,text=True,encoding='utf-8',capture_output=True)
        assert p.returncode==0,p.stderr
        return p.stdout.strip()
    try:
        spawn([str(pathlib.Path(args.db_bin)/'mysqld.exe'),'--no-defaults',f'--datadir={LAB / "data"}','--bind-address=127.0.0.1','--port=33561','--skip-log-bin','--console'],'db');ready(33561)
        port,directory=sql('SELECT @@port,@@datadir;').split('\t')
        check('exact isolated database identity',port=='33561' and directory.replace('\\','/').rstrip('/').lower()==str((LAB/'data').resolve()).replace('\\','/').lower())
        sql("CREATE DATABASE LAB_DB;USE LAB_DB;CREATE TABLE games(ind INT NOT NULL AUTO_INCREMENT PRIMARY KEY,host_id INT NOT NULL,player_id INT NOT NULL,status VARCHAR(32) NOT NULL,json LONGTEXT NOT NULL,version INT NOT NULL DEFAULT 0,date DATETIME NOT NULL,active INT NOT NULL) ENGINE=InnoDB;INSERT INTO games VALUES(1,1,2,'battle','{}',0,UTC_TIMESTAMP(),1);")
        for name in ['20261021_auth.sql','20261021_lobby_preflight.sql','20261021_lobby.sql','20261021_lobby_setup.sql']:
            sql('USE LAB_DB;'+(ROOT/'tools/migrations'/name).read_text())
        (LAB/'postchecks.txt').write_text(sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261021_lobby_postchecks.sql').read_text()),encoding='utf-8')
        sql("CREATE USER 'AUTH_USER'@'127.0.0.1' IDENTIFIED BY '';GRANT SELECT,INSERT ON LAB_DB.accounts TO 'AUTH_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.auth_login_attempts TO 'AUTH_USER'@'127.0.0.1';CREATE USER 'LOBBY_USER'@'127.0.0.1' IDENTIFIED BY '';GRANT SELECT ON LAB_DB.accounts TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.lobby_rooms TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.games TO 'LOBBY_USER'@'127.0.0.1';")
        check('runtime no TRIGGER or DDL privileges','TRIGGER' not in sql("SHOW GRANTS FOR 'LOBBY_USER'@'127.0.0.1';") and 'CREATE' not in sql("SHOW GRANTS FOR 'LOBBY_USER'@'127.0.0.1';"))
        db=lambda who:"['host'=>'127.0.0.1','port'=>33561,'name'=>'"+database+"','user'=>'"+who+"','pass'=>'']"
        config="<?php return ['db'=>"+db(authuser)+",'lobby_db'=>"+db(user)+",'limiter_secret'=>'"+os.urandom(32).hex()+"','allow_loopback_http'=>true];"
        (ROOT/'config/auth.local.php').write_text(config)
        router=LAB/'router.php'
        router.write_text("<?php spl_autoload_register(static function($c){$safe=['Berserk\\\\Core\\\\GameState','Berserk\\\\Core\\\\PlayerState','Berserk\\\\Core\\\\GameSettings','Berserk\\\\Core\\\\BoosterSettings','Berserk\\\\Core\\\\PlaymatCatalog'];if(str_starts_with($c,'Berserk\\\\Core\\\\')&&!in_array($c,$safe,true)&&!str_ends_with($c,'PrepareProcessor')&&!str_ends_with($c,'Command')&&!str_ends_with($c,'Result'))throw new RuntimeException('GAMEPLAY_TRAP');});$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if(in_array($p,['/auth.php','/lobby.php'],true)){require '"+str(ROOT/'www').replace('\\','/')+"'.$p;return true;}if(str_starts_with($p,'/assets/'))return false;http_response_code(404);")
        php=[args.php,'-n','-d',f'extension_dir={pathlib.Path(args.php).parent / "ext"}','-d','extension=mysqli','-d',f'session.save_path={LAB / "sessions"}','-d','display_errors=0','-d','log_errors=1']
        for port in range(33562,33566):spawn(php+['-S',f'127.0.0.1:{port}','-t',str(ROOT/'www'),str(router)],'http-'+str(port));ready(port)
        password='LobbyFixture123'
        names=['Creator','Candidate','Other','Fourth']
        clients=[Client(33562+i) for i in range(4)]
        for c,name in zip(clients,names):
            assert c.post('register',name,password)[0]==303
            assert c.post('login',name,password)[0]==303
        a,b,c,d=clients
        check('login redirects to lobby',a.post('login','Creator',password)[2]['Location']=='lobby.php')
        before=sql('USE LAB_DB;SELECT * FROM games WHERE ind=1;')
        check('guest API public list',Client().lobby()[0]==200)
        check('guest page shows lobby',Client().lobby(api=False)[0]==200)
        room=a.create();rid=room['id']
        check('create only room, no game',room['state']=='open' and room['game_id'] is None and sql('USE LAB_DB;SELECT COUNT(*) FROM games;')=='1')
        check('creator opens room without becoming candidate',a.act(room)[1]['room']['role']=='creator' and a.lobby(rid)[1]['room']['state']=='open')
        check('GET cannot claim candidate',b.lobby(rid)[0]==403 and sql(f'USE LAB_DB;SELECT state FROM lobby_rooms WHERE id={rid};')=='open')
        check('CSRF rejected',b.act(room,csrf='bad')[0]==403)
        tokens=[x.token('account') for x in [b,c]]
        with concurrent.futures.ThreadPoolExecutor() as pool:
            race=list(pool.map(lambda i:[b,c][i].act(room,csrf=tokens[i])[0],range(2)))
        check('two concurrent candidates one slot',sorted(race)==[200,403])
        CONCURRENCY['candidate_race']={'http_codes':race,'room_id':rid,'games_before_accept':int(sql('USE LAB_DB;SELECT COUNT(*) FROM games;'))}
        chosen=b if race[0]==200 else c;outsider=c if chosen is b else b
        waiting=a.lobby(rid)[1]['room'];candidate=chosen.lobby(rid)[1]['room']
        check('current candidate repeated open is read only',chosen.act(candidate)[0]==200 and a.lobby(rid)[1]['room']['candidate_request_id']==waiting['candidate_request_id'] and a.lobby(rid)[1]['room']['version']==waiting['version'])
        check('candidate not participant and no game',candidate['role']=='candidate' and candidate['game_id'] is None and candidate['candidate_request_id'] is None and not candidate['can_decide'])
        check('candidate cannot self-accept',chosen.act(waiting,'accept')[0]==403)
        check('occupied room outsider denied',outsider.lobby(rid)[0]==403)
        check('stale accept rejected',a.act(waiting,'accept',version=1)[0]==409)
        check('wrong request id rejected',a.act(waiting,'accept',request='a'*64)[0]==409)
        check('reject',a.act(waiting,'reject')[0]==200)
        check('rejected candidate old GET forbidden',chosen.lobby(rid)[0]==403)
        check('old open POST cannot reclaim',chosen.act(room)[0]==409)
        reopened=a.lobby(rid)[1]['room'];assert chosen.act(reopened)[0]==200
        fresh=a.lobby(rid)[1]['room']
        check('repeat candidate new request id',fresh['candidate_request_id']!=waiting['candidate_request_id'])
        check('stale request same account cannot be accepted',a.act(fresh,'accept',request=waiting['candidate_request_id'])[0]==409)
        check('stale reject rejected',a.act(waiting,'reject')[0]==409)
        # Same creator in separate PHP sessions: room lock, not PHP session lock, serializes accepts.
        a2=Client(33565);assert a2.post('login','Creator',password)[0]==303
        tokens=[x.token('account') for x in [a,a2]]
        with concurrent.futures.ThreadPoolExecutor() as pool:
            codes=list(pool.map(lambda i:[a,a2][i].act(fresh,'accept',csrf=tokens[i])[0],range(2)))
        check('two concurrent accept exactly one commit',sorted(codes)==[200,409])
        CONCURRENCY['double_accept']={'http_codes':codes,'room_id':rid,'linked_games':int(sql(f'USE LAB_DB;SELECT COUNT(*) FROM games g JOIN lobby_rooms r ON r.game_id=g.ind WHERE r.id={rid};'))}
        started=a.lobby(rid)[1]['room'];game=started['game_id']
        check('started shared by both',started['state']=='started' and chosen.lobby(rid)[1]['room']['game_id']==game)
        row=sql(f'USE LAB_DB;SELECT host_id,player_id,status,json,version FROM games WHERE ind={game};').split('\t');state=json.loads(row[3])
        check('consistent SQL JSON account identities',int(row[0])==state['host_id']==1 and int(row[1])==state['player_id'] and int(row[0])!=int(row[1]) and state['players']['host']['user_id']==int(row[0]) and state['players']['player']['user_id']==int(row[1]))
        check('configured system deck no Draft Sealed or side',row[2]=='deck' and state['mode']=='system' and state['draft'] is None and state['players']['host']['side'] is None and state['players']['player']['side'] is None and not state['battle'] and row[4]=='1')
        check('outsider started room forbidden',outsider.lobby(rid)[0]==403)
        count=sql('USE LAB_DB;SELECT COUNT(*) FROM games;')
        check('retry after lost accepted response read recovers and no duplicate',a.act(fresh,'accept')[0]==409 and a.lobby(rid)[1]['room']['game_id']==game and sql('USE LAB_DB;SELECT COUNT(*) FROM games;')==count)
        # A client discards an actual committed response, then resolves outcome only via GET.
        lost=a.create();assert b.act(lost)[0]==200;lostwaiting=a.lobby(lost['id'])[1]['room']
        a.act(lostwaiting,'accept')
        check('discarded HTTP reply recovered',a.lobby(lost['id'])[1]['room']['state']=='started' and a.act(lostwaiting,'accept')[0]==409)
        room2=a.create();assert b.act(room2)[0]==200;w=a.lobby(room2['id'])[1]['room'];tokens=[x.token('account') for x in [a,a2]]
        with concurrent.futures.ThreadPoolExecutor() as pool:
            ar=list(pool.map(lambda i:[a,a2][i].act(w,['accept','reject'][i],csrf=tokens[i])[0],range(2)))
        after=a.lobby(room2['id'])[1]['room']
        check('accept reject race one transition',sorted(ar)==[200,409] and after['state'] in ['open','started'])
        CONCURRENCY['accept_reject']={'http_codes':ar,'room_id':room2['id'],'final_state':after['state'],'final_version':after['version']}
        # Fail the JSON UPDATE AFTER INSERT; complete rollback must remove the new games row.
        rollback=a.create();assert b.act(rollback)[0]==200;w=a.lobby(rollback['id'])[1]['room'];n=sql('USE LAB_DB;SELECT COUNT(*) FROM games;')
        sql("REVOKE UPDATE ON LAB_DB.games FROM 'LOBBY_USER'@'127.0.0.1';")
        code,error=a.act(w,'accept');sql("GRANT UPDATE ON LAB_DB.games TO 'LOBBY_USER'@'127.0.0.1';")
        check('error creating GameState generic and rolls back game',code==503 and 'SQL' not in error['error'] and sql('USE LAB_DB;SELECT COUNT(*) FROM games;')==n and a.lobby(w['id'])[1]['room']['version']==w['version'])
        CONCURRENCY['rollback_after_games_insert']={'http_code':code,'games_before':int(n),'games_after':int(sql('USE LAB_DB;SELECT COUNT(*) FROM games;')),'room_version':a.lobby(w['id'])[1]['room']['version']}
        # Failure linking room after valid GameState must also roll back both writes.
        sql("REVOKE UPDATE ON LAB_DB.lobby_rooms FROM 'LOBBY_USER'@'127.0.0.1';")
        code,error=a.act(w,'accept');sql("GRANT UPDATE ON LAB_DB.lobby_rooms TO 'LOBBY_USER'@'127.0.0.1';")
        check('failure room link full rollback no orphan',code==503 and sql('USE LAB_DB;SELECT COUNT(*) FROM games;')==n and a.lobby(w['id'])[1]['room']['state']=='awaiting_confirmation')
        CONCURRENCY['rollback_room_link']={'http_code':code,'games_before':int(n),'games_after':int(sql('USE LAB_DB;SELECT COUNT(*) FROM games;')),'room_state':a.lobby(w['id'])[1]['room']['state']}
        check('accept after rollback succeeds',a.act(w,'accept')[0]==200)
        replaced=a.create();assert b.act(replaced)[0]==200;old=a.lobby(replaced['id'])[1]['room'];assert a.act(old,'reject')[0]==200
        reopened=a.lobby(replaced['id'])[1]['room'];assert c.act(reopened)[0]==200;new=a.lobby(replaced['id'])[1]['room'];assert a.act(new,'accept')[0]==200
        check('rejected account cannot access future match via room',b.lobby(replaced['id'])[0]==403 and b.act(old)[0]==403)
        check('unique game mapping',sql('USE LAB_DB;SELECT COUNT(*)=COUNT(DISTINCT game_id) FROM lobby_rooms WHERE game_id IS NOT NULL;')=='1')
        snapshot=lambda:sql('USE LAB_DB;SELECT * FROM lobby_rooms ORDER BY id;SELECT * FROM games ORDER BY ind;SELECT * FROM auth_login_attempts ORDER BY bucket_key;')
        snap=snapshot()
        for _ in range(3):a.lobby();a.lobby(rid);chosen.lobby(rid)
        check('GET polls no SQL mutation',snapshot()==snap)
        listed=a.lobby()[1]['rooms'];check('active table metadata no secrets',all('json' not in r and 'candidate_request_id' not in r and 'game_id' not in r for r in listed))
        check('legacy game not in lobby',len(listed)==int(sql('USE LAB_DB;SELECT COUNT(*) FROM lobby_rooms;')))
        sql(f"USE LAB_DB;UPDATE games SET status='game_over' WHERE ind={game};")
        check('finished excluded active table',rid not in [r['id'] for r in a.lobby()[1]['rooms']] and a.lobby(rid)[1]['room']['status']=='Завершена')
        token=a.token('account');check('logout login restores own room',a.request('logout',{'csrf':token})[0]==303 and a.lobby(rid)[0]==401 and a.post('login','Creator',password)[0]==303 and a.lobby(rid)[0]==200)
        guest=Client();guest.token();session=LAB/'sessions'/('sess_'+guest.sid());session.write_text(session.read_text()+'berserk_battle_journal_binding|s:64:"'+'b'*64+'";user_id|i:1;')
        check('invitation and legacy user_id cannot authorize lobby',guest.lobby(rid)[0]==401)
        check('legacy game unchanged',sql('USE LAB_DB;SELECT * FROM games WHERE ind=1;')==before)
        # Test actual GameRepository loading/CAS in disposable source copy only (never runtime route).
        regression=LAB/'regression';(regression/'config').mkdir(parents=True,exist_ok=True)
        import shutil
        for folder in ['src','tools']:shutil.copytree(ROOT/folder,regression/folder,dirs_exist_ok=True)
        (regression/'config/db.php').write_text("<?php return ['host'=>'127.0.0.1:33561','name'=>'"+database+"','user'=>'"+user+"','pass'=>''];")
        result=subprocess.run(php+[str(regression/'tools/test_repository_optimistic_locking.php')],capture_output=True,text=True)
        (LAB/'repository-regression.txt').write_text(result.stdout+result.stderr,encoding='utf-8')
        check('existing GameRepository optimistic locking real DB',result.returncode==0 and 'passed' in result.stdout)
        statecheck=regression/'check.php';statecheck.write_text("<?php require __DIR__.'/src/Core/Autoloader.php';Berserk\\Core\\Autoloader::register();Berserk\\Core\\Autoloader::addNamespace('Berserk\\\\',__DIR__.'/src/');$r=new Berserk\\Core\\GameRepository(new Berserk\\Core\\Db(require __DIR__.'/config/db.php'));$s=$r->findById("+str(game)+");if($s->hostId!==1||$s->status!=='deck'||$s->getPlayerKeyByUserId($s->playerId)!=='player')exit(1);echo 'PASS persisted account GameState loads';")
        result=subprocess.run(php+[str(statecheck)],capture_output=True,text=True)
        check('GameRepository loads accepted GameState',result.returncode==0 and 'PASS' in result.stdout)
        # Real browser tests with separate storage contexts and actual 1.5s polling.
        sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
        env=dict(os.environ,LOBBY_TEST_URL='http://127.0.0.1:33562',PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser,LOBBY_TEST_EVIDENCE=str(LAB))
        ui=subprocess.run([args.node,str(ROOT/'tools/test_lobby_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=100)
        (LAB/'browser-output.txt').write_text(ui.stdout+ui.stderr,encoding='utf-8');check('two browser UI polling suite',ui.returncode==0)
        (LAB/'postchecks.txt').write_text(sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261021_lobby_postchecks.sql').read_text()),encoding='utf-8')
        print(json.dumps({'passed':len(RESULTS),'results':RESULTS},ensure_ascii=False,indent=2))
    finally:
        (LAB/'results.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')
        (LAB/'concurrency.json').write_text(json.dumps(CONCURRENCY,ensure_ascii=False,indent=2),encoding='utf-8')
        (ROOT/'config/auth.local.php').unlink(missing_ok=True)
        for p in reversed(procs):p.terminate()
        for p in procs:
            try:p.wait(timeout=10)
            except subprocess.TimeoutExpired:p.kill()
        for f in logs:f.close()

if __name__=='__main__':main()
