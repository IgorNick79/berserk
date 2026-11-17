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
$card->markers = ['prey'=>['value'=>-1, 'expire'=>2, 'source'=>'player']];
lc($effects::forCard($card, $state) === ['Добыча'], 'Prey star contains only the marker name');
$html = $effects::render($card, $state, 'host');
lc(str_contains($html, '<h4>Маркер «Добыча»</h4>') && !str_contains($html, 'Значение эффекта')
    && !str_contains($html, '-1'), 'Prey technical value is not presented as a gameplay amount');
$badge = $ns . 'Badge';
lc(!str_contains($badge::forCard($card, $state), '-1'), 'Legacy prey is compact too');
$card->markers = [];
$card->modifiers = [
    ['stat'=>'ova', 'value'=>2, 'expire'=>'end_of_turn', 'condition'=>'enemy_front_row_empty'],
    ['stat'=>'action_range', 'value'=>1, 'types'=>['throw'], 'expire'=>'end_of_turn'],
];
$card->markers = ['stun'=>['value'=>1, 'expire'=>2, 'timing'=>'owner_turn', 'sources'=>[12,13]]];
foreach ($effects::forCard($card, $state) as $short) {
    lc(!str_contains($short, '(') && !str_contains($short, 'expire')
        && !str_contains($short, 'sources') && !str_contains($short, 'enemy_front_row_empty'),
        'Shared short labels contain no technical qualifiers');
}
$html = $effects::render($card, $state, 'host');
lc(str_contains($html, 'первый ряд противника пуст') && str_contains($html, 'До конца текущего хода'),
    'Known conditions and duration remain in detailed panel');
$card->modifiers = [];
$card->markers = [];
$card->markers = ['prey'=>['value'=>-1], 'hunt'=>['value'=>1]];
$html = $effects::render($card, $state, 'host');
lc(str_contains($html, 'Маркер поставил Владыка небес') && str_contains($html, 'Маркер поставил Гиррит'),
    'Known marker provenance is shown in small secondary text');
lc(!str_contains($html, 'Значение эффекта'), 'Marker presence is not a numerical buff');
$card->markers = ['rooted'=>['sources'=>[3,2]]];
$state->getCard(2)->revealed = false;
$html = $effects::render($card, $state, 'host', [
    'test3'=>['name'=>'Открытый источник'], 'test2'=>['name'=>'Скрытый источник'],
]);
lc(str_contains($html, 'Открытый источник') && !str_contains($html, 'Скрытый источник'),
    'Source names respect visibility before HTML generation');
$card->markers = [];
if (($argv[1] ?? '') === '--fixtures') {
    $s = ls();
    $s->getCard(1)->armor=2;
    $s->getCard(1)->armorMax=2;
    $s->getCard(1)->coins=1;
    $s->getCard(1)->markers=['poison'=>['value'=>3]];
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
    $s->getCard(1)->markers=['prey'=>['value'=>-1], 'hunt'=>['value'=>1,'bonus'=>2], 'poison'=>['value'=>3]];
    $out['markers']=$view->prepare($s,'host','host',null,$catalog,['sel'=>1])['data'];
    $s->getCard(1)->markers=['poison'=>['value'=>3]];
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
