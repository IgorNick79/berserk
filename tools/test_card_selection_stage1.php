<?php
declare(strict_types=1);

require __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\{Autoloader, CardInstance, Command, Engine, GameState};
use Berserk\Core\Choice\PairedRangedChoice;
use Berserk\View\Ui\{CardSelectionSpec, CreatureChoice, Panel};

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

$state = new GameState(970, 1, 2);
$state->status = 'battle';
$state->battle = ['active'=>'host', 'turn'=>2, 'strike'=>null, 'hidden_row_revealed'=>true];
foreach ([[1,'host',3,3,true], [2,'player',6,3,true], [3,'host',1,2,true], [4,'player',5,2,false]] as [$id,$owner,$row,$col,$visible]) {
    $state->addCard(new CardInstance(instanceId:$id, ukid:'card'.$id, owner:$owner, zone:'field',
        row:$row, col:$col, hp:20, hpMax:20, strikeWeak:1, strikeMedium:2, strikeStrong:3, revealed:$visible));
}
$state->getCard(1)->prop = ['actions'=>[['key'=>'pair', 'type'=>'paired_ranged', 'effects'=>[
    ['type'=>'discharge', 'value'=>1], ['type'=>'throw', 'value'=>1],
]]]];
$engine = new Engine();
$result = $engine->apply($state, 'host', new Command('action', ['card_id'=>1, 'action_key'=>'pair']));
if (!$result->success) throw new RuntimeException($result->error);

$handler = new PairedRangedChoice();
$catalog = ['card2'=>['name'=>'Одинаковая'], 'card3'=>['name'=>'Одинаковая'], 'card4'=>['name'=>'Секретная']];
$base = '/?first&game=970';
$spec = $handler->spec($state, 'host', $catalog, $base, 'host');
$html = Panel::render($spec);
$waiting = Panel::render($handler->spec($state, 'player', $catalog, '/?second&game=970', 'player'));
if (str_contains($waiting, 'data-card-instance') || str_contains($html, 'Секретная')) {
    throw new RuntimeException('Hidden card information was disclosed');
}
$masked = CreatureChoice::candidates($state, [4], 'host', $catalog, CardSelectionSpec::DEFAULT_MODE);
if ($masked[0]['label'] !== '???' || str_contains($masked[0]['label_html'], 'HP')) {
    throw new RuntimeException('Hidden enemy identity or health was disclosed');
}
$simple = CreatureChoice::candidates($state, [3, 2], 'host', $catalog, CardSelectionSpec::SIMPLE);
if (array_column($simple, 'instanceId') !== [2, 3]
    || !str_contains($simple[0]['label_html'], 'coords')
    || str_contains($simple[0]['label_html'], 'HP')) {
    throw new RuntimeException('Simple mode lost stable order or duplicate labels');
}
$spec->cardSelection->mode = CardSelectionSpec::EXTENDED;
echo json_encode(['panel'=>$html, 'extended'=>Panel::render($spec)], JSON_THROW_ON_ERROR);
