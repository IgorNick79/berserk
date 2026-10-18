<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\Autoloader;
use Berserk\Core\GameState;
use Berserk\View\Template;
use Berserk\View\Screen\ModeScreen;
use Berserk\View\Ui\PreGameLayout;
use Berserk\View\Ui\PreGameCard;
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
function foundationAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$tpl = new Template(__DIR__ . '/../templates');
$card = new PreGameCard($tpl);
$layout = new PreGameLayout($tpl);
$id = 'foundation-no-asset';
$visible = [$id => ['name' => str_repeat('ОченьДлинноеНазвание', 12) . '<script>', 'health' => 5,
    'move' => 2, 'strike' => ['weak' => 1, 'medium' => 2, 'strong' => 3],
    'rarity' => 'rare', 'element_code' => 'dark', 'element_label' => 'Тьма', 'instance_id' => 7,
    'descr' => str_repeat('<p>Подробное описание карты и её особенностей.</p>', 160)
        . '<script>alert(1)</script><img src="/images/icons/icon-tap.png" onerror="alert(1)">']];
$html = $card->render($id, $visible);
foundationAssert(str_contains($html, 'Изображение недоступно') && !str_contains($html, '<img class="pregame-card-image"'), 'Missing WebP');
foundationAssert(str_contains($html, 'aria-label="Описание карты"') && str_contains($html, '<template data-pregame-hint>'), 'Description info icon');
foundationAssert(!str_contains($html, '<script>') && !str_contains($html, 'onerror=') && str_contains($html, '&lt;script&gt;'), 'Escaped name and sanitized description');
foundationAssert(str_contains($html, '/assets/images/icons/icon-tap.png'), 'Description icons');
foundationAssert(str_contains($html, '/assets/images/element/dark.png'), 'Element icon');
foundationAssert(!str_contains($card->render('secret-card', $visible), 'Подробное'), 'Unlisted card denied');
foreach (['../secret', 'a/b', 'a%2fb', 'a" onclick="x', 'https://evil.test/a', ''] as $bad) {
    foundationAssert(str_contains($card->render($bad, [$bad => $visible[$id]]), 'Карта недоступна'), 'Unsafe ukid denied');
}
foreach (['common', 'uncommon', 'rare', 'ultrarare'] as $rarity) {
    $data = $visible; $data[$id]['rarity'] = $rarity;
    foundationAssert(!str_contains($card->render($id, $data), 'Редкость: —'), 'Known rarity');
}
foreach (['dark','plains','forests','swamps','mountains','neutral'] as $element) {
    $data = $visible; $data[$id]['element_code'] = $element;
    foundationAssert(str_contains($card->render($id, $data), '/assets/images/element/' . $element . '.png'), 'Known element');
}
// Temporary asset probes exercise existence checks without changing shipped images.
$assetId = 'foundation-' . bin2hex(random_bytes(8));
$assetFile = __DIR__ . '/../www/assets/cards/s1/' . $assetId . '.webp';
$createdDirs = [];
$dir = dirname($assetFile);
while (!is_dir($dir)) { $createdDirs[] = $dir; $dir = dirname($dir); }
foreach (array_reverse($createdDirs) as $dir) mkdir($dir);
try {
    file_put_contents($assetFile, 'existence-only-test');
    $assetHtml = $card->render($assetId, [$assetId => ['name' => 'Asset probe']]);
    foundationAssert(str_contains($assetHtml, '/assets/cards/s1/' . $assetId . '.webp'), 'Existing WebP URL');
    foundationAssert(str_contains($assetHtml, 'width="400" height="250"') && !str_contains($assetHtml, '.jpg'), '8:5 WebP only');
} finally {
    if (is_file($assetFile)) unlink($assetFile);
    foreach ($createdDirs as $dir) rmdir($dir);
}
$rarityFile = __DIR__ . '/../www/assets/images/rarity/s1_common.png';
$madeRarityFile = false;
$madeRarityDir = false;
try {
    if (!is_file($rarityFile)) {
        $probe = $visible; $probe[$id]['rarity'] = 'common';
        foundationAssert(str_contains($card->render($id, $probe), 'Редкость: Обычная') && !str_contains($card->render($id, $probe), 's1_common.png'), 'Missing rarity text fallback');
        if (!is_dir(dirname($rarityFile))) { mkdir(dirname($rarityFile)); $madeRarityDir = true; }
        file_put_contents($rarityFile, 'existence-only-test');
        $madeRarityFile = true;
    }
    $probe = $visible; $probe[$id]['rarity'] = 'common';
    foundationAssert(str_contains($card->render($id, $probe), '/assets/images/rarity/s1_common.png'), 'Existing rarity icon');
} finally {
    if ($madeRarityFile) unlink($rarityFile);
    if ($madeRarityDir) rmdir(dirname($rarityFile));
}
$decorated = $visible;
$decorated[$id] = array_merge($decorated[$id], ['elite'=>true, 'single'=>true, 'price'=>7, 'class'=>'Ангел <тест>', 'type'=>'fly']);
$decoratedHtml = $card->render($id, $decorated);
foundationAssert(str_contains($decoratedHtml, '/assets/images/card/elite-unique.png') && str_contains($decoratedHtml, '/assets/images/card/fly.png'), 'Cost and flight icons from main');
foundationAssert(str_contains($decoratedHtml, '/assets/images/card/class.png') && str_contains($decoratedHtml, 'Ангел &lt;тест&gt;'), 'Class metadata safely escaped');
foundationAssert(!str_contains($decoratedHtml, 'data-stat="move"'), 'Flying catalog cards do not show movement');
foundationAssert(strpos($decoratedHtml, 'pregame-card-heading') < strpos($decoratedHtml, 'pregame-card-media'), 'Heading precedes image');
$unknown = [$id => ['rarity' => '../../bad', 'element_code' => '../../bad']];
$unknownHtml = $card->render($id, $unknown);
foundationAssert(str_contains($unknownHtml, 'Редкость: —') && str_contains($unknownHtml, 'Стихия: —'), 'Unknown enums');
foundationAssert(str_contains($unknownHtml, 'aria-label="Здоровье: —"') && str_contains($unknownHtml, 'aria-label="Ходов: —"'), 'Missing stats');
foundationAssert(!str_contains($unknownHtml, '../../bad'), 'Enums cannot form arbitrary asset paths');
foundationAssert(str_contains($layout->render('', '<unsafe>'), '&lt;unsafe&gt;'), 'Escaped panel heading');
foundationAssert(str_contains($layout->render('', ''), 'Дополнительная информация появится здесь.'), 'Empty content');
try { $layout->render('', '', '', '', '', 'bad'); throw new RuntimeException('Invalid kind accepted'); }
catch (InvalidArgumentException $expected) {}
$state = new GameState(485, 1, 2);
$state->gameId = 485;
$host = (new ModeScreen($tpl))->prepare($state, 'host', 'host', null);
$guest = (new ModeScreen($tpl))->prepare($state, 'player', 'player', null);
$main = (string) $tpl->parse('screen/mode.tpl', $host['data']);
foundationAssert(str_contains($main, 'value="draft" checked') && str_contains($main, 'name="cmd" value="choose_mode"'), 'Mode command and default');
foundationAssert(strpos($main, 'value="draft"') < strpos($main, 'value="solomon"') && strpos($main, 'value="solomon"') < strpos($main, 'value="sealed"') && strpos($main, 'value="sealed"') < strpos($main, 'value="system"'), 'Mode order');
foundationAssert(!str_contains($guest['data']['content_html'], '<form') && str_contains($guest['data']['content_html'], 'Ожидание'), 'Guest waits');
// CSS slots contain a resolved URL; no nested placeholder expansion is required.
$resourceKey = hash('sha256', hash_file('sha256', __DIR__ . '/../www/assets/css/global.css')
    . hash_file('sha256', __DIR__ . '/../www/assets/css/pregame.css'));
$pageData = ['rkey' => $resourceKey, 'page_styles' => '<link rel="stylesheet" href="/assets/css/pregame.css?v=' . $resourceKey . '">'];
$pageOne = (string) $tpl->parse('page.tpl', $pageData);
$pageTwo = (string) $tpl->parse('page.tpl', $pageData);
foundationAssert($pageOne === $pageTwo, 'Stable resource version between renders');
foundationAssert(str_contains($pageOne, '/assets/css/pregame.css?v=' . $resourceKey), 'CSS URL is resolved independently of substitution order');
foundationAssert(str_contains($pageOne, '/assets/css/global.css?v=' . $resourceKey), 'Shared page CSS version');
foundationAssert(!str_contains($pageOne, '{{rkey}}'), 'No unresolved resource placeholder');
foundationAssert(!str_contains($layout->render($main, 'Режим игры'), 'Карта не выбрана'), 'Neutral mode panel');
if (in_array('--fixture', $argv, true)) {
    echo $tpl->parse('page.tpl', ['page_class'=>'pregame-page', 'game_id'=>485, 'role'=>'host',
        'page_styles'=>'<link rel="stylesheet" href="/assets/css/pregame.css">', 'scripts'=>'',
        'screen'=>$layout->render($main . str_repeat('<p>Основная область</p>', 100), 'Режим игры', $html . str_repeat('<p>Карточная информация</p>', 60), '<span>Место таймера</span>', '<button type="button">Управление</button>', 'card')]);
} else echo "PreGame foundation tests passed.\n";
