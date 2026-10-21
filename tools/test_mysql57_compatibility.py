"""MySQL 5.7 compatibility lab only. No production/config access. Requires local 5.7 binaries."""
import pathlib,subprocess,socket,time,json,tempfile,shutil,os
ROOT=pathlib.Path(__file__).resolve().parents[1];BIN=pathlib.Path('C:/OSPanel/modules/database/MySQL-5.7-Win10/bin')
LAB=pathlib.Path(tempfile.mkdtemp(prefix='mysql57-compat-',dir=ROOT/'tmp'));PORT=33591
OUT=ROOT/'docs/evidence/stage5e1';OUT.mkdir(parents=True,exist_ok=True)
with socket.socket() as s:assert s.connect_ex(('127.0.0.1',PORT))!=0
subprocess.run([str(BIN/'mysqld.exe'),'--no-defaults','--initialize-insecure','--basedir='+str(BIN.parent),'--datadir='+str(LAB/'data')],capture_output=True,check=True)
log=open(LAB/'server.log','wb');p=subprocess.Popen([str(BIN/'mysqld.exe'),'--no-defaults','--basedir='+str(BIN.parent),'--datadir='+str(LAB/'data'),'--port='+str(PORT),'--bind-address=127.0.0.1','--skip-log-bin','--console'],stdout=log,stderr=subprocess.STDOUT,creationflags=subprocess.CREATE_NO_WINDOW)
rows=[];details={}
def sql(s,ok=True):
 r=subprocess.run([str(BIN/'mysql.exe'),'--no-defaults','-h127.0.0.1','-P'+str(PORT),'-uroot','--batch','--raw','--skip-column-names'],input=s,capture_output=True,text=True,encoding='utf-8');
 if ok:assert r.returncode==0,r.stderr
 return r

def check(name,ok):
 rows.append({'test':name,'pass':bool(ok)})
 if not ok:raise AssertionError(name)
try:
 for _ in range(150):
  with socket.socket() as s:
   if s.connect_ex(('127.0.0.1',PORT))==0:break
  time.sleep(.1)
 identity=sql('SELECT VERSION(),@@port,@@datadir,@@sql_mode;').stdout.strip().split('\t');check('isolated port/datadir',identity[1]==str(PORT) and pathlib.Path(identity[2]).resolve()==(LAB/'data').resolve());details['server']=identity[0];details['sql_mode']=identity[3]
 sql("CREATE DATABASE stage5e1;USE stage5e1;CREATE TABLE games(ind INT NOT NULL AUTO_INCREMENT PRIMARY KEY,host_id INT NOT NULL,player_id INT NOT NULL,status VARCHAR(32) NOT NULL,json LONGTEXT NOT NULL,version INT NOT NULL DEFAULT 0,date DATETIME NOT NULL,active INT NOT NULL) ENGINE=InnoDB;")
 for name in ['20261021_auth.sql','20261021_lobby.sql','20261021_command_journal.sql','20261019_game_chat.sql','20261020_battle_history.sql']:
  sql('USE stage5e1;'+(ROOT/'tools/migrations'/name).read_text());check('original additive DDL parses '+name,True)
 for name in ['20261021_release_preflight.sql','20261021_auth_postchecks.sql','20261021_lobby_postchecks.sql','20261021_transport_preflight.sql','20261020_battle_history_postchecks.sql','20261021_mysql57_postchecks.sql']:
  r=sql('USE stage5e1;'+(ROOT/'tools/migrations'/name).read_text());check('read-only pre/postcheck '+name,True)
  if name=='20261021_release_preflight.sql':(OUT/'mysql57-preflight.txt').write_text(r.stdout,encoding='utf-8')
 sql("USE stage5e1;INSERT INTO accounts(login,login_key,password_hash,created_at)VALUES('Owner','owner','synthetic',NOW(6)),('Candidate','candidate','synthetic',NOW(6));")
 r=sql("USE stage5e1;INSERT INTO accounts(login,login_key,password_hash,created_at)VALUES('OWNER','owner','synthetic',NOW(6));",False);check('UNIQUE login key enforced',r.returncode!=0 and '1062' in r.stderr)
 r=sql("USE stage5e1;INSERT INTO lobby_rooms(creator_account_id,state,version,created_at,updated_at)VALUES(999,'open',1,NOW(6),NOW(6));",False);check('FK missing account enforced',r.returncode!=0 and '1452' in r.stderr)
 sql("USE stage5e1;INSERT INTO accounts(login,login_key,password_hash,created_at)VALUES('!invalid','!invalid','synthetic',NOW(6));")
 check('CHECK ignored on 5.7 (not protection)',sql("USE stage5e1;SELECT COUNT(*) FROM accounts WHERE login='!invalid';").stdout.strip()=='1')
 check('invalid data detected by read-only postcheck','\n1\n' in '\n'+sql('USE stage5e1;'+(ROOT/'tools/migrations/20261021_auth_postchecks.sql').read_text()).stdout)
 sql("USE stage5e1;START TRANSACTION;INSERT INTO games(host_id,player_id,status,json,version,date,active)VALUES(1,2,'mode','{}',1,NOW(),1);INSERT INTO lobby_rooms(creator_account_id,accepted_account_id,game_id,state,version,created_at,updated_at)VALUES(1,2,LAST_INSERT_ID(),'started',1,NOW(6),NOW(6));ROLLBACK;")
 check('single InnoDB rollback removes game and room',sql('USE stage5e1;SELECT (SELECT COUNT(*) FROM games)+(SELECT COUNT(*) FROM lobby_rooms);').stdout.strip()=='0')
 sql("USE stage5e1;START TRANSACTION;INSERT INTO games(host_id,player_id,status,json,version,date,active)VALUES(1,2,'mode','{}',1,NOW(),1);INSERT INTO lobby_rooms(creator_account_id,accepted_account_id,game_id,state,version,created_at,updated_at)VALUES(1,2,LAST_INSERT_ID(),'started',1,NOW(6),NOW(6));COMMIT;")
 check('single InnoDB commit persists linked pair',sql('USE stage5e1;SELECT COUNT(*) FROM lobby_rooms r JOIN games g ON g.ind=r.game_id;').stdout.strip()=='1')
 r=sql('SELECT @@in_transaction;',False);check('confirmed runtime blocker @@in_transaction',r.returncode!=0 and '1193' in r.stderr);details['runtime_blocker']=r.stderr.strip()
 probe=LAB/'lobby.php';probe.write_text("<?php require '"+(ROOT/'src/Core/Autoloader.php').as_posix()+"';Berserk\\Core\\Autoloader::register();Berserk\\Core\\Autoloader::addNamespace('Berserk\\\\','"+(ROOT/'src').as_posix()+"');$s=new Berserk\\Lobby\\LobbyStore(['host'=>'127.0.0.1','port'=>33591,'user'=>'root','pass'=>'','name'=>'stage5e1']);$id=$s->create(1);$s->act($id,2,1,null,'open');$r=$s->read($id,1);try{$s->act($id,1,$r['version'],$r['candidate_request_id'],'accept');echo json_encode(['accepted'=>true]);}catch(Throwable $e){echo json_encode(['accepted'=>false,'error_code'=>$e->getCode(),'state'=>$s->read($id,1)['state']]);}",encoding='utf-8')
 r=subprocess.run(['C:/OSPanel/modules/php/PHP_8.1/php.exe','-n','-d','extension_dir=C:/OSPanel/modules/php/PHP_8.1/ext','-d','extension=mysqli',str(probe)],capture_output=True,text=True,check=True);response=json.loads(r.stdout);details['actual_lobby_accept']=response
 check('real Lobby accept fails closed and rolls back on 5.7',response=={'accepted':False,'error_code':1193,'state':'awaiting_confirmation'} and sql('USE stage5e1;SELECT COUNT(*) FROM games;').stdout.strip()=='1')
 # Real AuthStore/AuthService on MySQL5.7 with the unchanged least-privilege grants.
 sql("CREATE USER 'compat_auth'@'127.0.0.1' IDENTIFIED BY '';GRANT SELECT,INSERT ON stage5e1.accounts TO 'compat_auth'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON stage5e1.auth_login_attempts TO 'compat_auth'@'127.0.0.1';")
 authprobe=LAB/'auth.php'
 authprobe.write_text("<?php require '"+(ROOT/'src/Core/Autoloader.php').as_posix()+"';Berserk\\Core\\Autoloader::register();Berserk\\Core\\Autoloader::addNamespace('Berserk\\\\','"+(ROOT/'src').as_posix()+"');$db=new Berserk\\Auth\\AuthStore(['host'=>'127.0.0.1','port'=>33591,'user'=>'compat_auth','pass'=>'','name'=>'stage5e1']);$s=new Berserk\\Auth\\AuthService($db,str_repeat('x',32));$a=$s->register('MysqlUser','SyntheticPassword123','7f000001');$b=$s->login('mysqluser','SyntheticPassword123','7f000001');echo json_encode(['same_account'=>$a->id===$b->id,'cost'=>password_get_info($db->find('mysqluser')['password_hash'])['options']['cost']]);",encoding='utf-8')
 r=subprocess.run(['C:/OSPanel/modules/php/PHP_8.1/php.exe','-n','-d','extension_dir=C:/OSPanel/modules/php/PHP_8.1/ext','-d','extension=mysqli',str(authprobe)],capture_output=True,text=True,check=True)
 details['native_auth']=json.loads(r.stdout);check('real Auth register/login cost12 on least-privilege MySQL5.7',details['native_auth']=={'same_account':True,'cost':12})
 # Generated columns/UNIQUE protections remain enforced even though CHECK is ignored.
 sql("USE stage5e1;INSERT INTO battle_history_matches VALUES(1,'recording','none',1,1,0,0,0,0,NOW(6),NOW(6));INSERT INTO battle_history_batches(game_id,journal_id,player_key,command_id,persistence_version,batch_hash,created_at)VALUES(1,1,'host','test',1,REPEAT('a',64),NOW(6));INSERT INTO battle_history_actions(game_id,action_id,root_id,action_type,initiator,lifecycle,started_event_seq)VALUES(1,'a','a','test','host','open',1);")
 step="INSERT INTO battle_history_steps(game_id,step_seq,journal_id,capture_order,boundary_key,step_type,action_id,last_event_seq,persistence_version,schema_version,state_json,public_json,host_json,player_json,state_hash,is_initial,is_final,created_at)VALUES(1,{seq},1,{seq},'{seq}','test','a',0,1,1,'{{}}','{{}}','{{}}','{{}}',REPEAT('a',64),1,0,NOW(6));"
 sql('USE stage5e1;'+step.format(seq=1));r=sql('USE stage5e1;'+step.format(seq=2),False);check('history generated initial UNIQUE enforced',r.returncode!=0 and '1062' in r.stderr)
 print(json.dumps({'checks':len(rows),'results':rows,'details':details,'limit':'Local MySQL5.7.39/PHP8.1.9, not exact production5.7.30/PHP8.4.5. Blocker checks are expected failures, not release PASS.'},indent=2))
finally:
 (OUT/'mysql57-results.json').write_text(json.dumps({'checks':len(rows),'results':rows,'details':details},indent=2),encoding='utf-8')
 p.terminate();p.wait(timeout=15);log.close();assert LAB.parent.resolve()==(ROOT/'tmp').resolve() and LAB.name.startswith('mysql57-compat-');shutil.rmtree(LAB)
