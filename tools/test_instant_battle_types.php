<?php
declare(strict_types=1);
$root = is_file(getcwd().'/src/Core/Autoloader.php') ? getcwd() : dirname(__DIR__);
require_once $root.'/src/Core/Autoloader.php';
require_once __DIR__.'/migrations/20261115_instant_battle_types.php';
use Berserk\Core\{Autoloader,CardInstance,Command,Engine,GameState,InstantProcessor,StrikeResolver};
Autoloader::register(); Autoloader::addNamespace('Berserk\\',$root.'/src/');
function btCheck(bool $ok,string $label):void { if(!$ok) throw new RuntimeException($label); }
function btState(string $kind,array $instant):GameState {
    $s=new GameState(9909,101,202); $s->status='battle';
    $s->battle=['active'=>'host','turn'=>1,'strike'=>[
        'attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'state'=>'waiting_instant',
        'instant_phase'=>'combat','instant_priority'=>'player','combat_instant_window'=>'main',
        'instant_stack'=>[],'instant_passed'=>[],'instant_next_sequence'=>0,
        'attack_dice'=>2,'defend_dice'=>0,'result'=>['attack'=>'weak','defend'=>'','winner'=>'attack'],
    ]];
    if($kind!=='legacy') $s->battle['strike']['kind']=$kind;
    foreach([1=>['host',3,3],2=>['player',4,3],3=>['player',6,1],4=>['player',4,4]] as $id=>[$owner,$r,$c])
        $s->addCard(new CardInstance(instanceId:$id,ukid:'bt'.$id,owner:$owner,zone:'field',row:$r,col:$c,hp:20,hpMax:20,
            strikeWeak:1,strikeMedium:2,strikeStrong:3,prop:$id===3?['instants'=>[$instant]]:[]));
    return $s;
}
$specs=[
 ['key'=>'dar_sily','trigger'=>'combat','phase'=>'power','effect'=>['type'=>'strike_level','mode'=>'set','value'=>'strong'],'battle_types'=>['strike']],
 ['key'=>'flash','trigger'=>'combat','phase'=>'setter','effect'=>['type'=>'damage_cap','value'=>1],'battle_types'=>['strike','tap','magic']],
 ['key'=>'magic_trick','trigger'=>'combat','phase'=>'redirect','target'=>'adjacent_ally','effect'=>['type'=>'redirect_strike'],'battle_types'=>['strike']],
];
foreach(['legacy','strike','tap','magic','shot','throw','discharge','uchr','unknown'] as $kind) foreach($specs as $i=>$inst) {
    $allowed=in_array($kind==='legacy'?'strike':$kind,$inst['battle_types'],true);
    $s=btState($kind,$inst); $ip=new InstantProcessor($s,new Engine());
    btCheck(($ip->getSourceInstants($s->getCard(3),'before','combat')!==[])===$allowed,"source $i/$kind");
    if($i===2) {
        $s->battle['strike']['state']='waiting_defender'; $sr=new StrikeResolver($s,new Engine());
        btCheck(($sr->getMageRedirectOptions('player')!==[])===$allowed,"mage options $kind");
        if(!$allowed) {
            $before=$s->toArray(); $r=$sr->chooseMageRedirect('player',new Command('choose_mage_redirect',['card_id'=>3,'target_id'=>4]));
            btCheck(!$r->success && $s->toArray()===$before,"mage reject unchanged $kind");
        }
    } else {
        btCheck(($ip->getInstants('player','combat','combat')!==[])===$allowed,"available $i/$kind");
        $before=$s->toArray(); $r=$ip->playCombat('player',3,$inst['key']);
        btCheck($r->success===$allowed,"declare $i/$kind");
        if(!$allowed) btCheck($before===$s->toArray(),"rejection unchanged $i/$kind");
    }
}
foreach([[],['strike'],null,'strike'] as $types) {
    $inst=$specs[0]; $inst['battle_types']=$types;
    btCheck(InstantProcessor::allowsBattleType($inst,['kind'=>'strike'])===($types===['strike']),'strict filter');
}
foreach(['strike','tap','magic','shot','throw','discharge','uchr'] as $kind) {
    $inst=$specs[0]; unset($inst['battle_types']); $s=btState($kind,$inst);
    btCheck((new InstantProcessor($s,new Engine()))->playCombat('player',3,$inst['key'])->success,'absent filter preserves declaration '.$kind);
}
btCheck(InstantProcessor::allowsBattleType([],null),'absent unrestricted');
btCheck(!InstantProcessor::allowsBattleType($specs[0],null),'restricted requires context');
foreach(['s1_5','s1_20','s1_38'] as $i=>$ukid) {
    $inst=$specs[$i]; unset($inst['battle_types']);
    $original=['custom'=>['preserve'=>true],'instants'=>[['key'=>'other','trigger'=>'turn'],$inst]];
    $next=json_decode(battleTypesProp($ukid,json_encode($original)),true);
    btCheck($next['instants'][1]['battle_types']===$specs[$i]['battle_types'],'migration types');
    $again=battleTypesProp($ukid,json_encode($next)); btCheck(json_decode($again,true)===$next,'idempotent');
    unset($next['instants'][1]['battle_types']); btCheck($next===$original,'all other fields preserved');
}
echo "OK battle_types: availability, declaration, mage route, strict values, legacy context, isolated migration\n";
