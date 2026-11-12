<?php
declare(strict_types=1);
ob_start(); require __DIR__ . '/test_lilith.php'; ob_end_clean();
$s = ls();
$s->persistenceVersion = 1;
$s->battle['strike'] = [
    'state'=>'waiting_instant','attacker_id'=>1,'target_id'=>2,'defender_id'=>null,
    'instant_phase'=>'combat','combat_instant_window'=>'wounds','instant_priority'=>'player',
    'instant_stack'=>[],'instant_played'=>[],'instant_passed'=>['host'],'instant_participants'=>['host','player'],
    'damage_applied'=>true,'wounds_window_done'=>true,'confirmed'=>['host','player'],
    'attack_dice'=>6,'defend_dice'=>1,'attack_mod'=>0,'defend_mod'=>0,
    'result'=>['attack'=>'strong','defend'=>'','winner'=>'attack'],
    'final'=>['attack'=>'strong','defend'=>'','decreased'=>false],
    'damage_total'=>3,'wounds_received'=>[2=>2],
];
$proof = 'Berserk' . chr(92) . 'Core' . chr(92) . 'Observation' . chr(92) . 'CombatMainPassKnowledge';
$s->battle['strike']['pending_wounds_resolution'] = true;
lc($proof::window($s, 'player') === null, 'Never skip a mandatory wounds resolution');
unset($s->battle['strike']['pending_wounds_resolution']);
lc($proof::window($s, 'host') === null, 'Never pass for another priority owner');
$plan = \Berserk\Core\Combat\CombatDecisionPlanner::decide(
    \Berserk\Core\Observation\PlayerObservation::build($s,'player'),
    \Berserk\Core\Legal\LegalActions::build($s,'player'));
lc(($plan['action']['type'] ?? null) === 'combat_instant_pass', 'Bot can finish terminal wounds window');
$engine = new \Berserk\Core\Engine();
lc($engine->apply($s,'player',new \Berserk\Core\Command('combat_instant_pass'))->success, 'Native pass succeeds');
lc(empty($s->battle['strike']), 'Combat finishes after wounds pass');

$s = ls();
$s->battle['pending_gate_pick']=['owner'=>'host','source_id'=>1,'count'=>2,'chosen'=>[],'candidates'=>['1_1','1_2']];
foreach ([1,2] as $col) {
    lc($engine->apply($s,'host',new \Berserk\Core\Command('choose_gate',['row'=>1,'col'=>$col]))->success,
        'Native gate selection succeeds');
}
lc(empty($s->battle['pending_gate_pick']) && count($s->battle['gate_notice']['cells'])===2,
    'Gate notice is published after final placement');
$panel = new \Berserk\View\Screen\Battle\InfoPanel(new \Berserk\View\Template(__DIR__.'/../templates'));
foreach (['host','player'] as $viewer) {
    $html = $panel->render($s,$viewer,$viewer,[],'?game=1');
    lc(str_contains($html,'Врата расставлены') && str_contains($html,'data-dismiss-battle-notice'),
        'Both viewers can see and dismiss completed gate placement');
}
echo "Bot wounds pass and gate notice passed.\n";
