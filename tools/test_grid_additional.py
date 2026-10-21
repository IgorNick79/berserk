"""Extra existing Grid suites against the already isolated Stage5C lab. No production config."""
import pathlib,subprocess,socket,time,shutil,json
ROOT=pathlib.Path(__file__).resolve().parents[1]
LAB=ROOT/'tmp/integration-lab'
BIN=pathlib.Path('C:/OSPanel/modules/database/MariaDB-10.8-Win10/bin')
PHP=['C:/OSPanel/modules/php/PHP_8.1/php.exe','-n','-d','extension_dir=C:/OSPanel/modules/php/PHP_8.1/ext','-d','extension=mysqli']
assert (LAB/'data/my.ini').is_file()
with socket.socket() as s:assert s.connect_ex(('127.0.0.1',33571))!=0
work=LAB/'grid-extra'
for folder in ['src','tools','templates']:shutil.copytree(ROOT/folder,work/folder,dirs_exist_ok=True)
(work/'config').mkdir(exist_ok=True)
shutil.copyfile(LAB/'regression/config/db.php',work/'config/db.php')
log=open(LAB/'grid-extra-db.log','wb')
p=subprocess.Popen([str(BIN/'mysqld.exe'),'--no-defaults','--datadir='+str(LAB/'data'),'--bind-address=127.0.0.1','--port=33571','--skip-log-bin','--console'],stdout=log,stderr=subprocess.STDOUT,creationflags=subprocess.CREATE_NO_WINDOW)
try:
    for _ in range(150):
        with socket.socket() as s:
            if s.connect_ex(('127.0.0.1',33571))==0:break
        time.sleep(.1)
    identity=subprocess.run([str(BIN/'mysql.exe'),'--no-defaults','-h127.0.0.1','-P33571','-uroot','--batch','--raw','--skip-column-names','-e','SELECT @@datadir,@@port;'],capture_output=True,text=True,check=True).stdout.strip().split('\t')
    assert pathlib.Path(identity[0]).resolve()==(LAB/'data').resolve() and identity[1]=='33571'
    rows=[];out=ROOT/'docs/evidence/stage5d1'
    for name in ['test_draft_temporary_rules.php','test_draft_timer_history.php','test_booster.php','test_view_sideboard.php']:
        r=subprocess.run(PHP+[str(work/'tools'/name)],cwd=work,capture_output=True,text=True,encoding='utf-8',errors='replace')
        (out/(name+'.txt')).write_text(r.stdout+r.stderr,encoding='utf-8')
        rows.append({'test':name,'exit':r.returncode,'skipped':'Skipping' in r.stdout,'tail':(r.stdout+r.stderr)[-700:]})
    (out/'additional-regressions.json').write_text(json.dumps(rows,indent=2,ensure_ascii=False),encoding='utf-8')
    print(json.dumps(rows,ensure_ascii=True));assert all(r['exit']==0 and not r['skipped'] for r in rows)
finally:p.terminate();p.wait(timeout=15);log.close()
