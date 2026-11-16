<?php
declare(strict_types=1);
define('C1_FIXTURES_ONLY',true);require __DIR__.'/test_single_roll_c1.php';
use Berserk\Core\{CardInstance,Dice,Engine,Command,GameState,ProphecyProcessor,InformationalWaitManager as IW};
use Berserk\View\{Template,Screen\BattleScreen};
$fixtures=[];$screen=new BattleScreen(new Template(getcwd().'/templates'));
foreach(['host','player']as$owner)foreach([true,false]as$elite){
 $s=c1state('strike',false);$s->nextInstanceId=100;$s->battle['active']=$owner;
 if($owner==='player')foreach($s->cards as$c)$c->owner=$c->owner==='host'?'player':'host';
 $s->getCard(1)->ukid='s1_132';$s->getCard(2)->ukid='s1_151';$s->getCard(1)->closed=false;
 $s->getCard(1)->prop=['strike_prophecy'=>['count'=>2,'all_elite'=>['damage'=>2],'all_ordinary'=>['poison'=>2]]];
 foreach([10,11]as$id)$s->addCard(new CardInstance(instanceId:$id,ukid:'shown_'.$id,owner:$owner,zone:'deck',order:$id,elite:$elite,hp:5,hpMax:5));
 $info=[];foreach($s->cards as$c)$info[$c->ukid]=['name'=>match($c->ukid){'s1_132'=>'Двухголовый тролль','s1_151'=>'Зомби',default=>$c->ukid},'prop'=>$c->prop];
 $render=fn(GameState $state,string $viewer)=>$screen->prepare($state,$viewer,$viewer,null,$info,['inspect'=>2])['data']['info_panel_html'];
 $before=$render($s,$owner);$_SESSION=['debug_roll'=>'6,1'];Dice::init();$e=new Engine();
 c1check($e->apply($s,$owner,new Command('strike',['card_id'=>1,'target_id'=>2]))->success,'Native Troll strike');
 c1check(($s->battle['pending_prophecy']['context']??null)==='strike','Native prophecy opened');
 c1check($s->battle['pending_prophecy']['title_target_ukid']==='s1_151','Target identity retained');
 if(!$elite)c1check(($s->getCard(2)->markers['poison']['value']??null)===2,'Native poison applied');
 $expected=$elite?'Зомби получает +2 урона':'Зомби: отравление 2';
 foreach(['host','player']as$viewer){
  $html=$render($s,$viewer);$d=new DOMDocument();@$d->loadHTML('<?xml encoding="UTF-8">'.$html);$x=new DOMXPath($d);
  c1check(str_contains($html,$expected),'Both viewers see named damage/poison result');
  c1check(!str_contains($x->query('//div[contains(@class,"card-choice")]/h3')->item(0)->textContent,'s1_151'),'No technical card code in title');
  c1check(str_contains($html,'data-instant-result="prophecy:'),'Both viewers foreground prophecy over retained card');
  c1check($x->query('//a[contains(@href,"cmd=close_prophecy")]')->length===($viewer===$owner?1:0),'Only prophecy owner can close');
 }
 $result=$render($s,$owner);$hp=$s->getCard(2)->hp;$markers=$s->getCard(2)->markers;
 if(!$elite){$old=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));unset($old->battle['strike']['prophecy_effect_result']);
  c1check((new ProphecyProcessor($old,$e))->close($owner)['ok'],'Older in-flight poison prophecy closes');
  c1check(($old->battle['strike']['prophecy_effect_result']['after']??null)===2&&$old->getCard(2)->markers===$markers,'Older save gets notice without reapplying poison');}
 $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));
 c1check(str_contains($render($s,$owner),$expected),'Names survive F5');
 $p=new ProphecyProcessor($s,$e);c1check(!$p->close($s->getOpponentKey($owner))['ok'],'Opponent close rejected');
 c1check($e->apply($s,$owner,new Command('close_prophecy'))->success&&$s->getCard(2)->hp===$hp&&$s->getCard(2)->markers===$markers,'Close never repeats damage or poison');
 $fixtures[$owner.($elite?'_damage':'_poison')]=['before'=>$before,'result'=>$result,'expected_text'=>$expected,'confirm_label'=>'Закрыть'];
 $legacy=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));$legacy->battle['pending_prophecy']=['owner'=>$owner,'source_id'=>1,'source_ukid'=>'s1_132','context'=>'strike','shown_ukids'=>[],'actions'=>[],'title'=>$elite?'обе элитные — s1_151 получает +2 урона':'обе рядовые — отравление 2'];
 c1check(str_contains($render($legacy,$owner),$expected),'Already saved damage/poison title resolves card name');
 c1check(empty($s->battle['strike']['prophecy_effect_result_active']),'Extra information is not before battle results');
 $normal=[];foreach(['host','player']as$viewer)$normal[$viewer]=$render($s,$viewer);
 $token=IW::token($s);
 c1check($e->apply($s,$owner,new Command('confirm_strike',['wait_id'=>$token]))->success,'First battle ACK');
 c1check(empty($s->battle['strike']['prophecy_effect_result_active']),'One battle ACK does not advance');
 $other=$s->getOpponentKey($owner);
 c1check($e->apply($s,$other,new Command('confirm_strike',['wait_id'=>IW::token($s)]))->success,'Second battle ACK');
 c1check(!empty($s->battle['strike']['prophecy_effect_result_active']),'New information follows battle results');
 c1check($s->getCard(2)->hp===$hp&&$s->getCard(2)->markers===$markers,'Opening information never repeats effects');
 $effectText=$elite?'Дополнительные раны от пророчества: 2':'Отравление: 2';
 foreach(['host','player']as$viewer){$html=$render($s,$viewer);
  c1check(str_contains($html,'data-strike-prophecy-result')&&str_contains($html,'Зомби'),'Both sides get named post-combat information');
  c1check(str_contains($html,'cmd=confirm_strike'),'Both sides have confirmation');
  $fixtures[$owner.($elite?'_damage':'_poison').'_after_'.$viewer]=['before'=>$normal[$viewer],'result'=>$html,'expected_text'=>$effectText];
 }
 $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));
 c1check(str_contains($render($s,$other),'data-strike-prophecy-result'),'F5 preserves post-combat information');
 $beforeStale=$s->toArray();c1check(!$e->apply($s,$owner,new Command('confirm_strike',['wait_id'=>$token]))->success&&$s->toArray()===$beforeStale,'Battle ACK cannot dismiss new information');
 c1check($e->apply($s,$owner,new Command('confirm_strike',['wait_id'=>IW::token($s)]))->success,'First information ACK');
 c1check(!empty($s->battle['strike']['prophecy_effect_result_active']),'Other player still must acknowledge');
 $snapshot=$s->toArray();c1check(!$e->apply($s,$owner,new Command('confirm_strike',['wait_id'=>IW::token($s)]))->success&&$snapshot===$s->toArray(),'Duplicate information ACK inert');
 c1check($e->apply($s,$other,new Command('confirm_strike',['wait_id'=>IW::token($s)]))->success,'Second information ACK');
 c1check(empty($s->battle['strike'])&&$s->getCard(2)->hp===$hp&&$s->getCard(2)->markers===$markers,'Continue once without repeating poison or wounds');
 $ui=['sel'=>1];$full=$screen->prepare($s,$owner,$owner,null,$info,$ui)['data'];
 $ajax=(new \Berserk\View\Screen\BattleSnapshotRenderer(new Template(getcwd().'/templates')))->render($s,$owner,$owner,null,$info,$ui);
 c1check($full['panel_html']===$ajax['fragments']['panel_html'],'Closed-card status identical on full page and AJAX');
 c1check(str_contains($full['panel_html'],'class="panel-card-status" data-panel-card-status role="status">Карта закрыта'),'Closed card uses shared badge markup');
 $fixtures['closed_status']=['full'=>$full['info_panel_html'],'fragments'=>$ajax['fragments']];
}
$dir=getenv('TROLL_UI_FIXTURES');if($dir)foreach($fixtures as$key=>$data)file_put_contents($dir.'/troll-'.$key.'.json',json_encode($data,JSON_THROW_ON_ERROR));
echo "Troll prophecy UI: $checks checks passed\n";
