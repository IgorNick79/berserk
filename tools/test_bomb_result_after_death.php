<?php
declare(strict_types=1);
ob_start();require __DIR__.'/test_battle_result_coordinates_ui.php';ob_end_clean();
use Berserk\Core\{ActionResolver,CardInstance,Command,Engine,ZoneManager};
foreach([1,7] as $hp){
    $s=resultCoordsState('bomb_shot',null,null);$s->battle['strike']=null;
    $attacker=$s->getCard(1);$target=$s->getCard(2);$attacker->row=3;$target->row=4;$target->hp=$hp;
    $attacker->prop=['actions'=>[['key'=>'bomb','type'=>'bomb_shot','name'=>'Бомба','value'=>1,'bomb_damage'=>2,'range'=>2]]];
    $result=(new ActionResolver($s,new Engine()))->handle('host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'bomb']));
    resultCoordsAssert($result->success,$result->error??'Bomb failed');
    resultCoordsAssert($s->battle['strike']['bomb']['row']===4&&$s->battle['strike']['bomb']['col']===3,'Bomb result lost original cell');
    resultCoordsAssert(count(ZoneManager::markersAt($s,'4_3'))===1,'Bomb stays on original cell');
    resultCoordsAssert($hp===1?$target->zone===CardInstance::ZONE_GRAVEYARD:$target->zone===CardInstance::ZONE_FIELD,'Target death/survival fixture');
    foreach(['host','player'] as $viewer){
        $html=resultCoordsRender($s,$viewer);
        $badges=resultCoordsBadges($html);
        $expected=['[I:3]','coords coords--'.($viewer==='host'?'enemy':'own')];
        resultCoordsAssert(in_array($expected,$badges,true),'InfoPanel must show original bomb coordinates after damage');
    }
}
echo "PASS: actual lethal/nonlethal bomb action, original marker/result cell and InfoPanel coordinates for both viewers.\n";
