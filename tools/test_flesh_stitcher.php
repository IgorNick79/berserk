<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,CardInstance,GameState,Engine,Command,Result,StitchRevival,EffectiveFeatures,PermanentFeatures,ZoneManager,BattleHelper,BattleWaitResolver,InformationalWaitManager,CardStats};
use Berserk\Core\Choice\ReviveChoice;
use Berserk\View\Ui\{CreatureChoice,Panel};
Autoloader::register(); Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
set_error_handler(static function(int $n,string $m,string $f,int $l):bool {if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
$checks=0;
function fsCheck(bool $ok,string $m):void {global $checks;++$checks;if(!$ok)throw new RuntimeException($m);}
function fsProp():array{return json_decode(file_get_contents(__DIR__.'/../docs/flesh-stitcher.prop.json'),true,512,JSON_THROW_ON_ERROR);}
function fsState(string $owner='host'):GameState {
 $s=new GameState(-24801,101,202);$s->status='battle';$s->battle=['active'=>$owner,'turn'=>1,'strike'=>null,'hidden_row_revealed'=>true];
 foreach([1,2,3,4,5,6] as $id) $s->addCard(new CardInstance(instanceId:$id,ukid:'fs_'.$id,owner:$id===6?($owner==='host'?'player':'host'):$owner,
 zone:in_array($id,[2,3])?'graveyard':'field',row:in_array($id,[2,3])?null:($id===1?3:6),col:in_array($id,[2,3])?null:$id%5+1,
 hp:in_array($id,[2,3])?0:10,hpMax:$id===3?7:10,element:'dark',move:1,moveMax:1,strikeWeak:$id===3?4:1,strikeMedium:2,strikeStrong:3));
 $s->getCard(1)->prop=fsProp();$s->getCard(1)->coins=4;
 $s->getCard(2)->prop=array_fill_keys(PermanentFeatures::KEYS,true)+['incarnation'=>1,'actions'=>[['type'=>'heal','value'=>10]],'horizontal_strike_reduction'=>true];
 return $s;
}
function fsReload(GameState $s):GameState{return GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));}
function fsCmd(GameState $s,string $type,array $args=[],?string $owner=null):Result{return (new Engine())->apply($s,$owner??$s->battle['active'],new Command($type,$args));}
function fsStart(GameState $s):Result{return fsCmd($s,'action',['card_id'=>1,'action_key'=>'stitch_revive']);}
function fsPick(GameState $s,int $id):Result{return fsCmd($s,'choose_revive_target',['target_id'=>$id,'choice_id'=>$s->battle['pending_revive']['choice_id']??'']);}
function fsCell(GameState $s,int $r=2,int $c=1,?string $token=null):Result{return fsCmd($s,'choose_revive_cell',['row'=>$r,'col'=>$c,'choice_id'=>$token??$s->battle['pending_revive']['choice_id']??'']);}
function fsReady():GameState{$s=fsState();fsCheck(fsStart($s)->success,'start');fsCheck(fsPick($s,2)->success,'revive pick');fsCheck(fsPick($s,3)->success,'exile pick');return $s;}
function fsHeal(GameState $s,int $id=4):Result{return fsCmd($s,'action',['card_id'=>1,'target_id'=>$id,'action_key'=>'dark_heal']);}
function fsAck(GameState &$s):void{foreach(['host','player']as$o)fsCheck(fsCmd($s,'confirm_strike',['wait_id'=>InformationalWaitManager::token($s)],$o)->success,'heal ACK');$s=fsReload($s);}

foreach(['host','player']as$owner){
 $s=fsState($owner);$raw=$s->getCard(2)->prop;
 fsCheck(StitchRevival::available($s,$s->getCard(1),fsProp()['actions'][0]),'legal combination');
 fsCheck(fsStart($s)->success,'start '.$owner);
 foreach(['card'=>2,'exile'=>3,'cell'=>null]as$step=>$id){
  $s=fsReload($s);fsCheck($s->battle['pending_revive']['step']===$step,'reload '.$step);
  $spec=(new ReviveChoice())->spec($s,$owner,[],'?game=1','host');fsCheck($spec!==null&&$spec->isMine,'own panel '.$step);
  $other=$owner==='host'?'player':'host';fsCheck(!(new ReviveChoice())->spec($s,$other,[],'?game=1','player')->isMine,'waiting other '.$step);
  fsCheck(BattleWaitResolver::current($s)['required_players']===[$owner],'native wait ownership '.$step);
  fsCheck($s->getCard(1)->coins===4&&!$s->getCard(1)->closed&&$s->getCard(3)->zone==='graveyard','no early payment '.$step);
  fsCheck(!fsCmd($s,'gain_coin',['card_id'=>1])->success,'pending blocks action');
  if($id!==null){$old=$s->battle['pending_revive']['choice_id'];fsCheck(fsPick($s,$id)->success,'choose '.$step);fsCheck(!fsCmd($s,'choose_revive_target',['target_id'=>$id,'choice_id'=>$old])->success,'duplicate stage rejected');}
  else {fsCheck(str_contains($spec->buttons[0]['label_html'],'coordinate'),'coordinate badge');$token=$s->battle['pending_revive']['choice_id'];fsCheck(fsCell($s)->success,'final');}
 }
 $s=fsReload($s);$a=$s->getCard(1);$r=$s->getCard(2);
 fsCheck($a->coins===1&&$a->closed&&!empty($a->flags[StitchRevival::USED]),'exact cost and limit');
 fsCheck($s->getCard(3)->zone==='exile'&&$r->zone==='field'&&$r->row===2&&$r->col===1,'moves');
 fsCheck([$r->strikeWeak,$r->strikeMedium,$r->strikeStrong,$r->hpMax,$r->hp]===[5,6,7,17,17],'X/Y and full health');
 fsCheck($r->closed&&$r->coins===0&&$r->prop===$raw,'native revive reset, raw unchanged');
 fsCheck(EffectiveFeatures::suppressed($r)&&count($r->effectiveProp())===count(PermanentFeatures::KEYS),'unique lost permanent kept');
 fsCheck(!fsCell($s,2,1,$token)->success&&$a->coins===1&&$r->hpMax===17,'duplicate final');
 foreach(['toHand','toGraveyard','toExile']as$method){$copy=fsReload($s);(new ZoneManager($copy))->$method($copy->getCard(1));fsCheck(EffectiveFeatures::suppressed($copy->getCard(2))&&$copy->getCard(2)->hpMax===17,'source exit '.$method);}
 (new Engine())->openCard($a);$a->coins=4;fsCheck(!fsStart($s)->success,'once after open');
 (new ZoneManager($s))->toGraveyard($a);(new ZoneManager($s))->toField($a,3,2);$a->hp=10;$a->closed=false;$a->coins=4;
 fsCheck(!fsStart($s)->success&&!empty($a->flags[StitchRevival::USED]),'limit survives source death return');
}
foreach(['one','no_ground','no_cell','coins','closed','web','dead','dying','hand','suppressed','used']as$case){
 $s=fsState();$a=$s->getCard(1);
 switch($case){case'one':$s->getCard(3)->zone='exile';break;case'no_ground':$s->getCard(2)->type='fly';$s->getCard(3)->type='fly';break;
 case'no_cell':foreach(StitchRevival::cells($s,$a)as$cell){[$r,$c]=array_map('intval',explode('_',$cell));$s->addCard(new CardInstance(instanceId:100+$r*10+$c,ukid:'block',owner:'host',zone:'field',row:$r,col:$c,hp:1,hpMax:1));}break;
 case'coins':$a->coins=2;break;case'closed':$a->closed=true;break;case'web':$a->markers['spider_web']=true;break;case'dead':$a->hp=0;break;case'dying':$a->dying=true;break;case'hand':$a->zone='hand';break;case'suppressed':EffectiveFeatures::applyMemoryLoss($s,$s->getCard(6),$a);break;case'used':$a->flags[StitchRevival::USED]=true;break;}
 fsCheck(!StitchRevival::available($s,$a,fsProp()['actions'][0]),'unavailable '.$case);$before=[$a->coins,$a->closed,$s->getCard(2)->zone,$s->getCard(3)->zone];fsCheck(!fsStart($s)->success,'reject '.$case);fsCheck($before===[$a->coins,$a->closed,$s->getCard(2)->zone,$s->getCard(3)->zone],'atomic start '.$case);
}
foreach(['coins','source_left','source_closed','suppressed','revive_left','exile_left','occupied','outside','same','wrong_owner','wrong_token']as$case){
 $s=fsReady();$a=$s->getCard(1);$token=$s->battle['pending_revive']['choice_id'];
 switch($case){case'coins':$a->coins=2;break;case'source_left':(new ZoneManager($s))->toHand($a);break;case'source_closed':$a->closed=true;break;case'suppressed':EffectiveFeatures::applyMemoryLoss($s,$s->getCard(6),$a);break;case'revive_left':$s->getCard(2)->zone='hand';break;case'exile_left':$s->getCard(3)->zone='hand';break;case'occupied':$s->getCard(4)->row=2;$s->getCard(4)->col=1;break;case'same':$s->battle['pending_revive']['exile_id']=2;break;}
 $s=fsReload($s);$a=$s->getCard(1);$before=[$a->coins,$a->closed,$s->getCard(2)->toArray(),$s->getCard(3)->toArray()];
 $args=['row'=>$case==='outside'?0:2,'col'=>1,'choice_id'=>$case==='wrong_token'?'bad':$token];fsCmd($s,'choose_revive_cell',$args,$case==='wrong_owner'?'player':'host');
 fsCheck($before===[$a->coins,$a->closed,$s->getCard(2)->toArray(),$s->getCard(3)->toArray()],'no partial stale final '.$case);
}
$s=fsState();fsCheck(fsStart($s)->success,'start cancellation');$token=$s->battle['pending_revive']['choice_id'];fsCheck(fsCmd($s,'cancel_pending',['choice_id'=>$token])->success&&$s->getCard(1)->coins===4&&!$s->getCard(1)->closed,'native cancel no refund inflation');
$s=fsReady();fsCheck(fsCell($s)->success,'first independent');$s->getCard(4)->prop=fsProp();$s->getCard(4)->coins=3;foreach([7,8]as$id)$s->addCard(new CardInstance(instanceId:$id,ukid:'another',owner:'host',zone:'graveyard',hpMax:3,strikeWeak:1));fsCheck(fsCmd($s,'action',['card_id'=>4,'action_key'=>'stitch_revive'])->success,'second instance independent');
foreach(['host','player']as$owner){$s=fsState($owner);$s->getCard(4)->hp=8;fsCheck(isset(BattleHelper::getAttackTargets($s,$s->getCard(1),'action:dark_heal',$owner)[4]),'heal targeting');fsCheck(fsHeal($s)->success,'heal');fsCheck($s->getCard(4)->hp===9&&$s->getCard(1)->coins===5&&$s->getCard(1)->closed,'heal coin tap');fsAck($s);(new Engine())->openCard($s->getCard(1));fsCheck(fsHeal($s)->success&&$s->getCard(1)->coins===6,'healing no battle limit');}
foreach(['full','enemy','other_element','dead','dying','hand','item','suppressed','source_dead','closed']as$case){$s=fsState();$a=$s->getCard(1);$t=$s->getCard(4);$t->hp=8;switch($case){case'full':$t->hp=10;break;case'enemy':$t->owner='player';break;case'other_element':$t->element='swamps';break;case'dead':$t->hp=0;break;case'dying':$t->dying=true;break;case'hand':$t->zone='hand';break;case'item':$t->type='artifact';break;case'suppressed':EffectiveFeatures::applyMemoryLoss($s,$s->getCard(6),$a);break;case'source_dead':$a->hp=0;break;case'closed':$a->closed=true;break;}$before=[$a->coins,$a->closed,$t->hp];fsCheck(!fsHeal($s)->success,'heal reject '.$case);fsCheck($before===[$a->coins,$a->closed,$t->hp],'no heal payment '.$case);}
$s=fsState();$s->getCard(1)->hp=8;fsCheck(isset(BattleHelper::getAttackTargets($s,$s->getCard(1),'action:dark_heal','host')[1])&&fsHeal($s,1)->success,'wounded self target');
$s=fsState();fsCheck(fsCmd($s,'gain_coin',['card_id'=>1])->success&&$s->getCard(1)->coins===5,'ordinary accumulation');
// Existing ordinary revive retains the two-stage path, payment/refund and flying arrival.
foreach(['creature','fly']as$type){$s=fsState();$s->getCard(1)->prop=['actions'=>[['key'=>'revive','type'=>'revive','coins'=>1]]];$s->getCard(2)->type=$type;fsCheck(fsCmd($s,'action',['card_id'=>1,'action_key'=>'revive'])->success,'legacy start');fsCheck(fsCmd($s,'choose_revive_target',['target_id'=>2])->success,'legacy pick');if($type==='creature')fsCheck(fsCmd($s,'choose_revive_cell',['row'=>2,'col'=>1])->success,'legacy cell');fsCheck($s->getCard(2)->zone===($type==='fly'?'flying':'field')&&!EffectiveFeatures::suppressed($s->getCard(2)),'legacy arrival');}
echo "OK: flesh stitcher ($checks checks)\n";
