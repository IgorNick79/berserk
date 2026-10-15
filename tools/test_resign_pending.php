<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, CardInstance, Command, Engine, GameState};
use Berserk\Core\Choice\ChoiceRegistry;
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
function resignAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function resignState(string $active, string $owner, string $context): GameState {
    // Engine logs commands. Run in an isolated source copy, never a live storage tree.
    $s = new GameState(-random_int(1000000000, 2000000000), 101, 202);
    $s->status = 'battle';
    $s->battle = ['active'=>$active, 'turn'=>3];
    foreach ([1=>'host', 2=>'player'] as $id=>$key) {
        $s->addCard(new CardInstance(instanceId:$id, ukid:'card'.$id, owner:$key,
            zone:CardInstance::ZONE_FIELD, row:$id===1?2:5, col:2, hp:7, hpMax:7,
            move:1, moveMax:1, strikeWeak:1, strikeMedium:2, strikeStrong:3));
    }
    $source = $owner === 'host' ? 1 : 2;
    if ($context === 'cell') $s->battle['pending_cell_marker_pick'] = [
        'owner'=>$owner, 'card_id'=>$source, 'label'=>'Разжигание костра',
        'marker'=>'bonfire', 'duration'=>'end_of_opponent_turn', 'cells'=>['3_1','4_5']];
    if ($context === 'target') $s->battle['pending_forced_strike'] = [
        'owner'=>$owner, 'attacker_id'=>$source, 'candidates'=>[$source===1?2:1]];
    if ($context === 'action') $s->battle['pending_card_choice'] = [
        'owner'=>$owner, 'card_id'=>$source, 'options'=>[['type'=>'heal','value'=>1]]];
    if ($context === 'ack') $s->battle['turn_phase'] = [
        'phase'=>'start', 'owner'=>$owner, 'pending_ack'=>['text'=>'Результат']];
    if ($context === 'result') $s->battle['strike'] = [
        'kind'=>'cell_marker', 'state'=>'results', 'attacker_id'=>$source,
        'cell_marker'=>['row'=>3,'col'=>1,'type'=>'bonfire'], 'confirmed'=>[]];
    // Must survive surrender as well as rejection: no incidental result cleanup.
    $s->battle['instant_result'] = ['sentinel'=>true];
    $s->battle['any_death_messages'] = ['sentinel'];
    $s->battle['degasca_messages'] = ['sentinel'];
    return $s;
}
$engine = new Engine();
$cases = 0;
foreach (['host','player'] as $active) foreach (['host','player'] as $owner) {
    foreach (['none','cell','target','action','ack','result'] as $context) {
        foreach (['host','player'] as $actor) {
            $s = resignState($active, $owner, $context);
            if (in_array($context, ['cell','target','action','ack'], true)) {
                resignAssert(ChoiceRegistry::current($s) !== null, 'Fixture must have active Choice: '.$context);
            }
            $before = $s->toArray();
            // Payload identity must not override the trusted actor argument.
            $r = $engine->apply($s, $actor, new Command('resign', [
                'playerKey'=>$s->getOpponentKey($actor), 'player_id'=>999, 'winner'=>$actor]));
            $opponent = $s->getOpponentKey($actor);
            resignAssert($r->success && $s->status === 'game_over' && $s->winner === $opponent, 'Surrender must award opponent: '.$context);
            resignAssert($r->events === ['resigned:'.$actor, 'winner:'.$opponent], 'Existing event contract changed');
            $expected = $before;
            $expected['status'] = 'game_over'; $expected['winner'] = $opponent; $expected['version']++;
            resignAssert($s->toArray() === $expected, 'Surrender must only change status/winner/version; no pending execution: '.$context);
            foreach (['host','player'] as $repeatActor) {
                $after = $s->toArray();
                resignAssert(!$engine->apply($s, $repeatActor, new Command('resign'))->success, 'Repeated surrender must be rejected');
                resignAssert($s->toArray() === $after, 'Repeated surrender mutated state');
            }
            $cases++;
        }
    }
}
foreach (['spectator','', 'HOST', '101'] as $outsider) {
    foreach (['none','cell','ack'] as $context) {
        $s = resignState('host','host',$context); $before = $s->toArray();
        $r = $engine->apply($s, $outsider, new Command('resign', ['playerKey'=>'host']));
        resignAssert(!$r->success && $s->toArray() === $before, 'Unknown actor must not surrender');
    }
}
foreach (['mode','place','game_over'] as $status) {
    $s = resignState('host','host','cell'); $s->status = $status; $before = $s->toArray();
    resignAssert(!$engine->apply($s,'host',new Command('resign'))->success && $s->toArray() === $before, 'Nonbattle surrender must remain rejected');
}
$s = resignState('host','host','cell'); $s->winner = 'player'; $before = $s->toArray();
resignAssert(!$engine->apply($s,'host',new Command('resign'))->success && $s->toArray() === $before, 'Existing winner must block surrender even with battle status');
foreach (['cell','target','action','ack'] as $context) foreach (['host','player'] as $actor) {
    foreach (['end_turn','move','action'] as $type) {
        $s = resignState('host','host',$context);
        unset($s->battle['instant_result'], $s->battle['any_death_messages'], $s->battle['degasca_messages']);
        $before = $s->toArray();
        $r = $engine->apply($s,$actor,new Command($type,['card_id'=>1,'row'=>3,'col'=>1]));
        resignAssert(!$r->success && $r->error === 'Ожидается выбор' && $s->toArray() === $before, 'Ordinary commands must remain blocked: '.$context.'/'.$type);
    }
}
echo "Resign during pending tests passed ($cases surrender cases, repeats, outsiders, state guards and command restrictions).\n";
