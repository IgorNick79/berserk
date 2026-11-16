<?php
declare(strict_types=1);
use Berserk\Core\{Autoloader,BattleHelper,Command,Engine,GameState,FlyingPreparation,ZoneManager,CardInstance};
require getcwd().'/src/Core/Autoloader.php';Autoloader::register();Autoloader::addNamespace('Berserk\\',getcwd().'/src/');
require __DIR__.'/flying_unit_fixture.php';
function fpAssert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
function fpFixture():GameState{
 $s=flyingUnitFixture(-999,1,2);$c=$s->getCard(1);$c->zone='field';$c->type='creature';$c->row=3;$c->col=3;
 (new ZoneManager($s))->toGraveyard($s->getCard(2));return $s;
}
$e=new Engine(clock:static fn()=>1000);
$s=fpFixture();fpAssert(isset(BattleHelper::getAttackTargets($s,$s->getCard(1),'strike','host')[4]),'single flyer offered for preparation');
$r=$e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>4]));fpAssert($r->success && empty($s->battle['strike']) && !$s->getCard(1)->closed,'click starts ack without battle or closing');
$p=$s->battle['pending_flying_preparation'];$ack=['operation_id'=>$p['id'],'confirm'=>0];
fpAssert($e->apply($s,'host',new Command('flying_preparation_ack',$ack))->success && !$s->getCard(1)->closed && !isset($s->getCard(1)->flags[FlyingPreparation::FLAG]),'cancel leaves card unchanged');
$e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>4]));$ack=['operation_id'=>$s->battle['pending_flying_preparation']['id'],'confirm'=>1];
fpAssert(!$e->apply($s,'player',new Command('flying_preparation_ack',$ack))->success,'opponent cannot confirm');
fpAssert($e->apply($s,'host',new Command('flying_preparation_ack',$ack))->success && $s->getCard(1)->closed,'confirm closes source');
fpAssert(!$e->apply($s,'host',new Command('flying_preparation_ack',$ack))->success,'duplicate confirmation rejected');
$s=GameState::fromArray($s->toArray());fpAssert($s->getCard(1)->flags[FlyingPreparation::FLAG]==='waiting','preparation survives snapshot roundtrip');
FlyingPreparation::changeTurn($s,'host','player');fpAssert(!FlyingPreparation::ready($s,$s->getCard(1)),'preparation inactive in opposing turn');
$s->battle['active']='host';FlyingPreparation::changeTurn($s,'player','host');$s->getCard(1)->closed=false;
$s->addCard(new CardInstance(5,'second-fly','player','flying',slot:2,hp:20,hpMax:20,type:'fly',closed:true));
$t=BattleHelper::getAttackTargets($s,$s->getCard(1),'strike','host');fpAssert(isset($t[4],$t[5]),'preparation allows either flyer');
$copy=GameState::fromArray($s->toArray());fpAssert($e->apply($copy,'host',new Command('strike',['card_id'=>1,'target_id'=>5]))->success && !empty($copy->battle['strike']),'prepared attack starts standard battle');
(new ZoneManager($s))->toField($s->getCard(2),5,3);$s->getCard(2)->dying=false;$s->getCard(2)->hp=20;
fpAssert(!isset(BattleHelper::getAttackTargets($s,$s->getCard(1),'strike','host')[4]) && !$e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>4]))->success,'incarnated ground creature blocks flying attack even at distance');
FlyingPreparation::changeTurn($s,'host','player');fpAssert(!isset($s->getCard(1)->flags[FlyingPreparation::FLAG]),'unused preparation expires');
$s=fpFixture();$s->getCard(1)->flags[FlyingPreparation::FLAG]='ready';(new ZoneManager($s))->toGraveyard($s->getCard(1));fpAssert(!isset($s->getCard(1)->flags[FlyingPreparation::FLAG]),'leaving battlefield clears preparation');
$s=fpFixture();$s->getCard(1)->prop=['can_attack_flying'=>true];fpAssert($e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>4]))->success && !empty($s->battle['strike']) && empty($s->battle['pending_flying_preparation']),'existing ability attacks without preparation');
$s=fpFixture();(new ZoneManager($s))->toField($s->getCard(2),4,3);$s->getCard(2)->dying=false;$s->getCard(2)->hp=20;
fpAssert(!FlyingPreparation::canPrepare($s,$s->getCard(1)),'ground creature prevents preparation');
fpAssert($e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>2]))->success,'adjacent ground attack preserved');
$s=fpFixture();(new ZoneManager($s))->toField($s->getCard(2),6,5);$s->getCard(2)->dying=false;$s->getCard(2)->hp=20;fpAssert(!$e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>2]))->success,'distant ground attack still rejected');

$s=fpFixture();$e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>4]));
$ack=['operation_id'=>$s->battle['pending_flying_preparation']['id'],'confirm'=>1];
(new ZoneManager($s))->toField($s->getCard(2),5,3);$s->getCard(2)->dying=false;$s->getCard(2)->hp=20;
fpAssert(!$e->apply($s,'host',new Command('flying_preparation_ack',$ack))->success && !$s->getCard(1)->closed,'ack revalidates changed board');
foreach (['closed','cannot_attack','defense','limit'] as $case) {
 $s=fpFixture();$c=$s->getCard(1);
 if($case==='closed')$c->closed=true;
 if($case==='cannot_attack')$c->modifiers[]=['stat'=>'cannot_attack','value'=>true];
 if($case==='defense')$s->getCard(4)->prop=['zoa'=>true];
 if($case==='limit')$c->flags['attacks_used_this_turn']=1;
 fpAssert(!$e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>4]))->success,'preparation respects '.$case);
}
