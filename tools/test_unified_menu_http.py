"""Isolated preferences/menu integration; reuses the established disposable DB bootstrap.
Never starts against an existing config or datadir; no production SQL is run.
"""
import pathlib,sys,json,shutil,subprocess,time
sys.dont_write_bytecode=True
ROOT=pathlib.Path(__file__).resolve().parents[1]
PROJECT=ROOT
WORK=PROJECT/'tmp'/('unified-menu-workspace-'+str(time.time_ns()))
assert not WORK.exists(), 'Fresh disposable workspace required'
WORK.mkdir(parents=True)
for name in ['src','templates','tools']:
    shutil.copytree(PROJECT/name,WORK/name)
(WORK/'config').mkdir()
for name in subprocess.check_output(['git','ls-files','config'],cwd=PROJECT,text=True).splitlines():
    if not name.endswith('.local.php') and name!='config/db.php':shutil.copy2(PROJECT/name,WORK/name)
shutil.copy2(PROJECT/'config/game-menu.json',WORK/'config/game-menu.json')
(WORK/'www').mkdir()
for file in (PROJECT/'www').glob('*.php'):shutil.copy2(file,WORK/'www'/file.name)
subprocess.run(['powershell','-NoProfile','-Command',"New-Item -ItemType Junction -Path '"+str(WORK/'www/assets')+"' -Target '"+str(PROJECT/'www/assets')+"' | Out-Null"],check=True)
source=(ROOT/'tools/test_integration_http.py').read_text(encoding='utf-8')
source=source.replace('from http.cookiejar import Cookie','from http.cookiejar import Cookie\nurllib.request.getproxies=lambda: {}')
source=source.replace("LAB = ROOT/'tmp/integration-lab'","LAB = ROOT/'tmp/unified-menu-lab'")
source=source.replace("assert not (ROOT/'config/auth.local.php')", "assert not (ROOT/'tmp/unified-menu-lab').exists(), 'Fresh isolated lab required'\n    assert not (ROOT/'config/auth.local.php')")
source=source.replace("'20261021_lobby_setup.sql'","'20261021_lobby_setup.sql','20261030_lobby_wizard.sql','20261030_lobby_chat.sql'")
source=source.replace("'20261020_battle_history.sql']", "'20261020_battle_history.sql','20261024_account_ui_preferences.sql']")
source=source.replace("        check('runtime no TRIGGER", "        sql(\"GRANT SELECT,INSERT,UPDATE ON LAB_DB.account_ui_preferences TO 'AUTH_USER'@'127.0.0.1';\")\n        check('runtime no TRIGGER")
source=source.replace("'/journal-access.php'", "'/journal-access.php','/ui-preferences.php','/panel-theme.php','/lobby-chat.php'")
a=source.index('        game=match()');b=source.index('    finally:',a)
body=r'''
        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence'/('waiting-room' if os.getenv('BERSERK_ROOM_REVIEW') else 'lobby-wizard');out.mkdir(parents=True,exist_ok=True)
        sql("GRANT SELECT,INSERT ON LAB_DB.lobby_chat_messages TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.lobby_chat_reads TO 'LOBBY_USER'@'127.0.0.1';")
        sql("USE LAB_DB;INSERT INTO account_ui_preferences(account_id,panel_theme) VALUES(1,'stone');")
        sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261030_account_ui_preferences.sql').read_text())
        check('migration retains selected theme',sql('USE LAB_DB;SELECT panel_theme FROM account_ui_preferences WHERE account_id=1;')=='stone')
        def endpoint(client,file='ui-preferences.php',post=None,suffix=''):
            req=urllib.request.Request(client.base.replace('auth.php',file)+suffix,data=None if post is None else urllib.parse.urlencode(post).encode())
            try:r=client.opener.open(req,timeout=30)
            except urllib.error.HTTPError as e:r=e
            text=r.read().decode('utf-8');return r.code,json.loads(text) if file.endswith('preferences.php') or file=='panel-theme.php' else text
        x=endpoint(a)[1];token=x['csrf'];first=x['preferences']
        check('first read preserves theme and initializes mat',first['theme']=='stone' and first['playmat_id'].startswith('mat-'))
        check('initial mat stable',endpoint(a)[1]['preferences']==first)
        check('guest preference read denied',endpoint(Client())[0]==401)
        check('CSRF save denied',endpoint(a,post={'key':'sound_enabled','value':'0','csrf':'bad'})[0]==403)
        for key,value in [('sound_enabled','yes'),('playmat_id','../evil'),('theme','unknown')]:
            check('invalid '+key+' denied',endpoint(a,post={'key':key,'value':value,'csrf':token})[0]==400)
        def save(client,key,value):return endpoint(client,'panel-theme.php',{'theme':value,'csrf':endpoint(client)[1]['csrf']}) if key=='theme' else endpoint(client,post={'key':key,'value':value,'csrf':endpoint(client)[1]['csrf']})
        check('sound saved in account',save(a,'sound_enabled','0')[1]['preferences']['sound_enabled'] is False)
        check('own mat saved',save(a,'playmat_id','mat-003')[1]['preferences']['playmat_id']=='mat-003')
        check('second player independent mat',save(b,'playmat_id','mat-009')[1]['preferences']['playmat_id']=='mat-009' and endpoint(a)[1]['preferences']['playmat_id']=='mat-003')
        check('existing theme endpoint saves same row',endpoint(a,'panel-theme.php',{'theme':'leather','csrf':token})[0]==200 and endpoint(a)[1]['preferences']['theme']=='leather')
        for theme in ['grimoire','stone','parchment','leather']:
            check('existing theme '+theme+' saved',endpoint(a,'panel-theme.php',{'theme':theme,'csrf':token})[0]==200 and endpoint(a)[1]['preferences']['theme']==theme)
        check('no alternate theme mutation route',endpoint(a,post={'key':'theme','value':'stone','csrf':token})[0]==400)
        before_revision=endpoint(a)[1]['preferences']['revision']
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results=list(pool.map(lambda kv:save(a,*kv),[('theme','leather'),('sound_enabled','0')]))
        now=endpoint(a)[1]['preferences']
        check('concurrent patches preserve both fields',all(r[0]==200 for r in results) and now['theme']=='leather' and now['sound_enabled'] is False and now['revision']==before_revision+2)
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:initial=list(pool.map(lambda _:endpoint(d)[1]['preferences'],range(4)))
        check('concurrent first reads retain one mat',len({r['playmat_id'] for r in initial})==1)
        game=match();before=sql('USE LAB_DB;SELECT version,json FROM games WHERE ind='+str(game)+';')
        check('participant sees peer mat only',endpoint(a,suffix='?game='+str(game))[1]['opponent']=={'playmat_id':'mat-009','revision':endpoint(b)[1]['preferences']['revision']})
        check('outsider denied peer projection',endpoint(c,suffix='?game='+str(game))[0]==403)
        check('game-scoped save updates account',endpoint(a,post={'key':'sound_enabled','value':'1','csrf':token},suffix='?game='+str(game))[0]==200)
        check('preference saves leave GameState/version unchanged',sql('USE LAB_DB;SELECT version,json FROM games WHERE ind='+str(game)+';')==before)
        check('new games omit preferences',all('sound_enabled' not in r and 'playmat_id' not in r for r in state(game)['players'].values()))
        historical_game=match();old=state(historical_game);old['status']='battle';old['battle']={'active':'host','turn':1,'turn_timer':{'period_id':1,'owner':'host','status':'running','remaining_seconds':180,'anchor_at':int(time.time())-600,'pause_reason':None}};set_fixture(historical_game,old)
        sql("USE LAB_DB;UPDATE games SET status='battle' WHERE ind="+str(historical_game)+';')
        for retired in ['playmat','preparation_preference']:
            check('retired '+retired+' endpoint denied',http(a,historical_game,{'csrf':token},'&ajax='+retired)[0]==400)
        raw_before=sql('USE LAB_DB;SELECT version,json FROM games WHERE ind='+str(historical_game)+';')
        for status in ['processing','committed','rejected']:
            command='historical-sound-'+status
            sql("USE LAB_DB;INSERT INTO battle_command_journal(game_id,player_key,command_id,command_type,payload_hash,expected_version,status,created_at,updated_at) VALUES("+str(historical_game)+",'host','"+command+"','set_sound_enabled','0000000000000000000000000000000000000000000000000000000000000000',0,'"+status+"',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6));")
            journal_before=sql('USE LAB_DB;SELECT * FROM battle_command_journal WHERE game_id='+str(historical_game)+';')
            preferences_before=endpoint(a)[1]['preferences']
            code,body,_=http(a,historical_game,query='&ajax=battle_command_status&command_id='+command)
            check('historical '+status+' recovery pure',code==200 and json.loads(body)['command_status']==status and sql('USE LAB_DB;SELECT version,json FROM games WHERE ind='+str(historical_game)+';')==raw_before)
            check('historical '+status+' preserves journal and account',journal_before==sql('USE LAB_DB;SELECT * FROM battle_command_journal WHERE game_id='+str(historical_game)+';') and endpoint(a)[1]['preferences']==preferences_before)
        code,body,_=http(a,historical_game,{'cmd':'set_sound_enabled','csrf':token,'expected_version':version(historical_game),'command_id':'new-sound-forbidden','sound_enabled':'0'},'&ajax=battle_command')
        check('new sound command rejected without journal',code==400 and sql("USE LAB_DB;SELECT COUNT(*) FROM battle_command_journal WHERE command_id='new-sound-forbidden';")=='0')
        page=http(a,game)[1]
        check('preparation single common menu',page.count('data-unified-menu ')==1 and 'data-preference="sound"' in page and 'game-menu.js' not in page)
        check('preparation no fake postpone', 'Отложить игру' not in page)
        # Existing match with deliberately conflicting old fields; account must win in presentation.
        data=state(game);data['status']='battle';data['battle']={'active':'host','turn':1};data['players']['host']['playmat_id']='mat-001';data['players']['host']['sound_enabled']=False;data['players']['player']['playmat_id']='mat-002'
        set_fixture(game,data);sql("USE LAB_DB;UPDATE games SET status='battle' WHERE ind="+str(game)+';')
        page=http(a,game)[1]
        check('battle single common menu',page.count('data-unified-menu ')==1 and 'battle-menu-panel' not in page)
        check('old match uses account mats in first paint','--playmat-own: url(\'/assets/images/playmat/003.webp\')' in page and '--playmat-opponent: url(\'/assets/images/playmat/009.webp\')' in page)
        check('old match uses account theme','data-panel-theme="leather"' in page)
        check('old fields retained',state(game)['players']['host']['playmat_id']=='mat-001')
        guest=Client().lobby(api=False)[1]
        check('guest common menu and no preferences',guest.count('data-unified-menu ')==1 and 'data-preference=' not in guest and 'Регистрация' in guest)
        check('lobby no Debug', 'data-menu-debug' not in a.lobby(api=False)[1] and 'set_lucktest_debug' not in a.lobby(api=False)[1])
        config_path=ROOT/'config/auth.local.php'
        config_path.write_text(config_path.read_text().replace("'allow_loopback_http'=>true", "'debug_account_ids'=>[1,2],'allow_loopback_http'=>true"))
        debug_game=match();debug_data=state(debug_game);debug_data['mode']='lucktest';debug_data['status']='settings';set_fixture(debug_game,debug_data)
        sql("USE LAB_DB;UPDATE games SET status='settings' WHERE ind="+str(debug_game)+';')
        debug_page=http(a,debug_game)[1]
        check('preparation Debug keeps command CSRF and version', all(part in debug_page for part in ['set_lucktest_debug','name="csrf"','name="prepare_version"','name="lucktest_url_roll"']))
        debug_version=version(debug_game)
        code,_,_=http(a,debug_game,{'cmd':'set_lucktest_debug','csrf':token,'prepare_version':debug_version,'lucktest_url_roll':'1'})
        check('existing preparation Debug command works',code==303 and state(debug_game)['lucktest_debug']['url_roll_enabled'] is True)
        check('preparation Debug stale version denied',http(a,debug_game,{'cmd':'set_lucktest_debug','csrf':token,'prepare_version':debug_version,'lucktest_url_roll':'0'})[0]==409)
        check('outsider cannot access preparation Debug',http(c,debug_game)[0]==403)
        debug_data['mode']='system';set_fixture(debug_game,debug_data)
        check('ordinary preparation has no Debug', 'set_lucktest_debug' not in http(a,debug_game)[1])
        battle_debug=state(game);battle_debug['mode']='lucktest';set_fixture(game,battle_debug)
        finished_game=match();finished=state(finished_game);finished['status']='game_over';finished['winner']='host';finished['battle']={'active':'host','turn':1,'result_reason':'resign'};set_fixture(finished_game,finished)
        sql("USE LAB_DB;UPDATE games SET status='game_over' WHERE ind="+str(finished_game)+';')
        for i in range(24):sql("USE LAB_DB;INSERT INTO lobby_rooms(creator_account_id,state,version,created_at,updated_at)VALUES(1,'open',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6));")
        room_count=sql('USE LAB_DB;SELECT COUNT(*) FROM lobby_rooms;');game_count=sql('USE LAB_DB;SELECT COUNT(*) FROM games;')
        for unused in range(2):
            code,page=endpoint(a,'lobby.php',suffix='?new=1');check('wizard GET is readonly '+str(unused),code==200 and 'id="create-wizard"' in page and sql('USE LAB_DB;SELECT COUNT(*) FROM lobby_rooms;')==room_count and sql('USE LAB_DB;SELECT COUNT(*) FROM games;')==game_count)
        def create_room(mode='system',timer='0',access='open',login='',key=None):
            return a.lobby(data={'action':'create','setup_mode':mode,'csrf':token,'creation_key':key or os.urandom(16).hex(),'turn_seconds':timer,'access':access,'invited_login':login})
        for login in ['Nobody123','Creator']:
            check('bad invitation '+login+' rejected before insert',create_room(access='closed',login=login)[0]==400 and sql('USE LAB_DB;SELECT COUNT(*) FROM lobby_rooms;')==room_count)
        check('invalid turn timer denied',create_room(timer='60')[0]==400)
        new_games={}
        for seconds in ['0','180']:
            key=os.urandom(16).hex();code,created=create_room(timer=seconds,access='closed',login='CANDIDATE',key=key);check('private create '+seconds,code==200);room=created['room']
            check('repeat create same room '+seconds,create_room(timer=seconds,access='closed',login='Candidate',key=key)[1]['room']['id']==room['id'])
            check('different setup same key denied '+seconds,create_room(timer='180' if seconds=='0' else '0',access='closed',login='Candidate',key=key)[0]==409)
            check('private hidden from outsider and guest '+seconds,all(r['id']!=room['id'] for r in c.lobby()[1]['rooms']) and all(r['id']!=room['id'] for r in Client().lobby()[1]['rooms']))
            check('private visible to invitee '+seconds,any(r['id']==room['id'] for r in b.lobby()[1]['rooms']) and b.lobby(room['id'])[1]['room']['role']=='invited')
            check('private direct outsider denied '+seconds,c.lobby(room['id'])[0]==403 and c.act(room)[0]==403)
            check('invitee requests '+seconds,b.act(room)[0]==200);waiting=a.lobby(room['id'])[1]['room']
            check('private rejection '+seconds,a.act(waiting,'reject')[0]==200);reopened=a.lobby(room['id'])[1]['room']
            check('private repeat request '+seconds,b.act(reopened)[0]==200);again=a.lobby(room['id'])[1]['room']
            check('private stale approval denied '+seconds,a.act(waiting,'accept')[0]==409)
            check('private remains hidden after rejection '+seconds,all(r['id']!=room['id'] for r in c.lobby()[1]['rooms']))
            check('creator confirms '+seconds,a.act(again,'accept')[0]==200)
            started=a.lobby(room['id'])[1]['room'];new_games[seconds]=started['game_id'];check('battle setting attached '+seconds,state(started['game_id'])['settings']['battle']['turn_seconds']==int(seconds))
        for seconds,g in new_games.items():
            fixture=state(g);fixture['status']='battle';fixture['battle']={'turn':1,'active':'host','hidden_row_revealed':True}
            if seconds=='0':fixture['battle']['untimed_start_recorded']='1:host'
            else:fixture['battle']['turn_timer']={'period_id':1,'owner':'host','status':'running','remaining_seconds':180,'anchor_at':int(time.time()),'pause_reason':None}
            set_fixture(g,fixture);sql("USE LAB_DB;UPDATE games SET status='battle' WHERE ind="+str(g)+';')
            raw=sql('USE LAB_DB;SELECT json,version FROM games WHERE ind='+str(g)+';');events=sql('USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id='+str(g)+';')
            for repeat in range(3):
                code,body,_=http(a,g,query='&ajax=battle_sync&force_snapshot=1');response=json.loads(body)
                check('read-only battle status '+seconds+' '+str(repeat),code==200 and (response['turn_timer'] is None)==(seconds=='0') and raw==sql('USE LAB_DB;SELECT json,version FROM games WHERE ind='+str(g)+';') and events==sql('USE LAB_DB;SELECT COUNT(*) FROM battle_history_events WHERE game_id='+str(g)+';'))
            def pause_cmd(client,name,fields):
                code,body,_=http(client,g,{'csrf':client.token('account'),'expected_version':version(g),'command_id':'wizard-'+name+'-'+os.urandom(4).hex(),'cmd':name,**fields},'&ajax=battle_command')
                return code,json.loads(body)
            code,response=pause_cmd(a,'request_pause',{'duration_seconds':'60'});check('HTTP pause request '+seconds,code==200 and response.get('command_status')=='committed');request_id=state(g)['battle']['match_pause']['request_id']
            check('HTTP pause accept '+seconds,pause_cmd(b,'accept_pause',{'request_id':request_id})[1].get('command_status')=='committed')
            for client in [a,b]:check('HTTP pause ready '+seconds,pause_cmd(client,'pause_ready',{'request_id':request_id})[1].get('command_status')=='committed')
            resumed=state(g);check('HTTP pause resumes correct clock '+seconds,resumed['battle']['match_pause']['mode']=='idle' and ('turn_timer' not in resumed['battle'] if seconds=='0' else resumed['battle']['turn_timer']['status']=='running'))
        # Real Engine publication, followed by the real HTTP automatic-command producer.
        fixture_php=r"""
        require 'src/Core/Autoloader.php';
        Berserk\Core\Autoloader::register();Berserk\Core\Autoloader::addNamespace('Berserk\\','src/');
        $s=Berserk\Core\GameState::fromArray(json_decode(stream_get_contents(STDIN),true));
        $s->status='battle';$s->cards=[];$s->cell_markers=[];$s->players['host']->side=1;
        $s->battle=['active'=>'host','turn'=>1,'hidden_row_revealed'=>true];
        foreach([1=>'host',2=>'player'] as $id=>$owner)$s->addCard(new Berserk\Core\CardInstance(
          instanceId:$id,ukid:'card_'.$id,owner:$owner,zone:'field',row:$id===1?3:4,col:3,hp:20,hpMax:20,
          move:1,moveMax:1,strikeWeak:1,strikeMedium:2,strikeStrong:3,
          prop:$id===1?['actions'=>[['key'=>'marker','type'=>'place_cell_marker','marker'=>'bonfire']]]:[]));
        $now=time()+300;$e=new Berserk\Core\Engine(null,static fn():int=>$now);
        Berserk\Core\TurnTimer::prepare($s,'host');Berserk\Core\TurnTimer::resume($s,1,'start_phase',$now);
        echo json_encode($s->toArray(),JSON_THROW_ON_ERROR);
        """
        for timing in ['0','180','historical']:
            g=new_games['0' if timing=='0' else '180'];fixture=state(g)
            if timing=='historical':fixture['settings'].pop('battle',None)
            opened=json.loads(subprocess.check_output([args.php,'-r',fixture_php],input=json.dumps(fixture).encode(),cwd=ROOT))
            for method in ['manual','auto']:
                current=json.loads(json.dumps(opened))
                set_fixture(g,current)
                def ack(client,token):
                    data={'csrf':client.token('account'),'expected_version':version(g),'command_id':'wait-'+os.urandom(8).hex(),'cmd':'confirm_strike','wait_id':token}
                    return json.loads(http(client,g,data,'&ajax=battle_command')[1])
                def open_wait():
                    for name,fields in [('action',{'card_id':'1','action_key':'marker'}),('choose_cell_marker',{'row':'3','col':'2'})]:
                        data={'csrf':a.token('account'),'expected_version':version(g),'command_id':'open-'+os.urandom(8).hex(),'cmd':name,**fields}
                        reply=json.loads(http(a,g,data,'&ajax=battle_command')[1]);check('HTTP publishes '+timing+name,reply.get('command_status')=='committed')
                open_wait()
                if method=='auto':
                    current=state(g);current['battle']['informational_wait']['started_at']=int(time.time())-11;current['battle']['informational_wait']['deadline_at']=current['battle']['informational_wait']['started_at']+10;set_fixture(g,current)
                count_before=int(sql("USE LAB_DB;SELECT COUNT(*) FROM battle_command_journal WHERE game_id="+str(g)+" AND command_id LIKE 'p2a:%';"))
                if method=='manual':
                    reply=ack(b,'1');check('HTTP manual ACK '+timing,reply.get('command_status')=='committed')
                else:
                    from concurrent.futures import ThreadPoolExecutor
                    with ThreadPoolExecutor(max_workers=2) as pool:
                        replies=list(pool.map(lambda client:http(client,g,query='&ajax=battle_sync&force_snapshot=1')[0],[a,b]))
                    check('HTTP concurrent auto ACK '+timing,all(code==200 for code in replies))
                saved=sql('USE LAB_DB;SELECT json,version FROM games WHERE ind='+str(g)+';')
                for repeat in range(3):
                    http(a,g,query='&ajax=battle_sync&force_snapshot=1')
                    check('HTTP ACK repeated status '+timing+method+str(repeat),saved==sql('USE LAB_DB;SELECT json,version FROM games WHERE ind='+str(g)+';'))
                count_after=int(sql("USE LAB_DB;SELECT COUNT(*) FROM battle_command_journal WHERE game_id="+str(g)+" AND command_id LIKE 'p2a:%';"))
                check('HTTP exactly one automatic command '+timing+method,count_after-count_before==(1 if method=='auto' else 0))
                before=version(g);check('HTTP repeated participant rejected '+timing+method,ack(b,'1').get('command_status')!='committed' and version(g)==before)
                check('HTTP owner finishes once '+timing+method,ack(a,'1').get('command_status')=='committed' and not state(g)['battle'].get('strike'))
            set_fixture(g,opened);open_wait();current=state(g);current['battle']['informational_wait']['wait_id']=2;current['battle']['informational_wait_generation']=2;set_fixture(g,current)
            before=version(g);check('HTTP stale wait rejected '+timing,ack(b,'1').get('command_status')!='committed' and version(g)==before)
            ack(b,'2');ack(a,'2')
        mode_cases=[('sealed',{'sealed_boosters':'3'}),('draft',{'boosters':'5','draft_timer_mode':'unlimited'}),('solomon',{'boosters':'5','stack_size':'8'}),('lucktest',{'lucktest_average_cost':'5','lucktest_balanced':'0','lucktest_card_count':'5','lucktest_quick_game':'1'})]
        for chosen,fields in mode_cases:
            if chosen=='lucktest':sql('USE LAB_DB;UPDATE cards SET price=5;') # Valid synthetic LuckTest cost catalog.
            code,created=a.lobby(data={'action':'create','setup_mode':chosen,'csrf':token,'creation_key':os.urandom(16).hex(),'turn_seconds':'0',**fields});check('create mode '+chosen,code==200)
            room=created['room'];check('request mode '+chosen,b.act(room)[0]==200);waiting=a.lobby(room['id'])[1]['room'];check('accept mode '+chosen,a.act(waiting,'accept')[0]==200)
            created_game=a.lobby(room['id'])[1]['room']['game_id'];saved=state(created_game);check('mode reaches existing preparation '+chosen,saved['status'] not in ['mode','settings'] and saved['settings']['battle']['turn_seconds']==0)
        def room_chat(client,rid,action='chat_messages',fields=None,query=None,port=None):
            url=client.base.replace('auth.php','lobby-chat.php')+'?'+urllib.parse.urlencode({'room':rid,'ajax':action,**(query or {})})
            if port is not None:url=url.replace(':33572',':'+str(port))
            payload=None if fields is None else urllib.parse.urlencode({'csrf':client.token('account'),**fields}).encode()
            req=urllib.request.Request(url,data=payload)
            try:response=client.opener.open(req,timeout=30)
            except urllib.error.HTTPError as error:response=error
            return response.code,json.loads(response.read().decode('utf-8'))
        _,created=create_room(timer='0',access='closed',login='Candidate');cr=created['room'];rid=cr['id'];ctx=room_chat(a,rid)[1]['context']
        raw_room=sql('USE LAB_DB;SELECT * FROM lobby_rooms WHERE id='+str(rid)+';');raw_games=sql('USE LAB_DB;SELECT ind,version,json FROM games ORDER BY ind;')
        check('room chat before game creation',cr['game_id'] is None and room_chat(b,rid)[1]['can_send'])
        check('room chat guest denied',room_chat(Client(),rid)[0]==401);check('room chat outsider denied',room_chat(c,rid)[0]==403)
        key=os.urandom(16).hex();send={'context':ctx,'request_id':key,'message':'Привет 😀'}
        check('room chat CSRF required',room_chat(a,rid,'chat_send',{**send,'csrf':'invalid'})[0]==403)
        code,sent=room_chat(a,rid,'chat_send',send);check('room chat unicode send',code==200 and sent['message']['message']=='Привет 😀');mid=sent['message']['id']
        check('room chat idempotent retry',room_chat(a,rid,'chat_send',send)[1]['message']['id']==mid and sql('USE LAB_DB;SELECT COUNT(*) FROM lobby_chat_messages WHERE room_id='+str(rid)+';')=='1')
        check('room chat changed retry denied',room_chat(a,rid,'chat_send',{**send,'message':'different'})[0]==400)
        check('room chat rate limit',room_chat(a,rid,'chat_send',{**send,'request_id':os.urandom(16).hex()})[0]==429)
        unread=room_chat(b,rid)[1];check('room chat unread peer',unread['unread'] and unread['latest_id']==mid)
        check('room chat mark read',room_chat(b,rid,'chat_read',{'context':ctx,'last_read_id':mid})[0]==200 and not room_chat(b,rid)[1]['unread'])
        check('room chat invalid cursor denied',room_chat(a,rid,query={'after_id':'0 OR 1'})[0]==400)
        check('room chat wrong method denied',room_chat(a,rid,'chat_send')[0]==405)
        check('room chat sender spoof denied',room_chat(a,rid,'chat_send',{**send,'sender_id':'3'})[0]==400)
        sql('USE LAB_DB;UPDATE lobby_chat_messages SET created_at=DATE_SUB(NOW(6),INTERVAL 2 SECOND) WHERE room_id='+str(rid)+';')
        retry={'context':ctx,'request_id':os.urandom(16).hex(),'message':'<b>Текст</b> 🐉'}
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            twice=list(pool.map(lambda port:room_chat(a,rid,'chat_send',retry,port=port),[33572,33573]))
        check('room chat concurrent identical sends one row',all(x[0]==200 for x in twice) and twice[0][1]['message']['id']==twice[1][1]['message']['id'] and twice[0][1]['message']['message']=='Текст 🐉')
        for text in ['', ' '*5, '😀'*1001]:check('room chat invalid message '+str(len(text)),room_chat(a,rid,'chat_send',{'context':ctx,'request_id':os.urandom(16).hex(),'message':text})[0]==400)
        bulk=','.join('('+str(rid)+",2,1,'page "+str(i)+"','bulk"+str(i).zfill(20)+"',DATE_SUB(NOW(6),INTERVAL 2 SECOND))" for i in range(55))
        sql('USE LAB_DB;INSERT INTO lobby_chat_messages(room_id,peer_account_id,sender_id,message,request_id,created_at) VALUES'+bulk+';')
        latest=room_chat(b,rid)[1];older=room_chat(b,rid,query={'context':ctx,'before_id':latest['messages'][0]['id']})[1]
        check('room chat paginated history',len(latest['messages'])==50 and latest['has_more'] and len(older['messages'])==7 and not older['has_more'])
        first=room_chat(b,rid,query={'context':ctx,'after_id':'0'})[1];check('room chat ascending pages',len(first['messages'])==50 and first['has_more'] and len(room_chat(b,rid,query={'context':ctx,'after_id':first['messages'][-1]['id']})[1]['messages'])==7)
        room_chat(b,rid,'chat_read',{'context':ctx,'last_read_id':latest['latest_id']});room_chat(b,rid,'chat_read',{'context':ctx,'last_read_id':mid})
        check('room chat read cursor monotonic',room_chat(b,rid)[1]['last_read_id']==latest['latest_id'])
        check('room chat leaves room and all games unchanged',raw_room==sql('USE LAB_DB;SELECT * FROM lobby_rooms WHERE id='+str(rid)+';') and raw_games==sql('USE LAB_DB;SELECT ind,version,json FROM games ORDER BY ind;'))
        b.act(cr);waiting=a.lobby(rid)[1]['room'];a.act(waiting,'accept');started=a.lobby(rid)[1]['room'];game_raw=sql('USE LAB_DB;SELECT json,version FROM games WHERE ind='+str(started['game_id'])+';')
        check('room chat history retained after start',room_chat(b,rid)[1]['messages'] and not room_chat(b,rid)[1]['can_send'])
        check('room chat old retry after start acknowledged',room_chat(a,rid,'chat_send',send)[1]['message']['id']==mid)
        check('room chat new send after start denied',room_chat(a,rid,'chat_send',{'context':ctx,'request_id':os.urandom(16).hex(),'message':'new'})[0]==403)
        check('room chat after start never advances game',game_raw==sql('USE LAB_DB;SELECT json,version FROM games WHERE ind='+str(started['game_id'])+';'))
        _,created=create_room(timer='0');pr=created['room'];pid=pr['id'];empty=room_chat(a,pid)[1]
        check('public room chat waits for candidate',not empty['can_send'] and not empty['messages'] and room_chat(b,pid)[0]==403)
        b.act(pr);old_context=room_chat(a,pid)[1]['context'];old_send={'context':old_context,'request_id':os.urandom(16).hex(),'message':'Private with candidate A'};old_id=room_chat(a,pid,'chat_send',old_send)[1]['message']['id']
        a.act(a.lobby(pid)[1]['room'],'reject');check('rejected candidate loses chat access',room_chat(b,pid)[0]==403)
        c.act(a.lobby(pid)[1]['room']);new_context=room_chat(c,pid)[1]['context'];fresh=room_chat(c,pid)[1]
        check('replacement candidate cannot see old messages or unread',not fresh['messages'] and not fresh['unread'] and fresh['last_read_id']=='0' and fresh['latest_id']=='0')
        check('creator old chat context rejected',room_chat(a,pid,query={'context':old_context})[0]==409)
        check('creator old pending send cannot reach replacement',room_chat(a,pid,'chat_send',old_send)[0]==409 and not room_chat(c,pid)[1]['messages'])
        check('old conversation read cursor cannot cross peers',room_chat(c,pid,'chat_read',{'context':new_context,'last_read_id':old_id})[0]==400)
        sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',UNIFIED_MENU_GAME=str(game),UNIFIED_MENU_PREPARATION=str(debug_game),UNIFIED_MENU_FINISHED=str(finished_game),UNIFIED_MENU_EVIDENCE=str(out),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        ui=subprocess.run([args.node,str(ROOT/'tools/test_unified_menu_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
        (out/'browser-output.txt').write_text(ui.stdout+ui.stderr,encoding='utf-8');check('real two-account browser suite',ui.returncode==0)
        sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
        wizard_ui=subprocess.run([args.node,str(ROOT/'tools/test_wizard_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
        (out/'wizard-browser-output.txt').write_text(wizard_ui.stdout+wizard_ui.stderr,encoding='utf-8');check('wizard real browser suite',wizard_ui.returncode==0)
        if os.getenv('BERSERK_ROOM_REVIEW'):
            _,created=create_room(timer='0',access='closed',login='Candidate');room=created['room']
            detail=a.lobby(room['id'])[1]['room'];check('room detail reflects saved private untimed setup',detail['access']=='closed' and detail['turn_seconds']==0)
            check('room private projection contains no setup/debug/key',all(k not in detail for k in ['setup_json','creation_key','debug','invited_account_id']))
            old=json.loads(sql('USE LAB_DB;SELECT setup_json FROM lobby_rooms WHERE id='+str(room['id'])+';'));old['settings'].pop('battle',None)
            encoded=json.dumps(old).replace('\\','\\\\').replace("'","''");sql("USE LAB_DB;UPDATE lobby_rooms SET setup_json='"+encoded+"' WHERE id="+str(room['id'])+';')
            check('historical room missing timer is 180',a.lobby(room['id'])[1]['room']['turn_seconds']==180)
            sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
            room_ui=subprocess.run([args.node,str(ROOT/'tools/test_waiting_room_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
            (out/'room-browser-output.txt').write_text(room_ui.stdout+room_ui.stderr,encoding='utf-8');check('waiting room browser suite',room_ui.returncode==0)
            sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
            chat_ui=subprocess.run([args.node,str(ROOT/'tools/test_room_chat_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
            (out/'chat-browser-output.txt').write_text(chat_ui.stdout+chat_ui.stderr,encoding='utf-8');check('room and battle chat browser suite',chat_ui.returncode==0)
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')
        print(json.dumps(RESULTS,ensure_ascii=False,indent=2))
'''
source=source[:a]+body+source[b:]
exec(compile(source,str(ROOT/'tools/test_integration_http.py'),'exec'),{'__name__':'__main__','__file__':str(WORK/'tools/test_integration_http.py'),'PROJECT_ROOT':str(PROJECT)})
