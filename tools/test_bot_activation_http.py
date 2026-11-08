"""Recheck activation in an isolated copy. Never use a configured server."""
import os
from pathlib import Path

root = Path(__file__).resolve().parents[1]
assert root.is_relative_to(Path(os.environ['TEMP']).resolve())
source = (root/'tools/test_bot_match_http.py').read_text(encoding='utf-8')
# Apply the new DDL after creating an ordinary match with the original schema.
source = source.replace(",'20261108_bot_matches.sql'", "")
start = "        csrf=a.token('account')"
probe = r'''
        ordinary = match()
        before = (state(ordinary), version(ordinary))
        migration = (ROOT/'tools/migrations/20261108_bot_matches.sql').read_text()
        columns = lambda: sql("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='LAB_DB' AND TABLE_NAME='lobby_rooms' AND COLUMN_NAME IN ('bot_account_id','bot_visited','bot_diagnostic') ORDER BY COLUMN_NAME")
        check('no bot columns before applying migration', columns()=='')
        sql('USE LAB_DB;'+migration)
        check('migration preserves existing PvP JSON and version', (state(ordinary),version(ordinary))==before)
        check('all three activation columns present', columns()=='bot_account_id\nbot_diagnostic\nbot_visited')
        try:
            sql('USE LAB_DB;'+migration)
        except AssertionError as error:
            check('raw replay fails with duplicate column and leaves existing match intact',
                'Duplicate column' in str(error) and (state(ordinary),version(ordinary))==before)
        else:
            raise AssertionError('Expected duplicate-column safeguard for raw DDL')
        # The documented runner skips the entire ALTER when the three exact columns already exist.
        if not columns():
            sql('USE LAB_DB;'+migration)
        check('idempotent preflight skip preserves existing match', (state(ordinary),version(ordinary))==before)
        path=ROOT/'config/auth.local.php'
        normal=path.read_text()
        count_games=lambda:sql('USE LAB_DB;SELECT COUNT(*) FROM games;SELECT COUNT(*) FROM lobby_rooms;')
        before_counts=count_games()
        try:
            for label,value,status in [('disabled','null',503),('wrong type',"'5'",503),('missing account','999999',503),('human account','1',400)]:
                path.write_text(normal.replace("'bot_account_id'=>5", "'bot_account_id'=>"+value))
                code,reply=a.lobby(data={'action':'create_bot','creation_key':os.urandom(16).hex(),'csrf':a.token('account')})
                check('configuration '+label+' fails closed',code==status and 'error' in reply and count_games()==before_counts)
        finally:
            path.write_text(normal)
        check('pre-migration PvP still authorizes both participants',http(a,ordinary)[0]==http(b,ordinary)[0]==200)
'''
assert source.count(start)==1
source=source.replace(start,probe+start)
source=source.replace("'tools/test_bot_match_browser.cjs'","'tools/test_bot_activation_browser.cjs'")
end = '        print(json.dumps(RESULTS,ensure_ascii=False))'
assert source.count(end)==1
source=source.replace(end,'''
        configured_ui=json.loads((LAB/'bot-configured-browser.json').read_text())
        ui_state=state(configured_ui['game'])
        check('Edge form persists chosen count budget balance and handicap',
              ui_state['settings']['lucktest']['card_count']==4 and ui_state['settings']['lucktest']['total_cost']==20
              and not ui_state['settings']['lucktest']['balance_metals'] and ui_state['settings']['battle']['handicap_gold']==1)
        check('Edge scenario materializes mandatory field and reserve picks on both sides',
              all(any((card['owner'],card['zone'],card['ukid'])==expected for card in ui_state['cards'].values())
                  for expected in [('host','field','card_8'),('player','field','card_9'),('host','deck','card_10'),('player','deck','card_11')]))
        if os.environ.get('BERSERK_BOT_NATURAL') == '1':
            natural=subprocess.run([args.node,str(ROOT/'tools/test_bot_activation_browser.cjs'),'--elimination'],
                env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=240)
            (LAB/'natural-browser-output.txt').write_text(natural.stdout+natural.stderr,encoding='utf-8')
            if natural.returncode==0:
                observed=json.loads((LAB/'natural-browser.json').read_text(encoding='utf-8'))
                persisted=state(observed['game'])
                observed['persisted_status']=persisted['status']
                observed['persisted_reason']=persisted['battle'].get('result_reason')
                (LAB/'natural-browser.json').write_text(json.dumps(observed,ensure_ascii=False),encoding='utf-8')
                if observed['stopped']=='game_over':
                    check('browser elimination matches durable reason',observed['persisted_status']=='game_over'
                        and observed['persisted_reason']=='elimination')
'''+end+'''
        if os.environ.get('BERSERK_BOT_MANUAL') == '1':
            input('Local playtest: http://127.0.0.1:33572/lobby.php . Press Enter to stop the disposable lab. ')
''')

exec(compile(source, str(root/'tools/test_bot_match_http.py'), 'exec'),
     {'__name__':'__main__','__file__':str(root/'tools/test_bot_match_http.py')})
