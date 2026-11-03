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
        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/opponent-handicap';out.mkdir(parents=True,exist_ok=True)
        sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261030_account_ui_preferences.sql').read_text())
        sql('USE LAB_DB;UPDATE cards SET price=3+MOD(ind,8);')
        def create(mode,gold,silver,enabled=True,**fields):
            code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':mode,'creation_key':os.urandom(16).hex(),'handicap_enabled':'1' if enabled else '0','handicap_gold':str(gold),'handicap_silver':str(silver),**fields})
            assert code==200,(code,data)
            public=next(r for r in Client().lobby()[1]['rooms'] if r['id']==data['room']['id'])
            check('public timer is saved value',public['turn_seconds']==0)
            check('public effective handicap',public['handicap']=={'gold':gold if enabled else 0,'silver':silver if enabled else 0})
            check('public setup remains private','setup_json' not in public and 'settings' not in public and 'debug' not in public)
            return data['room']
        def accept(room):
            assert b.act(room)[0]==200;pending=a.lobby(room['id'])[1]['room'];result=a.act(pending,'accept');assert result[0]==200,result
            return a.lobby(room['id'])[1]['room']['game_id']
        for gold,silver,enabled in [(0,0,True),(2,0,True),(0,3,True),(5,5,True),(5,5,False)]:
            game=accept(create('system',gold,silver,enabled));prep(a,game,'select_deck',deck_id='1',other_deck_id='1')
            for client in [a,b]:prep(client,game,'confirm_view')
            for client in [a,b]:prep(client,game,'confirm_turn')
            prep(a if state(game)['first_player']=='host' else b,game,'choose_side',side='1')
            snap=state(game);check('Deal reached',snap['status']=='deal')
            for owner in ['host','player']:
                player=snap['players'][owner];second=player['side']==2
                extra=[gold,silver] if owner=='player' and enabled else [0,0]
                check('Deal exact resources '+owner+str((gold,silver,enabled)),player['resources']=={'gold':(25 if second else 24)+extra[0],'silver':(23 if second else 22)+extra[1]})
            before=snap['players']['player']['resources']
            for client in [a,b,a]:check('Deal HTTP reload',http(client,game)[0]==200)
            check('no double handicap on reload',state(game)['players']['player']['resources']==before)
        for start in ['turn','place','battle']:
            for balance in ['0','1']:
                for gold,silver,enabled in [(0,0,True),(2,0,True),(0,3,True),(5,5,True),(5,5,False)]:
                    game=accept(create('lucktest',gold,silver,enabled,lucktest_start_stage=start,lucktest_balance_metals=balance));snap=state(game)
                    check('LuckTest stage '+str((start,balance,gold,silver,enabled)),snap['status']==start)
                    config=snap['settings']['battle'];check('handicap persisted',config['handicap_enabled']==enabled and config['handicap_gold']==gold and config['handicap_silver']==silver)
                    check('balance remains independent',snap['settings']['lucktest']['balance_metals']==(balance=='1'))
                    for owner in ['host','player']:
                        army=[c for c in snap['cards'].values() if c['owner']==owner and c['zone']==('field' if start=='battle' else 'squad')]
                        check('valid six-card army',len(army)==6 and all(c['price']>=0 for c in army));check('no negative resources',all(v>=0 for v in snap['players'][owner]['resources'].values()))
                    before=snap['players'];check('LuckTest reload',http(a,game)[0]==200);check('no reapplication',state(game)['players']==before)
        for mode in ['draft','solomon','sealed']:
            game=accept(create(mode,2,3));check(mode+' settings retained',state(game)['settings']['battle']['handicap_gold']==2)
        game=match();check('historical absent handicap remains absent or zero',state(game)['settings']['battle'].get('handicap_gold',0)==0)
        for bad in ['-1','6','1.5']:
            code,_=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'sealed','creation_key':os.urandom(16).hex(),'handicap_gold':bad});check('bad handicap denied '+bad,code==400)
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',UNIFIED_MENU_EVIDENCE=str(out),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        ui=subprocess.run([args.node,str(ROOT/'tools/test_handicap_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=120)
        (out/'browser-output.txt').write_text(ui.stdout+ui.stderr,encoding='utf-8');check('browser handicap suite',ui.returncode==0)
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(RESULTS,ensure_ascii=False,indent=2))
'''
tail="\nsource=source[:a]+body+source[b:]\nexec(compile(source,str(ROOT/'tools/test_integration_http.py'),'exec'),{'__name__':'__main__','__file__':str(WORK/'tools/test_integration_http.py'),'PROJECT_ROOT':str(PROJECT)})"
exec(compile(head+'\nbody='+repr(body)+tail,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
