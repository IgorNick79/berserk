<?php
declare(strict_types=1);
ob_start();require __DIR__.'/test_battle_snapshot_renderer.php';ob_end_clean();
use Berserk\View\Ui\LiveBattleCard;
$state=battleSnapshotState();$card=$state->getCard(1);$before=serialize($state->toArray());$samples=[];
foreach(['friendly'=>'host','enemy'=>'player'] as $side=>$owner){
    $card->owner=$owner;
    foreach([false,true] as $elite){
        $card->elite=$elite;$frame=$elite?'plain':'silver';
        $html=LiveBattleCard::render($card,$state,'host',false,'Проверка рамки','3/3');
        battleSnapshotAssert(str_contains($html,'frame_'.$side.'_102x92_'.$frame.'.svg'),'Correct owner/metal frame');
        $samples[$side.'_'.$frame]=$html;
    }
}
$card->owner='player';$card->elite=false;
$silverHidden=LiveBattleCard::render($card,$state,'host',true,'SECRET','?/?');
$card->elite=true;$goldHidden=LiveBattleCard::render($card,$state,'host',true,'SECRET','?/?');
battleSnapshotAssert($silverHidden===$goldHidden&&!str_contains($silverHidden,'SECRET'),'Hidden frame never exposes cost class or name');
$card->owner='host';$card->elite=false;
battleSnapshotAssert($before===serialize($state->toArray()),'Renderer does not mutate card state');
if(in_array('--fixture',$argv,true))echo json_encode($samples,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
else echo "Silver frames: gold/silver for both owners, hidden-data safety and state immutability passed.\n";
