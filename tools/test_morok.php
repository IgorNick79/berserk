<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, CardInstance, CardStats, Command, Dice, EffectiveFeatures, Engine, GameState, InstantProcessor, StrikeResolver, ZoneManager};
use Berserk\View\Ui\{ActiveCardEffects, LiveBattleCard};
Autoloader::register(); Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
set_error_handler(static function(int $n,string $m,string $f,int $l):bool {
    if (error_reporting() & $n) throw new ErrorException($m,0,$n,$f,$l);
    return false;
});
$checks=0;
function checkMorok(bool $ok,string $message):void { global $checks; ++$checks; if (!$ok) throw new RuntimeException($message); }
function morokProp():array { return json_decode(file_get_contents(__DIR__.'/../docs/morok.prop.json'),true,512,JSON_THROW_ON_ERROR); }
function morokCard(int $id,string $owner,int $row,int $col,array $prop=[]):CardInstance {
    return new CardInstance(instanceId:$id,ukid:'arbitrary_'.$id,owner:$owner,zone:'field',row:$row,col:$col,hp:20,hpMax:20,
        strikeWeak:2,strikeMedium:4,strikeStrong:6,move:1,moveMax:1,prop:$prop);
}
function morokState():GameState {
    $s=new GameState(-26026,101,202); $s->status='battle';
    $s->battle=['active'=>'host','turn'=>1,'hidden_row_revealed'=>true];
    $s->addCard(morokCard(1,'host',3,3)); $s->addCard(morokCard(2,'player',3,4,morokProp()));
    return $s;
}
function morokReload(GameState $s):GameState { return GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR)); }
function morokFrame(GameState $s,?int $defender=null):void {
    $s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>$defender,'state'=>'results','confirmed'=>[]];
}
function morokHit(GameState $s,string $attack='weak',string $defend='',bool $decrease=false):void {
    (new StrikeResolver($s,new Engine()))->apply(['attack'=>$attack,'defend'=>$defend,'winner'=>'attack'],$decrease);
}
function morokRoll(string $rolls):void { $_SESSION=['debug_roll'=>$rolls]; Dice::init(); }
function morokAura(GameState $s,CardInstance $c,bool $expected,string $label):void {
    checkMorok(CardStats::hasHorizontalStrikeReduction($s,$c)===$expected,$label.' gameplay');
    checkMorok(str_contains(implode(' ',ActiveCardEffects::forCard($c,$s)),'Защитная аура')===$expected,$label.' marker');
    $html=LiveBattleCard::render($c,$s,'host',false,'Card','20/20');
    checkMorok(str_contains($html,'Защитная аура')===$expected,$label.' rendered effect');
}
// Existing ranged path: once only, clamp at zero, no overlap with OVZ or other wounds.
foreach (['shot','throw','discharge','magic','tap','cast'] as $type) {
    foreach ([0,1,3] as $value) foreach ([false,true] as $closed) {
        $s=morokState(); $s->getCard(2)->closed=$closed;
        // Keep this matrix specific to ranged reduction, independently of the closed setter.
        unset($s->getCard(2)->prop['closed_strike_value']);
        $s->getCard(1)->prop=['actions'=>[['key'=>'probe','type'=>$type,'value'=>$value,'range'=>3,'near_shot'=>true]]];
        morokRoll('6'); $r=(new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'probe']));
        checkMorok($r->success,'Action '.$type.': '.$r->error);
        $expected=in_array($type,['shot','throw'],true)?max(0,$value-1):$value;
        checkMorok($s->getCard(2)->hp===20-$expected,"$type $value closed=$closed reduction once");
    }
}
$s=morokState(); (new Engine())->applyDamage($s,$s->getCard(2),3,'impact',$s->getCard(1));
checkMorok($s->getCard(2)->hp===17,'Direct wounds bypass ranged/strike passives');
// Nominated defender only, never attacker, ordinary target, closed defender, or ranged.
foreach ([false,true] as $nominated) foreach ([false,true] as $closed) {
    $s=morokState(); $s->getCard(2)->closed=$closed; morokFrame($s,$nominated?2:null); morokHit($s);
    checkMorok($s->getCard(2)->hp===20-($closed?1:($nominated?1:2)),'Defender and closed protections exclusive');
    checkMorok(($s->battle['strike']['attack_reduction']??0)===($nominated&&!$closed?1:0),'Only active nominated defender reduces');
}
$s=morokState();$s->getCard(1)->prop=morokProp();$s->getCard(2)->prop=[];morokFrame($s);morokHit($s,'','weak');
checkMorok($s->getCard(1)->hp===18,'Attacking Morok has no defender reduction');
// Clarified rule: even a zero-valued successful hit becomes exactly 1. Miss remains miss.
foreach ([0,1,2,7] as $value) {
    $s=morokState();$s->getCard(1)->strikeWeak=$value;$s->getCard(2)->closed=true;morokFrame($s);morokHit($s);
    checkMorok($s->getCard(2)->hp===19,'Closed numeric setter '.$value.' -> 1');
    checkMorok($s->battle['strike']['final']['attack']==='weak','Setter does not rewrite level');
    morokHit($s);checkMorok($s->getCard(2)->hp===19,'Damage not replayed');
}
$s=morokState();$s->getCard(2)->closed=true;morokFrame($s);morokHit($s,'');checkMorok($s->getCard(2)->hp===20,'Miss not converted to hit');
// Real roll path: the queued second die must remain unused for a closed target.
$s=morokState();$s->getCard(2)->closed=true;morokFrame($s);morokRoll('6,5');
(new StrikeResolver($s,new Engine()))->resolve();
checkMorok($s->battle['strike']['defend_dice']===0,'Closed target has no defense die');
checkMorok(Dice::remaining()===[5],'Closed target consumes no defense roll');
checkMorok($s->getCard(2)->hp===19,'Real closed strike path');
// Geometry, ownership, closed/open source, stacked recipients, and field edges.
foreach ([false,true] as $closed) foreach (['host','player'] as $owner) {
    foreach ([[3,2,true],[3,4,true],[2,3,false],[4,3,false],[2,2,false],[4,4,false],[3,1,false],[3,5,false]] as [$r,$c,$yes]) {
        $s=morokState();$source=$s->getCard(2);$source->row=3;$source->col=3;$source->closed=$closed;
        $target=$s->getCard(1);$target->owner=$owner;$target->row=$r;$target->col=$c;
        morokAura($s,$target,$yes,"Geometry $r,$c $owner closed=$closed");
    }
}
foreach ([1,5] as $edge) {
    $s=morokState();$s->getCard(2)->col=$edge;$s->getCard(1)->col=$edge===1?2:4;
    morokAura($s,$s->getCard(1),true,'Edge inward');$s->getCard(1)->col=$edge===1?5:1;
    morokAura($s,$s->getCard(1),false,'No edge wrap');
}
$s=morokState();$s->getCard(2)->col=3;$s->getCard(1)->col=2;$s->addCard(morokCard(3,'player',3,2));
foreach ([1,3] as $id) morokAura($s,$s->getCard($id),true,'Stacked recipient '.$id);
// Dynamic projection: movement, arrival, multiple providers, death/leave, suppression/restore, reload.
$s=morokState();$s->getCard(2)->col=2;$s->getCard(1)->col=3;$s->addCard(morokCard(3,'host',3,4,morokProp()));
morokAura($s,$s->getCard(1),true,'Two sources');
checkMorok(count(ActiveCardEffects::forCard($s->getCard(1),$s))===1,'Overlapping aura has one marker');
(new ZoneManager($s))->toHand($s->getCard(2));morokAura($s,$s->getCard(1),true,'One source left');
$s=morokReload($s);morokAura($s,$s->getCard(1),true,'Reload with remaining source');
$s->getCard(3)->col=5;morokAura($s,$s->getCard(1),false,'Source moved away');
$s->getCard(1)->col=4;morokAura($s,$s->getCard(1),true,'Recipient moved adjacent');
$s->addCard(morokCard(4,'player',3,4));morokAura($s,$s->getCard(4),true,'New stacked arrival');
$s->getCard(3)->dying=true;morokAura($s,$s->getCard(1),false,'Dying source');
$s->getCard(3)->dying=false;$s->getCard(3)->hp=0;morokAura($s,$s->getCard(1),false,'Zero HP source');
$s->getCard(3)->hp=20;$raw=$s->getCard(3)->prop;
checkMorok(EffectiveFeatures::applyMemoryLoss($s,$s->getCard(1),$s->getCard(3)),'Memory loss applied');
morokAura($s,$s->getCard(4),false,'Source suppressed');
checkMorok($s->getCard(3)->prop===$raw,'Raw prop preserved');
checkMorok(CardStats::getOvz($s,$s->getCard(3))===1,'OVZ remains permanent');
checkMorok(CardStats::getDamageReduction($s,$s->getCard(1),$s->getCard(3),'shot')===0,'Ranged reduction suppressed');
$s->getCard(3)->closed=true;checkMorok(CardStats::setIncomingStrikeValue($s,$s->getCard(3),6)===6,'Numeric setter suppressed');
$s=morokReload($s);morokAura($s,$s->getCard(4),false,'Suppressed reload');
(new ZoneManager($s))->toHand($s->getCard(1));morokAura($s,$s->getCard(4),true,'Features restored after source leaves');
(new ZoneManager($s))->toHand($s->getCard(3));morokAura($s,$s->getCard(4),false,'Last source leaves');
// Suppressing a recipient does not suppress an external provider.
$s=morokState();$s->addCard(morokCard(3,'host',5,5));EffectiveFeatures::applyMemoryLoss($s,$s->getCard(3),$s->getCard(1));
morokAura($s,$s->getCard(1),true,'Suppressed recipient retains external aura');
foreach (['flying','hand','graveyard'] as $zone) {$s=morokState();$s->getCard(2)->zone=$zone;morokAura($s,$s->getCard(1),false,'Source zone '.$zone);}
// Real damage consumes weak value (2), never numeric 1, for both attack and return strike.
$s=morokState();$s->getCard(2)->prop=[];$s->addCard(morokCard(3,'host',3,5,morokProp()));morokFrame($s);morokHit($s,'strong');
checkMorok($s->getCard(2)->hp===18 && $s->battle['strike']['final']['attack']==='weak','Incoming strong converted to weak');
$s=morokState();morokFrame($s);morokHit($s,'','strong');checkMorok($s->getCard(1)->hp===18,'Aura protects from return strike');
// Special strikes use their weak entry, closed numeric setter, never ranged categories.
foreach (['tap','magic'] as $type) foreach ([false,true] as $closed) {
    $s=morokState();$s->getCard(2)->prop=$closed?morokProp():[];$s->getCard(2)->closed=$closed;
    $s->addCard(morokCard(3,'host',3,5,morokProp()));
    $s->getCard(1)->prop=['actions'=>[['key'=>'probe','type'=>$type,'strike'=>['weak'=>2,'medium'=>4,'strong'=>6]]]];
    morokRoll('6');$r=(new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'probe']));
    checkMorok($r->success,'Special action '.$type.': '.$r->error);checkMorok($s->getCard(2)->hp===($closed?19:18),'Special weak/setter '.$type);
}
// Existing instant phases: power -> value -> setter; passives precede wound consequences.
foreach ([false,true] as $closed) {
    $s=morokState();$s->getCard(2)->closed=$closed;
    if (!$closed) {$s->getCard(2)->prop=[];$s->addCard(morokCard(3,'player',3,5,morokProp()));}
    morokFrame($s);$s->battle['strike']+=['attack_dice'=>6,'defend_dice'=>0,'attack_mod'=>0,'defend_mod'=>0,
        'result'=>['attack'=>'strong','defend'=>'','winner'=>'attack'],'instant_phase'=>'combat','instant_stack'=>[]];
    $s->addCard(morokCard(4,'host',1,1));$s->addCard(morokCard(5,'host',1,2));$s->addCard(morokCard(6,'player',6,5));
    foreach ([[4,'host','power',['type'=>'strike_level','mode'=>'set','value'=>'strong']],
              [5,'host','value',['type'=>'strike_level','mode'=>'reduce_one']],
              [6,'player','setter',['type'=>'damage_cap','value'=>1]]] as $i=>[$id,$owner,$phase,$effect]) {
        $s->battle['strike']['instant_stack'][]=['card_id'=>$id,'player'=>$owner,'phase'=>$phase,'effect'=>$effect,'label'=>$phase,'sequence'=>$i];
    }
    $s=morokReload($s);(new InstantProcessor($s,new Engine()))->resolveStack();
    checkMorok($s->getCard(2)->hp===19,'Instant phases plus passive '.$closed);
    checkMorok(array_column($s->battle['strike']['instant_summary'],'phase')===['power','value','setter'],'Phase order unchanged');
    checkMorok($s->battle['strike']['damage_cap']===1,'Resolved setter preserved');
}
// Existing own strike-reduction map still works without the new aura.
$s=morokState();$s->getCard(2)->prop=['strike_reduction'=>['strong'=>'medium']];morokFrame($s);morokHit($s,'strong');
checkMorok($s->getCard(2)->hp===16,'Existing reduction map unchanged');
// Suppression affects all unique defenses in their real resolution routes.
foreach (['shot','throw'] as $type) {
    $s=morokState();EffectiveFeatures::applyMemoryLoss($s,$s->getCard(1),$s->getCard(2));
    $s->getCard(1)->prop=['actions'=>[['key'=>'probe','type'=>$type,'value'=>3,'range'=>3,'near_shot'=>true]]];
    morokRoll('6');$r=(new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'probe']));
    checkMorok($r->success && $s->getCard(2)->hp===17,'Suppressed ranged route '.$type);
}
foreach ([false,true] as $closed) {
    $s=morokState();$s->getCard(2)->closed=$closed;EffectiveFeatures::applyMemoryLoss($s,$s->getCard(1),$s->getCard(2));
    morokFrame($s,2);morokHit($s,'strong');checkMorok($s->getCard(2)->hp===14,'Suppressed ordinary defenses '.$closed);
}
// Setter applies after value bonuses/reductions on a return hit too.
$s=morokState();$s->getCard(1)->prop=morokProp();$s->getCard(1)->closed=true;
$s->getCard(2)->prop=['ability'=>['value'=>3]];morokFrame($s);morokHit($s,'','strong');
checkMorok($s->getCard(1)->hp===19,'Closed recipient of response receives set value after bonus');
// All action types that represent strikes: numeric zero is a hit once set to 1.
foreach (['tap','magic'] as $type) {
    $s=morokState();$s->getCard(2)->closed=true;$s->getCard(1)->prop=['actions'=>[['key'=>'probe','type'=>$type,'value'=>0]]];
    morokRoll('6');$r=(new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'probe']));
    checkMorok($r->success && $s->getCard(2)->hp===19,'Zero special becomes 1 '.$type);
    $events=$s->battle['action_resolution']['notifications'];$event=end($events);
    checkMorok($event['outcome']==='hit','Zero special classified as hit '.$type);
}
// UCHR uses the existing route and category values without new pending.
foreach ([false,true] as $closed) {
    $s=morokState();$s->getCard(2)->col=5;$s->getCard(2)->closed=$closed;
    $s->getCard(2)->prop=$closed?morokProp():[];
    $s->addCard(morokCard(3,'host',3,4,morokProp()));
    $s->getCard(1)->prop=['actions'=>[['type'=>'uchr','strike'=>['weak'=>2,'medium'=>4,'strong'=>6]]]];
    morokRoll('6');$r=(new Engine())->apply($s,'host',new Command('uchr',['card_id'=>1,'target_id'=>2]));
    checkMorok($r->success,'UCHR route: '.$r->error);checkMorok($s->getCard(2)->hp===($closed?19:18),'UCHR weak/setter');
}
// A later wound bonus is not overwritten by the numeric passive.
$s=morokState();$s->getCard(2)->closed=true;
ZoneManager::addMarker($s,'3_4',['type'=>'lantern_light','source_id'=>1,'value'=>1]);
morokFrame($s);morokHit($s,'strong');checkMorok($s->getCard(2)->hp===18,'Wound-stage lantern adds after numeric setter');
// Wounds-phase instant observes the already settled value, including after reload.
$s=morokState();$s->getCard(2)->closed=true;$s->addCard(morokCard(3,'player',5,5));$s->addCard(morokCard(4,'player',4,4));
morokFrame($s);$s->battle['strike']+=['attack_dice'=>6,'defend_dice'=>0,'attack_mod'=>0,'defend_mod'=>0,
    'result'=>['attack'=>'strong','defend'=>'','winner'=>'attack'],'instant_phase'=>'combat','instant_stack'=>[
    ['card_id'=>3,'target_id'=>3,'player'=>'player','phase'=>'wounds','effect'=>['type'=>'redistribute_wounds'],'label'=>'Transfer','sequence'=>0]]];
(new InstantProcessor($s,new Engine()))->resolveStack();
checkMorok($s->getCard(2)->hp===19 && !empty($s->battle['strike']['pending_wounds_resolution']),'Wounds follow passive value');
$s=morokReload($s);$ip=new InstantProcessor($s,new Engine());$ip->resumeCombatWounds();
checkMorok(!empty($s->battle['pending_wound_transfer']),'Existing wounds pending opens');
$wt=new Berserk\Core\WoundTransferProcessor($s,new Engine());
foreach ([['chooseSource','donor_id',2],['chooseAmount','amount',1],['chooseTarget','target_id',4],['chooseTargetAmount','amount',1]] as [$method,$key,$value]) {
    $r=$wt->$method('player',new Command('test',[$key=>$value]));checkMorok($r->success,'Wounds step '.$method.': '.$r->error);
}
checkMorok($s->getCard(2)->hp===20 && $s->getCard(4)->hp===19,'Wounds transfer not overwritten by setter');

// Multiple live providers remain independent when one or both are suppressed.
$s=morokState();$s->getCard(2)->col=2;$s->getCard(1)->col=3;$s->addCard(morokCard(3,'host',3,4,morokProp()));$s->addCard(morokCard(4,'player',6,5));
EffectiveFeatures::applyMemoryLoss($s,$s->getCard(4),$s->getCard(2));morokAura($s,$s->getCard(1),true,'One of two providers suppressed');
EffectiveFeatures::applyMemoryLoss($s,$s->getCard(4),$s->getCard(3));morokAura($s,$s->getCard(1),false,'Both providers suppressed');
(new ZoneManager($s))->toHand($s->getCard(4));morokAura($s,$s->getCard(1),true,'Both providers restored');
// Known pre-existing asymmetry: damage_cap precedes response ability bonuses.
$s=morokState();$s->getCard(2)->prop=['ability'=>['value'=>3]];morokFrame($s);$s->battle['strike']['damage_cap']=1;morokHit($s,'','weak');
checkMorok($s->getCard(1)->hp===16,'Existing response cap-before-bonus order preserved');

// Result explanations come from resolved effects, not the cards' later state.
function morokResultHtml(GameState $s):string {
    $s->battle['strike'] += ['attack_dice'=>6,'defend_dice'=>0,'attack_mod'=>0,'defend_mod'=>0];
    $s->battle['strike']['result'] ??= ['attack'=>'strong','defend'=>'','winner'=>'attack'];
    $info=[];foreach ($s->cards as $c) $info[$c->ukid]=['name'=>'Card '.$c->instanceId,'health'=>20];
    return (new Berserk\View\Screen\Battle\InfoPanel(new Berserk\View\Template(__DIR__.'/../templates')))->render($s,'host','host',$info,'?game=1');
}
$s=morokState();$s->getCard(2)->closed=true;morokFrame($s);morokHit($s,'strong');
$s=morokReload($s);$s->getCard(2)->closed=false;
checkMorok(str_contains(morokResultHtml($s),'Урон по цели сведён к 1'),'Numeric explanation survives reload/opening');
$s=morokState();$s->getCard(2)->prop=[];$s->addCard(morokCard(3,'host',3,5,morokProp()));morokFrame($s);morokHit($s,'strong');
$s->getCard(3)->zone='hand';$s=morokReload($s);
checkMorok(str_contains(morokResultHtml($s),'Удар по цели сведён к слабому защитной аурой'),'Aura explanation survives source leaving/reload');
$s=morokState();morokFrame($s);morokHit($s,'','strong');
checkMorok(str_contains(morokResultHtml($s),'Ответный удар сведён к слабому защитной аурой'),'Return aura explanation');
$s=morokState();$s->getCard(1)->prop=morokProp();$s->getCard(1)->closed=true;$s->getCard(2)->prop=[];morokFrame($s);morokHit($s,'','strong');
checkMorok(str_contains(morokResultHtml($s),'Урон ответки сведён к 1'),'Return numeric explanation');
foreach ([false,true] as $suppressed) {
    $s=morokState();$s->getCard(2)->closed=true;if ($suppressed) EffectiveFeatures::applyMemoryLoss($s,$s->getCard(1),$s->getCard(2));
    morokFrame($s);morokHit($s,'');$html=morokResultHtml($s);
    checkMorok(!str_contains($html,'сведён к 1')&&!str_contains($html,'защитной аурой'),'No explanations on miss');
    if ($suppressed) {morokFrame($s);morokHit($s,'strong');checkMorok(!str_contains(morokResultHtml($s),'сведён к 1'),'No suppressed numeric explanation');}
}
foreach (['tap','magic','uchr','shot','throw'] as $type) {
    $s=morokState();$s->getCard(2)->closed=true;
    if ($type==='uchr') {$s->getCard(2)->col=5;$s->addCard(morokCard(3,'host',3,4,morokProp()));}
    else $s->addCard(morokCard(3,'host',3,5,morokProp()));
    $s->getCard(1)->prop=['actions'=>[['key'=>'probe','type'=>$type,'value'=>3,'range'=>3,'near_shot'=>true]]];morokRoll('6');
    $r=(new Engine())->apply($s,'host',new Command($type==='uchr'?'uchr':'action',['card_id'=>1,'target_id'=>2,'action_key'=>'probe']));
    checkMorok($r->success,'Explanation action '.$type.': '.$r->error);$html=morokResultHtml($s);$isStrike=in_array($type,['tap','magic','uchr'],true);
    checkMorok(str_contains($html,'Урон по цели сведён к 1')===$isStrike,'Numeric action explanation '.$type);
    checkMorok(str_contains($html,'Удар по цели сведён к слабому защитной аурой')===$isStrike,'Aura action explanation '.$type);
    if (!$isStrike) checkMorok(str_contains($html,'Урон снижен на 1'),'Existing ranged explanation retained '.$type);
}

echo "OK: $checks Morok checks\n";
