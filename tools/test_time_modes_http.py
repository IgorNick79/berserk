"""Wizard settings against a fresh disposable MariaDB/HTTP lab; never production."""
import os,pathlib,sys
sys.dont_write_bytecode=True
ROOT=pathlib.Path(__file__).resolve().parents[1]
base=(ROOT/'tools/test_unified_menu_http.py').read_text(encoding='utf-8')
head=base.split("\nbody=r'''",1)[0]
# Optional reuse of this suite's stopped disposable DB to avoid another 140 MB datadir.
if os.getenv('WIZARD_SETTINGS_REUSE_LAB'):
    lab=pathlib.Path(os.environ['WIZARD_SETTINGS_REUSE_LAB']).resolve()
    assert lab.is_relative_to(ROOT/'tmp') and lab.name=='unified-menu-lab' and (lab/'data/my.ini').is_file()
    guard="assert not (ROOT/'tmp/unified-menu-lab').exists(), 'Fresh isolated lab required'\n    "
    head=head.replace('a=source.index(\'        game=match()\')',"source=source.replace("+repr(guard)+",'').replace(\"ROOT/'tmp/unified-menu-lab'\", "+repr('pathlib.Path('+repr(str(lab))+')')+")\na=source.index('        game=match()')")
body=r'''
        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/time-modes';out.mkdir(parents=True,exist_ok=True)
        sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261030_account_ui_preferences.sql').read_text())
        sql("GRANT SELECT,INSERT ON LAB_DB.lobby_chat_messages TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.lobby_chat_reads TO 'LOBBY_USER'@'127.0.0.1';")
        import copy,uuid
        def accept(room,client=b):
            assert client.act(room)[0]==200;pending=a.lobby(room['id'])[1]['room'];assert a.act(pending,'accept')[0]==200
            return a.lobby(room['id'])[1]['room']['game_id']
        for mode in ['sealed','draft','solomon','system','lucktest']:
            for timing in ['none','fixed','adaptive']:
                fields={'action':'create','csrf':a.token('account'),'setup_mode':mode,'creation_key':os.urandom(16).hex(),'turn_time_mode':timing,'turn_fixed_seconds':'225','turn_creature_seconds':'40'}
                if mode=='system':fields.update(deck_id='1',other_deck_id='1',deck_mode='manual',other_deck_mode='manual')
                code,data=a.lobby(data=fields);check(mode+' create '+timing,code==200)
                room=data['room'];check('room time projection',room['turn_time']=={'mode':timing,'fixed_seconds':225,'creature_seconds':40})
                game=accept(room);settings=state(game)['settings']['battle'];check('time persisted to match',settings['turn_time_mode']==timing and settings['turn_fixed_seconds']==225 and settings['turn_creature_seconds']==40)
                if mode=='lucktest':
                    timer=state(game)['battle'].get('turn_timer');check('real first turn '+timing,timer is None if timing=='none' else timer['limit_seconds']==(225 if timing=='fixed' else 240))
        for timing in ['none','fixed','adaptive']:
            code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'opponent','creation_key':os.urandom(16).hex()});room=data['room'];assert b.act(room)[0]==200;assert a.act(a.lobby(room['id'])[1]['room'],'accept')[0]==200;ready=b.lobby(room['id'])[1]['room']
            code,data=b.lobby(room['id'],{'action':'configure','csrf':b.token('account'),'version':ready['version'],'candidate_request_id':ready['candidate_request_id'],'setup_mode':'lucktest','turn_time_mode':timing,'turn_fixed_seconds':'225','turn_creature_seconds':'40'})
            check('delegated timing '+timing,code==200 and state(data['room']['game_id'])['settings']['battle']['turn_time_mode']==timing)
        for bad in [{'turn_time_mode':'bad'},{'turn_time_mode':'fixed','turn_fixed_seconds':'59'},{'turn_time_mode':'fixed','turn_fixed_seconds':'601'},{'turn_time_mode':'fixed','turn_fixed_seconds':'61'},{'turn_time_mode':'adaptive','turn_creature_seconds':'9'},{'turn_time_mode':'adaptive','turn_creature_seconds':'121'},{'turn_time_mode':'adaptive','turn_creature_seconds':'15'},{'turn_time_mode':'none','turn_seconds':'180'}]:
            code,_=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'sealed','creation_key':os.urandom(16).hex(),**bad});check('invalid time rejected '+str(bad),code==400)
        def fixture(mode='adaptive',host=6,player=2,fixed=225,per=30,pending=False):
            game=match();args_fixture=[args.php,str(ROOT/'tools/test_turn_time_fixture.php'),str(game),mode,str(host),str(player),str(fixed),str(per)]
            if pending:args_fixture.append('pending')
            snapshot=json.loads(subprocess.check_output(args_fixture,encoding='utf-8-sig'));set_fixture(game,snapshot);sql(f"USE LAB_DB;UPDATE games SET status='battle' WHERE ind={game};");return game
        def sync(client,game):
            code,body,_=http(client,game,query='&ajax=battle_sync');assert code==200,(code,body[:300]);return json.loads(body)
        def command(client,game,kind,identity=None,**fields):
            payload={'cmd':kind,'csrf':client.token('account'),'expected_version':version(game),'command_id':identity or str(uuid.uuid4()),**fields}
            code,body,_=http(client,game,payload,'&ajax=battle_command');data=json.loads(body);assert code==200 and data.get('command_status')=='committed',(kind,code,data);return payload
        for count in [10,6,2,1]:
            game=fixture(host=count);first=sync(a,game)['turn_timer'];second=sync(b,game)['turn_timer'];check('HTTP adaptive '+str(count),first['limit_seconds']==count*30 and second['limit_seconds']==count*30 and first['period_id']==second['period_id']==1)
            before=state(game)['battle']['turn_timer'];sync(a,game);check('sync does not replace period',state(game)['battle']['turn_timer']['period_id']==before['period_id'])
        game=fixture();snap=state(game);snap['cards']['2']['zone']='graveyard';snap['cards']['2']['hp']=0;set_fixture(game,snap);check('death keeps current limit',sync(a,game)['turn_timer']['limit_seconds']==180)
        snap=state(game);added=copy.deepcopy(snap['cards']['1']);identifier=snap['next_instance_id'];added.update(instance_id=identifier,row=2,col=2);snap['cards'][str(identifier)]=added;snap['next_instance_id']=identifier+1;set_fixture(game,snap);check('summon keeps current limit',sync(b,game)['turn_timer']['limit_seconds']==180)
        payload=command(a,game,'end_turn');check('next owner computes own limit',sync(b,game)['turn_timer']['limit_seconds']==60)
        code,body,_=http(a,game,payload,'&ajax=battle_command');check('lost response retry does not start twice',code==200 and state(game)['battle']['turn_timer']['period_id']==2)
        snap=state(game);snap['cards']['3']['zone']='graveyard';snap['cards']['3']['hp']=0;set_fixture(game,snap);command(b,game,'end_turn');check('next host sees opponent-turn death',sync(a,game)['turn_timer']['limit_seconds']==150)
        for mode,expected in [('none',None),('fixed',225),('adaptive',240),('legacy',180)]:
            game=fixture(mode,per=40);before=sync(a,game)['turn_timer'];check('mode snapshot '+mode,before is None if expected is None else before['limit_seconds']==expected)
            command(a,game,'request_pause',duration_seconds=60);request=state(game)['battle']['match_pause']['request_id'];command(b,game,'accept_pause',request_id=request)
            frozen=sync(a,game)['turn_timer'];sync(b,game)
            for client in [a,b]:command(client,game,'pause_ready',request_id=request)
            after=sync(a,game)['turn_timer'];check('pause restores same clock '+mode,after is None if before is None else after['period_id']==before['period_id'] and after['limit_seconds']==expected and after['remaining_seconds']<=frozen['remaining_seconds'])
            if expected is None:
                for client in [a,b,a]:sync(client,game)
                check('untimed status never automatically ends turn',state(game)['battle']['active']=='host' and 'turn_timer' not in state(game)['battle'])
            else:
                snap=state(game);snap['battle']['turn_timer'].update(status='running',anchor_at=int(time.time())-expected-10,remaining_seconds=expected,pause_reason=None);set_fixture(game,snap)
                sync(b,game);current=state(game)['battle']['turn_timer'];check('expiry uses ordinary next turn '+mode,current['owner']=='player' and current['period_id']==2 and current.get('limit_seconds',180)==(80 if mode=='adaptive' else expected));sync(a,game);check('expiry repeat does not double advance',state(game)['battle']['turn_timer']['period_id']==2)
        game=fixture('adaptive',pending=True);timer=sync(a,game)['turn_timer'];check('real start pending pauses adaptive clock',timer['status']=='paused' and timer['limit_seconds']==180)
        command(a,game,'turn_sub_close');timer=sync(b,game)['turn_timer'];check('pending completion keeps allocated limit',timer['status']=='running' and timer['limit_seconds']==180 and timer['period_id']==1)
        snap=state(game);snap['battle']['turn_timer'].update(anchor_at=int(time.time())-190,remaining_seconds=180);snap['battle']['turn_phase']={'phase':'start','active_key':'host','passive_key':'player','active_queue':[],'passive_queue':[],'sub':None};set_fixture(game,snap)
        timer=sync(a,game)['turn_timer'];check('expired mandatory phase blocks automatic end',timer['status']=='expired' and state(game)['battle']['active']=='host' and timer['period_id']==1)
        display_game=fixture('adaptive',host=10,player=6,per=120)
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',UNIFIED_MENU_EVIDENCE=str(out),TIME_MODE_GAME=str(display_game),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        for script in ['test_time_modes_browser.cjs','test_wizard_browser.cjs','test_delegation_browser.cjs']:
            sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
            ui=subprocess.run([args.node,str(ROOT/'tools'/script)],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=240);(out/(script+'.txt')).write_text(ui.stdout+ui.stderr,encoding='utf-8');check(script,ui.returncode==0)
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(RESULTS,ensure_ascii=False,indent=2))
'''

tail="\nsource=source[:a]+body+source[b:]\nexec(compile(source,str(ROOT/'tools/test_integration_http.py'),'exec'),{'__name__':'__main__','__file__':str(WORK/'tools/test_integration_http.py'),'PROJECT_ROOT':str(PROJECT)})"
exec(compile(head+'\nbody='+repr(body)+tail,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
