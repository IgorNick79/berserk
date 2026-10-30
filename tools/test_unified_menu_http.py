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
source=source.replace("'20261020_battle_history.sql']", "'20261020_battle_history.sql','20261024_account_ui_preferences.sql']")
source=source.replace("        check('runtime no TRIGGER", "        sql(\"GRANT SELECT,INSERT,UPDATE ON LAB_DB.account_ui_preferences TO 'AUTH_USER'@'127.0.0.1';\")\n        check('runtime no TRIGGER")
source=source.replace("'/journal-access.php'", "'/journal-access.php','/ui-preferences.php','/panel-theme.php'")
a=source.index('        game=match()');b=source.index('    finally:',a)
body=r'''
        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/unified-menu';out.mkdir(parents=True,exist_ok=True)
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
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',UNIFIED_MENU_GAME=str(game),UNIFIED_MENU_PREPARATION=str(debug_game),UNIFIED_MENU_FINISHED=str(finished_game),UNIFIED_MENU_EVIDENCE=str(out),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        ui=subprocess.run([args.node,str(ROOT/'tools/test_unified_menu_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
        (out/'browser-output.txt').write_text(ui.stdout+ui.stderr,encoding='utf-8');check('real two-account browser suite',ui.returncode==0)
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')
        print(json.dumps(RESULTS,ensure_ascii=False,indent=2))
'''
source=source[:a]+body+source[b:]
exec(compile(source,str(ROOT/'tools/test_integration_http.py'),'exec'),{'__name__':'__main__','__file__':str(WORK/'tools/test_integration_http.py'),'PROJECT_ROOT':str(PROJECT)})
