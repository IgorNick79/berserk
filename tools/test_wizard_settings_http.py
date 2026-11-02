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
        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/wizard-settings';out.mkdir(parents=True,exist_ok=True)
        sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261030_account_ui_preferences.sql').read_text())
        def create(fields):
            return a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'system','creation_key':os.urandom(16).hex(),**fields})
        def accept(room):
            assert b.act(room)[0]==200
            pending=a.lobby(room['id'])[1]['room']
            result=a.act(pending,'accept')
            return result,a.lobby(room['id'])[1]['room']
        def setup(room):return json.loads(sql(f"USE LAB_DB;SELECT setup_json FROM lobby_rooms WHERE id={room['id']};"))
        manual={'deck_mode':'manual','other_deck_mode':'manual','deck_id':'1','other_deck_id':'1'}
        code,data=create(manual);check('manual choices accepted',code==200)
        room=data['room'];saved=setup(room)
        check('four choices persisted',saved['system_decks']=={'deck_mode':'manual','other_deck_mode':'manual','deck_id':1,'other_deck_id':1})
        check('room reload preserves settings',setup(a.lobby(room['id'])[1]['room'])==saved)
        result,room=accept(room);check('auto selection reaches view',result[0]==200 and state(room['game_id'])['status']=='view')
        game=room['game_id'];initial=state(game)
        check('both decks assigned',initial['players']['host']['deck_id']==initial['players']['player']['deck_id']==1)
        check('instances initialized',len(initial['cards'])==60)
        check('view remains unconfirmed',not initial['players']['host']['confirmed'] and not initial['players']['player']['confirmed'])
        again=a.act(room,'accept');check('repeat acceptance cannot create or reinitialize game',a.lobby(room['id'])[1]['room']['game_id']==game and state(game)==initial)
        prep(a,game,'confirm_view');check('host confirms existing view',state(game)['status']=='view')
        prep(b,game,'confirm_view');check('both confirmations reach roll',state(game)['status']=='turn')
        for fields in [{'deck_mode':'random','other_deck_mode':'random'}, {'deck_mode':'manual','deck_id':'1','other_deck_mode':'random'}]:
            code,data=create(fields);check('random or mixed accepted',code==200)
            result,room=accept(data['room']);check('random or mixed resolves via processor',result[0]==200 and state(room['game_id'])['status']=='view')
        for fields in [dict(manual,deck_id='999'),dict(manual,deck_mode='bad'),dict(manual,deck_id='0'),dict(manual,deck_id='-1')]:
            check('invalid choice rejected '+str(fields),create(fields)[0]==400)
        code,data=create(manual);room=data['room'];assert b.act(room)[0]==200;pending=a.lobby(room['id'])[1]['room']
        sql("USE LAB_DB;UPDATE decks SET status='inactive' WHERE ind=1;")
        count=sql('USE LAB_DB;SELECT COUNT(*) FROM games;');check('removed deck rejects acceptance',a.act(pending,'accept')[0]==400)
        check('failed acceptance rolls back game',count==sql('USE LAB_DB;SELECT COUNT(*) FROM games;') and a.lobby(room['id'])[1]['room']['game_id'] is None)
        sql("USE LAB_DB;UPDATE decks SET status='active' WHERE ind=1;")
        legacy=match();prep(a,legacy,'choose_mode',mode='system')
        check('legacy system retains deck stage',state(legacy)['status']=='deck')
        prep(a,legacy,'select_deck',**manual);check('legacy deck selector still works',state(legacy)['status']=='view' and len(state(legacy)['cards'])==60)
        for mode,fields in [('draft',{'boosters':'5','draft_timer_mode':'unlimited'}),('solomon',{'boosters':'5','stack_size':'8'}),('sealed',{'sealed_boosters':'3'}),('lucktest',{})]:
            if mode!='lucktest':fields.update(booster_common='7',booster_uncommon='4',booster_rare_slots='1',booster_rare_chance='70')
            code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':mode,'creation_key':os.urandom(16).hex(),**fields})
            check(mode+' settings saved',code==200 and setup(data['room'])['mode']==mode)
            result,room=accept(data['room']);check(mode+' preparation starts',result[0]==200)
            if mode!='lucktest':
                persisted=setup(room)['settings']['booster'];actual=state(room['game_id'])['settings']['booster']
                check(mode+' custom composition persisted and applied',persisted==actual and actual=={'common':7,'uncommon':4,'rare_slots':1,'ultra_rare_chance':30})
        sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',UNIFIED_MENU_EVIDENCE=str(out),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        for script in ['test_wizard_browser.cjs','test_wizard_settings_browser.cjs']:
            ui=subprocess.run([args.node,str(ROOT/'tools'/script)],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
            (out/(script+'.txt')).write_text(ui.stdout+ui.stderr,encoding='utf-8');check(script,ui.returncode==0)
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')
        print(json.dumps(RESULTS,ensure_ascii=False,indent=2))
'''
tail="\nsource=source[:a]+body+source[b:]\nexec(compile(source,str(ROOT/'tools/test_integration_http.py'),'exec'),{'__name__':'__main__','__file__':str(WORK/'tools/test_integration_http.py'),'PROJECT_ROOT':str(PROJECT)})"
exec(compile(head+'\nbody='+repr(body)+tail,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
