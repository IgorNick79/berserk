"""Real isolated DB/HTTP/browser checks using the established disposable lab bootstrap."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
source=(ROOT/'tools/test_unified_menu_http.py').read_text(encoding='utf-8')
a=source.index("body=r'''")+len("body=r'''");b=source.index("\n'''",a)
body=r'''
        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/view-deck';out.mkdir(parents=True,exist_ok=True)
        sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261030_account_ui_preferences.sql').read_text())
        game=match()
        fixture=json.loads(subprocess.check_output([args.php,str(ROOT/'tools/test_view_deck_fixture.php'),str(game)],encoding='utf-8-sig'))
        cards=list(fixture['cards'].values()) if isinstance(fixture['cards'],dict) else fixture['cards']
        set_fixture(game,fixture)
        sql(f"USE LAB_DB;UPDATE games SET status='view' WHERE ind={game};")
        ids=list(dict.fromkeys(card['ukid'] for card in cards))
        for i,ukid in enumerate(ids):
            sql("USE LAB_DB;INSERT INTO cards(ind,ukid,name,health,move,strike_weak,strike_medium,strike_strong,prop,price,elite,single) VALUES("+str(200+i)+",'"+ukid+"','Card "+str(i)+" — длинное название карты',5,1,1,2,3,'{}',3,"+str(i%2)+","+str(i%3==0).lower().replace('true','1').replace('false','0')+");")
        import uuid
        def command(client,kind,payload=None,expected=None,identity=None):
            data={'cmd':kind,'csrf':client.token('account'),'expected_version':version(game) if expected is None else expected,'command_id':identity or str(uuid.uuid4()),**(payload or {})}
            code,text,_=http(client,game,data,'&ajax=battle_command')
            return code,json.loads(text)
        own=next(card['instance_id'] for card in cards if card['owner']=='host')
        foreign=next(card['instance_id'] for card in cards if card['owner']=='player')
        check('foreign card rejected',command(a,'view_to_sideboard',{'card_id':foreign})[1]['command_status']=='rejected')
        before=version(game);identity=str(uuid.uuid4())
        first=command(a,'view_to_sideboard',{'card_id':own},before,identity)
        check('view transfer committed',first[1]['command_status']=='committed')
        replay=command(a,'view_to_sideboard',{'card_id':own},before,identity)
        check('same command id restores committed result',replay[1]['command_status']=='committed' and version(game)==before+1)
        check('return committed',command(a,'view_to_deck',{'card_id':own})[1]['command_status']=='committed')
        check('stale command conflicts',command(a,'view_to_sideboard',{'card_id':own},before)[1]['command_status']=='conflict')
        check('request pause committed',command(a,'request_pause',{'duration_seconds':'60'})[1]['command_status']=='committed')
        for kind in ['view_to_sideboard','view_to_deck','confirm_view']:
            result=command(a,kind,{'card_id':own})[1]
            check('pause blocks '+kind,result['command_status']=='rejected' and result.get('error')=='match_paused')
        request_id=state(game)['battle']['match_pause']['request_id']
        check('cancel pause committed',command(a,'cancel_pause_request',{'request_id':request_id})[1]['command_status']=='committed')
        set_fixture(game,fixture)
        race_version=version(game)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            jobs=[pool.submit(command,a,'view_to_sideboard',{'card_id':own},race_version),pool.submit(command,b,'request_pause',{'duration_seconds':'60'},race_version)]
            outcomes=[job.result()[1]['command_status'] for job in jobs]
        check('concurrent pause and transfer serialize with CAS',sorted(outcomes)==['committed','conflict'])
        # Browser tests use a fresh fixture after the server pause cooldown checks.
        set_fixture(game,fixture)
        for regression in ['test_draft_temporary_rules.php','test_draft_timer_history.php']:
            result=subprocess.run(php+[str(ROOT/'tools'/regression)],capture_output=True,text=True,encoding='utf-8',errors='replace')
            (out/(regression+'.txt')).write_text(result.stdout+result.stderr,encoding='utf-8')
            check('isolated real DB '+regression,result.returncode==0)
        battle_game=match()
        battle_fixture=json.loads(subprocess.check_output([args.php,str(ROOT/'tools/test_view_deck_fixture.php'),str(battle_game),'battle'],encoding='utf-8-sig'))
        set_fixture(battle_game,battle_fixture)
        sql(f"USE LAB_DB;UPDATE games SET status='battle' WHERE ind={battle_game};")
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',UNIFIED_MENU_GAME=str(game),BATTLE_COMPARE_GAME=str(battle_game),UNIFIED_MENU_EVIDENCE=str(out),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        run=subprocess.run([args.node,str(ROOT/'tools/test_view_deck_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=240)
        (out/'browser-output.txt').write_text(run.stdout+run.stderr,encoding='utf-8')
        print(run.stdout+run.stderr)
        check('view real HTTP/browser suite',run.returncode==0)
        check('host confirms View',command(a,'confirm_view')[1]['command_status']=='committed')
        check('player confirms View',command(b,'confirm_view')[1]['command_status']=='committed')
        check('View preserves preparation transition',state(game)['status']=='turn')
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')
'''
source=source[:a]+body+source[b:]
exec(compile(source,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
