import subprocess,tempfile,os,time,json,urllib.request,http.cookiejar,socket
from pathlib import Path
root=Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_EXE',r'C:/OSPanel/modules/php/PHP_8.1/php.exe')
with tempfile.TemporaryDirectory(prefix='lilith-http-') as temp:
 env=os.environ.copy();env['LILITH_TEST_STATE']=str(Path(temp)/'state.json')
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(Path(temp)/'server.log','w+')
 proc=subprocess.Popen([php,'-S',f'127.0.0.1:{port}',str(root/'tools/lilith_http_fixture.php')],cwd=root,env=env,stdout=log,stderr=log)
 try:
  time.sleep(1)
  clients={side:urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())) for side in ['host','player']}
  def request(side,cmd=None,payload=None):
   data=None if cmd is None else json.dumps({'cmd':cmd,'payload':payload or {}}).encode()
   return json.load(clients[side].open(urllib.request.Request(f'http://127.0.0.1:{port}/?side={side}',data=data,headers={'Content-Type':'application/json'})))
  def state():return json.loads(Path(env['LILITH_TEST_STATE']).read_text())
  request('host');request('player');assert request('player','end_turn')['ok']
  for _ in range(10):
   if not state()['battle'].get('turn_phase',{}).get('pending_ack'):break
   assert request('host','turn_ack')['ok']
  p=state()['battle']['pending_tremor'];assert request('host','choose_tremor_cell',{'marker_id':p['id'],'row':2,'col':2})['ok']
  p=state()['battle']['pending_grave_ritual'];payload={'operation_id':p['id'],'target_id':11}
  assert not request('player','choose_grave_ritual',payload)['ok']
  assert request('host','choose_grave_ritual',payload)['ok']
  assert not request('host','choose_grave_ritual',payload)['ok']
  assert not state()['battle'].get('grave_ritual')
  assert request('host','action',{'card_id':1,'action_key':'paired_ranged'})['ok']
  for target in [2,3]:
   p=state()['battle']['pending_paired_ranged'];payload={'operation_id':p['id'],'stage':p['stage'],'target_id':target}
   if target==2:
    import concurrent.futures
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool: replies=list(pool.map(lambda _:request('host','choose_paired_ranged',payload),range(2)))
    assert sorted(r['ok'] for r in replies)==[False,True]
   else:assert request('host','choose_paired_ranged',payload)['ok']
   assert not request('host','choose_paired_ranged',payload)['ok']
  for _ in range(12):
   st=state();strike=st['battle'].get('strike')
   if not strike:break
   # Obtain the native result ACK token from the rendered snapshot links.
   import re,html
   snapshots={side:request(side) for side in ['host','player']}
   side='player' if 'host' in strike.get('confirmed',[]) else 'host'
   serialized=html.unescape(json.dumps(snapshots[side],ensure_ascii=False)).replace('\\/','/')
   token=re.search(r'wait_id=([^&"<\s]+)',serialized)
   if not token:raise AssertionError('Missing native ACK token')
   reply=request(side,'confirm_strike',{'wait_id':token.group(1).rstrip(chr(92))});assert reply['ok'],reply.get('error')
  st=state();cards=st['cards'];cards={int(k):v for k,v in cards.items()} if isinstance(cards,dict) else {c['instanceId']:c for c in cards}
  assert not st['battle'].get('paired_ranged')
  assert cards[2]['hp']==19 and cards[3]['hp']==19
  assert 'snapshot' in request('host') and 'snapshot' in request('player')
  print('PASS isolated Lilith HTTP: two sessions, native turn, Tremor + ritual + grave choice, paired targets, ACK/sync/replay')

 finally:
  proc.terminate();proc.wait(timeout=10);log.seek(0)
  output=log.read();log.close()
  if 'Fatal error' in output:print(output)

