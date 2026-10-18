<?php
declare(strict_types=1);
// Test-only, in-memory placement transport. Replays real PrepareProcessor commands; never opens a DB.
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, CardInstance, Command, GameState};
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\View\Screen\PlaceScreen;
use Berserk\View\Template;
Autoloader::register(); Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
$role = ($argv[1] ?? 'host') === 'player' ? 'player' : 'host';
$steps = json_decode($argv[2] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
$_GET = json_decode($argv[3] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
$state = new GameState(970003, 1, 2); $state->status = 'place'; $state->firstPlayer = 'host';
$catalog = [];
foreach (['host','player'] as $owner) foreach (['one','two'] as $name) {
    $id = $state->nextInstanceId(); $ukid = $owner . '-' . $name;
    $state->cards[$id] = new CardInstance(instanceId:$id, ukid:$ukid, owner:$owner, zone:CardInstance::ZONE_SQUAD,
        hp:5, hpMax:5, price:2, moveMax:1, element:'dark', strikeWeak:1);
    $catalog[$ukid] = ['name'=>$owner === $role ? 'Моя карта ' . $name : 'opponent-secret-' . $name,
        'health'=>5, 'price'=>2, 'move'=>1, 'strike'=>['weak'=>1,'medium'=>2,'strong'=>3],
        'element_code'=>'dark','element_label'=>'Тьма','descr'=>'<p>Описание моей карты.</p>'];
}
$last = null;
foreach ($steps as $step) {
    $processor = new PrepareProcessor($state);
    $actor = $step['role']; $command = new Command($step['cmd'], $step);
    $last = match($step['cmd']) {
        'place_card' => $processor->placeCard($actor,$command),
        'unplace_card' => $processor->unplaceCard($actor,$command),
        'confirm_place' => $processor->confirmPlace($actor),
        default => throw new RuntimeException('Unexpected placement fixture command'),
    };
}
// Mirror the successful placement PRG: return to the unplaced cards list.
if (($_GET['cmd'] ?? '') === 'place_card' && $last !== null && $last->success) $_GET['card'] = '0';
$screenName = $state->status;
if ($screenName === 'place') {
    $tpl = new Template(__DIR__ . '/../templates');
    $result = (new PlaceScreen($tpl))->prepare($state,$role,$role,
        isset($_GET['cmd']) && $last !== null && !$last->success ? $last->error : null,$catalog);
    $screenData = $result['data']; $scriptsHtml = '';
    $source = file_get_contents(__DIR__ . '/../www/index.php');
    $start = strpos($source, '$isPreGame = \Berserk\View\Ui\PreGameLayout::supports($screenName);');
    if ($start === false) throw new RuntimeException('Page rendering block not found');
    $render = str_replace('__DIR__', var_export(dirname(__DIR__) . '/www', true), substr($source,$start));
    ob_start(); eval($render); $html = (string) ob_get_clean();
} else {
    // The destination loads through a normal navigation so its own battle scripts can initialize.
    $html = '<!doctype html><html><body class="battle-page"><div data-test-battle>Бой</div></body></html>';
}
echo json_encode(['html'=>$html,'status'=>$state->status,
    'remaining'=>count($state->getCardsInZone($role,CardInstance::ZONE_SQUAD)),
    'field'=>count($state->getCardsInZone($role,CardInstance::ZONE_FIELD)),
    'confirmed'=>$state->getPlayer($role)->isConfirmed('place'),
    'error'=>$last !== null && !$last->success ? $last->error : null],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
