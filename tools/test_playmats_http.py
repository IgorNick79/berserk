import urllib.request,urllib.parse,http.cookiejar,json,re,os
from pathlib import Path
root=Path(__file__).resolve().parent.parent
fixture=json.loads((root/'tmp/playmat-fixture.json').read_text())
base=os.environ.get('PLAYMAT_TEST_URL','http://127.0.0.1:18920/index.php')
assert re.fullmatch(r'http://127\.0\.0\.1:\d+/index\.php',base), 'Loopback test server required'
base+='?'
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
a,b=client(),client()
def request(client,role='first',ajax=None,data=None,game=None,**extra):
 q={role:'','game':game or fixture['game'],**extra}
 if ajax:q['ajax']=ajax
 req=urllib.request.Request(base+urllib.parse.urlencode(q),data=urllib.parse.urlencode(data).encode() if data is not None else None)
 try:
  with client.open(req) as r:return r.status,r.read().decode()
 except urllib.error.HTTPError as e:return e.code,e.read().decode()
checks=0
def check(x,msg):
 global checks
 checks+=1
 assert x,msg
status,html=request(a);check(status==200,'page boot')
match=re.search(r'id="battle-playmats">(.*?)</script>',html,re.S);check(bool(match),'config boot')
config=json.loads(match[1]);csrf=config['csrf'];own=config['state']['own'];opp=config['state']['opponent_url']
status,payload=request(a,ajax='playmat');check(status==405,'GET rejected')
status,payload=request(a,ajax='playmat',data={'playmat_id':'mat-001'});check(status==403,'CSRF rejected')
status,payload=request(a,ajax='playmat',data={'playmat_id':'invalid','csrf':csrf});check(status==400,'unknown catalog rejected')
status,payload=request(a,ajax='playmat',game=fixture['foreign'],data={'playmat_id':'mat-001','csrf':csrf});check(status==403,'foreign game rejected')
status,payload=request(a,ajax='playmat',data={'playmat_id':'mat-018','player_id':2,'player_key':'player','csrf':csrf});data=json.loads(payload);check(status==200 and data['ok'],'save own')
check(data['playmats']['own']=='mat-018' and data['playmats']['opponent_url']==opp,'payload cannot target opponent')
check('fragments' not in data and 'turn_timer' not in data,'cosmetic response pure')
status,payload=request(a,ajax='battle_sync',since_version=fixture['version'],render_token=fixture['token']);poll=json.loads(payload)
check(poll['type']=='no_change' and 'fragments' not in poll,'cosmetic poll no render')
check(poll['playmats']['own']=='mat-018','poll own saved')
status,html=request(b,'second');boot=json.loads(re.search(r'id="battle-playmats">(.*?)</script>',html,re.S)[1]);check(boot['state']['opponent_url'].endswith('/018.webp'),'opponent refresh')
status,payload=request(b,'second','playmat',{'playmat_id':'mat-016','csrf':boot['csrf']});check(status==200,'inactive participant can change')
status,payload=request(a,ajax='battle_sync',since_version=poll['sync_version'],render_token=fixture['token']);poll2=json.loads(payload);check(poll2['type']=='no_change' and poll2['playmats']['opponent_url'].endswith('/016.webp'),'opponent sync no render')
status,html=request(a);config=json.loads(re.search(r'id="battle-playmats">(.*?)</script>',html,re.S)[1]);check(config['state']['own']=='mat-018','refresh persists')
(root/'tmp/playmat-http-result.json').write_text(json.dumps({'checks':checks,'fixture':fixture,'boot':config},indent=2))
print(f'Playmat HTTP: {checks} checks passed')
