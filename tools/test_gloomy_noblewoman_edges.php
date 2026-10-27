<?php
declare(strict_types=1);
require __DIR__.'/test_gloomy_noblewoman.php';
use Berserk\Core\{CardHealing,CardStats,Command,Engine,GameState,EffectiveFeatures,DamageResolver,InstantProcessor,ActionResolver,StrikeResolver,TurnProcessor,TurnPhaseProcessor,ValhallaProcessor,ZoneManager,InformationalWaitManager};
use Berserk\Core\Choice\ChoiceRegistry;
function gnHeal(GameState$s,int$target=7,int$source=5):\Berserk\Core\Result{return gnCmd($s,$s->getCard($source)->owner,'action',['card_id'=>$source,'target_id'=>$target,'action_key'=>'heal']);}
function gnDecision(GameState$s,string$apply='yes'):void{$owner=$s->battle['pending_card_heal']['owner'];gnCheck(gnCmd($s,$owner,'choose_card_heal',['heal_token'=>'$current','apply'=>$apply])->success,'noble decision');}
function gnAck(GameState$s):void{foreach(['host','player']as$o)gnCheck(gnCmd($s,$o,'confirm_strike',['wait_id'=>InformationalWaitManager::token($s)])->success,'ack '.$o);}
$edgeStart=$checks;
// Thresholds, amounts and genuine decline at the first attempt.
foreach([7,8,9,12]as$hp)foreach([1,2,3]as$value)foreach(['host','player']as$targetOwner){
 $s=gnState($fixture);$s->getCard(1)->hp=$hp;$s->getCard(2)->hp=8;$s->getCard(5)->prop['actions'][0]['value']=$value;
 $id=$targetOwner==='host'?7:8;$before=$s->getCard($id)->hp;gnCheck(gnHeal($s,$id)->success,'threshold heal');
 $mode=$hp<8&&$targetOwner==='host'?'double':($hp>8&&$targetOwner==='player'?'block':null);
 gnCheck(isset($s->battle['pending_card_heal'])===($mode!==null),'threshold pending '.$hp);
 if($mode!==null){$s=gnCopy($s);gnDecision($s);}
 $expected=$mode==='block'?0:($mode==='double'?2*$value:$value);
 gnCheck($s->getCard($id)->hp===$before+$expected,'threshold final amount');
}
$s=gnState($fixture);$s->getCard(2)->hp=8;gnCheck(gnHeal($s)->success,'decline start');$token=$s->battle['pending_card_heal']['token'];$before=gnCopy($s)->toArray();
gnCheck(!gnCmd($s,'player','choose_card_heal',['heal_token'=>$token,'apply'=>'yes'])->success,'wrong owner');
gnCheck(!gnCmd($s,'host','choose_card_heal',['heal_token'=>'stale','apply'=>'yes'])->success,'stale token');
gnDecision($s,'no');gnCheck($s->getCard(7)->hp===5,'declined ordinary amount');
gnCheck(!gnCmd($s,'host','choose_card_heal',['heal_token'=>$token,'apply'=>'yes'])->success&&$s->getCard(7)->hp===5,'duplicate decision');
gnAck($s);$s->getCard(5)->closed=false;gnCheck(gnHeal($s)->success&&!CardHealing::waiting($s)&&$s->getCard(7)->hp===7,'second same turn ordinary');
// A full target and neutral/suppressed/absent noble still consume the first attempt.
foreach(['neutral','suppressed','absent','full']as$case){$s=gnState($fixture);$s->getCard(2)->hp=8;
 if($case==='neutral')$s->getCard(1)->hp=8;if($case==='suppressed')EffectiveFeatures::applyMemoryLoss($s,$s->getCard(6),$s->getCard(1));if($case==='absent')(new ZoneManager($s))->toHand($s->getCard(1));if($case==='full')$s->getCard(7)->hp=12;
 gnCheck(gnHeal($s)->success,'first unavailable '.$case);if(CardHealing::waiting($s))gnDecision($s,'no');gnAck($s);
 $s->getCard(1)->zone='field';$s->getCard(1)->hp=6;unset($s->getCard(1)->flags['memory_loss_sources']);$s->getCard(5)->closed=false;$s->getCard(7)->hp=3;
 gnCheck(gnHeal($s)->success&&!CardHealing::waiting($s)&&$s->getCard(7)->hp===5,'no deferred eligibility '.$case);
}
// Almost full: doubled declaration remains 4 while actual gain is capped to 1.
$s=gnState($fixture);$s->getCard(2)->hp=8;$s->getCard(7)->hp=11;gnHeal($s);gnDecision($s);gnCheck($s->battle['card_healing']['last']['modified']===4&&$s->battle['card_healing']['last']['restored']===1,'declared/modified/actual distinct');
// Satyr decline and saturated per-turn usage.
$s=gnState($fixture);$s->getCard(2)->hp=8;gnHeal($s,3);gnDecision($s);$s=gnCopy($s);gnCheck(gnCmd($s,'host','cancel_pending',['heal_token'=>'$current'])->success,'Satyr decline');gnCheck($s->getCard(3)->hp===12&&empty($s->getCard(3)->flags['on_heal_wound_used_this_turn']),'decline no wounds/no use');
$s=gnState($fixture);$s->getCard(1)->hp=8;$s->getCard(2)->hp=8;$s->getCard(3)->flags['on_heal_wound_used_this_turn']=3;gnCheck(gnHeal($s,3)->success&&!CardHealing::waiting($s),'Satyr exhausted');
// Noble suppress/leave during saved choice skips the now-invalid effect, never refunds/duplicates costs.
foreach(['suppress','leave']as$change){$s=gnState($fixture);$s->getCard(2)->hp=8;gnHeal($s);
 if($change==='suppress')EffectiveFeatures::applyMemoryLoss($s,$s->getCard(6),$s->getCard(1));else(new ZoneManager($s))->toHand($s->getCard(1));
 $s=gnCopy($s);gnDecision($s);gnCheck($s->getCard(7)->hp===5&&$s->getCard(5)->closed,'invalid noble skipped '.$change);}
// The maximum is a creation stat, starting HP remains printed eight.
$s=new GameState(-2715403,101,202);$processor=new \Berserk\Core\Prepare\PrepareProcessor($s);$method=new ReflectionMethod($processor,'createDeckInstances');$method->setAccessible(true);
$method->invoke($processor,'host',[['ukid'=>'s1_154','count'=>1,'health'=>8,'prop'=>gnProp()]]);$n=array_values($s->cards)[0];gnCheck($n->hp===8&&$n->hpMax===12,'native deck creation HP 8/12');
$s=gnState($fixture);$n=$s->getCard(1);gnCheck(CardStats::computeArmor($s,$n)===1,'own armor 1');EffectiveFeatures::applyMemoryLoss($s,$s->getCard(6),$n);gnCheck(CardStats::computeArmor($s,$n)===1&&!empty($n->effectiveProp()['zor'])&&empty($n->effectiveProp()['vampire']),'permanent armor/zor, vampire suppressed');
$n->prop['armor']=['value'=>2,'line'=>true];gnCheck(CardStats::computeArmor($s,$n)===0,'unique line armor still suppressed');
// Keyword recovery and transferred wounds never call the card-healing gate.
foreach(['vampire','regeneration','deadeat']as$kind){$s=gnState($fixture);$s->getCard(1)->hp=6;$s->getCard(2)->hp=8;$a=$s->getCard(5);$a->hp=5;
 if($kind==='vampire'){$a->prop['vampire']=true;(new Engine())->applyDamage($s,$s->getCard(8),1,'strike',$a);}
 elseif($kind==='regeneration'){$a->prop['regeneration']=2;(new Engine())->applyRegeneration($a);}
 else{$a->prop['deadeat']=true;(new Engine())->applyDamage($s,$s->getCard(8),50,'strike',$a);}
 gnCheck(empty($s->battle['card_healing']['first_used']),'keyword excluded '.$kind);$a->closed=false;gnCheck(gnHeal($s)->success&&isset($s->battle['pending_card_heal']),'first card healing remains '.$kind);
}
// Multiheal modifies just its first deterministic target, one aggregate identity survives pending.
$s=gnState($fixture);$s->getCard(2)->hp=8;$s->getCard(5)->prop['actions']=[['key'=>'multi','type'=>'heal','value'=>2,'max_targets'=>3]];
gnCheck(gnCmd($s,'host','action',['card_id'=>5,'action_key'=>'multi'])->success,'multi start');
gnCheck(gnCmd($s,'host','choose_multi_heal',['target_ids'=>[7,5]])->success,'multi picks');gnDecision($s);gnCheck($s->getCard(7)->hp===7&&$s->getCard(5)->hp===12,'only first target doubled');
// Native end-turn resets the first-healing period; no manual ledger reset.
$s=gnState($fixture);$s->getCard(1)->hp=8;$s->getCard(2)->hp=8;gnHeal($s);gnAck($s);
gnCheck(gnCmd($s,'host','end_turn')->success,'end turn');gnCheck($s->battle['active']==='player','next active');$s->getCard(2)->hp=6;
gnCheck(gnHeal($s,8,6)->success&&isset($s->battle['pending_card_heal']),'next own heal available');
// Native Kele hit: already dealt damage must not repeat when healing resumes.
$s=gnState($fixture);$s->getCard(2)->hp=8;$a=$s->getCard(3);$a->prop=['on_strike_self_heal'=>['value'=>2,'no_close_if_full'=>true]];$a->hp=7;$a->strikeWeak=1;
$s->battle['strike']=['kind'=>'strike','attacker_id'=>3,'target_id'=>4,'defender_id'=>null,'state'=>'waiting_choice','confirmed'=>[]];
(new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'','winner'=>'attack'],false);
gnCheck(isset($s->battle['pending_card_heal'])&&$s->getCard(4)->hp===11,'hit waits before self heal');$s=gnCopy($s);gnDecision($s);
gnCheck($s->getCard(3)->hp===11&&$s->getCard(4)->hp===11&&!empty($s->battle['strike']['damage_applied']),'resume no repeated strike');
// Explicit on-death healing is included; no revival/HP setting was routed.
$s=gnState($fixture);$s->getCard(2)->hp=8;$s->getCard(6)->prop=['on_death'=>[['type'=>'heal','value'=>2,'target'=>'near']]];
$s->getCard(6)->row=4;$s->getCard(6)->col=1;
(new DamageResolver($s))->triggerOnDeath($s->getCard(6));gnCheck(isset($s->battle['pending_card_heal']),'death heal included');gnDecision($s);gnCheck($s->getCard(7)->hp===7,'death heal doubled');
// Automatic explanation is visible to both viewers without a decision/ACK pending.
$s=gnState($fixture);gnHeal($s);foreach(['host','player']as$viewer){$_GET=$_SESSION=[];$data=(new \Berserk\View\Screen\BattleScreen(new \Berserk\View\Template(__DIR__.'/../templates')))->prepare($s,$viewer,$viewer,null,[])['data'];gnCheck(str_contains($data['info_panel_html'],'Удвоение другой Дворянкой'),'conflict explanation '.$viewer);}
gnCheck(!CardHealing::waiting($s),'no conflict choice');
// Death-trigger healing inside a real strike pauses the rest of that strike, not its paid damage.
$s=gnState($fixture);$s->getCard(2)->hp=8;$a=$s->getCard(3);$a->prop=['on_strike_self_heal'=>['value'=>2]];$a->hp=7;$a->strikeWeak=2;
$d=$s->getCard(6);$d->hp=1;$d->row=4;$d->col=2;$d->prop=['on_death'=>[['type'=>'heal','value'=>1,'target'=>'near']]];
$s->battle['strike']=['kind'=>'strike','attacker_id'=>3,'target_id'=>6,'defender_id'=>null,'state'=>'waiting_choice','confirmed'=>[]];
(new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'','winner'=>'attack'],false);
gnCheck(isset($s->battle['pending_card_heal'])&&$a->hp===7,'death healing pauses strike tail');$s=gnCopy($s);gnDecision($s);
gnCheck($s->getCard(3)->hp===11&&$s->getCard(7)->hp===4&&$s->getCard(6)->hp<=0,'death queue then self-heal exactly once');
gnCheck(!empty($s->battle['strike']['damage_applied']),'death parent strike completes');
// Valhalla keeps its remaining effects until after the healing choice.
$s=gnState($fixture);$s->getCard(2)->hp=8;$dead=$s->getCard(5);$dead->zone='graveyard';$dead->hp=0;
$dead->prop=['valhalla'=>[['type'=>'heal','value'=>2,'target'=>'own_creature'],['type'=>'get_coins','value'=>1,'target'=>'own_creature']]];
$s->getCard(7)->prop['save_coins']=true;
$vp=new ValhallaProcessor($s,new Engine());gnCheck($vp->beginExecute($dead,'host'),'Valhalla target choice');
$vr=gnCmd($s,'host','valhalla_pick',['target_id'=>7]);gnCheck($vr->success&&$s->getCard(7)->coins===0,'Valhalla waits before next effect: '.json_encode([$vr,$s->getCard(7)->coins,$s->battle]));$s=gnCopy($s);gnDecision($s);
gnCheck($s->getCard(7)->hp===7&&$s->getCard(7)->coins===1,'Valhalla healing then coin once');
// Turn-end explicit healing keeps both the phase and its following effect suspended.
$s=gnState($fixture);$s->getCard(2)->hp=8;$s->getCard(5)->prop=['turn_end'=>[['type'=>'heal','value'=>2],['type'=>'get_coins','coins'=>1]]];
gnCheck(gnCmd($s,'host','end_turn')->success,'turn-end healing starts');
gnCheck(isset($s->battle['pending_card_heal'])&&$s->getCard(5)->coins===0&&($s->battle['turn_phase']['active_key']??'')==='host','ending phase retained during heal');$s=gnCopy($s);gnDecision($s);
gnCheck($s->getCard(5)->hp===12&&$s->getCard(5)->coins===1&&!empty($s->battle['turn_phase']['pending_ack']),'turn effect continuation completes once');
// Shaksi's already selected die effect and target survive a separate noble choice.
$s=gnState($fixture);$s->getCard(2)->hp=8;$s->battle['pending_shaksi_roll']=['owner'=>'host','source_id'=>5,'effect'=>['type'=>'heal','value'=>3],'candidates'=>[7]];
gnCheck(gnCmd($s,'host','choose_shaksi_roll',['target_id'=>7])->success,'Shaksi heal selection');
if(CardHealing::waiting($s))gnDecision($s);gnCheck($s->getCard(7)->hp===9,'Shaksi 3 doubled to 6');
// Preserve the existing full-heal -> open replacement and its poison semantics.
$s=gnState($fixture);$s->getCard(1)->hp=$s->getCard(2)->hp=8;$s->getCard(7)->hp=12;$s->getCard(7)->closed=true;
$s->getCard(7)->prop=['on_heal_instead_open'=>['once_per_turn'=>true]];$s->getCard(7)->markers['poison']=1;
$s->getCard(5)->prop['actions'][0]['value']='full';$s->getCard(5)->prop['actions'][0]['heal_poison']=true;
gnCheck(gnHeal($s)->success&&!$s->getCard(7)->closed&&isset($s->getCard(7)->markers['poison']),'full heal/open existing semantics');
echo 'Gloomy noblewoman edges: '.($checks-$edgeStart)." additional checks passed\n";
