<?php
declare(strict_types=1);
require __DIR__.'/test_moon_banshee.php';
use Berserk\Core\{GameState,CardInstance,Engine,Command,InstantProcessor,StrikeResolver,TemporaryHealth,ZoneManager,EffectiveFeatures,BattleHelper};
use Berserk\Core\Choice\CombatPickChoice;
use Berserk\View\{Template};
use Berserk\View\Screen\BattleScreen;
$edgeStart=$count;
function mbState(bool $combat=false):GameState {
 global $scenario,$prop;
 $s=new GameState(-9157,101,202);$s->status='battle';$s->players['host']->side=1;$s->players['player']->side=2;
 $s->battle=['turn'=>1,'active'=>'host','strike'=>null,'hidden_row_revealed'=>true];
 foreach($scenario['cards']as$raw){if($raw['ukid']==='s1_157')$raw['prop']=$prop;$s->addCard(CardInstance::fromArray($raw));}
 if($combat){$s->getCard(3)->owner='player';$s->getCard(2)->closed=true;$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'state'=>'waiting_instant',
  'attack_dice'=>6,'defend_dice'=>0,'attack_mod'=>0,'defend_mod'=>0,'result'=>['attack'=>'strong','defend'=>'','winner'=>'attack'],
  'confirmed'=>[],'instant_phase'=>'combat','combat_instant_window'=>'main','instant_priority'=>'player','instant_participants'=>['host','player'],'instant_passed'=>[],'instant_next_sequence'=>0,'instant_stack'=>[]];}
 return $s;
}
function mbCmd(GameState &$s,string $type,array $params=[],string $owner='host'): \Berserk\Core\Result {
 $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));
 return (new Engine())->apply($s,$owner,new Command($type,$params));
}
function mbStack(GameState &$s):void {
 mbCheck(mbCmd($s,'combat_instant_play',['card_id'=>3,'instant_key'=>'krik'],'player')->success,'stack scream');
 mbCheck(empty($s->battle['pending_combat_pick']) && ($s->battle['strike']['instant_stack'][0]['target_id']??null)===1,'opposing strike automatically bound without picker');
}
function mbResolve(GameState &$s):void {mbCheck(mbCmd($s,'combat_instant_pass',[],'player')->success,'pass player');mbCheck(mbCmd($s,'combat_instant_pass')->success,'pass host');}
foreach(['suppressed','source_dead','hitter_gone']as$change){
 $s=mbState(true);mbStack($s);
 if($change==='suppressed')$s->getCard(3)->flags['stitched_unique_loss']=true;
 if($change==='source_dead'){$s->getCard(3)->hp=0;$s->getCard(3)->dying=true;}
 if($change==='hitter_gone')$s->getCard(1)->zone='exile';
 mbResolve($s);mbCheck(!$s->battle['strike']['instant_summary'][0]['applied']&&$s->getCard(3)->coins===1,$change.' no effect/coin');
}
$s=mbState(true);mbStack($s);mbResolve($s);$hp=$s->getCard(2)->hp;$coins=$s->getCard(3)->coins;
for($i=0;$i<2;$i++)(new StrikeResolver($s,new Engine()))->recalcTable();
mbCheck($s->getCard(2)->hp===$hp&&$s->getCard(3)->coins===$coins&&count($s->battle['strike']['strike_value_effects'])===1,'pure recalc does not grant or wound twice');
mbCheck(!mbCmd($s,'combat_instant_pass')->success&&$s->getCard(2)->hp===$hp,'repeated pass no duplicate strike');
// Generic selectable numeric effects retain their existing picker and stale-token checks.
$s=mbState(true);$s->getCard(3)->prop['instants'][0]['target']='striking_creature';
$s->getCard(3)->prop['instants'][0]['effect']=['type'=>'strike_value','value'=>3];
mbCheck(mbCmd($s,'combat_instant_play',['card_id'=>3,'instant_key'=>'krik'],'player')->success,'generic pending UI');
$handler=new CombatPickChoice();$own=$handler->spec($s,'player',[],'/test','player');$other=$handler->spec($s,'host',[],'/test','host');
mbCheck(str_contains(json_encode($own->buttons),$s->battle['pending_combat_pick']['pick_token']),'picker token rendered');
mbCheck(count($own->buttons)===2&&$other->isMine===false&&$other->buttons===[],'only actual attacker offered, opponent no choices');
$old=$s->battle['pending_combat_pick']['pick_token'];mbCheck(mbCmd($s,'cancel_pending',['pick_token'=>$old],'player')->success,'cancel picker');
mbCheck(mbCmd($s,'combat_instant_play',['card_id'=>3,'instant_key'=>'krik'],'player')->success,'new picker');
mbCheck(!mbCmd($s,'choose_combat_pick',['target_id'=>1,'pick_token'=>$old],'player')->success,'old picker cannot select new one');
// Legacy deployed prop receives the same automatic targeting without database writes.
$s=mbState(true);$s->getCard(3)->prop['instants'][0]['target']='striking_creature';mbStack($s);mbResolve($s);
mbCheck($s->getCard(2)->hp===15&&$s->getCard(3)->coins===2,'legacy prop resolves opposing strike automatically');
// On the attacking side Banshee reduces only the enemy return strike.
$s=mbState(true);$s->getCard(3)->owner='host';
mbCheck((new InstantProcessor($s,new Engine()))->getInstants('host','combat','combat')===[],'no enemy return strike: scream unavailable');
$s=mbState();mbCheck(mbCmd($s,'action',['card_id'=>3,'action_key'=>'horror','target_id'=>2])->success,'health UI action');
foreach(['host','player']as$viewer){$_GET=$_SESSION=[];$data=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,$viewer,$viewer,null,[],['sel'=>3,'mode'=>'action:horror'])['data'];mbCheck(str_contains(json_encode($data,JSON_UNESCAPED_UNICODE),'17'),'HP rendered for '.$viewer);}
// External suppression and the source leaving do not cancel an already materialized spell.
$s->getCard(2)->flags['stitched_unique_loss']=true;(new ZoneManager($s))->toExile($s->getCard(3));
mbCheck(mbCmd($s,'end_turn')->success&&$s->getCard(2)->hp===20&&$s->getCard(2)->hpMax===20,'expiry independent of unique ability loss/source');
$s=mbState();$s->getCard(2)->hp=15;TemporaryHealth::apply($s->getCard(2),-3,'host');
$s->getCard(2)->hp+=4;$s->getCard(2)->hpMax+=4;
mbCheck(mbCmd($s,'end_turn')->success&&$s->getCard(2)->hp===19&&$s->getCard(2)->hpMax===24,'independent positive health retained');
$s=mbState();TemporaryHealth::apply($s->getCard(2),-3,'host');(new ZoneManager($s))->toExile($s->getCard(2));
mbCheck($s->getCard(2)->hpMax===20&&$s->getCard(2)->modifiers===[],'exile restores maximum');
$s=mbState(true);$s->battle['strike']['attack_dice']=2;$s->battle['strike']['result']['attack']='weak';mbStack($s);mbResolve($s);
foreach(['host','player']as$viewer){$_GET=$_SESSION=[];$html=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,$viewer,$viewer,null,[],[])['data']['info_panel_html'];mbCheck(str_contains($html,'Крик баньши: величина удара'),'numeric result wording '.$viewer);}
// Glorm + Banshee: a cap of one is not one inflicted wound when the strike value became zero.
$s=mbState(true);$s->getCard(1)->strikeWeak=1;$s->battle['strike']['attack_dice']=2;$s->battle['strike']['result']['attack']='weak';
$s->getCard(4)->owner='player';$s->getCard(4)->prop=['instants'=>[['key'=>'flash','name'=>'Отвлекающая вспышка','trigger'=>'combat','phase'=>'setter','effect'=>['type'=>'damage_cap','value'=>1,'self_wound'=>1]]]];
mbStack($s);mbCheck(mbCmd($s,'combat_instant_play',['card_id'=>4,'instant_key'=>'flash'],'player')->success,'Glorm stacks with Banshee');mbResolve($s);
mbCheck($s->getCard(2)->hp===20,'Banshee zero strike remains zero under Glorm cap');
foreach(['host','player']as$viewer){$_GET=$_SESSION=[];$html=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,$viewer,$viewer,null,[],[])['data']['info_panel_html'];
 mbCheck(str_contains($html,'По цели: <b>0</b> урона'),'zero final damage is explicit '.$viewer);
 mbCheck(str_contains($html,'Крик баньши: величина удара 1 → 0'),'Banshee before/after values '.$viewer);
 mbCheck(str_contains($html,'максимальный урон удара — 1'),'Glorm cap is described as a maximum '.$viewer);
}

echo 'Moon Banshee edges: '.($count-$edgeStart)." additional checks passed\n";
