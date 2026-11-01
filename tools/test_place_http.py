import sys
sys.stdout.reconfigure(encoding="utf-8")
"""Real isolated DB/HTTP/browser checks using the established disposable lab bootstrap."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
source=(ROOT/'tools/test_unified_menu_http.py').read_text(encoding='utf-8')
a=source.index("body=r'''")+len("body=r'''");b=source.index("\n'''",a)
body=r'''


        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/place';out.mkdir(parents=True,exist_ok=True)
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
        import copy
        selected={}
        for owner in ['host','player']:
            own=[c for c in cards if c['owner']==owner][::2][:3]
            for card in own:selected[str(card['instance_id'])]=copy.deepcopy(card)
        fixture['cards']=selected;fixture['status']='place';fixture['first_player']='host'
        fixture['players']['host']['side']=1;fixture['players']['player']['side']=2
        for card in selected.values():card['zone']='squad'
        host_ids=[int(k) for k,c in selected.items() if c['owner']=='host'];player_ids=[int(k) for k,c in selected.items() if c['owner']=='player']
        def reset(data=None):
            f=copy.deepcopy(data or fixture);set_fixture(game,f);sql(f"USE LAB_DB;UPDATE games SET status='{f['status']}' WHERE ind={game};")
        def action(client,kind,payload=None,expected=None):
            return http(client,game,{'cmd':kind,'csrf':client.token('account'),'prepare_version':version(game) if expected is None else expected,**(payload or {})})
        def zone(identity):return state(game)['cards'][str(identity)]['zone']
        reset()
        code,html,_=http(a,game,query='&card='+str(host_ids[0]))
        check('host sees nine allowed cells',code==200 and html.count('allowed move-target')==9)
        code,html,_=http(b,game,query='&card='+str(player_ids[0]))
        check('player sees eleven allowed cells',code==200 and html.count('allowed move-target')==11)
        check('invalid initial outer cell rejected',action(a,'place_card',{'card_id':host_ids[0],'row':1,'col':1})[0]>=400 and zone(host_ids[0])=='squad')
        before=version(game)
        check('real POST places own card',action(a,'place_card',{'card_id':host_ids[0],'row':1,'col':2})[0] in [200,303] and zone(host_ids[0])=='field')
        check('duplicate old POST conflicts without another placement',action(a,'place_card',{'card_id':host_ids[0],'row':1,'col':2},before)[0]>=400 and version(game)==before+1)
        code,html,_=http(a,game,query='&card='+str(host_ids[0]))
        check('field selection highlights eight empty valid cells',code==200 and html.count('allowed move-target')==8)
        before=version(game)
        check('one POST repositions field card',action(a,'place_card',{'card_id':host_ids[0],'row':2,'col':3})[0] in [200,303] and version(game)==before+1 and zone(host_ids[0])=='field' and state(game)['cards'][str(host_ids[0])]['row']==2)
        check('replayed reposition cannot execute twice',action(a,'place_card',{'card_id':host_ids[0],'row':2,'col':3},before)[0]>=400 and version(game)==before+1)
        before=version(game)
        check('invalid reposition leaves origin and version intact',action(a,'place_card',{'card_id':host_ids[0],'row':1,'col':1})[0]>=400 and version(game)==before and state(game)['cards'][str(host_ids[0])]['col']==3)
        check('unplace via real POST',action(a,'unplace_card',{'card_id':host_ids[0]})[0] in [200,303] and zone(host_ids[0])=='squad')
        check('re-place through existing sequence',action(a,'place_card',{'card_id':host_ids[0],'row':2,'col':2})[0] in [200,303] and state(game)['cards'][str(host_ids[0])]['row']==2)
        check('foreign card forbidden',action(a,'place_card',{'card_id':player_ids[0],'row':1,'col':3})[0]>=400)
        v=version(game);identity=str(uuid.uuid4())
        check('Place pause request committed',command(a,'request_pause',{'duration_seconds':'60'},v,identity)[1]['command_status']=='committed')
        check('pause replay restores committed result once',command(a,'request_pause',{'duration_seconds':'60'},v,identity)[1]['command_status']=='committed' and version(game)==v+1)
        rid=state(game)['battle']['match_pause']['request_id']
        check('Place pause accepted',command(b,'accept_pause',{'request_id':rid})[1]['command_status']=='committed')
        v=version(game)
        check('pause blocks field reposition',action(a,'place_card',{'card_id':host_ids[0],'row':1,'col':3})[0]>=400 and version(game)==v and state(game)['cards'][str(host_ids[0])]['row']==2)
        for kind,payload in [('place_card',{'card_id':host_ids[1],'row':1,'col':2}),('unplace_card',{'card_id':host_ids[0]}),('confirm_place',{}),('pathfinder_choice',{'step':0,'choice':'start'})]:
            v=version(game);check('pause blocks '+kind,action(a,kind,payload)[0]>=400 and version(game)==v and state(game)['status']=='place')
        check('host ready',command(a,'pause_ready',{'request_id':rid})[1]['command_status']=='committed')
        check('player ready',command(b,'pause_ready',{'request_id':rid})[1]['command_status']=='committed')
        check('Place pause resumed',state(game)['battle']['match_pause']['mode']=='idle')
        reset();v=version(game)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            request=pool.submit(command,a,'request_pause',{'duration_seconds':'60'},v)
            placement=pool.submit(action,b,'place_card',{'card_id':player_ids[0],'row':4,'col':1},v)
            pr=request.result()[1];ar=placement.result()
        check('pause and placement cannot both commit',not(pr['command_status']=='committed' and ar[0] in [200,303]))
        check('pause winner leaves card in squad',zone(player_ids[0])=='squad' if pr['command_status']=='committed' else True)

        # Fill the complete first host placement area, then check the next area.
        filled=copy.deepcopy(fixture);extra=[copy.deepcopy(c) for c in cards if c['owner']=='host'][::2][:10]
        for card in extra:card['zone']='squad';filled['cards'][str(card['instance_id'])]=card
        reset(filled)
        for card,cell in zip(extra,[(r,c) for r in [1,2,3] for c in [2,3,4]]):
            check('fill permitted cell '+str(cell),action(a,'place_card',{'card_id':card['instance_id'],'row':cell[0],'col':cell[1]})[0] in [200,303])
        code,html,_=http(a,game,query='&card='+str(extra[-1]['instance_id']))
        check('filled first area unlocks exactly four next cells',code==200 and html.count('allowed move-target')==4)
        check('occupied cell still rejected',action(a,'place_card',{'card_id':extra[-1]['instance_id'],'row':1,'col':2})[0]>=400)
        # Real Pathfinder confirmation/choice/rearrange path.
        pf=copy.deepcopy(fixture)
        for owner,ids_,row in [('host',host_ids,1),('player',player_ids,4)]:
            for col,cid in enumerate(ids_,2 if owner=='host' else 1):pf['cards'][str(cid)].update(zone='field',row=row,col=col)
        pf['cards'][str(host_ids[0])]['prop']={'pathfinder_rearrange':True}
        reset(pf)
        check('host confirms full field',action(a,'confirm_place')[0] in [200,303])
        check('player confirms full field',action(b,'confirm_place')[0] in [200,303])
        check('Pathfinder choice retains preparation',state(game)['status']=='place' and state(game)['battle']['pathfinder']['stage']=='choice')
        check('Pathfinder opponent cannot choose',action(b,'pathfinder_choice',{'step':0,'choice':'start'})[0]>=400)
        pf_choice=state(game)
        check('Pathfinder pause request',command(a,'request_pause',{'duration_seconds':'60'})[1]['command_status']=='committed')
        rid=state(game)['battle']['match_pause']['request_id']
        check('Pathfinder pause accept',command(b,'accept_pause',{'request_id':rid})[1]['command_status']=='committed')
        v=version(game);check('pause blocks actual Pathfinder choice',action(a,'pathfinder_choice',{'step':0,'choice':'rearrange'})[0]>=400 and version(game)==v and state(game)['battle']['pathfinder']['stage']=='choice')
        command(a,'pause_ready',{'request_id':rid});command(b,'pause_ready',{'request_id':rid})

        check('choose rearrangement',action(a,'pathfinder_choice',{'step':0,'choice':'rearrange'})[0] in [200,303])
        check('Pathfinder field reposition',action(a,'place_card',{'card_id':host_ids[0],'row':2,'col':3})[0] in [200,303] and state(game)['status']=='place')
        code,html,_=http(a,game);check('Pathfinder extra enemy row displayed',code==200 and 'coords--enemy' in html)
        check('Pathfinder unplace',action(a,'unplace_card',{'card_id':host_ids[0]})[0] in [200,303])
        check('Pathfinder re-place',action(a,'place_card',{'card_id':host_ids[0],'row':2,'col':2})[0] in [200,303])
        check('finish Pathfinder via existing confirmation',action(a,'confirm_place')[0] in [200,303] and state(game)['status']=='battle')

        browser_game=match();browser_fixture=copy.deepcopy(fixture);browser_fixture['game_id']=browser_game
        browser_fixture['cards']={}
        for owner in ['host','player']:
            for card in [c for c in cards if c['owner']==owner][::2][:6]:
                card=copy.deepcopy(card);card['zone']='squad';browser_fixture['cards'][str(card['instance_id'])]=card
        set_fixture(browser_game,browser_fixture);sql(f"USE LAB_DB;UPDATE games SET status='place' WHERE ind={browser_game};")
        pf_game=match();pf_choice['game_id']=pf_game;set_fixture(pf_game,pf_choice);sql(f"USE LAB_DB;UPDATE games SET status='place' WHERE ind={pf_game};")
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',UNIFIED_MENU_GAME=str(browser_game),PLACE_PATHFINDER_GAME=str(pf_game),UNIFIED_MENU_EVIDENCE=str(out),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        run=subprocess.run([args.node,str(ROOT/'tools/test_place_http_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=300)
        (out/'browser-output.txt').write_text(run.stdout+run.stderr,encoding='utf-8');print(run.stdout+run.stderr)
        check('Place real HTTP/browser suite',run.returncode==0)
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')

'''
source=source[:a]+body+source[b:]
exec(compile(source,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
