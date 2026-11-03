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
        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/delegation';out.mkdir(parents=True,exist_ok=True)
        sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261030_account_ui_preferences.sql').read_text())
        sql("GRANT SELECT,INSERT ON LAB_DB.lobby_chat_messages TO 'LOBBY_USER'@'127.0.0.1';GRANT SELECT,INSERT,UPDATE ON LAB_DB.lobby_chat_reads TO 'LOBBY_USER'@'127.0.0.1';")
        conf=ROOT/'config/auth.local.php';conf.write_text(conf.read_text().replace("'allow_loopback_http'=>true", "'debug_account_ids'=>[1,2],'allow_loopback_http'=>true"))
        def raw(room):return json.loads(sql(f"USE LAB_DB;SELECT setup_json FROM lobby_rooms WHERE id={room['id']};"))
        def games():return int(sql('USE LAB_DB;SELECT COUNT(*) FROM games;'))
        def create(closed=False):
            code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'opponent','creation_key':os.urandom(16).hex(),'access':'closed' if closed else 'open','invited_login':'Candidate' if closed else ''})
            assert code==200,(code,data);return data['room']
        def accept(room):
            assert b.act(room)[0]==200;pending=a.lobby(room['id'])[1]['room'];assert a.act(pending,'accept')[0]==200;return b.lobby(room['id'])[1]['room']
        def configure(client,room,mode='sealed',**fields):
            return client.lobby(room['id'],{'action':'configure','csrf':client.token('account'),'version':room['version'],'candidate_request_id':(room.get('candidate_request_id') or '0'*64),'setup_mode':mode,**fields})
        for closed in [False,True]:
            room=create(closed);before=games();check('no mode selected',room['game_type'] is None and raw(room)=={'selection':'opponent','configurator_id':None});check('no fake conditions',room['turn_seconds'] is None)
            check('outsider cannot configure',configure(c,room)[0]==403);check('host cannot configure',configure(a,room)[0]==403)
            assert b.act(room)[0]==200;pending=b.lobby(room['id'])[1]['room'];check('candidate cannot configure before acceptance',configure(b,pending)[0]==403)
            assert a.act(pending,'reject')[0]==200;check('reject leaves delegation unset',raw(a.lobby(room['id'])[1]['room'])['configurator_id'] is None)
            approved=accept(a.lobby(room['id'])[1]['room']);check('accept does not create game',games()==before and approved['game_id'] is None);check('only accepted opponent configures',approved['can_configure'] and not a.lobby(room['id'])[1]['room']['can_configure'])
            check('refresh keeps approval',b.lobby(room['id'])[1]['room']['can_configure']);check('host cannot accept twice',a.act(approved,'accept')[0]==409)
            assert b.act(approved,'withdraw')[0]==200;reopened=a.lobby(room['id'])[1]['room'];check('withdraw resets room',reopened['state']=='open' and raw(reopened)=={'selection':'opponent','configurator_id':None});check('old form cannot save',configure(b,approved)[0]==403)
            if closed:check('closed hidden from outsider',all(r['id']!=room['id'] for r in c.lobby()[1]['rooms']))
        for mode,extra,stage in [('sealed',{},'view'),('draft',{},'draft'),('solomon',{},'draft'),('system',{'deck_mode':'manual','other_deck_mode':'manual','deck_id':'1','other_deck_id':'1'},'view'),('lucktest',{'lucktest_start_stage':'turn'},'turn')]:
            room=accept(create());before=games();code,data=configure(b,room,mode,**extra);check(mode+' saved',code==200);game=data['room']['game_id'];snapshot=state(game)
            check(mode+' starts correct preparation',snapshot['status']==stage);check(mode+' identities retained',snapshot['host_id']==1 and snapshot['player_id']==2);check(mode+' one match',games()==before+1)
            check(mode+' repeat recovers same game',configure(b,room,mode,**extra)[0]==200 and games()==before+1);check(mode+' host still cannot configure',configure(a,room,mode,**extra)[0]==403)
            check(mode+' mismatched retry rejected',configure(b,room,'lucktest' if mode!='lucktest' else 'sealed')[0]==409)
        room=accept(create());check('opponent debug cannot impersonate host',configure(b,room,'lucktest',lucktest_url_roll='1')[0]==403)
        check('invalid settings keep room configurable',configure(b,room,'lucktest',lucktest_total_cost='999')[0]==400 and b.lobby(room['id'])[1]['room']['can_configure'])
        # Distinct PHP workers, same authenticated opponent: lost-response/concurrent final submission.
        import copy
        other=Client(33575)
        for cookie in b.jar:other.jar.set_cookie(copy.copy(cookie))
        before=games()
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            replies=list(pool.map(lambda client:configure(client,room),[b,other]))
        check('concurrent repeats both recover',all(r[0]==200 for r in replies));check('concurrent repeats create one game',games()==before+1)
        room=accept(create());before=games()
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            futures=[pool.submit(configure,b,room,'sealed'),pool.submit(configure,other,room,'lucktest',lucktest_start_stage='turn')]
            results=[f.result() for f in futures]
        check('conflicting saves have one winner',sorted(r[0] for r in results)==[200,409] and games()==before+1)
        room=accept(create());before=games()
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            save=pool.submit(configure,b,room);leave=pool.submit(other.act,room,'withdraw');save.result();leave.result()
        final=a.lobby(room['id'])[1]['room'];check('withdraw save race leaves coherent room',(final['state']=='open' and final['game_id'] is None and games()==before) or (final['state']=='started' and final['game_id'] is not None and games()==before+1))
        stale=accept(create());assert b.act(stale,'withdraw')[0]==200;empty=a.lobby(stale['id'])[1]['room'];assert c.act(empty)[0]==200;pending=a.lobby(empty['id'])[1]['room'];assert a.act(pending,'accept')[0]==200
        latest=c.lobby(empty['id'])[1]['room'];check('former participant cannot configure',configure(b,stale)[0]==403)
        old=dict(latest,version=stale['version'],candidate_request_id=stale['candidate_request_id']);check('old nonce cannot configure new participant',configure(c,old)[0]==409)
        assert c.act(latest,'withdraw')[0]==200
        key=os.urandom(16).hex();payload={'action':'create','csrf':a.token('account'),'setup_mode':'opponent','creation_key':key}
        code,data=a.lobby(data=payload);room=accept(data['room']);check('create retry after approval recovers room',a.lobby(data=payload)[1]['room']['id']==room['id'])
        check('CSRF denied',configure(b,room,csrf='bad')[0]==403);check('recursive delegation denied',configure(b,room,'opponent')[0]==400)
        code,data=configure(b,room);check('configured nonce room',code==200);check('create retry after match recovers room',a.lobby(data=payload)[1]['room']['id']==room['id'])
        locked=accept(create(True));code,data=configure(b,locked,access='open',invited_login='Other');check('configurator cannot change lock',code==200 and data['room']['access']=='closed' and c.lobby(locked['id'])[0]==403)
        normal=a.create();assert b.act(normal)[0]==200;pending=a.lobby(normal['id'])[1]['room'];before=games();check('normal acceptance unchanged',a.act(pending,'accept')[0]==200 and games()==before+1)
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',UNIFIED_MENU_EVIDENCE=str(out),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        for script in ['test_delegation_browser.cjs','test_wizard_browser.cjs']:
            sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
            ui=subprocess.run([args.node,str(ROOT/'tools'/script)],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=240)
            (out/(script+'.txt')).write_text(ui.stdout+ui.stderr,encoding='utf-8');check(script,ui.returncode==0)
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(RESULTS,ensure_ascii=False,indent=2))
'''

tail="\nsource=source[:a]+body+source[b:]\nexec(compile(source,str(ROOT/'tools/test_integration_http.py'),'exec'),{'__name__':'__main__','__file__':str(WORK/'tools/test_integration_http.py'),'PROJECT_ROOT':str(PROJECT)})"
exec(compile(head+'\nbody='+repr(body)+tail,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
