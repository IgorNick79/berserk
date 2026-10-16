<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, CardInstance, Command, Dice, Engine, FirstStrikeJump, GameState, StrikeResolver, TurnProcessor};
use Berserk\Core\Choice\{ChoiceRegistry, FirstStrikeJumpChoice};
use Berserk\Core\Movement\MovementResolver;
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
$checks = 0;
function scAssert(bool $ok, string $label): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($label);
}
function scState(): GameState {
    $s = new GameState(991, 1, 2); $s->status = 'battle';
    $s->battle = ['active' => 'host', 'turn' => 1, 'hidden_row_revealed' => true];
    $prop = json_decode(file_get_contents(__DIR__ . '/../docs/cards/swamp_children.prop.json'), true, 512, JSON_THROW_ON_ERROR);
    $s->addCard(new CardInstance(instanceId: 1, ukid: 'arbitrary_enemy', owner: 'host', zone: 'field', row: 3, col: 2, hp: 20, hpMax: 20, strikeWeak: 2, strikeMedium: 3, strikeStrong: 4));
    $s->addCard(new CardInstance(instanceId: 2, ukid: 'arbitrary_defender', owner: 'player', zone: 'field', row: 3, col: 3, hp: 20, hpMax: 20, move: 2, moveMax: 2, strikeWeak: 2, strikeMedium: 3, strikeStrong: 4, prop: $prop));
    return $s;
}
function scReload(GameState $s): GameState {
    return GameState::fromArray(json_decode(json_encode($s->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
}
function scRoll(): void { $_SESSION = ['debug_roll' => '6,1,4']; Dice::init(); }
function scStrike(GameState $s, bool $finish = true): void {
    $s->battle['strike'] = ['attacker_id' => 1, 'target_id' => 2, 'defender_id' => null, 'state' => 'waiting_defender', 'confirmed' => []];
    scRoll(); (new StrikeResolver($s, new Engine()))->resolve();
    // Direct resolver fixtures need the reconciliation normally performed by Engine.
    Berserk\Core\InformationalWaitManager::reconcile($s, time());
    if ($finish) scFinish($s);
}
function scChoose(GameState $s, string $owner, array $data): Berserk\Core\Result {
    return (new Engine())->apply($s, $owner, new Command('choose_first_strike_movement', $data + ['choice_id' => $s->battle[FirstStrikeJump::PENDING]['id'] ?? '']));
}
function scFinish(GameState $s): void {
    if (empty($s->battle['strike'])) return;
    foreach (['host', 'player'] as $owner) scAssert((new Engine())->apply($s, $owner, new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success, 'Combat acknowledgement');
    scAssert(empty($s->battle['strike']), 'Combat completed');
}

// Actual declaration and automatic pre-dice block, preserving the result and action accounting.
$s = scState(); scRoll();
scAssert((new Engine())->apply($s, 'host', new Command('strike', ['card_id' => 1, 'target_id' => 2]))->success, 'Real strike declaration');
scAssert($s->battle['strike']['first_strike_blocked'] === true, 'Automatic block');
scAssert($s->battle['strike']['damage_total'] === 0 && $s->getCard(2)->hp === 20 && $s->getCard(1)->hp === 20, 'No wounds or answer');
scAssert($s->getCard(1)->closed && $s->getCard(1)->flags['attacks_used_this_turn'] === 1, 'Attack action consumed once');
scAssert(Dice::remaining() === [6,1,4], 'No dice for blocked strike');
scAssert(empty($s->battle[FirstStrikeJump::PENDING]), 'No pending before combat ACKs');
scAssert((new Engine())->apply($s,'host',new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success, 'First result ACK');
scAssert(empty($s->battle[FirstStrikeJump::PENDING]), 'One result ACK does not open jump');
$s=scReload($s);
scAssert((new Engine())->apply($s,'player',new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success, 'Second ACK after reload opens deferred effect');
scAssert(empty($s->battle['strike']), 'Combat finished before jump');
$p = $s->battle[FirstStrikeJump::PENDING];
scAssert($p['owner'] === 'player' && $p['stage'] === 'jump', 'Defender chooses jump');
scAssert(!in_array('3_3', $p['cells'], true) && !in_array('3_2', $p['cells'], true) && !in_array('6_5', $p['cells'], true), 'No origin, occupied or distant cells');
scAssert(in_array('1_3', $p['cells'], true) && in_array('4_4', $p['cells'], true), 'Existing Manhattan range two');
$handler = ChoiceRegistry::current($s);
scAssert($handler instanceof FirstStrikeJumpChoice, 'Choice registered');
$spec = $handler->spec($s, 'player', [], '?game=991', 'player');
scAssert(str_contains($spec->title, 'обязательного прыжка') && !str_contains(json_encode($spec), 'cancel_pending'), 'Mandatory jump UI');
scAssert(!$handler->spec($s, 'host', [], '?game=991', 'host')->isMine, 'Other player waits');
scAssert(!(new Engine())->apply($s, 'player', new Command('cancel_pending'))->success, 'Cannot cancel');
scAssert(!scChoose($s, 'host', ['row' => 1, 'col' => 3])->success, 'Wrong owner rejected');
foreach ([[3,3],[3,2],[6,5],[0,3]] as [$r,$c]) scAssert(!scChoose($s, 'player', ['row'=>$r,'col'=>$c])->success, 'Invalid cell rejected');
scAssert(!scChoose($s, 'player', ['row'=>1,'col'=>3,'choice_id'=>'old'])->success, 'Stale jump rejected');
$s = scReload($s); $originResult = $s->battle['coin_post_combat_history'];
scAssert(FirstStrikeJump::used($s, $s->getCard(2)), 'First use persists');
scAssert(scChoose($s, 'player', ['row'=>1,'col'=>3])->success, 'Jump after reload');
scAssert($s->getCard(2)->row === 1 && $s->getCard(2)->move === 2 && !$s->getCard(2)->closed && empty($s->getCard(2)->flags['moved_this_turn']), 'Jump preserves action');
$pull = $s->battle[FirstStrikeJump::PENDING];
scAssert($pull['stage'] === 'pull' && $pull['owner'] === 'host' && $pull['candidates'] === [1], 'Enemy selects own adjacent creature');
scAssert($pull['from_row'] === 3 && $pull['from_col'] === 3 && $pull['id'] !== $p['id'], 'Vacated origin and fresh identity');
scAssert(!scChoose($s,'host',['target_id'=>1,'choice_id'=>$p['id']])->success, 'Old jump cannot accept next pending');
scAssert(!scChoose($s,'player',['target_id'=>1])->success && !scChoose($s,'host',['target_id'=>2])->success && !scChoose($s,'host',['target_id'=>999])->success, 'Pull owner and candidates validated');
$spec = $handler->spec($s,'host',['arbitrary_enemy'=>['name'=>'Враг']], '?game=991','host');
scAssert(str_contains($spec->buttons[0]['label'], 'Враг (3, 2)') && !str_contains(json_encode($spec), 'cancel_pending'), 'Pull UI identifies card without decline');
$s = scReload($s);
scAssert(scChoose($s,'host',['target_id'=>1])->success, 'Pull resumes');
scAssert($s->getCard(1)->row === 3 && $s->getCard(1)->col === 3 && $s->getCard(1)->closed && $s->getCard(1)->flags['attacks_used_this_turn'] === 1, 'Forced move retains action state');
scAssert($s->battle['coin_post_combat_history'] === $originResult, 'Two choices preserve combat result');
$s = scReload($s);
scAssert(!scChoose($s,'host',['target_id'=>1,'choice_id'=>$pull['id']])->success, 'No replay after reload');
scAssert(Dice::remaining() === [6,1,4], 'Choices never reroll'); scFinish($s);

// Same-turn second strike and next turn, including an ignored first strike.
foreach ([['zoa'], ['zoa'=>true], ['zoa','zoal'], ['zov'], ['zoan']] as $ignore) {
    $s = scState(); $s->getCard(1)->prop['ignore'] = $ignore; scStrike($s);
    $bypass = in_array('zoa', $ignore, true) || !empty($ignore['zoa']);
    scAssert(!empty($s->battle[FirstStrikeJump::PENDING]) === !$bypass, 'Specific existing ignore semantics');
    scAssert(FirstStrikeJump::used($s,$s->getCard(2)), 'First attack consumed even on bypass');
    if ($bypass) {
        scAssert($s->getCard(2)->hp < 20, 'Bypassing strike resolves');
        $s = scReload($s); unset($s->battle['strike']); $s->getCard(1)->prop = [];
        scStrike($s); scAssert(empty($s->battle[FirstStrikeJump::PENDING]), 'Second normal strike not blocked after ignored first');
        unset($s->battle['strike']); $s->battle['active'] = 'player'; scStrike($s);
        scAssert(!empty($s->battle[FirstStrikeJump::PENDING]), 'New half-turn restores protection');
    }
}
$s = scState(); scStrike($s); unset($s->battle[FirstStrikeJump::PENDING], $s->battle['strike']); $s = scReload($s); scStrike($s);
scAssert(empty($s->battle[FirstStrikeJump::PENDING]), 'Second strike not blocked after successful first');
// Real turn transition resets through existing trigger flags.
$s = scState(); scStrike($s); unset($s->battle[FirstStrikeJump::PENDING]); scFinish($s);
scAssert((new TurnProcessor($s,new Engine()))->endTurn('host',new Command('end_turn'))->success,'Actual turn transition');
scAssert(!FirstStrikeJump::used($s,$s->getCard(2)), 'Protection resets on actual transition');

// Non-qualifying actions must not consume the first strike; fly is a creature too.
foreach (['shot','throw','discharge','impact','execute','cast'] as $type) {
    $s = scState(); scAssert(!(new FirstStrikeJump($s,new Engine()))->tryBlock($s->getCard(1),$s->getCard(2),$type), 'Not a strike: '.$type);
    scAssert(!FirstStrikeJump::used($s,$s->getCard(2)), 'Non-strike does not consume');
}
$s = scState(); $s->getCard(1)->owner = 'player'; scStrike($s, false);
scAssert(!FirstStrikeJump::used($s,$s->getCard(2)), 'Friendly strike does not consume');
$s = scState(); $s->getCard(1)->type = 'artifact'; scStrike($s, false);
scAssert(!FirstStrikeJump::used($s,$s->getCard(2)), 'Non-creature does not consume');
$s = scState(); $s->getCard(1)->type = 'fly'; scStrike($s);
scAssert(!empty($s->battle[FirstStrikeJump::PENDING]), 'Flying creature strike protected');

// No legal jump still blocks and finishes; closed/moved status does not spend or prevent effect jump.
foreach (['rooted','spider_web','full','closed'] as $reason) {
    $s = scState();
    if ($reason === 'full') {
        $cells = (new MovementResolver($s,new Engine()))->triggeredJumpCells($s->getCard(2),2);
        foreach ($cells as $key => $_) {[$r,$c]=array_map('intval',explode('_',$key)); $s->addCard(new CardInstance(instanceId:$s->nextInstanceId,ukid:'blocker',owner:'player',zone:'field',row:$r,col:$c,hp:3,hpMax:3));}
    } elseif ($reason === 'closed') { $s->getCard(2)->closed=true; $s->getCard(2)->flags['moved_this_turn']=true; }
    else $s->getCard(2)->markers[$reason]=['value'=>1];
    scStrike($s); scAssert($s->getCard(2)->hp===20,'Block even without jump');
    scAssert(!empty($s->battle[FirstStrikeJump::PENDING]) === ($reason==='closed'),'Legal jump availability: '.$reason);
    if ($reason==='closed') {scAssert(scChoose($s,'player',['row'=>1,'col'=>3])->success,'Closed card jumps'); scAssert($s->getCard(2)->closed && $s->getCard(2)->flags['moved_this_turn'],'Jump retains existing action state'); scChoose($s,'host',['target_id'=>1]);}
    scFinish($s);
}
// Jump target legality is rechecked, including markers and changed occupancy.
$s=scState();scStrike($s);$s->addCard(new CardInstance(instanceId:3,ukid:'new',owner:'host',zone:'field',row:1,col:3,hp:3,hpMax:3));
scAssert(!scChoose($s,'player',['row'=>1,'col'=>3])->success,'New occupancy revalidated');
$s=scState();$s->cell_markers['1_3']=['type'=>'bonfire'];scStrike($s);
scAssert(!in_array('1_3',$s->battle[FirstStrikeJump::PENDING]['cells'],true),'Blocking marker excluded');
// Pull candidate restrictions, multiple targets, and absence of a second pending.
$s=scState();$s->getCard(1)->row=6;$s->getCard(1)->col=5;scStrike($s);scChoose($s,'player',['row'=>1,'col'=>3]);
scAssert(empty($s->battle[FirstStrikeJump::PENDING]),'No adjacent enemy: no second pending');scFinish($s);
$s=scState();$s->addCard(new CardInstance(instanceId:3,ukid:'diagonal',owner:'host',zone:'field',row:4,col:4,hp:5,hpMax:5,move:2,moveMax:2));
scStrike($s);scChoose($s,'player',['row'=>1,'col'=>3]);
scAssert($s->battle[FirstStrikeJump::PENDING]['candidates']===[1,3],'Diagonal adjacency included, distant jumper excluded');
$s->getCard(3)->markers['rooted']=['value'=>1];scAssert(!scChoose($s,'host',['target_id'=>3])->success,'Rooted pull revalidated');unset($s->getCard(3)->markers['rooted']);
scAssert(scChoose($s,'host',['target_id'=>3])->success && $s->getCard(3)->move===2 && !$s->getCard(3)->closed,'Forced move does not spend action');
// Real special strike paths and their ignore continuations.
foreach (['tap','magic','uchr'] as $type) {
    foreach ([false,true] as $ignore) {
        $s=scState();$s->getCard(1)->prop=['actions'=>[['key'=>'probe','type'=>$type,'value'=>2]]];
        if ($type==='uchr') $s->getCard(1)->col=1;
        if ($ignore) $s->getCard(1)->prop['ignore']=['zoa'];
        scRoll();$cmd=new Command($type==='uchr'?'uchr':'action',['card_id'=>1,'target_id'=>2,'action_key'=>'probe']);
        scAssert((new Engine())->apply($s,'host',$cmd)->success,'Special strike path: '.$type);
        scAssert(FirstStrikeJump::used($s,$s->getCard(2)),'Special consumes first');
        scAssert(empty($s->battle[FirstStrikeJump::PENDING]),'Special waits for combat completion');
        scFinish($s);
        scAssert(!empty($s->battle[FirstStrikeJump::PENDING])===!$ignore,'Special block and ignore: '.$type);
        scAssert($s->getCard(2)->hp===($ignore?18:20),'Special damage blocked once');
    }
}
// The protected creature can receive its first strike as a combat answer.
$s=scState();$s->getCard(1)->prop=$s->getCard(2)->prop;$s->getCard(2)->prop=[];
$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'state'=>'results','confirmed'=>[]];
(new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'weak','winner'=>'attack'],false);
scAssert($s->getCard(1)->hp===20 && $s->getCard(2)->hp===18 && !empty($s->battle['strike']['first_answer_blocked']),'Answer blocked without undoing primary damage');
scAssert(empty($s->battle[FirstStrikeJump::PENDING]),'Answer effect deferred');
scFinish($s);
scAssert($s->battle[FirstStrikeJump::PENDING]['card_id']===1,'Answer recipient chooses jump');
// Existing static defense remains independent.
$s=scState();$s->getCard(2)->prop=['zoa'=>true];
scAssert(!(new Engine())->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>2]))->success,'Static defense unchanged');
// Actual screen and InfoPanel render both stages for the correct player, with board targets.
$screen = new Berserk\View\Screen\BattleScreen(new Berserk\View\Template(__DIR__.'/../templates'));
$info = ['arbitrary_enemy'=>['name'=>'Противник','health'=>20], 'arbitrary_defender'=>['name'=>'Дети топи','health'=>20]];
$s=scState();scStrike($s);
foreach (['player','host'] as $owner) {
    $data=$screen->prepare($s,$owner,$owner,null,$info)['data'];$html=implode('',array_filter($data,'is_string'));
    scAssert(str_contains($html,'pending-first-strike-cell')===($owner==='player'),'Board jump visibility by owner');
    if ($owner==='player') scAssert(str_contains($html,'обязательного прыжка Детей топи') && str_contains($html,'choice_id='),'Real mandatory cell panel');
}
scChoose($s,'player',['row'=>1,'col'=>3]);
foreach (['host','player'] as $owner) {
    $data=$screen->prepare($s,$owner,$owner,null,$info)['data'];$html=implode('',array_filter($data,'is_string'));
    scAssert(str_contains($html,'pending-first-strike-target')===($owner==='host'),'Board pull visibility by owner');
    if ($owner==='host') scAssert(str_contains($html,'Выберите своё существо'),'Real mandatory enemy panel');
}
// Board destinations honor rooted, field markers, movement limits and live candidate legality.
$s=scState();$s->battle['moves_limit']=['owner'=>'player','limit'=>0,'moved_ids'=>[]];scStrike($s);
scAssert(empty($s->battle[FirstStrikeJump::PENDING]) && $s->getCard(2)->hp===20,'Movement limit prevents jump, keeps block');
$s=scState();$s->getCard(1)->markers['rooted']=['sources'=>[99]];scStrike($s);scChoose($s,'player',['row'=>1,'col'=>3]);
scAssert(empty($s->battle[FirstStrikeJump::PENDING]),'Rooted enemy excluded');
$s=scState();scStrike($s);scChoose($s,'player',['row'=>1,'col'=>3]);$s->cell_markers['3_3']=['type'=>'bonfire'];
scAssert(!scChoose($s,'host',['target_id'=>1])->success,'Pull destination revalidated');
// Resolving the saved blocked combat cannot spend another action or roll again.
$s=scState();scStrike($s,false);$s=scReload($s);$before=$s->toArray();(new StrikeResolver($s,new Engine()))->resolve();
scAssert($s->toArray()===$before && Dice::remaining()===[6,1,4],'Blocked resolve replay is inert');
scFinish($s);
// During a suspended strike, normal commands and combat ACKs cannot advance it.
foreach (['confirm_strike','end_turn','move','strike'] as $type) {
    scAssert(!(new Engine())->apply($s,'host',new Command($type,['card_id'=>1,'target_id'=>2,'row'=>1,'col'=>1]))->success,'Mandatory pending gates '.$type);
}
// Defense arrays behave like independent existing protections: any applicable unignored key blocks.
foreach ([
    ['creature',['zoa','zoan'],['zoa'],true],
    ['creature',['zoa','zoan'],['zoa','zoan'],false],
    ['fly',['zoa','zoan'],['zoa'],false],
    ['fly',['zoa','zoal'],['zoa'],true],
    ['fly',['zoa','zoal'],['zoa','zoal'],false],
    ['creature',['zoan','zoal'],['zoal'],true],
    ['creature',['zoal'],[],false],
    ['creature',[],[],false],
    ['creature',['zov'],[],false],
] as [$type,$defenses,$ignore,$blocked]) {
    $s=scState();$s->getCard(1)->type=$type;$s->getCard(1)->prop['ignore']=$ignore;
    $s->getCard(2)->prop['first_strike_jump']['defense']=$defenses;
    scStrike($s,false);
    scAssert(!empty($s->battle['strike']['first_strike_blocked'])===$blocked,'Array protection and ignore by attacking type');
    scAssert(FirstStrikeJump::used($s,$s->getCard(2)),'Array first strike consumed on bypass too');
}
// A real after-combat instant window, its result and reload must finish before the jump.
$s=scState();$s->getCard(2)->coins=1;
$s->addCard(new CardInstance(instanceId:3,ukid:'after_probe',owner:'player',zone:'field',row:5,col:4,hp:9,hpMax:10,
    prop:['instants'=>[['key'=>'after_probe','name'=>'After probe','trigger'=>'turn','target'=>'self','effect'=>['type'=>'heal','value'=>1]]]]));
scStrike($s,false);
scAssert(empty($s->battle[FirstStrikeJump::PENDING]) && $s->getCard(2)->row===3,'No early jump or movement');
scFinish($s);
scAssert(!empty($s->battle['turn_instant_stack']['continuation']['coin_origin_strike']),'Shared continuation retains deferred jump');
scAssert(empty($s->battle[FirstStrikeJump::PENDING]),'No jump during after-combat window');
$s=scReload($s);$e=new Engine();
scAssert($e->apply($s,'player',new Command('play_turn_instant',['card_id'=>3,'instant_key'=>'after_probe']))->success,'Play after-combat instant');
scAssert($e->apply($s,'player',new Command('pass_turn_instant'))->success,'Resolve after-combat stack');
scAssert(!empty($s->battle['turn_instant_result']) && empty($s->battle[FirstStrikeJump::PENDING]),'Jump waits for instant result ACK');
$s=scReload($s);
scAssert($e->apply($s,'player',new Command('turn_instant_result_ok'))->success,'Acknowledge after-combat instant');
scAssert(empty($s->battle['strike']) && !empty($s->battle[FirstStrikeJump::PENDING]),'Jump starts only after complete combat, regardless of coins');
$id=$s->battle[FirstStrikeJump::PENDING]['id'];$s=scReload($s);
(new Berserk\Core\CoinAbilities($s,new Engine()))->advance();
scAssert($s->battle[FirstStrikeJump::PENDING]['id']===$id,'Resume does not reopen pending');
// Legal cells and origin are taken at effect execution, after combat has finished.
$s=scState();scStrike($s,false);$s->getCard(2)->row=4;
scFinish($s);
scAssert($s->battle[FirstStrikeJump::PENDING]['from_row']===4,'Origin reflects actual pre-jump position');
$s=scState();scStrike($s,false);$s->getCard(2)->markers['rooted']=['sources'=>[99]];scFinish($s);
scAssert(empty($s->battle[FirstStrikeJump::PENDING]),'No unfulfillable pending if movement becomes unavailable');
scAssert(count($s->battle['coin_post_combat_history'])===1,'Unavailable deferred jump consumed once');
// Opt-in ranged attacks use the actual ActionResolver path and the same defense/ignore keys.
foreach (['shot'=>'zov','throw'=>'zot','discharge'=>'zom','magic'=>'zom'] as $type=>$defense) {
    foreach (['block','ignore','unrelated'] as $mode) {
        $s=scState();$s->getCard(1)->col=1;
        $s->getCard(1)->prop=['actions'=>[['key'=>'ranged','type'=>$type,'range'=>3,'value'=>2,'near_shot'=>true]]];
        $s->getCard(2)->prop['first_strike_jump']['types']=['strike','uchr','tap','magic','answer','shot','throw','discharge'];
        $s->getCard(2)->prop['first_strike_jump']['defense']=[$defense];
        if ($mode==='ignore') $s->getCard(1)->prop['ignore']=[$defense];
        if ($mode==='unrelated') $s->getCard(1)->prop['ignore']=['zoal'];
        scRoll();
        scAssert((new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'ranged']))->success,'Ranged command: '.$type);
        scAssert(FirstStrikeJump::used($s,$s->getCard(2)),'Ranged first attack consumed');
        scAssert($s->getCard(2)->hp===($mode==='ignore'?18:20),'Ranged defense/ignore damage: '.$type);
        scAssert(empty($s->battle[FirstStrikeJump::PENDING]),'Ranged pending waits for result');
        if ($mode!=='ignore') scAssert(Dice::remaining()===[6,1,4],'Blocked ranged attack does not roll');
        $s=scReload($s);scFinish($s);
        scAssert(!empty($s->battle[FirstStrikeJump::PENDING])===($mode!=='ignore'),'Deferred ranged jump after ACK');
        if ($mode==='ignore') {
            $s->getCard(1)->prop=[];scStrike($s,false);
            scAssert(empty($s->battle['strike']['first_strike_blocked']),'Ignored ranged first attack prevents later melee block');
        }
    }
}
// Action-specific ignore is respected for shot/throw, but cannot bypass another listed protection.
foreach (['shot'=>'zov','throw'=>'zot'] as $type=>$defense) {
    foreach ([[$defense],['zoa',$defense]] as $defenses) {
        $s=scState();$s->getCard(1)->col=1;
        $s->getCard(1)->prop=['actions'=>[['key'=>'ranged','type'=>$type,'range'=>3,'value'=>2,'ignore'=>[$defense]]]];
        $s->getCard(2)->prop['first_strike_jump']['types']=[$type];
        $s->getCard(2)->prop['first_strike_jump']['defense']=$defenses;
        scRoll();scAssert((new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'ranged']))->success,'Action-specific ignore path');
        scAssert(!empty($s->battle['strike']['first_strike_blocked'])===(count($defenses)>1),'Action ignore only bypasses matching protection');
    }
}
// A triggered ranged effect must not close its source when no_close is set.
$s=scState();$s->getCard(2)->prop['first_strike_jump']['types']=['discharge'];
scRoll();scAssert((new Berserk\Core\ActionResolver($s,new Engine()))->triggeredDischarge($s->getCard(1),$s->getCard(2),['range'=>3,'value'=>2])->success,'Triggered discharge can be blocked');
scAssert(!$s->getCard(1)->closed && $s->getCard(2)->hp===20,'Triggered attack preserves no_close');
echo "Swamp children: {$checks} assertions passed.\n";

