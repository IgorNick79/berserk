<?php
declare(strict_types=1);
require_once getcwd().'/src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,CardInstance,Command,Dice,Engine,GameState,SingleRoll,InstantProcessor,BattleWaitResolver};
Autoloader::register();Autoloader::addNamespace('Berserk\\',getcwd().'/src/');
set_error_handler(static function($n,$m,$f,$l){if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
$checks=0;
function c1check(bool $ok,string $label):void {global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
function c1state(string $type,bool $windows=true):GameState {
    $s=new GameState(9919,101,202);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];
    foreach([1=>['host',3,3],2=>['player',in_array($type,['uchr','shot','throw','discharge'],true)?5:4,3],3=>['host',1,1],4=>['player',6,5]] as $id=>[$owner,$row,$col])
        $s->addCard(new CardInstance(instanceId:$id,ukid:'c1_'.$id,owner:$owner,zone:'field',row:$row,col:$col,hp:19,hpMax:20,strikeWeak:1,strikeMedium:2,strikeStrong:3,
            prop:$id>=3&&$windows?['instants'=>[['key'=>'test-heal','trigger'=>'combat','phase'=>'dice','target'=>'self','effect'=>['type'=>'heal','value'=>1]]]]:[]));
    $s->getCard(1)->closed=true;$s->getCard(1)->coins=2;return $s;
}
function c1start(GameState $s,string $type):void {
    $_SESSION=['debug_roll'=>'4,6,2'];Dice::init();
    $r=(new SingleRoll($s,new Engine()))->begin($s->getCard(1),$s->getCard(2),$type,
        ['type'=>$type,'range'=>6,'strike'=>['weak'=>1,'medium'=>2,'strong'=>3]],$type==='uchr'?'uchr':'damage');
    c1check($r->success,'start '.$type);
}
if (defined('C1_FIXTURES_ONLY')) return;
foreach(['tap','magic','shot','throw','discharge','uchr'] as $type) {
    $plain=c1state($type,false);c1start($plain,$type);$expected=$plain->getCard(2)->hp;
    c1check(empty($plain->battle['single_roll']),'no stop without admitted instant');
    $s=c1state($type);c1start($s,$type);
    c1check(isset($s->battle['single_roll'])&&empty($s->battle['strike']),'own roll context, no artificial combat');
    c1check($s->getCard(2)->hp===19,'damage suspended');
    c1check(!array_key_exists('defend_dice',$s->battle['single_roll']),'no fictitious defender die');
    c1check($s->battle['single_roll']['original_dice']===4&&$s->battle['single_roll']['attack_dice']===4,'saved die');
    $remaining=Dice::remaining();
    $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));
    $ip=new InstantProcessor($s,new Engine());$before=$s->toArray();
    c1check(!$ip->passCombat('player')->success&&$s->toArray()===$before,'foreign priority rejected');
    c1check($ip->passCombat('host')->success&&isset($s->battle['single_roll']),'first pass transfers');
    c1check($ip->passCombat('player')->success&&empty($s->battle['single_roll']),'second pass resumes');
    c1check($s->getCard(2)->hp===$expected,'same result without window '.$type.' expected '.$expected.' actual '.$s->getCard(2)->hp.' '.json_encode($s->battle['strike']??null));
    c1check(Dice::remaining()===$remaining,'no repeated roll');
    c1check($s->getCard(1)->coins===2,'no repeated payment');
    $hp=$s->getCard(2)->hp;c1check(!(new SingleRoll($s,new Engine()))->resume()->success&&$s->getCard(2)->hp===$hp,'duplicate resume inert');
    $s=c1state($type);c1start($s,$type);$s->getCard(2)->hp=0;
    $ip=new InstantProcessor($s,new Engine());$ip->passCombat('host');$ip->passCombat('player');
    c1check(empty($s->battle['single_roll'])&&empty($s->battle['strike']),'dead target cancels without applying tail');
}
// A real native stack item (technical healing fixture, not a C2 card adapter).
$s=c1state('shot');c1start($s,'shot');$e=new Engine();
$r=$e->apply($s,'host',new Command('combat_instant_play',['card_id'=>3,'instant_key'=>'test-heal']));
c1check($r->success,'native declaration');
$r=$e->apply($s,'host',new Command('combat_instant_pass'));c1check($r->success,'native first pass');
$r=$e->apply($s,'player',new Command('combat_instant_pass'));c1check($r->success,'native second pass');
c1check(($s->battle['single_roll']['stage']??null)==='results'&&$s->getCard(2)->hp===19,'nonempty stack awaits ACK');
foreach(['host','player'] as $actor) c1check($e->apply($s,$actor,new Command('confirm_strike',['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success,'native result ACK');
c1check(empty($s->battle['single_roll'])&&$s->getCard(3)->hp===20&&$s->getCard(2)->hp===17,'native stack resolves then attack once');
// Automatic pass retains the existing priority contract.
$s=c1state('shot');$s->getCard(4)->prop=[];c1start($s,'shot');
c1check($s->battle['single_roll']['instant_passed']===['player'],'automatic pass');
(new InstantProcessor($s,new Engine()))->passCombat('host');
c1check(empty($s->battle['single_roll'])&&$s->getCard(2)->hp===17,'automatic plus explicit pass resumes');
foreach(['source_dead','target_zone','target_owner','target_range','terminal'] as $case) {
    $s=c1state('shot');c1start($s,'shot');
    if($case==='source_dead')$s->getCard(1)->hp=0;
    if($case==='target_zone')$s->getCard(2)->zone='graveyard';
    if($case==='target_owner')$s->getCard(2)->owner='host';
    if($case==='target_range'){$s->getCard(2)->row=3;$s->getCard(2)->col=4;}
    if($case==='terminal'){$s->status='game_over';$s->winner='player';}
    $hp=$s->getCard(2)->hp;(new SingleRoll($s,new Engine()))->resume();
    c1check(empty($s->battle['single_roll'])&&$s->getCard(2)->hp===$hp,'safe cancellation '.$case);
}
$s=c1state('shot');$parent=['phase'=>'turn','priority'=>'host','stack'=>[],'continuation'=>['type'=>'test-parent']];
$s->battle['turn_instant_stack']=$parent;
$r=(new \Berserk\Core\ActionResolver($s,new Engine()))->triggeredRanged($s->getCard(1),$s->getCard(2),
    ['type'=>'shot','range'=>6,'strike'=>['weak'=>2,'medium'=>2,'strong'=>2]]);
c1check($r->success&&empty($s->battle['single_roll'])&&$s->battle['turn_instant_stack']===$parent,'nested triggered attack preserves parent and opens no independent stack');
$s=c1state('shot');c1start($s,'shot');$e=new Engine();$before=$s->getCard(1)->coins;
c1check(!$e->apply($s,'host',new Command('gain_coin',['card_id'=>1]))->success&&$s->getCard(1)->coins===$before,'unrelated action blocked');
$s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'expired','remaining_seconds'=>0,'anchor_at'=>null,'pause_reason'=>null];
c1check($e->apply($s,'host',new Command('combat_instant_pass'))->success,'expired timer permits continuation pass');
c1check($e->apply($s,'player',new Command('combat_instant_pass'))->success&&empty($s->battle['single_roll']),'expired timer finishes saved action');
$s=c1state('shot');unset($s->battle['strike']);$before=$s->toArray();
(new InstantProcessor($s,new Engine()))->getSourceInstants($s->getCard(3),'before','combat');
c1check($s->toArray()===$before,'context lookup is read-only without a strike key');
echo "Single roll C1: $checks checks passed\n";
