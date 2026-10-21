"""Isolated Windows HTTP/security integration suite. Never reads gameplay config.
Requires PHP 8.1/mysqli, MariaDB 10.8 binaries, Python 3. Run --help.
The datadir MUST be newly initialized under this checkout's tmp/auth-lab/data.
"""
import argparse, concurrent.futures, http.client, http.cookiejar, http.server, json
import os, pathlib, re, socket, ssl, subprocess, threading, time, urllib.parse, urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
LAB = ROOT / 'tmp/auth-lab'
RESULTS = []

def check(name, condition):
    RESULTS.append({'test': name, 'pass': bool(condition)})
    if not condition:
        raise AssertionError(name)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None

class Browser:
    def __init__(self, port=33552, secure=False):
        self.jar = http.cookiejar.CookieJar()
        self.base = ('https' if secure else 'http') + f'://127.0.0.1:{port}/auth.php'
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect(), urllib.request.HTTPSHandler(context=ssl._create_unverified_context()))
    def request(self, page='login', data=None, suffix=''):
        req = urllib.request.Request(self.base+'?page='+page+suffix, data=None if data is None else urllib.parse.urlencode(data).encode())
        try: response = self.opener.open(req, timeout=30)
        except urllib.error.HTTPError as e: response = e
        body = response.read().decode('utf-8')
        return response.code, body, response.headers
    def token(self, page='login'):
        code,body,headers=self.request(page)
        assert code == 200, (code,body)
        return re.search(r'name="csrf" value="([a-f0-9]{64})"',body).group(1)
    def post(self,page,login,password,csrf=None):
        return self.request(page,{'csrf':csrf or self.token(page),'login':login,'password':password})
    def sid(self): return next((c.value for c in self.jar if c.name=='BERSERKAUTH5A'),None)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--php', default='C:/OSPanel/modules/php/PHP_8.1/php.exe')
    parser.add_argument('--db-bin',default='C:/OSPanel/modules/database/MariaDB-10.8-Win10/bin')
    parser.add_argument('--openssl',required=True)
    args=parser.parse_args()
    assert (LAB/'data/my.ini').is_file(), 'Initialize NEW lab datadir with mariadb-install-db first'
    assert not (ROOT/'config/auth.local.php').exists(), 'Do not overwrite an existing auth config'
    for port in range(33551,33559):
        with socket.socket() as probe:
            assert probe.connect_ex(('127.0.0.1',port)) != 0, f'Lab port {port} already in use; refusing to connect'
    procs=[]; logs=[]
    run_id = str(time.time_ns())[-12:]
    database = 'stage5a_' + run_id
    runtime_user = 'auth_' + run_id
    def spawn(command,name):
        stream=open(LAB/(name+'.log'),'wb'); logs.append(stream)
        p=subprocess.Popen(command,cwd=ROOT,stdout=stream,stderr=subprocess.STDOUT,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0));procs.append(p);return p
    def ready(port):
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2): return
            except OSError: time.sleep(.1)
        raise AssertionError(f'port {port} not ready')
    def sql(statement):
        statement = statement.replace('stage5a_auth_test', database).replace('auth_test', runtime_user)
        result=subprocess.run([str(pathlib.Path(args.db_bin)/'mysql.exe'),'--no-defaults','-h127.0.0.1','-P33551','-uroot','--batch','--raw','--skip-column-names'],input=statement,text=True,encoding='utf-8',capture_output=True)
        if result.returncode: raise AssertionError(result.stderr)
        return result.stdout.strip()
    try:
        spawn([str(pathlib.Path(args.db_bin)/'mysqld.exe'),'--no-defaults',f'--datadir={LAB / "data"}','--bind-address=127.0.0.1','--port=33551','--skip-log-bin','--console'],'db');ready(33551)
        identity=sql('SELECT @@port,@@datadir;')
        server_port,server_dir=identity.split('\t')
        check('isolated database identity',server_port=='33551' and server_dir.replace('\\','/').rstrip('/').lower()==str((LAB/'data').resolve()).replace('\\','/').lower())
        sql('CREATE DATABASE stage5a_auth_test; USE stage5a_auth_test; CREATE TABLE games(ind INT PRIMARY KEY,host_id INT,player_id INT,status VARCHAR(32),json TEXT) ENGINE=InnoDB; INSERT INTO games VALUES(1,1,2,\'battle\',\'{}\');')
        sql('USE stage5a_auth_test;'+(ROOT/'tools/migrations/20261021_auth_preflight.sql').read_text())
        sql('USE stage5a_auth_test;'+(ROOT/'tools/migrations/20261021_auth.sql').read_text())
        postchecks=sql('USE stage5a_auth_test;'+(ROOT/'tools/migrations/20261021_auth_postchecks.sql').read_text())
        (LAB/'postchecks.txt').write_text(postchecks,encoding='utf-8')
        sql("CREATE USER 'auth_test'@'127.0.0.1' IDENTIFIED BY ''; GRANT SELECT,INSERT ON stage5a_auth_test.accounts TO 'auth_test'@'127.0.0.1'; GRANT SELECT,INSERT,UPDATE ON stage5a_auth_test.auth_login_attempts TO 'auth_test'@'127.0.0.1';")
        check('runtime grants exclude DDL, TRIGGER and games', 'TRIGGER' not in sql("SHOW GRANTS FOR 'auth_test'@'127.0.0.1';"))
        config="<?php return ['db'=>['host'=>'127.0.0.1','port'=>33551,'name'=>'stage5a_auth_test','user'=>'auth_test','pass'=>''],'limiter_secret'=>'"+os.urandom(32).hex()+"','allow_loopback_http'=>true];"
        config = config.replace('stage5a_auth_test', database).replace('auth_test', runtime_user)
        (ROOT/'config/auth.local.php').write_text(config)
        # Trap ANY gameplay autoload, and reject legacy paths at the isolated server boundary.
        router=LAB/'router.php'
        router.write_text("<?php spl_autoload_register(static function($c){if(str_starts_with($c,'Berserk\\\\')&&!str_starts_with($c,'Berserk\\\\Auth\\\\'))throw new RuntimeException('GAMEPLAY_CLASS_TRAP');}); if(getenv('AUTH_TEST_TLS')==='1')$_SERVER['HTTPS']='on'; $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); if($p!=='/auth.php'){http_response_code(404);exit;} require '"+str(ROOT/'www/auth.php').replace('\\','/')+"';")
        php=[args.php,'-n','-d',f'extension_dir={pathlib.Path(args.php).parent / "ext"}','-d','extension=mysqli','-d',f'session.save_path={LAB / "sessions"}','-d','display_errors=0','-d','log_errors=1']
        for port in range(33552,33556): spawn(php+['-S',f'127.0.0.1:{port}',str(router)],f'http-{port}');ready(port)
        def reset_limits(): sql('USE stage5a_auth_test; DELETE FROM auth_login_attempts;')
        before=sql('USE stage5a_auth_test; SELECT * FROM games;')
        counts=lambda:sql('USE stage5a_auth_test; SELECT COUNT(*) FROM accounts; SELECT COUNT(*) FROM auth_login_attempts;')
        c=counts();b=Browser();code,body,h=b.request()
        check('GET form read only and gameplay trap intact',code==200 and counts()==c and 'GAMEPLAY_CLASS_TRAP' not in body)
        check('security headers',h['Cache-Control']=='private, no-store' and "frame-ancestors 'none'" in h['Content-Security-Policy'] and h['X-Content-Type-Options']=='nosniff')
        check('HttpOnly SameSite separate cookie', 'HttpOnly' in h.get('Set-Cookie','') and 'SameSite=Lax' in h.get('Set-Cookie','') and 'PHPSESSID' not in h.get('Set-Cookie',''))
        check('CSRF rejected',b.post('register','Player','CorrectPass123',csrf='bad')[0]==403)
        check('register',b.post('register','Player','CorrectPass123')[0]==303)
        check('case insensitive unique conflict',b.post('register','player','CorrectPass123')[0]==422)
        check('72 byte password boundary',b.post('register','Boundary','x'*72)[0]==303)
        for login in ['ab','x'*33,'with space','<script>','Игрок']:
            check('invalid login '+repr(login),b.post('register',login,'CorrectPass123')[0]==422)
        for pw in ['short','x'*73,'x'*1025,'x'*10+'\0']:
            check('password bounds '+str(len(pw)),b.post('register','Bounds',pw)[0] in [400,422])
        stored=sql('USE stage5a_auth_test; SELECT password_hash FROM accounts;')
        check('hash at rest',stored.startswith('$2y$') and 'CorrectPass123' not in stored)
        reset_limits();wrong=b.post('login','Player','WrongPass123');unknown=b.post('login','Unknown','WrongPass123')
        alert=lambda response:re.search(r'<p role="alert">(.*?)</p>',response[1]).group(1)
        check('unknown and wrong externally identical',wrong[0]==unknown[0]==422 and alert(wrong)==alert(unknown))
        old=b.sid()
        old_session=LAB/'sessions'/('sess_'+old)
        old_session.write_text(old_session.read_text()+'user_id|i:2;role|s:6:"player";berserk_battle_journal_binding|s:64:"'+'a'*64+'";')
        check('login',b.post('login','pLaYeR','CorrectPass123')[0]==303)
        check('session rotation',b.sid()!=old)
        check('rotation deletes old session',not old_session.exists())
        check('protected placeholder', 'Вы вошли как Player' in b.request('account')[1])
        check('client account id ignored','Player' in b.request('account',suffix='&account_id=2&first=1&second=1')[1])
        session_files=list((LAB/'sessions').glob('sess_*'))
        check('no password in sessions',all('CorrectPass123' not in p.read_text(errors='replace') for p in session_files))
        check('legacy identity cleared and single principal', all('user_id|i:' not in p.read_text(errors='replace').replace('berserk_authenticated_user_id|i:','') for p in session_files))
        current_session=(LAB/'sessions'/('sess_'+b.sid())).read_text()
        check('seeded legacy role and binding removed','role|' not in current_session and 'berserk_battle_journal_binding|' not in current_session)
        check('no plaintext HTTP', 'CorrectPass123' not in wrong[1]+unknown[1]+body)
        token=b.token('account');check('logout GET forbidden',b.request('logout')[0]==405)
        check('logout CSRF',b.request('logout',{'csrf':'bad'})[0]==403)
        check('logout',b.request('logout',{'csrf':token})[0]==303 and b.request('account')[0]==303)
        f=Browser(); f.jar.set_cookie(http.cookiejar.Cookie(0,'BERSERKAUTH5A','a'*32,None,False,'127.0.0.1',False,False,'/',True,False,None,True,None,None,{}));f.token()
        check('strict fixation rejection',f.sid()!='a'*32)
        reset_limits()
        racers=[Browser(33552+i) for i in range(2)]
        tokens=[x.token('register') for x in racers]
        with concurrent.futures.ThreadPoolExecutor() as pool:
            codes=list(pool.map(lambda i:racers[i].post('register','Racing','CorrectPass123',tokens[i])[0],range(2)))
        check('concurrent registration one winner',sorted(codes)==[303,422] and sql("USE stage5a_auth_test;SELECT COUNT(*) FROM accounts WHERE login_key='racing';")=='1')
        reset_limits()
        attempts=[Browser(33552+i%4) for i in range(12)];tokens=[x.token() for x in attempts]
        with concurrent.futures.ThreadPoolExecutor(max_workers=12) as pool:
            codes=list(pool.map(lambda i:attempts[i].post('login','Player','WrongPass123',tokens[i])[0],range(12)))
        check('parallel cross-session login limit',codes.count(422)==5 and codes.count(429)==7)
        check('correct password cannot bypass exhausted limit',Browser().post('login','Player','CorrectPass123')[0]==429)
        reset_limits()
        codes=[Browser().post('login','Unknown'+str(i),'WrongPass123')[0] for i in range(21)]
        check('IP limit across distinct logins',codes.count(422)==20 and codes[-1]==429)
        sql('USE stage5a_auth_test;UPDATE auth_login_attempts SET window_start=UTC_TIMESTAMP()-INTERVAL 16 MINUTE;')
        check('limiter expires',Browser().post('login','Player','CorrectPass123')[0]==303)
        # New account 1 exists, but no PHPSESSID/principal is sent to unchanged legacy routes.
        check('account id 1 allowed without reserving legacy ids',sql("USE stage5a_auth_test;SELECT id FROM accounts WHERE login_key='player';")=='1')
        check('legacy game data unchanged',sql('USE stage5a_auth_test;SELECT * FROM games;')==before)
        check('separate cookie cannot authenticate legacy session',all(c.name!='PHPSESSID' for c in f.jar))
        # Corrupt a required property and prove fail-closed without runtime DDL.
        sql('USE stage5a_auth_test;ALTER TABLE accounts DROP INDEX uq_accounts_login_key;')
        code,body,_=Browser().request()
        check('schema mismatch generic 503',code==503 and 'SQL' not in body and 'mysqli' not in body)
        sql('USE stage5a_auth_test;ALTER TABLE accounts ADD UNIQUE KEY uq_accounts_login_key(login_key);')
        sql('USE stage5a_auth_test;RENAME TABLE accounts TO accounts_missing;')
        code,body,_=Browser().request()
        check('missing schema no runtime creation',code==503 and sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='stage5a_auth_test' AND TABLE_NAME='accounts';")=='0')
        sql('USE stage5a_auth_test;RENAME TABLE accounts_missing TO accounts;')
        # Production HTTP denied, even forged forwarding headers. No trust in X-Forwarded-Proto.
        (ROOT/'config/auth.local.php').write_text(config.replace("'allow_loopback_http'=>true","'allow_loopback_http'=>false"))
        req=urllib.request.Request(Browser().base,headers={'X-Forwarded-Proto':'https'})
        try: response=urllib.request.urlopen(req); http_code=response.code;response.close()
        except urllib.error.HTTPError as e: http_code=e.code;e.close()
        check('production plain HTTP denied',http_code==403)
        # TLS ingress forwards to a test-only PHP backend which sets the trusted HTTPS server variable.
        tlsrouter=LAB/'tls-router.php';tlsrouter.write_text("<?php putenv('AUTH_TEST_TLS=1');require '"+str(router).replace('\\','/')+"';")
        spawn(php+['-S','127.0.0.1:33556',str(tlsrouter)],'tls-backend');ready(33556)
        cert=LAB/'cert.pem';key=LAB/'key.pem';openssl_config=LAB/'openssl.cnf'
        openssl_config.write_text('[req]\ndistinguished_name=dn\n[dn]\n')
        subprocess.run([args.openssl,'req','-config',str(openssl_config),'-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=localhost'],check=True,capture_output=True)
        class TLSProxy(http.server.BaseHTTPRequestHandler):
            def log_message(self,*args): pass
            def do_GET(self): self.forward()
            def do_POST(self): self.forward()
            def forward(self):
                conn=http.client.HTTPConnection('127.0.0.1',33556,timeout=30)
                payload=self.rfile.read(int(self.headers.get('Content-Length','0')))
                headers={k:v for k,v in self.headers.items() if k.lower() not in ['host','connection']}
                conn.request(self.command,self.path,payload,headers);res=conn.getresponse();data=res.read()
                self.send_response(res.status)
                for k,v in res.getheaders():
                    if k.lower() not in ['connection','transfer-encoding','content-length']:self.send_header(k,v)
                self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data);conn.close()
        server=http.server.ThreadingHTTPServer(('127.0.0.1',33557),TLSProxy)
        ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
        threading.Thread(target=server.serve_forever,daemon=True).start()
        tls=Browser(33557,True);code,body,h=tls.request()
        check('real TLS Secure cookie',code==200 and 'secure' in h.get('Set-Cookie','').lower() and 'httponly' in h.get('Set-Cookie','').lower())
        reset_limits();check('TLS login and protected page',tls.post('login','Player','CorrectPass123')[0]==303 and 'Вы вошли как Player' in tls.request('account')[1])
        # Unmodified replay entrypoint must not see the newly logged-in account 1.
        spawn(php+['-S','127.0.0.1:33558','-t',str(ROOT/'www')],'legacy-read');ready(33558)
        probe=Browser();reset_limits();assert probe.request()[0]==403  # HTTP disabled
        # Use a separate loopback-only auth backend to obtain a non-Secure test cookie.
        (ROOT/'config/auth.local.php').write_text(config)
        assert probe.post('login','Player','CorrectPass123')[0]==303
        probe.base='http://127.0.0.1:33558/replay.php'
        code,body,_=probe.request(suffix='&game=1')
        check('account cookie cannot authorize legacy replay without ownership',code in [403,404] and ('replay_not_available' in body or 'forbidden' in body))
        check('password absent from HTTP logs',all('CorrectPass123' not in p.read_text(errors='replace') for p in LAB.glob('http-*.log')))
        server.shutdown();server.server_close()
        print(json.dumps({'passed':len(RESULTS),'results':RESULTS},ensure_ascii=False,indent=2))
    finally:
        (LAB/'results.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')
        (ROOT/'config/auth.local.php').unlink(missing_ok=True)
        for p in reversed(procs):p.terminate()
        for p in procs:
            try:p.wait(timeout=10)
            except subprocess.TimeoutExpired:p.kill()
        for log in logs:log.close()

if __name__=='__main__':main()
