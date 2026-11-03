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
        out=pathlib.Path(PROJECT_ROOT)/'docs/evidence/delegation';out.mkdir(parents=True,exist_ok=True)
        def page(client,url):
            try:r=client.opener.open(client.base.replace('auth.php',url),timeout=30)
            except urllib.error.HTTPError as e:r=e
            return r.code,r.read().decode('utf-8')
        for mode,fields,stage in [('sealed',{},'view'),('draft',{},'draft'),('solomon',{},'draft'),('system',{'deck_id':'1','other_deck_id':'1','deck_mode':'manual','other_deck_mode':'manual'},'view'),('lucktest',{'lucktest_start_stage':'turn'},'turn')]:
            code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':mode,'creation_key':os.urandom(16).hex(),**fields});check(mode+' ordinary creation',code==200)
            room=data['room'];check(mode+' not delegated',not room['conditions_pending'] and not room['can_configure']);assert b.act(room)[0]==200;pending=a.lobby(room['id'])[1]['room'];assert a.act(pending,'accept')[0]==200
            game=a.lobby(room['id'])[1]['room']['game_id'];check(mode+' ordinary stage',state(game)['status']==stage)
        code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'opponent','creation_key':os.urandom(16).hex()});room=data['room'];assert b.act(room)[0]==200;pending=a.lobby(room['id'])[1]['room']
        for client in [a,b,c]:check('wizard unavailable before approval',page(client,'lobby.php?room='+str(room['id'])+'&configure=1')[0]==403)
        assert a.act(pending,'accept')[0]==200;ready=b.lobby(room['id'])[1]['room']
        check('approved wizard available',page(b,'lobby.php?room='+str(room['id'])+'&configure=1')[0]==200)
        for client in [a,c]:check('wizard forbidden to non-configurator',page(client,'lobby.php?room='+str(room['id'])+'&configure=1')[0]==403)
        code,data=b.lobby(room['id'],{'action':'configure','csrf':b.token('account'),'version':ready['version'],'candidate_request_id':ready['candidate_request_id'],'setup_mode':'sealed'});check('save accepted',code==200)
        check('reload completed wizard recovers game',page(b,'lobby.php?room='+str(room['id'])+'&configure=1')[0]==303)
        for target,client,player_id in [('place',b,2),('battle',b,2),('battle',c,3)]:
            code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'opponent','creation_key':os.urandom(16).hex()});room=data['room'];assert client.act(room)[0]==200;pending=a.lobby(room['id'])[1]['room'];assert a.act(pending,'accept')[0]==200;ready=client.lobby(room['id'])[1]['room']
            code,data=client.lobby(room['id'],{'action':'configure','csrf':client.token('account'),'version':ready['version'],'candidate_request_id':ready['candidate_request_id'],'setup_mode':'lucktest','lucktest_start_stage':target,'handicap_enabled':'1','handicap_gold':'2','handicap_silver':'3'})
            check('delegated quick start '+target+str(player_id),code==200)
            snap=state(data['room']['game_id']);check('quick stage and identity',snap['status']==target and snap['host_id']==1 and snap['player_id']==player_id)
            check('quick handicap targets actual opponent',snap['settings']['battle']['handicap_gold']==2 and snap['settings']['battle']['handicap_silver']==3)
        code,data=a.lobby(data={'action':'create','csrf':a.token('account'),'setup_mode':'opponent','creation_key':os.urandom(16).hex()});room=data['room'];assert b.act(room)[0]==200;assert a.act(a.lobby(room['id'])[1]['room'],'accept')[0]==200;ready=b.lobby(room['id'])[1]['room']
        payload={'action':'configure','csrf':b.token('account'),'version':ready['version'],'candidate_request_id':ready['candidate_request_id'],'setup_mode':'system','deck_mode':'random','other_deck_mode':'random'}
        code,data=b.lobby(room['id'],payload);check('delegated random system decks',code==200);game=data['room']['game_id']
        sql("USE LAB_DB;UPDATE decks SET status='inactive' WHERE ind=1;")
        code,data=b.lobby(room['id'],payload);check('committed result restores without reroll or catalog revalidation',code==200 and data['room']['game_id']==game)
        sql("USE LAB_DB;UPDATE decks SET status='active' WHERE ind=1;")
        (out/'regression-checks.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(RESULTS,ensure_ascii=False,indent=2))
'''

tail="\nsource=source[:a]+body+source[b:]\nexec(compile(source,str(ROOT/'tools/test_integration_http.py'),'exec'),{'__name__':'__main__','__file__':str(WORK/'tools/test_integration_http.py'),'PROJECT_ROOT':str(PROJECT)})"
exec(compile(head+'\nbody='+repr(body)+tail,str(ROOT/'tools/test_unified_menu_http.py'),'exec'),{'__name__':'__main__','__file__':str(ROOT/'tools/test_unified_menu_http.py')})
