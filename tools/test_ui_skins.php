<?php
declare(strict_types=1);
ob_start();require __DIR__.'/test_combat_panel_ui.php';ob_end_clean();
use Berserk\View\Ui\{DiceValue,Coords,PanelTheme};
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
foreach([1,2,3,4,5,6] as $n)battleSnapshotAssert(str_contains(DiceValue::render($n),'dice_'.$n.'.svg'),'Six real faces');
battleSnapshotAssert(str_contains(DiceValue::render(4,2),'>+2</span>')&&str_contains(DiceValue::render(4,2),'= 6</span>'),'Separate positive modifier and total');
battleSnapshotAssert(str_contains(DiceValue::render(3,-1),'>-1</span>')&&str_contains(DiceValue::render(3,-1),'= 2</span>'),'Negative modifier');
battleSnapshotAssert(!str_contains(DiceValue::render(5),'panel-dice-total'),'No redundant +0');
battleSnapshotAssert(!str_contains(DiceValue::render(0,3),'<img'),'No defender roll stays dash');
battleSnapshotAssert(!str_contains(DiceValue::render(7),'.svg'),'Out-of-range value is not a fake face');
battleSnapshotAssert(PanelTheme::valid('stone')&&!PanelTheme::valid('<script>')&&!PanelTheme::valid(['stone']),'Theme whitelist');
$coords=Coords::render(5,2,'host','host');
battleSnapshotAssert(str_contains($coords,'coords--own')&&str_contains($coords,'[II:2]')&&str_contains($coords,'coordinate.svg')&&!str_contains($coords,'#'),'Own creature on enemy half has own badge');
battleSnapshotAssert(Coords::render(9,2,'host')==='','Invalid coordinates omitted');
$s=battleSnapshotState();$s->getCard(2)->revealed=true;
$s->battle['strike']=['kind'=>'strike','state'=>'results','attacker_id'=>1,'target_id'=>2,'defender_id'=>null,
 'attack_dice'=>4,'defend_dice'=>3,'attack_mod'=>2,'defend_mod'=>-1,
 'result'=>['attack'=>'weak','defend'=>'','winner'=>'attack'],'final'=>['attack'=>'weak','defend'=>'','decreased'=>false],
 'damage'=>1,'damage_total'=>1,'confirmed'=>[]];
$before=serialize($s->toArray());
$dice=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,'host','host',null,$cardsInfo,['sel'=>1])['data'];
battleSnapshotAssert(str_contains($dice['info_panel_html'],'dice_4.svg')&&str_contains($dice['info_panel_html'],'= 6</span>'),'InfoPanel actual strike uses SVG + total');
battleSnapshotAssert($before===serialize($s->toArray()),'View does not mutate game');
$closed=combatPanelFixture('closed','host',1);
if(in_array('--fixture',$argv,true)) echo json_encode(['normal'=>combatPanelFixture('normal','host',1),'closed'=>$closed,'pending'=>combatPanelFixture('instant_result','host',1),'dice'=>$dice],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
else echo "UI skins: theme whitelist, dice +/-/0/no-roll/fallback, badge orientation/ownership, actual InfoPanel and immutable state passed.\n";
