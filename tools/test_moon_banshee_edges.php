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
 if($combat){$s->getCard(2)->closed=true;$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'state'=>'waiting_instant',
  'attack_dice'=>6,'defend_dice'=>0,'attack_mod'=>0,'defend_mod'=>0,'result'=>['attack'=>'strong','defend'=>'','winner'=>'attack'],
  'confirmed'=>[],'instant_phase'=>'combat','combat_instant_window'=>'main','instant_priority'=>'host','instant_participants'=>['host','player'],'instant_passed'=>[],'instant_next_sequence'=>0,'instant_stack'=>[]];}
 return $s;
}
function mbCmd(GameState &$s,string $type,array $params=[],string $owner='host'): \Berserk\Core\Result {
 $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));
 return (new Engine())->apply($s,$owner,new Command($type,$params));
}
function mbStack(GameState &$s):void {
 mbCheck(mbCmd($s,'combat_instant_play',['card_id'=>3,'instant_key'=>'krik'])->success,'open picker');
 mbCheck(mbCmd($s,'choose_combat_pick',['target_id'=>1,'pick_token'=>$s->battle['pending_combat_pick']['pick_token']])->success,'choose attacker');
}
function mbResolve(GameState &$s):void {mbCheck(mbCmd($s,'combat_instant_pass')->success,'pass host');mbCheck(mbCmd($s,'combat_instant_pass',[],'player')->success,'pass player');}
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
$s=mbState(true);mbCheck(mbCmd($s,'combat_instant_play',['card_id'=>3,'instant_key'=>'krik'])->success,'pending UI');
$handler=new CombatPickChoice();$own=$handler->spec($s,'host',[],'/test','host');$other=$handler->spec($s,'player',[],'/test','player');
mbCheck(str_contains(json_encode($own->buttons),$s->battle['pending_combat_pick']['pick_token']),'picker token rendered');
mbCheck(count($own->buttons)===2&&$other->isMine===false&&$other->buttons===[],'only actual attacker offered, opponent no choices');
$old=$s->battle['pending_combat_pick']['pick_token'];mbCheck(mbCmd($s,'cancel_pending',['pick_token'=>$old])->success,'cancel picker');
mbCheck(mbCmd($s,'combat_instant_play',['card_id'=>3,'instant_key'=>'krik'])->success,'new picker');
mbCheck(!mbCmd($s,'choose_combat_pick',['target_id'=>1,'pick_token'=>$old])->success,'old picker cannot select new one');
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
echo 'Moon Banshee edges: '.($count-$edgeStart)." additional checks passed\n";
