<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/test_lilith.php';
ob_end_clean();
foreach (['heal', 'uchr', 'life_gift'] as $kind) {
    $s = ls();
    $s->persistenceVersion = 1;
    $source = $s->getCard(1);
    if ($kind === 'life_gift') {
        $source->coins = 2;
        $source->prop = ['actions'=>[['key'=>'gift', 'type'=>'life_gift', 'min_x'=>1]]];
        $s->getCard(3)->hp -= 3;
        foreach ([
            ['action', ['card_id'=>1, 'action_key'=>'gift']],
            ['choose_life_gift', ['amount'=>2]],
            ['choose_life_gift', ['enemy_id'=>2]],
        ] as [$type, $payload]) {
            $engine = new \Berserk\Core\Engine();
            $r = $engine->apply($s, 'host', new \Berserk\Core\Command($type, $payload));
            lc($r->success, 'Life gift step: ' . $r->error);
        }
        $command = new \Berserk\Core\Command('choose_life_gift', ['ally_id'=>3]);
    } elseif ($kind === 'heal') {
        $target = $s->getCard(3);
        $target->row = 2;
        $target->col = 3;
        $target->hp = 15;
        $source->prop = ['actions'=>[['key'=>'heal', 'type'=>'heal', 'value'=>2]]];
        $command = new \Berserk\Core\Command('action', ['card_id'=>1, 'target_id'=>3, 'action_key'=>'heal']);
    } else {
        $s->getCard(2)->row = 5;
        $s->getCard(3)->row = 4;
        $s->getCard(3)->col = 3;
        $source->prop = ['actions'=>[['key'=>'uchr', 'type'=>'uchr', 'value'=>2]]];
        $command = new \Berserk\Core\Command('uchr', ['card_id'=>1, 'target_id'=>2]);
    }
    $engine = new \Berserk\Core\Engine();
    $result = $engine->apply($s, 'host', $command);
    lc($result->success, 'Open native result: ' . $kind . ' ' . $result->error);
    lc(($s->battle['strike']['state'] ?? null) === 'results', 'Native result exists');
    foreach (['host', 'player'] as $viewer) {
        $observation = \Berserk\Core\Observation\PlayerObservation::build($s, $viewer);
        $legal = \Berserk\Core\Legal\LegalActions::build($s, $viewer);
        $plan = \Berserk\Core\Combat\CombatDecisionPlanner::decide($observation, $legal);
        lc(($plan['action']['type'] ?? null) === 'confirm_strike', 'Bot acknowledges ' . $kind . ' as ' . $viewer);
        lc($engine->apply($s, $viewer, new \Berserk\Core\Command(
            $plan['action']['type'], $plan['action']['payload']))->success, 'Native acknowledgement succeeds');
    }
    lc(empty($s->battle['strike']), 'Both acknowledgements finish ' . $kind);
}
echo "Bot healing and UCHR acknowledgements passed.\n";
