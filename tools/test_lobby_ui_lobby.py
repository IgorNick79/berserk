"""Run unchanged HTTP race/rollback suite plus context faults on either isolated engine."""
import pathlib,sys,shutil,json
sys.dont_write_bytecode=True
ROOT=pathlib.Path(__file__).resolve().parents[1]
engine=sys.argv[1];assert engine in ['mysql57','mariadb108'];del sys.argv[1]
lab=ROOT/'tmp'/('lobby-ui-'+engine)
assert not lab.exists(),'Fresh lab required'
out=ROOT/'docs/evidence/lobby-ui'/engine;out.mkdir(parents=True,exist_ok=True)
path=ROOT/'tools/test_lobby_http.py'
source=path.read_text(encoding='utf-8').replace("LAB = ROOT/'tmp/lobby-lab'", "LAB = ROOT/'tmp/lobby-ui-"+engine+"'")
start=source.index('        # Real browser tests with separate storage contexts')
end=source.index("        (LAB/'postchecks.txt')",start)
source=source[:start]+'''        cfg={'host':'127.0.0.1','port':33561,'name':database,'user':'root','pass':''}
        context=subprocess.run(php+[str(ROOT/'tools/test_room_transaction.php'),json.dumps(cfg)],capture_output=True,text=True,encoding='utf-8')
        (LAB/'context-results.json').write_text(context.stdout,encoding='utf-8')
        assert context.returncode==0,context.stdout+context.stderr
        check('transaction context lifecycle and unknown COMMIT faults',all(x['pass'] for x in json.loads(context.stdout)))
        check('final no orphan or mismatched games',sql("USE LAB_DB;SELECT COUNT(*) FROM games g LEFT JOIN lobby_rooms r ON r.game_id=g.ind WHERE g.ind<>1 AND g.host_id<>990001 AND (r.id IS NULL OR r.creator_account_id<>g.host_id OR r.accepted_account_id<>g.player_id);")=='0')
        (LAB/'server-version.txt').write_text(sql('SELECT VERSION();'),encoding='utf-8')
'''+source[end:]
if engine=='mysql57':
 source=source.replace("default='C:/OSPanel/modules/database/MariaDB-10.8-Win10/bin'","default='C:/OSPanel/modules/database/MySQL-5.7-Win10/bin'")
 source=source.replace("subprocess.run([str(pathlib.Path(args.db_bin)/'mariadb-install-db.exe'),f'--datadir={LAB / \"data\"}','--port=33561'],check=True,capture_output=True)","subprocess.run([str(pathlib.Path(args.db_bin)/'mysqld.exe'),'--no-defaults','--initialize-insecure','--basedir='+str(pathlib.Path(args.db_bin).parent),f'--datadir={LAB / \"data\"}'],check=True,capture_output=True)")
try:
 exec(compile(source,str(path),'exec'),{'__name__':'__main__','__file__':str(path)})
finally:
 for name in ['results.json','concurrency.json','context-results.json','server-version.txt']:
  if (lab/name).exists():shutil.copy2(lab/name,out/name)
 assert lab.resolve().parent==(ROOT/'tmp').resolve() and lab.name=='lobby-ui-'+engine
 if lab.exists():shutil.rmtree(lab)
