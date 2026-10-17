<?php
declare(strict_types=1);
ob_start(); require __DIR__ . '/test_battle_snapshot_renderer.php'; ob_end_clean();
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
$state = battleSnapshotState();
$card = $state->getCard(1);
$card->ukid = 's1_ui-' . bin2hex(random_bytes(6));
$card->element = 'mountains';
$card->prop['save_coins']=true; $card->prop['coins']=['max_value'=>4];
$card->prop['ova']=1; $card->prop['ovz']=2; $card->prop['regeneration']=['value'=>3];
$card->modifiers=[['stat'=>'ova','value'=>1],['stat'=>'regeneration','value'=>1]];
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
        $card->closed=true;
        $closedHtml=$render();
        battleSnapshotAssert(substr_count($closedHtml, 'Карта закрыта')===1 && str_contains($closedHtml, 'button small disabled'), 'Closed card keeps button without duplicate text');
        $state->battle['active']='player';
        battleSnapshotAssert(substr_count($render(), 'Карта закрыта')===1, 'Status remains when mode button is absent');
        $state->battle['active']='host'; $card->closed=false;

        battleSnapshotAssert(str_contains($html, 'aria-label="Монеты: 0/4"') && str_contains($html, 'data-stat="coins"'), 'Coin-saving cards show zero coins and limit');
        foreach (['Здоровье: 3/3','Ходов: 1/1','Простой удар: 1/2/3','Опыт в атаке: 2','Опыт в защите: 2','Регенерация: 4'] as $label) {
            battleSnapshotAssert(str_contains($html, 'aria-label="'.$label.'"'), 'Stat hint uses existing values: '.$label);
        }
        battleSnapshotAssert(!str_contains($html, '@2x'), 'Normal-resolution assets used');
        battleSnapshotAssert(!str_contains($render(), '/assets/images/card/fly.png'), 'Ground card has no flying icon');
        $card->type='fly';
        battleSnapshotAssert(!str_contains($render(), 'data-stat="move"'), 'Intrinsic flying card hides movement');
        $flying = $render();
        battleSnapshotAssert(strpos($flying, '/assets/images/card/fly.png') > strpos($flying, 'data-stat="regen"'), 'Flying icon follows ability values');
        battleSnapshotAssert(str_contains($flying, '/assets/images/card/fly.png'), 'Normal flying asset');

        $card->type='creature'; $card->zone='flying';
        battleSnapshotAssert(!str_contains($render(), 'data-stat="move"'), 'Acquired flight hides movement');
        battleSnapshotAssert(str_contains($render(), 'aria-label="Летающее существо"'), 'Acquired flight also shows hint');
        $card->zone='field';
        $prop=$card->prop; $mods=$card->modifiers; $card->prop=[]; $card->modifiers=[];
        foreach (['ova','ovz','regen'] as $stat) battleSnapshotAssert(!str_contains($render(),'data-stat="'.$stat.'"'), 'Absent ability omitted');
        $card->prop=$prop; $card->modifiers=$mods;

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
        battleSnapshotAssert(str_contains($html, '/assets/images/card/class.png') && str_contains($html, 'Класс существа: Эльф, Воин'), 'Class icon and hint');
        foreach (['common','uncommon','rare','ultrarare'] as $rarity) {
            $cardsInfo[$card->ukid]['rarity']=$rarity;
            battleSnapshotAssert(str_contains($render(), '/assets/images/rarity/s1_'.$rarity.'.png'), 'Rarity follows database value');
        }
        $cardsInfo[$card->ukid]['class']='<script>test</script>';
        battleSnapshotAssert(!str_contains($render(), '<script>') && str_contains($render(), '&lt;script&gt;'), 'Class text escaped');
        $cardsInfo[$card->ukid]['class']='';
        battleSnapshotAssert(!str_contains($render(), '/assets/images/card/class.png'), 'No empty class icon');
        unset($cardsInfo[$card->ukid]['class']); $card->classes=['Воин'];
        battleSnapshotAssert(str_contains($render(), 'Воин'), 'Existing class list fallback');
        $cardsInfo[$card->ukid]['rarity']='../bad';
        battleSnapshotAssert(!str_contains($render(), '/assets/images/rarity/'), 'Invalid rarity omitted');
        unlink($path);
        battleSnapshotAssert(str_contains($render(), '/assets/images/card/class.png') && str_contains($render(), 'panel-card-media') && !str_contains($render(), 'panel-card-preview'), 'Class and reserved media remain without preview');
        echo "Card panel metadata tests passed.\n";
    }
} finally {
    if(is_file($path))unlink($path);
    foreach($created as $dir)rmdir($dir);
}
