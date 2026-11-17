<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/test_lilith.php';
ob_end_clean();
$s = ls();
$s->persistenceVersion = 1;
$s->addCard(new \Berserk\Core\CardInstance(instanceId:10, ukid:'deck', owner:'host', zone:'deck'));
$engine = new \Berserk\Core\Engine();
(new \Berserk\Core\GraveRitual($s, $engine))->start($s->getCard(1), $cfg);
$pending = $s->battle['pending_grave_ritual'];
$plan = \Berserk\Core\Combat\CombatDecisionPlanner::decide(
    \Berserk\Core\Observation\PlayerObservation::build($s, 'host'),
    \Berserk\Core\Legal\LegalActions::build($s, 'host'));
lc($plan['action'] === ['type'=>'choose_grave_ritual', 'payload'=>['operation_id'=>$pending['id'], 'ack'=>1]],
    'Bot receives native result acknowledgement');
$opponent = \Berserk\Core\Legal\LegalActions::build($s, 'player');
lc($opponent['actions'] === [], 'Opponent cannot acknowledge owner result');
lc($engine->apply($s, 'host', new \Berserk\Core\Command($plan['action']['type'], $plan['action']['payload']))->success,
    'Native acknowledgement succeeds');
lc(empty($s->battle['pending_grave_ritual']) && empty($s->battle['grave_ritual']), 'Ritual completes');
$s->battle['pending_grave_ritual'] = ['id'=>'pick', 'owner'=>'host', 'candidates'=>[3]];
$legal = \Berserk\Core\Legal\LegalActions::build($s, 'host');
lc($legal['decision'] !== 'grave_ritual_ack', 'Target choice is not mistaken for a result');
$notice = $s->battle['grave_ritual_notice']['player'] ?? null;
lc(($notice['milled_ukid'] ?? null) === 'deck', 'Opponent retains milled card after bot acknowledgement');
$html = (new \Berserk\View\Screen\Battle\InfoPanel(new \Berserk\View\Template(__DIR__ . '/../templates')))
    ->render($s, 'player', 'second', ['deck'=>['name'=>'Отправленная карта']], '?game=1');
lc(str_contains($html, 'Отправленная карта') && str_contains($html, 'data-dismiss-battle-notice'),
    'Opponent sees a locally dismissible ritual notice');
echo "Bot grave ritual acknowledgement passed.\n";
