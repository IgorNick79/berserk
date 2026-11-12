<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/test_lilith.php';
ob_end_clean();
$state = ls();
$card = $state->getCard(1);
$ns = 'Berserk' . chr(92) . 'View' . chr(92) . 'Ui' . chr(92);
$effects = $ns . 'ActiveCardEffects';
$live = $ns . 'LiveBattleCard';
lc($effects::render($card, $state, 'host') === '', 'No empty effects block');
$card->armor = 2;
$card->coins = 3;
$card->markers = ['poison'=>['value'=>2]];
lc($effects::entries($card, $state) === [], 'Separate indicators are not duplicated');
$card->modifiers = [
    ['stat'=>'ability_strike', 'value'=>2, 'source'=>'prophecy_charge', 'expire'=>'permanent'],
    ['stat'=>'damage_reduction', 'value'=>1, 'types'=>['strike','throw','uchr'],
        'expire'=>'end_of_opponent_turn', 'source'=>'host'],
    ['stat'=>'damage_reduction', 'value'=>1, 'types'=>['strike','throw','uchr'],
        'expire'=>'end_of_opponent_turn', 'source'=>'player'],
];
$entries = $effects::entries($card, $state);
lc(count($entries) === 3, 'Different sources remain separate');
lc(array_column($entries, 'short') === $effects::forCard($card, $state), 'One enumeration for both views');
$html = $effects::render($card, $state, 'host');
lc(substr_count($html, '<article ') === count($entries), 'One panel block per star effect');
lc(!str_contains($html, '<ul') && !str_contains($html, '<li'), 'No list presentation');
lc(str_contains($html, 'удар через ряд') && str_contains($html, 'метание'), 'Attack types explained');
lc(str_contains($html, 'снимается после удара') && !str_contains($html, 'постоянно'), 'Consumption overrides misleading permanent wording');
lc(!str_contains($html, 'source') && !str_contains($html, 'host') && !str_contains($html, 'player'), 'No internal source fields exposed');
$mini = $live::render($card, $state, 'host', false, 'Карта', '20/20');
lc(str_contains($mini, 'Активные эффекты: 3'), 'Star count matches panel');
$card->modifiers[0]['value'] = 3;
lc(str_contains($effects::render($card, $state, 'host'), 'на 3'), 'Changed value is current');
$card->owner = 'player';
$card->revealed = false;
lc($effects::render($card, $state, 'host') === '', 'Concealed enemy emits no effects HTML');
$card->revealed = true;
lc($effects::render($card, $state, 'host') !== '', 'Revealed enemy can be inspected');
$card->modifiers = [];
lc($effects::render($card, $state, 'host') === '', 'Removing effects clears panel');
if (($argv[1] ?? '') === '--fixtures') {
    $s = ls();
    $catalog = ['test1'=>['name'=>'Выбранное существо','descr'=>'Описание через информационный значок'],
        'test2'=>['name'=>'Существо противника'], 'test3'=>['name'=>'Другое существо']];
    $viewClass = 'Berserk' . chr(92) . 'View' . chr(92) . 'Screen' . chr(92) . 'BattleScreen';
    $templateClass = 'Berserk' . chr(92) . 'View' . chr(92) . 'Template';
    $view = new $viewClass(new $templateClass(__DIR__ . '/../templates'));
    $mods = [
        ['stat'=>'ability_strike','value'=>2,'source'=>'prophecy_charge','expire'=>'permanent'],
        ['stat'=>'damage_reduction','value'=>1,'types'=>['strike','shot','throw','tap','uchr','execute','impact'],
            'expire'=>'end_of_opponent_turn','source'=>'host'],
        ['stat'=>'ova','value'=>1,'expire'=>'end_of_turn'],
    ];
    $out = [];
    foreach (['none'=>[], 'one'=>[$mods[0]], 'many'=>$mods, 'changed'=>[array_replace($mods[0],['value'=>3])]] as $key=>$modifiers) {
        $s->getCard(1)->modifiers = $modifiers;
        $out[$key] = $view->prepare($s,'host','host',null,$catalog,['sel'=>1])['data'];
    }
    $s->getCard(1)->modifiers=[];
    $out['other']=$view->prepare($s,'host','host',null,$catalog,['sel'=>3])['data'];
    $s->getCard(2)->modifiers=$mods;
    $s->getCard(2)->revealed=true;
    $out['enemy']=$view->prepare($s,'host','host',null,$catalog,['inspect'=>2])['data'];
    $s->getCard(2)->revealed=false;
    $out['hidden']=$view->prepare($s,'host','host',null,$catalog,['inspect'=>2])['data'];
    echo json_encode($out,JSON_THROW_ON_ERROR);
    exit;
}
echo "Active effects panel checks passed.\n";
