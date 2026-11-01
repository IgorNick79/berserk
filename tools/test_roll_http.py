"""Real isolated DB/HTTP/browser checks using the established disposable lab bootstrap."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
source=(ROOT/'tools/test_unified_menu_http.py').read_text(encoding='utf-8')
a=source.index("body=r'''")+len("body=r'''");b=source.index("\n'''",a)
body=r'''
        import uuid
        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/roll';out.mkdir(parents=True,exist_ok=True)
        sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261030_account_ui_preferences.sql').read_text())
        game=match()
        fixture=json.loads(subprocess.check_output([args.php,str(ROOT/'tools/test_view_deck_fixture.php'),str(game)],encoding='utf-8-sig'))
        def command(client,kind,payload=None,expected=None,identity=None):
            data={'cmd':kind,'csrf':client.token('account'),'expected_version':version(game) if expected is None else expected,'command_id':identity or str(uuid.uuid4()),**(payload or {})}
            return json.loads(http(client,game,data,'&ajax=battle_command')[1])
        def action(client,kind,expected=None):
            return http(client,game,{'cmd':kind,'side':'1','csrf':client.token('account'),'prepare_version':version(game) if expected is None else expected})
        def reset(stage):
            import copy
            f=copy.deepcopy(fixture);f['status']=stage;f['first_player']='host';f['players']['host']['dice']=6;f['players']['player']['dice']=3;f['battle']=dict(f.get('battle') or {});f['battle'].pop('match_pause',None)
            set_fixture(game,f);sql(f"USE LAB_DB;UPDATE games SET status='{stage}' WHERE ind={game};")
        for stage in ['turn','side']:
            reset(stage)
            before=version(game);identity=str(uuid.uuid4())
            sent=command(a,'request_pause',{'duration_seconds':'60'},before,identity)
            check(stage+' pause committed',sent['command_status']=='committed')
            replay=command(a,'request_pause',{'duration_seconds':'60'},before,identity)
            check(stage+' lost response replay once',replay['command_status']=='committed' and version(game)==before+1)
            check(stage+' recovered status',json.loads(http(a,game,query='&ajax=battle_command_status&command_id='+identity)[1])['command_status']=='committed')
            request_id=state(game)['battle']['match_pause']['request_id']
            check(stage+' accept',command(b,'accept_pause',{'request_id':request_id})['command_status']=='committed')
            check(stage+' active retained on HTTP refresh',http(a,game)[0]==200 and state(game)['battle']['match_pause']['mode']=='active')
            for actor in ([a,b] if stage=='turn' else [a]):
                before_block=version(game);blocked=action(actor,'confirm_turn' if stage=='turn' else 'choose_side')
                check(stage+' paused legacy action blocked',blocked[0]>=400 and version(game)==before_block and state(game)['status']==stage)
            check(stage+' ready host',command(a,'pause_ready',{'request_id':request_id})['command_status']=='committed')
            check(stage+' ready player',command(b,'pause_ready',{'request_id':request_id})['command_status']=='committed')
            check(stage+' resumed',state(game)['battle']['match_pause']['mode']=='idle')
            if stage=='turn':
                check('host confirms after resume',action(a,'confirm_turn')[0] in [200,303])
                check('player confirms after resume',action(b,'confirm_turn')[0] in [200,303])
                check('turn to side keeps pause accounting',state(game)['status']=='side' and state(game)['battle']['match_pause']['used']['host']==1)
        for stage in ['turn','side']:
            reset(stage)
            if stage=='turn':action(a,'confirm_turn')
            v=version(game)
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                pause_job=pool.submit(command,a if stage=='turn' else b,'request_pause',{'duration_seconds':'60'},v)
                action_job=pool.submit(action,b if stage=='turn' else a,'confirm_turn' if stage=='turn' else 'choose_side',v)
                pause_result=pause_job.result();action_result=action_job.result()
            current=state(game)
            check(stage+' race cannot both commit',not (pause_result.get('command_status')=='committed' and action_result[0] in [200,303]))
            check(stage+' race pause cannot be bypassed',current['status']==stage if pause_result.get('command_status')=='committed' else True)
        reset('turn')
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',UNIFIED_MENU_GAME=str(game),UNIFIED_MENU_EVIDENCE=str(out),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        run=subprocess.run([args.node,str(ROOT/'tools/test_roll_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=240)
        (out/'browser-output.txt').write_text(run.stdout+run.stderr,encoding='utf-8');print(run.stdout+run.stderr)
        check('Roll real browser suite',run.returncode==0)
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')

'''
source=source[:a]+body+source[b:]
exec(compile(source,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
