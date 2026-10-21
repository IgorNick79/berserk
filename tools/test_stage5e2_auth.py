"""Existing Auth security suite, fresh isolated MariaDB lab; no private configs read."""
import pathlib,sys,subprocess,shutil
sys.dont_write_bytecode=True
ROOT=pathlib.Path(__file__).resolve().parents[1]
lab=ROOT/'tmp/stage5e2-auth';assert not lab.exists()
out=ROOT/'docs/evidence/stage5e2';out.mkdir(parents=True,exist_ok=True)
lab.mkdir();(lab/'sessions').mkdir()
binary='C:/OSPanel/modules/database/MariaDB-10.8-Win10/bin/mariadb-install-db.exe'
subprocess.run([binary,'--datadir='+str(lab/'data'),'--port=33551'],capture_output=True,check=True)
path=ROOT/'tools/test_auth_http.py'
source=path.read_text(encoding='utf-8').replace("LAB = ROOT / 'tmp/auth-lab'", "LAB = ROOT / 'tmp/stage5e2-auth'")
sys.argv += ['--openssl','C:/OSPanel/modules/http/Apache_2.2-PHP_5.2-5.4/bin/openssl.exe']
try:
 exec(compile(source,str(path),'exec'),{'__name__':'__main__','__file__':str(path)})
finally:
 if (lab/'results.json').exists():shutil.copy2(lab/'results.json',out/'auth-results.json')
 assert lab.resolve().parent==(ROOT/'tmp').resolve() and lab.name=='stage5e2-auth'
 shutil.rmtree(lab)
