<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,BattleWaitResolver,CardInstance,Command,Dice,Engine,GameState,TurnPhaseProcessor};
use Berserk\Core\Choice\ShaksiRollChoice;
use Berserk\View\{Template};
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Ui\Panel;
Autoloader::register();Autoloader::addNamespace('Berserk\\',dirname(__DIR__).'/src/');
$checks=0;
function uiCheck(bool $ok,string $name): void { global $checks;++$checks;if(!$ok)throw new RuntimeException($name); }
function uiCard(int $id,string $owner,int $row,int $col,array $prop=[]): CardInstance {
    return new CardInstance(instanceId:$id,ukid:'fixture_'.$id,owner:$owner,zone:CardInstance::ZONE_FIELD,row:$row,col:$col,hp:8,hpMax:10,move:2,moveMax:2,strikeWeak:1,strikeMedium:2,strikeStrong:3,prop:$prop);
}
$_SESSION=['debug_roll'=>'1,6'];Dice::init();$remaining=Dice::remaining();
$fixtures=[];
foreach (['host','player'] as $viewer) foreach (range(1,6) as $dice) {
    $s=new GameState(-915,101,202);$s->status='battle';$s->battle=['active'=>$viewer,'turn'=>1,'strike'=>null];
    $s->addCard(uiCard(1,$viewer,2,1));$s->addCard(uiCard(2,$s->getOpponentKey($viewer),5,4));
    $s->battle['pending_shaksi_roll']=['source_id'=>1,'owner'=>$viewer,'dice'=>$dice,'effect'=>['type'=>'heal','value'=>3,'default_side'=>'all','include_self'=>true],'candidates'=>[1,2]];
    $_GET=['sh_side'=>'all'];$before=serialize($s);
    $spec=(new ShaksiRollChoice())->spec($s,$viewer,['fixture_1'=>['name'=>'Шакси'],'fixture_2'=>['name'=>'<script>цель</script>']],'?game=915',$viewer);
    $html=Panel::render($spec);
    uiCheck(substr_count($html,'<div class="dice">'.$dice.'</div>')===1,'Saved dice face rendered once');
    uiCheck(!str_contains($spec->title,'кубик '.$dice),'No numeric dice prose in title');
    uiCheck(str_contains($html,'coords--own') && str_contains($html,'coords--enemy'),'Both coordinate colors retained');
    uiCheck(str_contains($html,$viewer==='host'?'# [II:1]':'# [II:5]'),'Roman row and mirrored column');
    uiCheck(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>'),'Card names remain escaped');
    uiCheck(serialize($s)===$before && Dice::remaining()===$remaining,'Rendering never mutates state or rolls dice');
    uiCheck(!(new ShaksiRollChoice())->spec($s,$s->getOpponentKey($viewer),[],'?game=915',$s->getOpponentKey($viewer))->isMine,'Opponent remains waiting');
    $fixtures['shaksi-'.$viewer]=$html;
}
$s=new GameState(-916,101,202);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null,'hidden_row_revealed'=>true];
$s->addCard(uiCard(1,'host',3,3,['actions'=>[['key'=>'leap','type'=>'jump','range'=>3]]]));$s->addCard(uiCard(2,'player',6,5));
$view=new BattleScreen(new Template(dirname(__DIR__).'/templates'));
$info=['fixture_1'=>['name'=>'Болотник','health'=>10],'fixture_2'=>['name'=>'Противник','health'=>10]];
$before=serialize($s);
$normal=$view->prepare($s,'host','host',null,$info,['sel'=>1,'mode'=>'strike','pile'=>''])['data'];
$jump=$view->prepare($s,'host','host',null,$info,['sel'=>1,'mode'=>'action:leap','pile'=>''])['data'];
uiCheck(str_contains($normal['field_html'],'move-target') && !str_contains($normal['field_html'],'jump-target'),'Normal mode only shows moves');
uiCheck(str_contains($jump['field_html'],'jump-target') && !str_contains($jump['field_html'],'move-target'),'Jump mode only shows jumps');
uiCheck(!str_contains($jump['field_html'],'cmd=move') && str_contains($jump['field_html'],'cmd=jump'),'Jump cells never issue move command');
uiCheck(str_contains($jump['field_html'],'row=2&amp;col=3') || str_contains($jump['field_html'],'row=2&col=3'),'Overlapping adjacent cell remains available');
uiCheck(serialize($s)===$before,'Movement preview read-only');
$opponent=$view->prepare($s,'player','player',null,$info,['sel'=>1,'mode'=>'action:leap','pile'=>''])['data'];
uiCheck(!str_contains($opponent['field_html'],'jump-target') && !str_contains($opponent['field_html'],'move-target'),'Opponent cannot move selected foreign card');
$fixtures['bolotnik-normal']=$normal;$fixtures['bolotnik-jump']=$jump;
$b=new GameState(-917,101,202);$b->status='battle';$b->battle=['active'=>'player','turn'=>2,'strike'=>null,'hidden_row_revealed'=>true];
$b->addCard(uiCard(1,'host',3,1,json_decode(file_get_contents(dirname(__DIR__).'/docs/cards/s1_119.prop.json'),true,512,JSON_THROW_ON_ERROR)));$b->addCard(uiCard(2,'player',3,2));
$binfo=['fixture_1'=>['name'=>'Бал-Бугур','health'=>10],'fixture_2'=>['name'=>'Противник','health'=>10]];
$engine=new Engine();(new TurnPhaseProcessor($b,$engine))->beginStartPhase('player');
uiCheck(isset($b->battle['pending_cell_effect_direction']),'Lantern task opens real direction choice');
uiCheck(BattleWaitResolver::current($b)['required_players']===['host'],'Lantern clock ownership follows source owner on enemy turn');
$fixtures['bulbagur-choice']=$view->prepare($b,'host','host',null,$binfo,['sel'=>1,'mode'=>'strike','pile'=>''])['data'];
$fixtures['bulbagur-wait']=$view->prepare($b,'player','player',null,$binfo,['sel'=>2,'mode'=>'strike','pile'=>''])['data'];
uiCheck(str_contains($fixtures['bulbagur-choice']['info_panel_html'],'choose_cell_effect_direction'),'Actual InfoPanel shows direction controls');
uiCheck(!str_contains($fixtures['bulbagur-wait']['info_panel_html'],'choose_cell_effect_direction'),'Opponent has no direction command');
uiCheck($engine->apply($b,'host',new Command('choose_cell_effect_direction',['direction'=>'right']))->success,'Direction resolves via Engine');
$fixtures['bulbagur-lit']=$view->prepare($b,'host','host',null,$binfo,['sel'=>1,'mode'=>'strike','pile'=>''])['data'];
uiCheck(substr_count($fixtures['bulbagur-lit']['field_html'],'cell-marker-lantern_light')===3,'Exactly three light overlays after selection');
$dir=dirname(__DIR__).'/tmp/card-ability-ui';if(!is_dir($dir))mkdir($dir,0777,true);
foreach($fixtures as $name=>$content){
    $body=is_array($content)?'<div class="battle-main"><div class="battle-left"><div class="battle-layout"><div class="battle-field">'.$content['field_html'].'</div></div></div></div><aside class="info-panel">'.$content['info_panel_html'].'</aside>':'<aside class="info-panel">'.$content.'</aside>';
    file_put_contents($dir.'/'.$name.'.html','<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="http://card-ui.test/assets/css/global.css"></head><body class="battle-page"><div class="battle-shell">'.$body.'</div></body></html>');
}
echo "Card ability UI: $checks checks passed\n";
