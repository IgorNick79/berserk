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
        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/lucktest-cost';out.mkdir(parents=True,exist_ok=True)
        sql('USE LAB_DB;'+(ROOT/'tools/migrations/20261030_account_ui_preferences.sql').read_text())
        def create(fields):
            return a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'system','creation_key':os.urandom(16).hex(),**fields})
        def accept(room):
            assert b.act(room)[0]==200
            pending=a.lobby(room['id'])[1]['room']
            result=a.act(pending,'accept')
            return result,a.lobby(room['id'])[1]['room']
        def setup(room):return json.loads(sql(f"USE LAB_DB;SELECT setup_json FROM lobby_rooms WHERE id={room['id']};"))
        manual={'deck_mode':'manual','other_deck_mode':'manual','deck_id':'1','other_deck_id':'1'}
        code,data=create(manual);check('manual choices accepted',code==200)
        room=data['room'];saved=setup(room)
        check('four choices persisted',saved['system_decks']=={'deck_mode':'manual','other_deck_mode':'manual','deck_id':1,'other_deck_id':1})
        check('room reload preserves settings',setup(a.lobby(room['id'])[1]['room'])==saved)
        result,room=accept(room);check('auto selection reaches view',result[0]==200 and state(room['game_id'])['status']=='view')
        game=room['game_id'];initial=state(game)
        check('both decks assigned',initial['players']['host']['deck_id']==initial['players']['player']['deck_id']==1)
        check('instances initialized',len(initial['cards'])==60)
        check('view remains unconfirmed',not initial['players']['host']['confirmed'] and not initial['players']['player']['confirmed'])
        again=a.act(room,'accept');check('repeat acceptance cannot create or reinitialize game',a.lobby(room['id'])[1]['room']['game_id']==game and state(game)==initial)
        prep(a,game,'confirm_view');check('host confirms existing view',state(game)['status']=='view')
        prep(b,game,'confirm_view');check('both confirmations reach roll',state(game)['status']=='turn')
        for fields in [{'deck_mode':'random','other_deck_mode':'random'}, {'deck_mode':'manual','deck_id':'1','other_deck_mode':'random'}]:
            code,data=create(fields);check('random or mixed accepted',code==200)
            result,room=accept(data['room']);check('random or mixed resolves via processor',result[0]==200 and state(room['game_id'])['status']=='view')
        for fields in [dict(manual,deck_id='999'),dict(manual,deck_mode='bad'),dict(manual,deck_id='0'),dict(manual,deck_id='-1')]:
            check('invalid choice rejected '+str(fields),create(fields)[0]==400)
        code,data=create(manual);room=data['room'];assert b.act(room)[0]==200;pending=a.lobby(room['id'])[1]['room']
        sql("USE LAB_DB;UPDATE decks SET status='inactive' WHERE ind=1;")
        count=sql('USE LAB_DB;SELECT COUNT(*) FROM games;');check('removed deck rejects acceptance',a.act(pending,'accept')[0]==400)
        check('failed acceptance rolls back game',count==sql('USE LAB_DB;SELECT COUNT(*) FROM games;') and a.lobby(room['id'])[1]['room']['game_id'] is None)
        sql("USE LAB_DB;UPDATE decks SET status='active' WHERE ind=1;")
        legacy=match();prep(a,legacy,'choose_mode',mode='system')
        check('legacy system retains deck stage',state(legacy)['status']=='deck')
        prep(a,legacy,'select_deck',**manual);check('legacy deck selector still works',state(legacy)['status']=='view' and len(state(legacy)['cards'])==60)
        for mode,fields in [('draft',{'boosters':'5','draft_timer_mode':'unlimited'}),('solomon',{'boosters':'5','stack_size':'8'}),('sealed',{'sealed_boosters':'3'}),('lucktest',{})]:
            if mode!='lucktest':fields.update(booster_common='7',booster_uncommon='4',booster_rare_slots='1',booster_rare_chance='70')
            code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':mode,'creation_key':os.urandom(16).hex(),**fields})
            check(mode+' settings saved',code==200 and setup(data['room'])['mode']==mode)
            result,room=accept(data['room']);check(mode+' preparation starts',result[0]==200)
            if mode!='lucktest':
                persisted=setup(room)['settings']['booster'];actual=state(room['game_id'])['settings']['booster']
                check(mode+' custom composition persisted and applied',persisted==actual and actual=={'common':7,'uncommon':4,'rare_slots':1,'ultra_rare_chance':30})
        for start in ['turn','place','battle']:
            for balanced,metals in [('0','1'),('1','0')]:
                code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'lucktest','creation_key':os.urandom(16).hex(),'lucktest_start_stage':start,'lucktest_balanced':balanced,'lucktest_balance_metals':metals})
                check(start+' room created',code==200)
                result,room=accept(data['room']);check(start+' enters requested stage',result[0]==200 and state(room['game_id'])['status']==start)
                settings=state(room['game_id'])['settings']['lucktest'];check(start+' independent flags persisted',settings['balanced']==(balanced=='1') and settings['balance_metals']==(metals=='1'))
                if start=='turn':
                    game=room['game_id'];prep(a,game,'confirm_turn');prep(b,game,'confirm_turn');first=state(game)['first_player'];prep(a if first=='host' else b,game,'choose_side',side='1');check('manual roll reaches place',state(game)['status']=='place')
                if start!='battle':
                    game=room['game_id']
                    for client,owner,cells in [(a,'host',[(1,2),(1,3),(1,4),(2,2),(2,3),(2,4)]),(b,'player',[(4,1),(4,2),(4,3),(4,4),(4,5),(5,2)])]:
                        cards=[card for card in state(game)['cards'].values() if card['owner']==owner and card['zone']=='squad']
                        for card,(row,col) in zip(cards,cells):prep(client,game,'place_card',card_id=card.get('instance_id',card.get('id')),row=row,col=col)
                        prep(client,game,'confirm_place')
                    check(start+' manual placement reaches battle',state(game)['status']=='battle')
        conf=ROOT/'config/auth.local.php';conf.write_text(conf.read_text().replace("'allow_loopback_http'=>true", "'debug_account_ids'=>[1,2],'allow_loopback_http'=>true"))
        for invalid in [{'lucktest_start_stage':'invalid'},{'lucktest_balance_metals':'yes'},{'lucktest_start_stage':'place','lucktest_scenario':'{"cards":[{"owner":"host","ukid":"card_1"}]}'}]:
            code,_=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'lucktest','creation_key':os.urandom(16).hex(),**invalid});check('invalid lucktest rejected '+str(invalid),code==400)
        code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'lucktest','creation_key':os.urandom(16).hex(),'lucktest_start_stage':'battle','lucktest_url_roll':'1','lucktest_scenario':'{"cards":[{"owner":"host","ukid":"card_1"}]}'});check('debug fields accepted',code==200)
        result,room=accept(data['room']);check('debug scenario still starts battle',result[0]==200 and state(room['game_id'])['status']=='battle')
        sql('USE LAB_DB;UPDATE cards SET price=3+MOD(ind,7);')
        examples=[]
        for count in range(3,11):
            for budget in [10,60] if count!=6 else [10,45,60]:
                code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'lucktest','creation_key':os.urandom(16).hex(),'lucktest_card_count':str(count),'lucktest_total_cost':str(budget),'lucktest_start_stage':'turn'})
                check('cost room created '+str((count,budget)),code==200)
                saved=setup(data['room'])['settings']['lucktest'];check('total budget persisted',saved['total_cost']==budget and saved['card_count']==count)
                result,room=accept(data['room']);check('total cost generation accepted',result[0]==200)
                snapshot=state(room['game_id']);totals={}
                for owner in ['host','player']:
                    cards=[c for c in snapshot['cards'].values() if c['owner']==owner and c['zone']=='squad']
                    check('actual requested squad count',len(cards)==count);totals[owner]=sum(c['price'] for c in cards)
                examples.append({'count':count,'target':budget,**totals})
        (out/'http-generation-examples.json').write_text(json.dumps(examples,indent=2),encoding='utf-8')
        sql('USE LAB_DB;DELETE FROM auth_login_attempts;')
        env=dict(os.environ,UNIFIED_MENU_URL='http://127.0.0.1:33572',UNIFIED_MENU_EVIDENCE=str(out),PLAYWRIGHT_MODULE=args.playwright,BROWSER_EXECUTABLE=args.browser)
        for script in ['test_wizard_browser.cjs','test_wizard_settings_browser.cjs','test_lucktest_wizard_browser.cjs']:
            ui=subprocess.run([args.node,str(ROOT/'tools'/script)],env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
            (out/(script+'.txt')).write_text(ui.stdout+ui.stderr,encoding='utf-8');check(script,ui.returncode==0)
        (out/'http-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8')
        print(json.dumps(RESULTS,ensure_ascii=False,indent=2))
'''
tail="\nsource=source[:a]+body+source[b:]\nexec(compile(source,str(ROOT/'tools/test_integration_http.py'),'exec'),{'__name__':'__main__','__file__':str(WORK/'tools/test_integration_http.py'),'PROJECT_ROOT':str(PROJECT)})"
exec(compile(head+'\nbody='+repr(body)+tail,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
