"""Real isolated DB/HTTP/browser checks using the established disposable lab bootstrap."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
source=(ROOT/'tools/test_unified_menu_http.py').read_text(encoding='utf-8')
a=source.index("body=r'''")+len("body=r'''");b=source.index("\n'''",a)
body=r'''

        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/deal';out.mkdir(parents=True,exist_ok=True)
        sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261030_account_ui_preferences.sql').read_text())
        game=match()
        fixture=json.loads(subprocess.check_output([args.php,str(ROOT/'tools/test_view_deck_fixture.php'),str(game)],encoding='utf-8-sig'))
        cards=list(fixture['cards'].values()) if isinstance(fixture['cards'],dict) else fixture['cards']
        set_fixture(game,fixture)
        sql(f"USE LAB_DB;UPDATE games SET status='view' WHERE ind={game};")
        ids=list(dict.fromkeys(card['ukid'] for card in cards))
        for i,ukid in enumerate(ids):
            sql("USE LAB_DB;INSERT INTO cards(ind,ukid,name,health,move,strike_weak,strike_medium,strike_strong,prop,price,elite,single) VALUES("+str(200+i)+",'"+ukid+"','Card "+str(i)+" — длинное название карты',5,1,1,2,3,'{}',3,"+str(i%2)+","+str(i%3==0).lower().replace('true','1').replace('false','0')+");")
        sql("USE LAB_DB;UPDATE cards SET rarity='common',class='Воин',descr='Описание карты для проверки хинта' WHERE ind>=200;")
        import uuid
        def command(client,kind,payload=None,expected=None,identity=None):
            data={'cmd':kind,'csrf':client.token('account'),'expected_version':version(game) if expected is None else expected,'command_id':identity or str(uuid.uuid4()),**(payload or {})}
            code,text,_=http(client,game,data,'&ajax=battle_command')
            return code,json.loads(text)
        fixture['status']='deal';fixture['first_player']='host';fixture['players']['host']['side']=1;fixture['players']['player']['side']=2
        for player in fixture['players'].values():player['resources']={'gold':30,'silver':30}
        for card in fixture['cards'].values():
            card['zone']='deck' if card['ukid']==cards[-1]['ukid'] else 'hand';card['element']='plains' if card['ukid']==cards[0]['ukid'] else 'forests'
        set_fixture(game,fixture);sql(f"USE LAB_DB;UPDATE games SET status='deal' WHERE ind={game};")
        ukid=cards[0]['ukid'];before=version(game);identity=str(uuid.uuid4())
        result=command(a,'pick_card',{'ukid':ukid},before,identity)[1]
        check('Deal pick via shared AJAX',result['command_status']=='committed')
        check('repeat pick restores once',command(a,'pick_card',{'ukid':ukid},before,identity)[1]['command_status']=='committed' and version(game)==before+1)
        check('stale pick conflicts',command(a,'pick_card',{'ukid':ukid},before)[1]['command_status']=='conflict')
        check('unpick via shared AJAX',command(a,'unpick_card',{'ukid':ukid})[1]['command_status']=='committed')
        check('Deal pause requested',command(a,'request_pause',{'duration_seconds':'60'})[1]['command_status']=='committed')
        for kind in ['pick_card','unpick_card','reshuffle','confirm_deal']:
            check('pause blocks '+kind,command(a,kind,{'ukid':ukid})[1]['command_status']=='rejected')
        request_id=state(game)['battle']['match_pause']['request_id']
        check('cancel Deal pause',command(a,'cancel_pause_request',{'request_id':request_id})[1]['command_status']=='committed')
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',UNIFIED_MENU_GAME=str(game),UNIFIED_MENU_EVIDENCE=str(out),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        run=subprocess.run([args.node,str(ROOT/'tools/test_deal_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
        (out/'browser-output.txt').write_text(run.stdout+run.stderr,encoding='utf-8');print(run.stdout+run.stderr)
        check('Deal real browser',run.returncode==0)
        previous=version(game);reshuffle_id=str(uuid.uuid4())
        check('reshuffle AJAX committed',command(a,'reshuffle',expected=previous,identity=reshuffle_id)[1]['command_status']=='committed')
        check('reshuffle replay no second gold charge',command(a,'reshuffle',expected=previous,identity=reshuffle_id)[1]['command_status']=='committed' and state(game)['players']['host']['reshuffles']==1)
        for client,owner in [(a,'host'),(b,'player')]:
            pool=list(state(game)['cards'].values());target=next(c['ukid'] for c in pool if c['owner']==owner and c['zone']=='hand')
            check(owner+' picks before confirmation',command(client,'pick_card',{'ukid':target})[1]['command_status']=='committed')
            check(owner+' confirms via AJAX',command(client,'confirm_deal')[1]['command_status']=='committed')
        check('Deal AJAX transition to place',state(game)['status']=='place')
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')

'''
source=source[:a]+body+source[b:]
exec(compile(source,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
