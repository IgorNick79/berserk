<?php
declare(strict_types=1);

require __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\{Autoloader, CardInstance, Command, Engine, GameState};
use Berserk\View\Screen\BattleSnapshotRenderer;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function fixture(string $type): GameState
{
    $state = new GameState(970, 1, 2);
    $state->status = 'battle';
    $state->battle = ['active'=>'host', 'turn'=>2, 'strike'=>null, 'hidden_row_revealed'=>true];
    $action = ['key'=>'stage3', 'type'=>$type, 'max_targets'=>2, 'value'=>1, 'min_x'=>1, 'filter'=>'own_forest'];
    for ($id = 1; $id <= 5; $id++) {
        $own = $id <= 3;
        $state->addCard(new CardInstance(instanceId:$id, ukid:'card'.$id, owner:$own ? 'host' : 'player',
            zone:'field', element:$own ? 'forests' : 'plains', row:$own ? 3 : 4, col:$id, hp:8, hpMax:10, coins:$id === 1 ? 3 : 0,
            strikeWeak:1, strikeMedium:2, strikeStrong:3, revealed:$id !== 5,
            prop:$id === 1 ? ['actions'=>[$action]] : []));
    }
    $state->addCard(new CardInstance(instanceId:6, ukid:'nonforest', owner:'host', zone:'field',
        element:'plains', row:2, col:1, hp:8, hpMax:10));
    $result = (new Engine())->apply($state, 'host', new Command('action', ['card_id'=>1, 'action_key'=>'stage3']));
    check($result->success, 'Open ' . $type . ': ' . $result->error);
    return $state;
}

$renderer = new BattleSnapshotRenderer(new Template(__DIR__ . '/../templates'));
$catalog = ['card1'=>['name'=>'Уголь'], 'card2'=>['name'=>'Ясень'], 'card3'=>['name'=>'Берёза'],
    'card4'=>['name'=>'Дуб'], 'card5'=>['name'=>'Секретная']];
$render = static fn(GameState $state, string $side = 'host'): array => $renderer->render($state, $side, $side, null, $catalog)['fragments'];
$engine = new Engine();
$heal = fixture('heal');
$gift = fixture('life_gift');
$fixtures = ['heal'=>$render($heal), 'gift'=>$render($gift), 'waiting'=>$render($gift, 'player')];
check(!str_contains($fixtures['waiting']['info_panel_html'], 'data-card-instance'), 'Waiting player sees no candidate rows');
check(!str_contains($fixtures['gift']['info_panel_html'], 'Секретная'), 'Concealed enemy name is masked');
$sameGift = GameState::fromArray($gift->toArray());
$sameGift->bumpVersion();
$fixtures['giftRefresh'] = $render($sameGift);
preg_match('/data-card-selection="([^"]+)"/', $fixtures['gift']['info_panel_html'], $originalContext);
preg_match('/data-card-selection="([^"]+)"/', $fixtures['giftRefresh']['info_panel_html'], $refreshedContext);
check($originalContext !== [] && $originalContext === $refreshedContext,
    'An unrelated version increment does not replace the life gift decision');
$ambiguous = GameState::fromArray($gift->toArray());
$ambiguous->battle['pending_life_gift']['allies'][] = 4;
$fixtures['ambiguous'] = $render($ambiguous);

$reduced = GameState::fromArray($heal->toArray());
$reduced->battle['pending_multi_heal']['candidates'] = [1, 2];
$fixtures['reduced'] = $render($reduced);

foreach ([[], [1, 2, 3], [4]] as $invalid) {
    check(!$engine->apply($heal, 'host', new Command('choose_multi_heal', ['target_ids'=>$invalid]))->success,
        'Reject invalid heal picks');
}
check($heal->battle['pending_multi_heal']['candidates'] === [1, 2, 3], 'Only own forest cards are eligible');
$cancelLink = (new \Berserk\Core\Choice\MultiHealChoice())->spec($heal, 'host', $catalog, '?first&game=970', 'host')->form['cancel'];
parse_str((string) parse_url($cancelLink, PHP_URL_QUERY), $cancelUi);
$cancelled = GameState::fromArray($heal->toArray());
check($engine->apply($cancelled, 'host', new Command('cancel_pending'))->success, 'Cancel heal');
check(!$cancelled->getCard(1)->closed && $cancelled->getCard(2)->hp === 8, 'Cancel does not resolve heal');
$afterCancel = $renderer->render($cancelled, 'host', 'host', null, $catalog, ['sel'=>1, 'mode'=>$cancelUi['mode']]);
check(!$afterCancel['ui']['mode_explicit'] && !str_contains($afterCancel['fragments']['info_panel_html'], 'name="target_id"'),
    'Cancel clears the targeting mode without reopening a generic target picker');
$fixtures['cancelled'] = $afterCancel['fragments'];
check($engine->apply($heal, 'host', new Command('choose_multi_heal', ['target_ids'=>[2, 3]]))->success, 'Confirm heal');
check($heal->getCard(2)->hp === 9, 'First heal target received healing');
$giftRetry = GameState::fromArray($gift->toArray());
check($engine->apply($giftRetry, 'host', new Command('cancel_pending'))->success, 'Cancel life gift');
check(!$giftRetry->getCard(1)->closed && $giftRetry->getCard(1)->coins === 3, 'Cancel preserves source and coins');
check($engine->apply($giftRetry, 'host', new Command('action', ['card_id'=>1, 'action_key'=>'stage3']))->success,
    'Reopen independent life gift on the same source');
preg_match('/data-card-selection="([^"]+)"/', $render($giftRetry)['info_panel_html'], $newContext);
check($newContext !== [] && $newContext !== $originalContext, 'Reopened gift gets a different context');
check(!$engine->apply($gift, 'host', new Command('choose_life_gift', ['amount'=>2, 'enemy_id'=>2, 'ally_id'=>3]))->success,
    'Reject targets from the wrong group');
check($engine->apply($gift, 'host', new Command('choose_life_gift', ['amount'=>2, 'enemy_id'=>4, 'ally_id'=>3]))->success,
    'Confirm life gift with exact existing fields');
check($gift->getCard(1)->coins === 1 && $gift->getCard(4)->hp === 6, 'Life gift spent coins and wounded the enemy');
echo json_encode($fixtures, JSON_THROW_ON_ERROR);
