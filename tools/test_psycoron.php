<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, GameState, CardInstance, Command, Engine, Dice, RangedNeighborTeleport, CoinAbilities, BattleWaitResolver};
use Berserk\Core\Choice\RangedNeighborTeleportChoice;
use Berserk\View\Ui\CellMarkerPicker;
Autoloader::register(); Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
$count = 0;
function check(bool $ok, string $message): void { global $count; $count++; if (!$ok) throw new RuntimeException($message); }
function card(int $id, string $owner, int $r, int $c, array $prop = []): CardInstance {
    return new CardInstance(instanceId:$id, ukid:'test_'.$id, owner:$owner, zone:'field', row:$r, col:$c,
        hp:20, hpMax:20, move:1, moveMax:1, strikeWeak:1, strikeMedium:1, strikeStrong:1, prop:$prop);
}
function state(string $type = 'shot'): GameState {
    $s = new GameState(153,1,2); $s->status='battle'; $s->battle=['active'=>'host','turn'=>1,'strike'=>null];
    $s->addCard(card(1,'host',1,1,['actions'=>[['key'=>'attack','type'=>$type,'value'=>2]]]));
    $s->addCard(card(2,'player',3,3));
    $s->addCard(card(3,'player',3,4,json_decode(file_get_contents(__DIR__.'/../docs/cards/psycoron.prop.json'),true,512,JSON_THROW_ON_ERROR)));
    $s->addCard(card(4,'host',6,5)); return $s;
}
function reloadState(GameState $s): GameState { return GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR)); }
function attack(GameState $s): void {
    $_SESSION=['debug_roll'=>'4*']; Dice::init();
    $r=(new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'attack']));
    check($r->success, 'attack: '.($r->error ?? '')); check(empty($s->battle[RangedNeighborTeleport::PENDING]),'not before completion');
}
function finish(GameState $s): void {
    foreach (['host','player'] as $player) {
        $r=(new Engine())->apply($s,$player,new Command('confirm_strike',['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]));
        check($r->success,'ACK '.($r->error ?? ''));
    }
}
function choose(GameState $s,string $op, string $cell='', string $owner='player',?string $id=null): \Berserk\Core\Result {
    return (new Engine())->apply($s,$owner,new Command('choose_ranged_neighbor_teleport',[
        'operation'=>$op,'cell'=>$cell,'choice_id'=>$id ?? ($s->battle[RangedNeighborTeleport::PENDING]['id'] ?? '')]));
}
foreach (['shot','throw','discharge','magic','tap','uchr'] as $type) {
    $s=state($type); if (in_array($type,['magic','tap'],true)) { $s->getCard(1)->row=2; $s->getCard(1)->col=2; } if ($type==='uchr') $s->getCard(1)->row=3; attack($s); $s=reloadState($s); $hp=$s->getCard(2)->hp; finish($s);
    check(isset($s->battle[RangedNeighborTeleport::PENDING])===in_array($type,['shot','throw','discharge'],true),'trigger '.$type);
    check($s->getCard(2)->hp===$hp,'no repeated wounds');
}
foreach (['armor','defense','far','self','noncreature','zero','one','dead','friendly'] as $case) {
    $s=state();
    if ($case==='armor') $s->getCard(2)->armor=2;
    if ($case==='defense') $s->getCard(2)->prop=['zoa'=>true];
    if ($case==='far') $s->getCard(3)->row=6;
    if ($case==='self') { $s->getCard(2)->prop=$s->getCard(3)->prop; $s->getCard(3)->prop=[]; }
    if ($case==='noncreature') $s->getCard(2)->type='artifact';
    if ($case==='zero') $s->getCard(1)->prop['actions'][0]['value']=0;
    if ($case==='one') $s->getCard(2)->armor=1;
    if ($case==='dead') $s->getCard(2)->hp=1;
    if ($case==='friendly') $s->getCard(2)->owner='host';
    attack($s); finish($s);
    check(isset($s->battle[RangedNeighborTeleport::PENDING])===in_array($case,['one','dead','friendly'],true),'wounds gate '.$case);
}
$s=state(); attack($s); $s->getCard(2)->row=6; finish($s);
check(isset($s->battle[RangedNeighborTeleport::PENDING]),'snapshot adjacency');
check(BattleWaitResolver::current($s)['required_players']===['player'],'owner projected');
$p=$s->battle[RangedNeighborTeleport::PENDING]; $offer=$p['id'];
$spec=(new RangedNeighborTeleportChoice())->spec($s,'player',[],'?game=153','player');
check(array_column($spec->buttons,'label')===['Телепортироваться','Отмена'],'offer labels');
check(!choose($s,'start','','host')->success,'wrong owner');
check(choose($s,'start')->success,'open cells'); check(empty($s->getCard(3)->flags['ranged_neighbor_teleport_used']),'start free');
check(!choose($s,'start','','player',$offer)->success,'old stage rejected');
$s=reloadState($s); check($s->battle[RangedNeighborTeleport::PENDING]['stage']==='cell','cell stage reload');
$picker=CellMarkerPicker::targets($s,'player','?game=153'); check(count($picker)===3,'three free neighbours at corner');
check(CellMarkerPicker::targets($s,'host','?game=153')===[],'opponent no targets');
check(CellMarkerPicker::identity($s,'player')!=='','picker identity');
$s=reloadState($s); $id=$s->battle[RangedNeighborTeleport::PENDING]['id'];
check(choose($s,'select','1_2')->success,'cell click teleports immediately');
check($s->getCard(3)->row===1 && $s->getCard(3)->col===2,'moved without confirmation');
check(!empty($s->getCard(3)->flags['ranged_neighbor_teleport_used']),'spent once');
check(!choose($s,'confirm','1_2','player',$id)->success,'duplicate rejected');
$s->battle['turn']++; $s->getCard(1)->closed=false; $s->getCard(2)->row=2; $s->getCard(2)->col=3;
attack($s); finish($s); check(empty($s->battle[RangedNeighborTeleport::PENDING]),'turn does not reset use');
foreach (['cancel_offer','cancel_cell','occupied','moved_attacker','dead_attacker','dead_source','no_cells','blocking_marker'] as $case) {
    $s=state(); attack($s);
    if ($case==='moved_attacker') $s->getCard(1)->row=2;
    if ($case==='dead_attacker') { $s->getCard(1)->zone='graveyard'; $s->getCard(1)->hp=0; }
    if ($case==='dead_source') $s->getCard(3)->zone='graveyard';
    if ($case==='no_cells') foreach ([[1,2],[2,1],[2,2]] as $i=>$rc) $s->addCard(card(10+$i,'host',...$rc));
    if ($case==='blocking_marker') foreach (['1_2','2_1','2_2'] as $key) $s->cell_markers[$key]=[['type'=>'bonfire','blocking'=>true]];
    finish($s);
    if (in_array($case,['moved_attacker','dead_attacker','dead_source','no_cells','blocking_marker'],true)) {
        check(empty($s->battle[RangedNeighborTeleport::PENDING]),'no impossible pending '.$case); continue;
    }
    if ($case==='occupied') {
        check(choose($s,'start')->success,'start');
        $s->addCard(card(10,'host',1,2)); check(!choose($s,'select','1_2')->success,'revalidate occupancy');
    } else {
        if ($case==='cancel_cell') check(choose($s,'start')->success,'start then cancel');
        check(choose($s,'cancel')->success,'cancel');
    }
    check(empty($s->getCard(3)->flags['ranged_neighbor_teleport_used']),'no spend '.$case);
}
// Multiple instances are serialized by the existing post-combat queue.
$s=state(); $s->addCard(card(5,'host',4,3,$s->getCard(3)->prop)); attack($s); finish($s);
check($s->battle[RangedNeighborTeleport::PENDING]['card_id']===3,'first instance'); choose($s,'cancel');
check($s->battle[RangedNeighborTeleport::PENDING]['card_id']===5,'second instance'); check(choose($s,'cancel','','host')->success,'second owner');
// Multiple targets share a single combat context and deduplicate each reacting instance.
$s=state(); $s->getCard(1)->prop=['actions'=>[['key'=>'attack','type'=>'multi_discharge','value'=>1,'max_targets'=>2]]];
$s->addCard(card(5,'player',4,4));
check((new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'action_key'=>'attack']))->success,'multi start');
check((new Engine())->apply($s,'host',new Command('choose_multi_discharge',['target_ids'=>[2,5]]))->success,'multi wounds');
check(count($s->battle['strike']['coin_post_combat_effects'])===1,'one reaction per combat');
finish($s); check(choose($s,'cancel')->success,'multi cancel'); check(empty($s->battle[RangedNeighborTeleport::PENDING]),'no duplicate offer');
// Existing mandatory choices gate advancement, preserving the original attack result.
$s=state(); attack($s); (new CoinAbilities($s,new Engine()))->completeCombat($s->battle['strike']); $s->battle['strike']=null; $s->battle['pending_prophecy']=['owner'=>'host']; (new CoinAbilities($s,new Engine()))->advance();
check(empty($s->battle[RangedNeighborTeleport::PENDING]),'mandatory choice blocks queue');
unset($s->battle['pending_prophecy']); (new CoinAbilities($s,new Engine()))->advance();
check(isset($s->battle[RangedNeighborTeleport::PENDING]),'queue resumes after mandatory');
foreach ([1=>2,4=>3,6=>4] as $roll=>$damage) {
    $s=state(); $s->battle['active']='player'; $_SESSION=['debug_roll'=>$roll.'*']; Dice::init();
    $r=(new Engine())->apply($s,'player',new Command('action',['card_id'=>3,'target_id'=>2,'action_key'=>'magic']));
    check($r->success,'standard magic'); check($s->getCard(3)->closed,'magic taps'); check($s->getCard(2)->hp===20-$damage,'magic level');
    $s=reloadState($s); check(!(new Engine())->apply($s,'player',new Command('action',['card_id'=>3,'target_id'=>2,'action_key'=>'magic']))->success,'magic replay rejected');
}
// Real after-instant stack, reload and result ACK must finish before the proposal.
foreach (['shot','throw','discharge'] as $type) {
    $s=state($type); $s->getCard(4)->hp=19; $s->getCard(4)->owner='player';
    $s->getCard(4)->prop=['instants'=>[['key'=>'after_probe','name'=>'After probe','trigger'=>'turn','target'=>'self','effect'=>['type'=>'heal','value'=>1]]]];
    attack($s); finish($s);
    check(!empty($s->battle['turn_instant_stack']['continuation']['coin_origin_strike']),'after window retains origin');
    check(empty($s->battle[RangedNeighborTeleport::PENDING]),'no proposal in after window');
    $s=reloadState($s); $e=new Engine();
    check($e->apply($s,'player',new Command('play_turn_instant',['card_id'=>4,'instant_key'=>'after_probe']))->success,'after instant played');
    check($e->apply($s,'player',new Command('pass_turn_instant'))->success,'after stack resolved');
    check(!empty($s->battle['turn_instant_result']) && empty($s->battle[RangedNeighborTeleport::PENDING]),'wait for stack result');
    $s=reloadState($s);
    check($e->apply($s,'player',new Command('turn_instant_result_ok'))->success,'stack result acknowledged');
    check(isset($s->battle[RangedNeighborTeleport::PENDING]),'proposal after complete stack');
    check(!empty($s->battle[RangedNeighborTeleport::PENDING]['combat_result']['after_instant_summary']),'archive full result');
}
// Render the actual field and InfoPanel after restoring cell choice.
$s=state(); attack($s); finish($s); choose($s,'start'); $s=reloadState($s);
$_GET=[]; $screen=new \Berserk\View\Screen\BattleScreen(new \Berserk\View\Template(__DIR__.'/../templates'));
$data=$screen->prepare($s,'player','player',null,[])['data'];
$html=implode('',array_filter($data,'is_string'));
check(str_contains($html,'data-cell-marker-field='),'field picker rendered');
check(str_contains($html,'data-cell-marker-picker='),'panel picker rendered');
check(!str_contains($html,'Подтвердить'),'no extra confirmation after reload');
check(str_contains($html,'operation=select'),'field cell action available');
check(!str_contains($html,'operation=confirm'),'no confirm action rendered');
check(!str_contains($html,'Выберите клетку для костра'),'teleport picker title');
// An occupied selected cell cannot be submitted; select a different current free cell instead.
$s->addCard(card(10,'host',1,2)); check(!choose($s,'select','1_2')->success,'stale cell');
check(choose($s,'select','2_1')->success,'valid current destination');
// Declining preserves the ability for a later attack.
$s=state(); attack($s); finish($s); choose($s,'cancel'); $s->getCard(1)->closed=false; attack($s); finish($s);
check(isset($s->battle[RangedNeighborTeleport::PENDING]),'offer again after decline');
// A zero wound or unsupported damage cause cannot record an effect.
foreach (['strike','uchr','impact','poison'] as $type) {
    $s=state(); $s->battle['strike']=['state'=>'resolving'];
    RangedNeighborTeleport::record($s,$s->getCard(1),$s->getCard(2),$type,1);
    check(empty($s->battle['strike']['coin_post_combat_effects']),'unsupported cause '.$type);
}

// Separate instances can both spend their own once-per-battle limit.
$s=state(); $s->addCard(card(5,'host',4,3,$s->getCard(3)->prop)); attack($s); finish($s);
check(choose($s,'start')->success && choose($s,'select','1_2')->success,'first instance teleport');
check(choose($s,'start','','host')->success && choose($s,'select','2_1','host')->success,'second instance teleport');
check(!empty($s->getCard(3)->flags['ranged_neighbor_teleport_used']) && !empty($s->getCard(5)->flags['ranged_neighbor_teleport_used']),'independent successful limits');
// Damage shields and non-field targets are excluded at the real damage layer.
$s=state(); $s->getCard(2)->modifiers[]=['stat'=>'shield_light']; attack($s); finish($s);
check(empty($s->battle[RangedNeighborTeleport::PENDING]),'full shield prevents trigger');
$s=state(); attack($s); finish($s); choose($s,'start');
check(!choose($s,'select','0_1')->success && !choose($s,'select','7_1')->success,'field bounds checked');
check(!choose($s,'select','6_5')->success,'only attacker neighbours');

// Existing production prop names are shortened on the action button too.
$s=state(); $s->battle['active']='player';
$s->getCard(3)->prop['actions'][0]['name']='Магический удар на 2-3-4';
$_GET=['sel'=>3];
$data=$screen->prepare($s,'player','player',null,['test_3'=>['name'=>'Сайкорон','health'=>20]])['data'];
check(str_contains($data['info_panel_html'],'Магический удар'),'short magic action available');
check(!str_contains($data['info_panel_html'],'Магический удар на 2-3-4'),'old production name shortened');

echo "Psycoron: {$count} assertions passed.\n";

