"""Build a local review bundle from pinned, hash-verified runtime. No network/deployment/DDL."""
import pathlib,json,hashlib,zipfile,subprocess,re
ROOT=pathlib.Path(__file__).resolve().parents[1]
manifest_path=ROOT/'docs/stage5e1-release-manifest.json'
m=json.loads(manifest_path.read_text(encoding='utf-8'))
git=['git','-c','safe.directory='+ROOT.as_posix()]
assert not subprocess.check_output(git+['diff',m['release_head'],'--name-only','--','src','www','templates','config'],cwd=ROOT,text=True).strip(),'Runtime differs from reviewed HEAD'
out=ROOT/'docs/evidence/stage5e1';out.mkdir(parents=True,exist_ok=True)
archive=out/'stage5e1-runtime-cfbc809.zip'
entries={}
for item in m['runtime']:
    f=ROOT/item['path'];assert hashlib.sha256(f.read_bytes()).hexdigest()==item['sha256'],item['path']
    dest='operator/reference/www.htaccess' if item['path']=='www/.htaccess' else 'runtime/'+item['path']
    entries[dest]=f.read_bytes()
for item in m['read_only_sql']:
    f=ROOT/item['source'];assert hashlib.sha256(f.read_bytes()).hexdigest()==item['sha256']
    entries['operator/'+item['source']]=f.read_bytes()
for f in sorted((ROOT/'docs/stage5e-config').glob('*')):entries['operator/config-templates/'+f.name]=f.read_bytes()
for name in ['stage5e1-compatibility-report.md','stage5e1-mysql57-audit.md','stage5e1-deployment-checklist.md','stage5e-rollback.md']:
    entries['operator/'+name]=(ROOT/'docs'/name).read_bytes()
entries['operator/release-manifest.json']=manifest_path.read_bytes()
entries['README.txt']=b'Review bundle only. Runtime cfbc809 includes HTTPS=on/1 and explicit bcrypt12 policy. NO-GO: MySQL5.7 MatchCreator @@in_transaction blocker remains. NO deployment authorization. Extract into separate staging. Never copy operator/ into www. Preserve private configs, storage, card assets and hosting .htaccess. No CREATE SQL bundled. Follow operator checks.\n'
assert not any(x.lower().endswith(('.local.php','.pem','.key')) or '/storage/' in x or '/sessions/' in x for x in entries)
assert not any(re.search(r'(?im)^\s*(CREATE|ALTER|DROP|TRUNCATE|INSERT|UPDATE|DELETE)\b',data.decode('utf-8')) for name,data in entries.items() if name.endswith('.sql'))
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for name,data in sorted(entries.items()):
        info=zipfile.ZipInfo(name,(2026,10,21,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,data)
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    assert all(z.read(k)==v for k,v in entries.items())
r={'runtime_head':m['release_head'],'runtime_files':sum(n.startswith('runtime/') for n in entries),'entries':len(entries),'bytes':archive.stat().st_size,'sha256':hashlib.sha256(archive.read_bytes()).hexdigest(),'no_create_sql':True,'roundtrip_verified':True}
(out/'bundle-check.json').write_text(json.dumps(r,indent=2),encoding='utf-8');print(json.dumps(r))
