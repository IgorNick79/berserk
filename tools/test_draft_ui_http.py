"""Real isolated DB/HTTP/browser checks using the established disposable lab bootstrap."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
source=(ROOT/'tools/test_unified_menu_http.py').read_text(encoding='utf-8')
a=source.index("body=r'''")+len("body=r'''");b=source.index("\n'''",a)
body=r'''
        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/draft';out.mkdir(parents=True,exist_ok=True)
        sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261030_account_ui_preferences.sql').read_text())
        import copy,uuid
        art=list((pathlib.Path(PROJECT_ROOT)/'www/assets/cards').glob('*/*.webp'))[:2]
        assert len(art)==2, 'Two local reference arts required'
        ukids=[p.stem for p in art]
        for i,ukid in enumerate(ukids):
            sql("USE LAB_DB;INSERT INTO cards(ind,ukid,name,health,move,strike_weak,strike_medium,strike_strong,prop,price,elite,single) VALUES("+str(200+i)+",'"+ukid+"','Draft card "+str(i)+"',5,1,1,2,3,'{}',3,"+str(i)+",0);")
        games=[]
        for kind,count in [('grid',9),('solomon',8)]:
            game=match()
            fixture=json.loads(subprocess.check_output([args.php,str(ROOT/'tools/test_view_deck_fixture.php'),str(game)],encoding='utf-8-sig'))
            fixture['game_id']=game;fixture['status']='draft';fixture['cards']={};fixture['mode']='draft';fixture['settings']['mode']='draft'
            fixture['settings']['draft'].update(type=kind,timer_mode='unlimited',stack_size=count,grid_mode='continuous')
            fixture['draft']={'grid':[ukids[i%2] for i in range(count)],'pool':[ukids[i%2] for i in range(80)],'recycle':[],'turn':'host','picked':{'host':[],'player':[]},'history':[],'grid_mode':'continuous','decision_id':1,'consecutive_passes':0,'round_first_player':'host','round_picks':0}
            set_fixture(game,fixture);sql(f"USE LAB_DB;UPDATE games SET status='draft' WHERE ind={game};");games.append(game)
            code,html,_=http(a,game)
            check(kind+' real page loads',code==200 and 'battle-mini-card' in html and 'draft-page' in html)

        # Real timed pause and native preparation guards, separate from browser games.
        game=match();timed=copy.deepcopy(fixture);timed['game_id']=game
        timed['settings']['draft'].update(type='grid',timer_mode='default')
        timed['draft']['grid']=[ukids[i%2] for i in range(9)]
        timed['draft']['timer']={'mode':'default','total_limit':600,'action_limit':60,'elapsed':{'host':0,'player':0},'action_started_at':int(time.time())-5}
        set_fixture(game,timed);sql(f"USE LAB_DB;UPDATE games SET status='draft' WHERE ind={game};")
        def pause_cmd(client,kind,payload=None,identity=None,expected=None):
            data={'cmd':kind,'csrf':client.token('account'),'expected_version':version(game) if expected is None else expected,'command_id':identity or str(uuid.uuid4()),**(payload or {})}
            return json.loads(http(client,game,data,'&ajax=battle_command')[1])
        v=version(game);identity=str(uuid.uuid4())
        check('timed Draft requests pause',pause_cmd(a,'request_pause',{'duration_seconds':60},identity,v)['command_status']=='committed')
        check('lost pause response recovers through existing journal',json.loads(http(a,game,query='&ajax=battle_command_status&command_id='+identity)[1])['command_status']=='committed')
        check('pause replay does not commit twice',pause_cmd(a,'request_pause',{'duration_seconds':60},identity,v)['command_status']=='committed' and version(game)==v+1)
        rid=state(game)['battle']['match_pause']['request_id']
        check('timed Draft accepts pause',pause_cmd(b,'accept_pause',{'request_id':rid})['command_status']=='committed')
        code,html,_=http(a,game)
        import re
        csrf=re.search(r'data-stage="draft"[^>]*data-csrf="([^"]+)"',html).group(1)
        saved=state(game)['draft'];v=version(game)
        for kind,payload in [('draft_row',{'row':1}),('draft_col',{'col':1}),('draft_pass',{}),('draft_confirm',{'decision_id':1,'positions[]':0}),('finish_draft',{})]:
            response=http(a,game,{'cmd':kind,'csrf':csrf,'prepare_version':v,**payload})
            check('pause rejects native '+kind,response[0]>=400 and state(game)['draft']==saved and version(game)==v)
        response=http(a,game,{'cmd':'draft_sync','csrf':csrf})
        sync=json.loads(response[1]);check('timed paused sync cannot auto-pick',sync['ok'] and not sync['changed'] and not sync['events'] and state(game)['draft']==saved)
        frozen=sync['timer']['remaining_action'];time.sleep(2)
        again=json.loads(http(a,game,{'cmd':'draft_sync','csrf':csrf})[1]);check('timed pause preserves both clocks',again['timer']['remaining_action']==frozen and again['timer']['remaining_total']==sync['timer']['remaining_total'])
        pause_cmd(a,'pause_ready',{'request_id':rid});pause_cmd(b,'pause_ready',{'request_id':rid})
        resumed=state(game);check('timed Draft resumes existing clock',resumed['battle']['match_pause']['mode']=='idle' and 'paused_at' not in resumed['draft']['timer'] and resumed['draft']['timer']['action_started_at']>timed['draft']['timer']['action_started_at'])

        v=version(game)
        response=http(a,game,{'cmd':'draft_row','row':1,'csrf':csrf,'prepare_version':v})
        check('native row commits after resume',response[0] in [200,303] and len(state(game)['draft']['picked']['host'])==3 and state(game)['draft']['turn']=='player')
        response=http(a,game,{'cmd':'draft_row','row':1,'csrf':csrf,'prepare_version':v})
        check('stale row cannot apply twice',response[0]>=400 and len(state(game)['draft']['picked']['host'])==3)
        code,html,_=http(b,game);other_csrf=re.search(r'data-stage="draft"[^>]*data-csrf="([^"]+)"',html).group(1)
        response=http(b,game,{'cmd':'draft_col','col':1,'csrf':other_csrf,'prepare_version':version(game)})
        check('native column commits for second player',response[0] in [200,303] and len(state(game)['draft']['picked']['player'])==3)

        game=match();race=copy.deepcopy(timed);race['game_id']=game;race['draft']['timer']['action_started_at']=int(time.time())
        set_fixture(game,race);sql(f"USE LAB_DB;UPDATE games SET status='draft' WHERE ind={game};")
        code,html,_=http(a,game);race_csrf=re.search(r'data-stage="draft"[^>]*data-csrf="([^"]+)"',html).group(1);v=version(game)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            pause_result=pool.submit(pause_cmd,b,'request_pause',{'duration_seconds':60},str(uuid.uuid4()),v)
            row_result=pool.submit(http,a,game,{'cmd':'draft_row','row':1,'csrf':race_csrf,'prepare_version':v})
            pr=pause_result.result();rr=row_result.result()
        check('pause and native row cannot both commit',not(pr['command_status']=='committed' and rr[0] in [200,303]))
        check('race preserves either original or complete row allocation',len(state(game)['draft']['picked']['host']) in [0,3])
        # Catalog for isolated historical timer/transition DB regressions.
        for i in range(60):
            sql("USE LAB_DB;INSERT INTO cards(ind,ukid,name,health,move,strike_weak,strike_medium,strike_strong,prop,price,elite,single) VALUES("+str(202+i)+",'s98_draft_"+str(i)+"','Backend fixture',5,1,1,2,3,'{}',3,0,0);")
        sql("USE LAB_DB;UPDATE cards SET rarity='common',class='Warrior',descr='Fixture description' WHERE ind>=200;")

        finished_game=match();finished=copy.deepcopy(timed);finished['game_id']=finished_game;finished['settings']['draft']['timer_mode']='unlimited';finished['draft'].pop('timer',None)
        finished['draft']['picked']={'host':['s98_draft_'+str(i) for i in range(30)],'player':['s98_draft_'+str(i) for i in range(30,60)]}
        finished['draft']['turn']='host';set_fixture(finished_game,finished);sql(f"USE LAB_DB;UPDATE games SET status='draft' WHERE ind={finished_game};")
        code,html,_=http(a,finished_game);finish_csrf=re.search(r'data-stage="draft"[^>]*data-csrf="([^"]+)"',html).group(1)
        response=http(a,finished_game,{'cmd':'finish_draft','csrf':finish_csrf,'prepare_version':version(finished_game)})
        check('native finish transitions to View',response[0] in [200,303] and state(finished_game)['status']=='view' and state(finished_game)['draft'] is None)
        for test in ['test_draft_timer_history.php']:
            result=subprocess.run([args.php,str(ROOT/'tools'/test)],capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=120)
            (out/(test+'.txt')).write_text(result.stdout+result.stderr,encoding='utf-8');check('isolated '+test,result.returncode==0)
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',DRAFT_GAMES=json.dumps(games),UNIFIED_MENU_EVIDENCE=str(out),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        run=subprocess.run([args.node,str(ROOT/'tools/test_draft_ui_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=300)
        (out/'browser-output.txt').write_text(run.stdout+run.stderr,encoding='utf-8');print(run.stdout+run.stderr)
        check('real Draft browser suite',run.returncode==0)
        check('browser pass changes active player',state(games[0])['draft']['turn']=='player')
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')

'''
source=source[:a]+body+source[b:]
exec(compile(source,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
