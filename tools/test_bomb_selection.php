<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/test_bomb_result_after_death.php';
ob_end_clean();
$s = resultCoordsState('bomb_shot', null, null);
$s->battle['strike'] = null;
$s->battle['active'] = 'host';
$s->battle['turn'] = 1;
$s->battle['hidden_row_revealed'] = true;
$s->getCard(2)->revealed = true;
$s->getCard(1)->row = 3;
$s->getCard(2)->row = 5;
$s->getCard(1)->prop = ['actions'=>[['key'=>'bomb','type'=>'bomb_shot','name'=>'Бомба','value'=>1,'bomb_damage'=>2,'range'=>2]]];
$info = [];
foreach ($s->cards as $card) $info[$card->ukid] = ['name'=>'Карта ' . $card->instanceId];
$ns = 'Berserk' . chr(92) . 'View' . chr(92);
$viewClass = $ns . 'Screen' . chr(92) . 'BattleScreen';
$templateClass = $ns . 'Template';
$view = new $viewClass(new $templateClass(__DIR__ . '/../templates'));
$ui = ['sel'=>1,'mode'=>'action:bomb','mode_explicit'=>true];
$active = $view->prepare($s,'host','host',null,$info,$ui)['data'];
$inspected = $view->prepare($s,'host','host',null,$info,$ui+['inspect'=>2])['data'];
resultCoordsAssert(str_contains($active['info_panel_html'],'data-card-selection=')
    && str_contains($active['info_panel_html'],'data-card-instance="2"'), 'Bomb target uses shared selection');
resultCoordsAssert(str_contains($active['field_html'],'inspect=2'), 'Field target opens inspection');
if (($argv[1] ?? '') === '--fixture') {
    echo json_encode(['active'=>$active,'inspected'=>$inspected],JSON_THROW_ON_ERROR);
} else {
    echo "Bomb card selection passed.\n";
}
