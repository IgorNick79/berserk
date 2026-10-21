<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{ActionResolver, Autoloader, CardInstance, Command, Engine, GameState, StrikeResolver, TurnProcessor};
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
$checks = 0;
function verify(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function fixture(array $prop, bool $closed = false, string $zone = 'field'): array {
    $s = new GameState(192155, 101, 202); $s->status = 'battle';
    $s->battle = ['turn'=>1, 'active'=>'host', 'strike'=>null, 'hidden_row_revealed'=>true];
    $a = new CardInstance(instanceId:1, ukid:'source', owner:'host', zone:'field', row:3, col:3, hp:20, hpMax:20, move:1, moveMax:1, strikeWeak:1, strikeMedium:2, strikeStrong:3, prop:$prop);
    $t = new CardInstance(instanceId:2, ukid:'target', owner:'player', zone:$zone, row:3, col:4, hp:20, hpMax:20, closed:$closed, move:1, moveMax:1);
    $s->addCard($a); $s->addCard($t); return [$s,$a,$t];
}
function strike(GameState $s, string $level): void {
    $s->battle['strike'] = ['attacker_id'=>1,'target_id'=>2,'original_target_id'=>2,'defender_id'=>null,'state'=>'waiting_choice','confirmed'=>[],'defenders'=>[],'friendly_fire'=>false];
    (new StrikeResolver($s,new Engine()))->apply(['attack'=>$level,'defend'=>'','winner'=>'attack'],false);
    $s->battle['strike'] = null;
}
function endTurn(GameState $s): void {
    $r=(new TurnProcessor($s,new Engine()))->endTurn($s->battle['active'],new Command('end_turn'));
    verify($r->success, $r->error ?? 'End turn failed');
}
$marker=['type'=>'stun','expire'=>1,'timing'=>'owner_turn'];
$medusa=['direct'=>true,'strike_effects'=>[['levels'=>['strong'],'marker'=>$marker]]];
foreach (['', 'weak','medium','strong'] as $level) {
    [$s,$a,$t]=fixture($medusa); strike($s,$level);
    verify($t->closed === ($level==='strong'), 'Medusa closes only on strong strike');
    verify(isset($t->markers['stun']) === ($level==='strong'), 'Medusa marker strength');
    endTurn($s);
    verify($t->closed === ($level==='strong'), 'Medusa target skips next opening');
    endTurn($s); verify(!isset($t->markers['stun']), 'Stun expires at end of victim turn');
    endTurn($s); verify(!$t->closed, 'Victim opens on following own turn');
}
foreach (['','weak','medium','strong'] as $level) {
    [$s,$a,$t]=fixture(['direct'=>true,'strike_effects'=>[['close'=>true]],'damage_reduction'=>[['types'=>['strike'],'value'=>2]]]);
    strike($s,$level); verify($t->closed, 'Goblin closes on hit and miss');
    verify(!isset($t->markers['stun']), 'Goblin does not stun');
    endTurn($s); verify(!$t->closed, 'Goblin target opens normally next turn');
}
foreach ([false,true] as $closed) {
    [$s,$a,$t]=fixture(['actions'=>[['key'=>'borg','near'=>true,'type'=>'tap','value'=>3,'marker'=>['if'=>'target_closed']+$marker]]],$closed);
    $r=(new ActionResolver($s,new Engine()))->handle('host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'borg']));
    verify($r->success,$r->error ?? 'Borg action');
    verify($t->hp===17,'Borg special strike deals 3');
    verify(isset($t->markers['stun'])===$closed,'Borg stuns only closed target');
    $s->battle['strike']=null; endTurn($s);
    verify($t->closed===$closed,'Borg target opening');
    endTurn($s); endTurn($s); verify(!$t->closed,'Borg stun lasts one own turn');
}
[$s,$a,$t]=fixture($medusa,false,'flying');
(new Engine())->applyStrikeEffects($a,$t,'strong');
verify($t->closed,'Medusa closes flying target'); endTurn($s);
verify($t->closed,'Flying target skips opening');

// Uriil applies the aura on a successful ordinary strike. Its first tick is
// skipped because the attacker is still in its current own turn.
foreach (['weak','medium','strong'] as $level) {
    foreach (['field','flying'] as $zone) {
        [$s,$a,$t]=fixture(['direct'=>true]);
        $a->zone=$zone;
        $t->prop=['answer'=>['types'=>['strike'],'value'=>0,'marker'=>[
            'type'=>'stun','expire'=>1,'timing'=>'owner_turn','skip_first_tick'=>true
        ]],'regeneration'=>['value'=>1]];
        strike($s,$level);
        verify($a->closed,'Attacker closes after attacking Uriil');
        verify(isset($a->markers['stun']),'Uriil aura marks attacker');
        endTurn($s);
        verify(isset($a->markers['stun']),'Uriil aura survives current attacker turn');
        verify(empty($a->markers['stun']['skip_first_tick']),'First tick consumed');
        endTurn($s);
        verify($a->closed,'Attacker skips next own opening after Uriil');
        endTurn($s);
        verify(!isset($a->markers['stun']),'Uriil aura expires after skipped turn');
        endTurn($s);
        verify(!$a->closed,'Attacker opens on subsequent own turn after Uriil');
    }
}
// A ranged attack does not trigger the ordinary-strike aura.
[$s,$a,$t]=fixture([]);
$t->prop=['answer'=>['types'=>['strike'],'value'=>0,'marker'=>[
    'type'=>'stun','expire'=>1,'timing'=>'owner_turn','skip_first_tick'=>true
]]];
(new Engine())->applyAnswer($s,$t,$a,'shot');
verify(!isset($a->markers['stun']),'Uriil aura does not trigger on a shot');

echo "OK: $checks checks\n";

