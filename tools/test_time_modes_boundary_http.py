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
        for bad in [{'turn_seconds':'0','turn_fixed_seconds':'-1'},{'turn_time_mode':'fixed','turn_seconds':'0'},{'turn_time_mode':'adaptive','turn_creature_seconds':'1.5'},{'turn_time_mode':'fixed','turn_fixed_seconds':'-60'}]:
            code,_=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'sealed','creation_key':os.urandom(16).hex(),**bad});check('strict time input '+str(bad),code==400)
        for mode,fixed,per,host,limit in [('fixed',60,30,6,60),('fixed',600,30,6,600),('adaptive',180,10,1,10),('adaptive',180,120,10,1200)]:
            game=match();snap=json.loads(subprocess.check_output([args.php,str(ROOT/'tools/test_turn_time_fixture.php'),str(game),mode,str(host),'2',str(fixed),str(per)],encoding='utf-8-sig'));set_fixture(game,snap);sql(f"USE LAB_DB;UPDATE games SET status='battle' WHERE ind={game};")
            code,body,_=http(a,game,query='&ajax=battle_sync');check('boundary snapshot '+str(limit),code==200 and json.loads(body)['turn_timer']['limit_seconds']==limit)
        game=match();snap=json.loads(subprocess.check_output([args.php,str(ROOT/'tools/test_turn_time_fixture.php'),str(game),'adaptive','0','2'],encoding='utf-8-sig'));set_fixture(game,snap);sql(f"USE LAB_DB;UPDATE games SET status='game_over' WHERE ind={game};")
        code,body,_=http(a,game,query='&ajax=battle_sync');check('zero uses existing elimination',code==200 and state(game)['status']=='game_over' and state(game)['winner']=='player' and 'turn_timer' not in state(game)['battle'])
        code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'sealed','creation_key':os.urandom(16).hex()});check('new room default fixed 180',code==200 and data['room']['turn_time']['mode']=='fixed' and data['room']['turn_time']['fixed_seconds']==180)
        (out/'boundary-http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(RESULTS,ensure_ascii=False,indent=2))
'''

tail="\nsource=source[:a]+body+source[b:]\nexec(compile(source,str(ROOT/'tools/test_integration_http.py'),'exec'),{'__name__':'__main__','__file__':str(WORK/'tools/test_integration_http.py'),'PROJECT_ROOT':str(PROJECT)})"
exec(compile(head+'\nbody='+repr(body)+tail,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
