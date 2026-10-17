<?php
declare(strict_types=1);
ob_start(); require __DIR__ . '/test_battle_snapshot_renderer.php'; ob_end_clean();
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
$state = battleSnapshotState();
$card = $state->getCard(1);
$card->ukid = 's1_ui-' . bin2hex(random_bytes(6));
$card->element = 'mountains';
$cardsInfo[$card->ukid] = ['name'=>'Metadata card', 'health'=>3, 'rarity'=>'rare', 'price'=>7, 'elite'=>true, 'single'=>true, 'class'=>'Эльф, Воин', 'element'=>'Горы'];
$path = __DIR__ . '/../www/assets/cards/s1/' . $card->ukid . '.webp';
$created=[]; $dir=dirname($path);
while (!is_dir($dir)) { $created[]=$dir; $dir=dirname($dir); }
foreach (array_reverse($created) as $dir) mkdir($dir);
$render = function () use ($state, &$cardsInfo) {
    return (new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($state,'host','host',null,$cardsInfo,['sel'=>1])['data']['panel_html'];
};
try {
    file_put_contents($path, 'fixture');
    if (in_array('--panel', $argv ?? [], true)) {
        echo json_encode($render(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $html = $render();
        foreach ([[false,false,'ordinary'],[false,true,'ordinary-unique'],[true,false,'elite'],[true,true,'elite-unique']] as [$elite,$single,$icon]) {
            $cardsInfo[$card->ukid]['elite']=$elite;
            $cardsInfo[$card->ukid]['single']=$single;
            battleSnapshotAssert(str_contains($render(), '/assets/images/card/'.$icon.'.png'), 'Cost icon follows elite/single');
        }
        battleSnapshotAssert(str_contains($html, 'Стоимость: 7'), 'Database price displayed');
        $cardsInfo[$card->ukid]['price']=0;
        battleSnapshotAssert(str_contains($render(), 'Стоимость: 0'), 'Zero cost preserved');
        $cardsInfo[$card->ukid]['price']=7;

        battleSnapshotAssert(strpos($html, 'panel-card-title') < strpos($html, 'panel-card-preview'), 'Title above artwork');
        battleSnapshotAssert(str_contains($html, '/assets/images/element/mountains.png'), 'Element icon uses new directory');
        battleSnapshotAssert(str_contains($html, 'panel-card-class') && str_contains($html, 'Эльф, Воин'), 'Class next to title');
        foreach (['common','uncommon','rare','ultrarare'] as $rarity) {
            $cardsInfo[$card->ukid]['rarity']=$rarity;
            battleSnapshotAssert(str_contains($render(), '/assets/images/rarity/s1_'.$rarity.'.png'), 'Rarity follows database value');
        }
        $cardsInfo[$card->ukid]['class']='<script>test</script>';
        battleSnapshotAssert(!str_contains($render(), '<script>') && str_contains($render(), '&lt;script&gt;'), 'Class text escaped');
        $cardsInfo[$card->ukid]['class']='';
        battleSnapshotAssert(!str_contains($render(), 'panel-card-class'), 'No empty class badge');
        unset($cardsInfo[$card->ukid]['class']); $card->classes=['Воин'];
        battleSnapshotAssert(str_contains($render(), 'Воин'), 'Existing class list fallback');
        $cardsInfo[$card->ukid]['rarity']='../bad';
        battleSnapshotAssert(!str_contains($render(), '/assets/images/rarity/'), 'Invalid rarity omitted');
        unlink($path);
        battleSnapshotAssert(str_contains($render(), 'panel-card-class') && !str_contains($render(), 'panel-card-preview'), 'Class remains in heading without preview');
        echo "Card panel metadata tests passed.\n";
    }
} finally {
    if(is_file($path))unlink($path);
    foreach($created as $dir)rmdir($dir);
}
