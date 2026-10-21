"""Isolated journal-access HTTP regression. Trusted HTTPS is injected by the test server,
never taken from client headers. No database/network beyond loopback; no production config.
"""
import http.client, http.cookies, json, os, pathlib, re, shutil, socket, subprocess, tempfile, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP='C:/OSPanel/modules/php/PHP_8.1/php.exe'

def main():
    (ROOT/'tmp').mkdir(exist_ok=True)
    lab=pathlib.Path(tempfile.mkdtemp(prefix='journal-https-',dir=ROOT/'tmp'))
    app=lab/'app';ledger=lab/'ledger';sessions=lab/'sessions'
    for p in [app/'www',app/'config',app/'tools',ledger,sessions]:p.mkdir(parents=True,exist_ok=True)
    shutil.copytree(ROOT/'src',app/'src')
    shutil.copyfile(ROOT/'www/journal-access.php',app/'www/journal-access.php')
    shutil.copyfile(ROOT/'tools/test_journal_invitations.php',app/'tools/test_journal_invitations.php')
    (app/'config/db.php').write_text('<?php return [];',encoding='utf-8')
    config="<?php return ['journal_access_enabled'=>true,'journal_access_directory'=>'"+ledger.as_posix()+"','journal_access_local_http'=>false,'journal_enabled'=>true,'journal_game_ids'=>[7,8],'recording_enabled'=>true,'record_game_ids'=>[7,8]];"
    (app/'config/battle_history.local.php').write_text(config,encoding='utf-8')
    (lab/'db.php').write_text("<?php namespace Berserk\\Core; final class Db {public function __construct(array $c) {} public function fetchOne(string $s):?array {if(!preg_match('/^SELECT id FROM lobby_rooms WHERE game_id=(\\d+)$/',$s,$m))throw new \\RuntimeException('Unexpected fixture query');return (int)$m[1]===8?['id'=>1]:null;}}",encoding='utf-8')
    (lab/'router.php').write_text("<?php if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)!=='/journal-access.php'){http_response_code(404);exit;} $_SERVER['HTTPS']=getenv('TEST_TRUSTED_HTTPS'); require '"+(lab/'db.php').as_posix()+"'; require '"+(app/'www/journal-access.php').as_posix()+"';",encoding='utf-8')
    (lab/'issue.php').write_text("<?php require '"+(app/'src/Core/Autoloader.php').as_posix()+"';Berserk\\Core\\Autoloader::register();Berserk\\Core\\Autoloader::addNamespace('Berserk\\\\','"+(app/'src').as_posix()+"');$a=new Berserk\\Core\\BattleHistory\\JournalInvitations('"+ledger.as_posix()+"');$d=json_decode(stream_get_contents(STDIN),true);if($d['op']==='issue')echo json_encode($a->issue($d['game'],'host',time()+60));else $a->revoke($d['id']);",encoding='utf-8')
    rows=[];processes=[];logs=[]
    def check(name,ok):
        rows.append({'test':name,'pass':bool(ok)})
        if not ok:raise AssertionError(name)
    def grant(game=7):
        r=subprocess.run([PHP,'-n',str(lab/'issue.php')],input=json.dumps({'op':'issue','game':game}),capture_output=True,text=True,check=True)
        return json.loads(r.stdout)
    def revoke(g):subprocess.run([PHP,'-n',str(lab/'issue.php')],input=json.dumps({'op':'revoke','id':g['id']}),capture_output=True,text=True,check=True)
    def request(port,method='GET',data=None,cookie=None,headers=None,path='/journal-access.php'):
        import urllib.parse
        h=dict(headers or {})
        if cookie:h['Cookie']='PHPSESSID='+cookie
        body=None
        if data is not None:body=urllib.parse.urlencode(data);h['Content-Type']='application/x-www-form-urlencoded'
        c=http.client.HTTPConnection('127.0.0.1',port,timeout=10)
        try:
            c.request(method,path,body=body,headers=h);r=c.getresponse();return r.status,r.read().decode(),dict(r.getheaders())
        finally:c.close()
    def form(port):
        code,body,h=request(port);assert code==200
        jar=http.cookies.SimpleCookie();jar.load(h['Set-Cookie'])
        return re.search(r'name="csrf" value="([a-f0-9]{64})"',body)[1],jar['PHPSESSID'].value,h
    try:
        for signal in ['on','1','','off','0','true','ON']:
            with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
            log=open(lab/('server-'+str(port)+'.log'),'wb');logs.append(log)
            p=subprocess.Popen([PHP,'-n','-d','session.save_path='+str(sessions),'-d','display_errors=0','-S','127.0.0.1:'+str(port),str(lab/'router.php')],env=dict(os.environ,TEST_TRUSTED_HTTPS=signal),stdout=log,stderr=subprocess.STDOUT,creationflags=subprocess.CREATE_NO_WINDOW);processes.append(p)
            for _ in range(100):
                with socket.socket() as s:
                    if s.connect_ex(('127.0.0.1',port))==0:break
                time.sleep(.05)
            code,_,h=request(port,headers={'X-Forwarded-Proto':'https','Forwarded':'proto=https','HTTPS':'on','X-Forwarded-Ssl':'on'})
            check('server HTTPS='+repr(signal)+' ignores client scheme headers',code==(200 if signal in ['on','1'] else 403))
            if signal not in ['on','1']:
                check('insecure denial creates no session '+repr(signal),'Set-Cookie' not in h)
                g=grant();code,body,h=request(port,'POST',{'csrf':'not-a-session-token','invitation':g['secret']},headers={'X-Forwarded-Proto':'https','HTTPS':'1'})
                check('insecure POST cannot consume invitation '+repr(signal),code==403 and body=='HTTPS required' and 'Set-Cookie' not in h and not json.loads((ledger/'journal-access.json').read_text())[g['id']]['used'])
                continue
            token,sid,h=form(port)
            jar=http.cookies.SimpleCookie();jar.load(h['Set-Cookie']);m=jar['PHPSESSID']
            check('secure invitation cookie '+signal,bool(m['secure']) and bool(m['httponly']) and m['samesite']=='Strict')
            g=grant();code,_,_=request(port,'POST',{'csrf':'bad','invitation':g['secret']},sid)
            check('CSRF denied without burning invitation '+signal,code==403 and not json.loads((ledger/'journal-access.json').read_text())[g['id']]['used'])
            code,_,h=request(port,'POST',{'csrf':token,'invitation':g['secret']},sid)
            jar=http.cookies.SimpleCookie();jar.load(h.get('Set-Cookie',''));rotated=jar['PHPSESSID'].value
            session=(sessions/('sess_'+rotated)).read_text()
            check('consume redirects to legacy journal '+signal,code==303 and h.get('Location')=='/battle-journal.php?game=7')
            check('session rotates and grants binding only '+signal,rotated!=sid and 'berserk_battle_journal_binding|' in session and 'berserk_authenticated_user_id|' not in session)
            token2,sid2,_=form(port);check('one-time replay denied '+signal,request(port,'POST',{'csrf':token2,'invitation':g['secret']},sid2)[0]==403)
            g=grant();revoke(g);token2,sid2,_=form(port);check('revoked invitation denied '+signal,request(port,'POST',{'csrf':token2,'invitation':g['secret']},sid2)[0]==403)
            g=grant();state=json.loads((ledger/'journal-access.json').read_text());state[g['id']]['expires_at']=0;(ledger/'journal-access.json').write_text(json.dumps(state))
            token2,sid2,_=form(port);check('expired invitation denied '+signal,request(port,'POST',{'csrf':token2,'invitation':g['secret']},sid2)[0]==403)
            for game in [8,9]:
                g=grant(game);token2,sid2,_=form(port);code,_,_=request(port,'POST',{'csrf':token2,'invitation':g['secret']},sid2)
                check('account-owned or disabled game denied '+signal+'/'+str(game),code==403 and not json.loads((ledger/'journal-access.json').read_text())[g['id']]['used'])
            check('query invitation not accepted '+signal,request(port,path='/journal-access.php?invitation=not-a-token')[0]==400)
        env=dict(os.environ,BERSERK_STAGE4D1_ISOLATED='1',BERSERK_STAGE4D1_ACCESS=str(ledger))
        r=subprocess.run([PHP,'-n',str(app/'tools/test_journal_invitations.php')],env=env,capture_output=True,text=True)
        check('original one-time invitation regression suite',r.returncode==0 and 'PASS' in r.stdout)
        print(json.dumps({'passed':len(rows),'results':rows,'limits':'Real loopback HTTP endpoint/session/ledger; trusted server HTTPS simulated by router, DB ownership read uses isolated fake Db; no actual TLS or production FPM check.'},indent=2))
    finally:
        for p in processes:p.terminate()
        for p in processes:
            try:p.wait(timeout=10)
            except subprocess.TimeoutExpired:p.kill();p.wait()
        for log in logs:log.close()
        # Newly created tempfile tree only; never storage/games or production paths.
        assert lab.parent.resolve()==(ROOT/'tmp').resolve() and lab.name.startswith('journal-https-')
        shutil.rmtree(lab)

if __name__=='__main__':main()
