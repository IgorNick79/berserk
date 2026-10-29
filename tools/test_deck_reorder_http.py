import subprocess,tempfile,os,time,json,urllib.request,http.cookiejar,socket
from pathlib import Path
root=Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_EXE',r'C:/OSPanel/modules/php/PHP_8.1/php.exe')
with tempfile.TemporaryDirectory(prefix='seer-http-') as temp:
 env=os.environ.copy();env['SEER_TEST_STATE']=str(Path(temp)/'state.json')
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(Path(temp)/'server.log','w+')
 proc=subprocess.Popen([php,'-S',f'127.0.0.1:{port}',str(root/'tools/deck_reorder_http_fixture.php')],cwd=root,env=env,stdout=log,stderr=log)
 try:
  time.sleep(1)
  clients={side:urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())) for side in ['host','player']}
  def request(side,cmd=None,payload=None):
   data=None if cmd is None else json.dumps({'cmd':cmd,'payload':payload or {}}).encode()
   return json.load(clients[side].open(urllib.request.Request(f'http://127.0.0.1:{port}/?side={side}',data=data,headers={'Content-Type':'application/json'})))
  def state():return json.loads(Path(env['SEER_TEST_STATE']).read_text())
  request('host');request('player');r=request('host','end_turn');assert r['ok'],r
  p=state()['battle']['pending_deck_reorder'];assert p['stage']=='offer'
  def choose(action,**extra):
   p=state()['battle']['pending_deck_reorder'];payload={'operation_id':p['id'],'revision':p['revision'],'action':action,**extra}
   r=request('player','choose_deck_reorder',payload);assert r['ok'],r
   assert not request('player','choose_deck_reorder',payload)['ok']
   return r
  choose('show');owner=json.dumps(request('player'),ensure_ascii=False);enemy=json.dumps(request('host'),ensure_ascii=False)
  for i in range(10,13):assert f'Secret s1_{i}' in owner and f'/assets/cards/s1/s1_{i}.webp' in owner;assert f'Secret s1_{i}' not in enemy and f'/assets/cards/s1/s1_{i}.webp' not in enemy
  choose('next',direction='bottom');choose('pick',card_id=11);choose('finish')
  s=state();assert not s['battle'].get('pending_deck_reorder');assert s['battle']['active']=='player'
  print('PASS isolated HTTP: two cookie clients, native end_turn, three stages, snapshots/images/privacy, replay, partial random finish, continuation')
 finally:
  proc.terminate();proc.wait(timeout=10);log.seek(0)
  output=log.read();log.close()
  if 'Fatal error' in output:print(output)

