"""Bot match HTTP and Chromium checks. Run from a disposable source copy in TEMP."""
import pathlib

root = pathlib.Path(__file__).resolve().parents[1]
import os
assert root.is_relative_to(pathlib.Path(os.environ['TEMP']).resolve())
source = (root/'tools/test_integration_http.py').read_text(encoding='utf-8')
source = source.replace("from http.cookiejar import Cookie", "from http.cookiejar import Cookie\nurllib.request.getproxies=lambda: {}")
source = source.replace("'20261021_lobby_setup.sql'", "'20261021_lobby_setup.sql','20261030_lobby_wizard.sql','20261108_bot_matches.sql'")
source = source.replace("'allow_loopback_http'=>true];", "'allow_loopback_http'=>true,'bot_account_id'=>5,'debug_account_ids'=>[1,4]];")
source = source.replace("'20261020_battle_history.sql']", "'20261020_battle_history.sql','20261024_account_ui_preferences.sql','20261030_account_ui_preferences.sql']")
source = source.replace("        check('runtime no TRIGGER", "        sql(\"GRANT SELECT,INSERT,UPDATE ON LAB_DB.account_ui_preferences TO 'AUTH_USER'@'127.0.0.1';\")\n        check('runtime no TRIGGER")
start = source.index('        game=match()')
end = source.index('    finally:', start)
body = r'''
        urllib.request.getproxies=lambda:{}
        virtual=Client()
        bot_password=os.urandom(24).hex()
        assert virtual.post('register','BerserkBot',bot_password)[0]==303
        assert virtual.post('login','BerserkBot',bot_password)[0]==303
        check('virtual account has distinct ID',sql("USE LAB_DB;SELECT id FROM accounts WHERE login_key='berserkbot'")=='5')
        csrf=a.token('account')
        code,wizard=a.lobby(api=False)
        check('lobby is available',code==200)
        check('opponent cannot configure new rooms',a.lobby(data={'action':'create','csrf':csrf,
            'setup_mode':'opponent','creation_key':os.urandom(16).hex()})[0]==400)

        key=os.urandom(16).hex()
        payload={'action':'create_bot','creation_key':key,'csrf':csrf}
        check('bad CSRF denied',a.lobby(data={**payload,'csrf':'bad'})[0]==403)
        a2=Client(33573);assert a2.post('login','Creator',password)[0]==303
        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            requests=[pool.submit(a.lobby,data=payload),pool.submit(a2.lobby,data={**payload,'csrf':a2.token('account')})]
            outcomes=[f.result() for f in requests]
        check('concurrent create returns same game',outcomes[0]==outcomes[1] and outcomes[0][0]==200)
        game=int(outcomes[0][1]['url'].split('=')[-1])
        check('real automatic preparation',state(game)['status']=='battle')
        check('separate host/player',state(game)['host_id']==1 and state(game)['player_id']==5)
        check('one persisted room for key',sql(f"USE LAB_DB;SELECT COUNT(*) FROM lobby_rooms WHERE creation_key='{key}'")=='1')
        check('outsider cannot tick bot',http(c,game,query='&ajax=battle_sync')[0]==403)
        check('virtual seat cannot be controlled via gameplay HTTP',http(virtual,game,query='&ajax=battle_sync')[0]==403)

        check('repeat create recovers existing game',a.lobby(data=payload)==outcomes[0])
        defaults=state(game)['settings']['lucktest']
        check('bot defaults preserve six cards and target 45',defaults['card_count']==6 and defaults['total_cost']==45)
        totals=lambda:sql('USE LAB_DB;SELECT COUNT(*) FROM games;SELECT COUNT(*) FROM lobby_rooms;SELECT COUNT(*) FROM battle_command_journal;')
        baseline=totals()
        scenario=json.dumps({'cards':[{'owner':'host','ukid':'card_8'},{'owner':'player','ukid':'card_9'}],
                             'deck':[{'owner':'host','ukid':'card_10'},{'owner':'player','ukid':'card_11'}]})
        for overrides in [{'lucktest_card_count':'11'}, {'lucktest_total_cost':'61'}, {'lucktest_start_stage':'place'},
                          {'handicap_gold':'6'}, {'lucktest_balanced':'2'}, {'setup_mode':'system'},
                          {'lucktest_placement':'smart','lucktest_scenario':scenario},
                          {'lucktest_scenario':scenario.replace('card_8','missing_card')}]:
            check('invalid bot setup rolls back: '+next(iter(overrides)),
                  a.lobby(data={**payload,'creation_key':os.urandom(16).hex(),**overrides})[0]==400 and totals()==baseline)
        check('non-Debug account cannot submit mandatory cards',
              b.lobby(data={**payload,'csrf':b.token('account'),'creation_key':os.urandom(16).hex(),'lucktest_scenario':scenario})[0]==403
              and totals()==baseline)
        sql('USE LAB_DB;UPDATE cards SET price=2+MOD(ind,7);')
        selected={**payload,'creation_key':os.urandom(16).hex(),'lucktest_card_count':'4','lucktest_total_cost':'20',
                  'lucktest_balanced':'0','lucktest_balance_metals':'0','handicap_enabled':'1','handicap_gold':'2',
                  'handicap_silver':'1','lucktest_scenario':scenario}
        try:
            code, reply=a.lobby(data=selected)
            assert code==200,(code,reply)
            configured=state(int(reply['url'].split('=')[-1]))
            settings=configured['settings']
            check('configured match persists both balances off and selected budget',
                  settings['lucktest']['total_cost']==20 and not settings['lucktest']['balanced'] and not settings['lucktest']['balance_metals'])
            check('configured handicap persists for the opponent',
                  settings['battle']['handicap_enabled'] and settings['battle']['handicap_gold']==2 and settings['battle']['handicap_silver']==1)
            cards=list(configured['cards'].values())
            for owner, squad, reserve in [('host','card_8','card_10'),('player','card_9','card_11')]:
                field=[card for card in cards if card['owner']==owner and card['zone']=='field']
                deck=[card for card in cards if card['owner']==owner and card['zone']=='deck']
                check(owner+' gets four field cards and 26 reserve cards with mandatory picks',
                      len(field)==4 and len(deck)==26 and squad in [c['ukid'] for c in field] and reserve in [c['ukid'] for c in deck])
                if owner=='host':
                    check('host budget guides actual field cost',abs(sum(2+int(c['ukid'].split('_')[1])%7 for c in field)-20)<=2)
            check('configured retry resumes same game',a.lobby(data=selected)==(code,reply))
            check('changed settings cannot reuse the creation key',a.lobby(data={**selected,'lucktest_total_cost':'21'})[0]==409)
        finally:
            sql('USE LAB_DB;UPDATE cards SET price=1;')

        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            polls=[pool.submit(http,client,game,query='&ajax=battle_sync') for client in (a,a2)]
            responses=[f.result() for f in polls]
            if not all(r[0]==200 for r in responses):print([(r[0],r[1][:600]) for r in responses])
            check('parallel authorized polls return snapshots',all(r[0]==200 for r in responses))
        check('bot journal has at most one commit per version',sql(f"USE LAB_DB;SELECT COUNT(*) FROM (SELECT expected_version FROM battle_command_journal WHERE game_id={game} AND player_key='player' AND status='committed' GROUP BY expected_version HAVING COUNT(*)>1) q")=='0')
        v=version(game)
        resign={'cmd':'resign','expected_version':v,'command_id':'human-resign-acceptance','csrf':csrf}
        check('stale request does not save',http(a,game,{**resign,'expected_version':v-1,'command_id':'human-stale'},'&ajax=battle_command')[0]==409 and version(game)==v)
        check('fresh surrender commits exactly once',http(a,game,resign,'&ajax=battle_command')[0]==200 and version(game)==v+1)
        check('duplicate surrender preserves version',http(a,game,resign,'&ajax=battle_command')[0]==200 and version(game)==v+1)
        check('same ID with changed payload conflicts',http(a,game,{**resign,'cmd':'end_turn'},'&ajax=battle_command')[0]==409 and version(game)==v+1)
        totals=lambda:sql('USE LAB_DB;SELECT COUNT(*) FROM games;SELECT COUNT(*) FROM lobby_rooms;SELECT COUNT(*) FROM battle_command_journal;')
        before=totals()
        sql("USE LAB_DB;CREATE TRIGGER bot_reject_prepare BEFORE UPDATE ON games FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='disposable failure';")
        try:
            check('creation failure rolls room game and journal back',a.lobby(data={**payload,'creation_key':os.urandom(16).hex()})[0]>=400 and totals()==before)
        finally:
            sql('USE LAB_DB;DROP TRIGGER bot_reject_prepare;')

        sql("USE LAB_DB;UPDATE cards SET prop='{\"pathfinder_rearrange\":true}';")
        try:
            code,detail=a.lobby(data={**payload,'creation_key':os.urandom(16).hex()})
            check('mandatory preparation is neither bypassed nor partially saved',code==409 and 'подготовке' in detail['error'] and totals()==before)
        finally:
            sql("USE LAB_DB;UPDATE cards SET prop='{}';")
        code,failure=a.lobby(data={**payload,'creation_key':os.urandom(16).hex()})
        assert code==200
        failure_game=int(failure['url'].split('=')[-1])
        if state(failure_game)['battle']['active']=='host':
            opening={'cmd':'end_turn','expected_version':version(failure_game),'command_id':'human-pass-before-failure','csrf':csrf}
            assert http(a,failure_game,opening,'&ajax=battle_command')[0]==200
        assert state(failure_game)['battle']['active']=='player'
        before_state=state(failure_game)
        v=version(failure_game)
        sql("USE LAB_DB;CREATE TRIGGER bot_reject_action BEFORE UPDATE ON games FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='disposable action failure';")
        try:
            code,reply,_=http(a,failure_game,query='&ajax=battle_sync')
            check('bot rollback preserves durable state and version',code==200 and json.loads(reply)['bot_status']=='bot_stopped'
                and version(failure_game)==v and state(failure_game)==before_state)
            check('failed bot journal and rollback of movement memory',sql(f"USE LAB_DB;SELECT status FROM battle_command_journal WHERE game_id={failure_game} AND command_id='bot:{v}';SELECT bot_visited IS NULL FROM lobby_rooms WHERE game_id={failure_game};")=='failed\n1')
        finally:
            sql('USE LAB_DB;DROP TRIGGER bot_reject_action;')
        check('ordinary PvP still creates and authorizes both sides',http(a,(pvp:=match()))[0]==http(b,pvp)[0]==200)
        check('ordinary PvP has no virtual binding',sql(f"USE LAB_DB;SELECT COUNT(*) FROM lobby_rooms WHERE game_id={pvp} AND bot_account_id IS NULL")=='1')
        code,created=a.lobby(data={'action':'create','csrf':csrf,'setup_mode':'lucktest',
            'creation_key':os.urandom(16).hex(),'lucktest_start_stage':'battle'})
        assert code==200,(code,created)
        room=created['room'];assert b.act(room)[0]==200
        offered=a.lobby(room['id'])[1]['room'];assert a.act(offered,'accept')[0]==200
        pvp=a.lobby(room['id'])[1]['room']['game_id']
        v=version(pvp);assert state(pvp)['status']=='battle'
        responses=[http(client,pvp,query='&ajax=battle_sync') for client in (a,b)]
        check('PvP battle polls remain projection only here',all(r[0]==200 and 'bot_status' not in json.loads(r[1]) for r in responses)
            and version(pvp)==v)

        env=dict(os.environ,INTEGRATION_URL='http://127.0.0.1:33572',PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser,INTEGRATION_EVIDENCE=str(LAB))
        ui=subprocess.run([args.node,str(ROOT/'tools/test_bot_match_browser.cjs')],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
        (LAB/'bot-browser-output.txt').write_text(ui.stdout+ui.stderr,encoding='utf-8')
        check('Chromium bot match',ui.returncode==0)
        browser=json.loads((LAB/'bot-browser.json').read_text())
        game=browser['game']
        check('browser match persisted game_over',state(game)['status']=='game_over')
        check('bot committed from its side',int(sql(f"USE LAB_DB;SELECT COUNT(*) FROM battle_command_journal WHERE game_id={game} AND player_key='player' AND command_id LIKE 'bot:%' AND status='committed'"))>0)
        check('human never submits a bot command',sql(f"USE LAB_DB;SELECT COUNT(*) FROM battle_command_journal WHERE game_id={game} AND player_key='host' AND command_id LIKE 'bot:%'")=='0')
        fields=('id','side','command','expected_version','status','before_version','after_version')
        journal=sql(f"USE LAB_DB;SELECT command_id,player_key,command_type,expected_version,status,before_version,after_version FROM battle_command_journal WHERE game_id={game} ORDER BY id")
        (LAB/'bot-journal.jsonl').write_text(''.join(json.dumps(dict(zip(fields,line.split('\t'))))+'\n' for line in journal.splitlines()),encoding='utf-8')
        print(json.dumps(RESULTS,ensure_ascii=False))
'''
exec(compile(source[:start]+body+source[end:],str(root/'tools/test_integration_http.py'),'exec'),{'__name__':'__main__','__file__':str(root/'tools/test_integration_http.py')})
