"""Diagnose the observed random settings regression against unchanged release code only."""
import pathlib,sys,subprocess,tarfile,io,json,shutil
sys.dont_write_bytecode=True
ROOT=pathlib.Path(__file__).resolve().parents[1]
lab=ROOT/'tmp/lobby-ui-baseline';assert not lab.exists()
out=ROOT/'docs/evidence/lobby-ui';out.mkdir(parents=True,exist_ok=True)
path=ROOT/'tools/test_integration_http.py'
source=path.read_text(encoding='utf-8').replace("LAB = ROOT/'tmp/integration-lab'","LAB = ROOT/'tmp/lobby-ui-baseline'")
start=source.index("        password='LobbyFixture123'")
end=source.index('\n    finally:',start)
source=source[:start]+'''        import tarfile,io,shutil
        git=['git','-c','safe.directory='+ROOT.as_posix()]
        snapshot=subprocess.check_output(git+['archive','7c88219','src','tools/test_settings_flow.php'],cwd=ROOT)
        baseline=LAB/'baseline';baseline.mkdir()
        with tarfile.open(fileobj=io.BytesIO(snapshot)) as archive:
            assert all(not pathlib.PurePosixPath(m.name).is_absolute() and '..' not in pathlib.PurePosixPath(m.name).parts for m in archive.getmembers())
            archive.extractall(baseline,filter='data')
        (baseline/'config').mkdir();shutil.copy2(ROOT/'config/db.php',baseline/'config/db.php')
        runs=[]
        for attempt in range(3):
            r=subprocess.run(php+[str(baseline/'tools/test_settings_flow.php')],cwd=baseline,capture_output=True,text=True,encoding='utf-8',errors='replace')
            runs.append({'attempt':attempt+1,'exit':r.returncode,'output':r.stdout+r.stderr})
        proof={'baseline':'7c882199a34871ce038264f9af51956473c52ad2','runtime':'PHP8.1.9/MariaDB10.8.4','scope':'isolated synthetic integration catalog; pristine baseline src and test; fixed three diagnostic runs, not retry-until-green','runs':runs}
        (LAB/'baseline-results.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2),encoding='utf-8')
        print(json.dumps(proof,ensure_ascii=False,indent=2))
'''+source[end:]
try:
 exec(compile(source,str(path),'exec'),{'__name__':'__main__','__file__':str(path)})
finally:
 if (lab/'baseline-results.json').exists():shutil.copy2(lab/'baseline-results.json',out/'baseline-settings-flow.json')
 assert lab.resolve().parent==(ROOT/'tmp').resolve() and lab.name=='lobby-ui-baseline'
 if lab.exists():shutil.rmtree(lab)
