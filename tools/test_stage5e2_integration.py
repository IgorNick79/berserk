"""Full existing Stage 5C/5E integration on fresh isolated MySQL or MariaDB."""
import pathlib,sys,shutil
sys.dont_write_bytecode=True
ROOT=pathlib.Path(__file__).resolve().parents[1]
engine=sys.argv[1];assert engine in ['mysql57','mariadb108'];del sys.argv[1]
lab=ROOT/'tmp'/('stage5e2-integration-'+engine);assert not lab.exists()
out=ROOT/'docs/evidence/stage5e2'/engine;out.mkdir(parents=True,exist_ok=True)
path=ROOT/'tools/test_integration_http.py'
source=path.read_text(encoding='utf-8').replace("LAB = ROOT/'tmp/integration-lab'", "LAB = ROOT/'tmp/stage5e2-integration-"+engine+"'")
if engine=='mysql57':
 source=source.replace("default='C:/OSPanel/modules/database/MariaDB-10.8-Win10/bin'","default='C:/OSPanel/modules/database/MySQL-5.7-Win10/bin'")
 source=source.replace("subprocess.run([str(pathlib.Path(args.db_bin)/'mariadb-install-db.exe'),f'--datadir={LAB / \"data\"}','--port=33571'],check=True,capture_output=True)","subprocess.run([str(pathlib.Path(args.db_bin)/'mysqld.exe'),'--no-defaults','--initialize-insecure','--basedir='+str(pathlib.Path(args.db_bin).parent),f'--datadir={LAB / \"data\"}'],check=True,capture_output=True)")
try:
 exec(compile(source,str(path),'exec'),{'__name__':'__main__','__file__':str(path)})
finally:
 for name in ['results.json','regressions.json','browser-results.json','browser-output.txt','battle-two-accounts.png']:
  if (lab/name).exists():shutil.copy2(lab/name,out/('integration-'+name))
 assert lab.resolve().parent==(ROOT/'tmp').resolve() and lab.name=='stage5e2-integration-'+engine
 if lab.exists():shutil.rmtree(lab)
