<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, ActionResolver, BattleHelper, CardInstance, CardStats, CoinAbilities, Command, Dice, Engine, GameState, StrikeResolver, ValhallaProcessor};
use Berserk\Core\Choice\{ChoiceRegistry, CoinAbilityChoice};
Autoloader::register(); Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
$count=0;
function bbAssert(bool $ok,string $message):void {global $count; $count++; if(!$ok)throw new RuntimeException($message);}
function bbState(bool $coin=false):GameState {
 $s=new GameState(97822,101,202);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'hidden_row_revealed'=>true];
 $prop=json_decode(file_get_contents(__DIR__.'/../docs/cards/bon_and_berrow.prop.json'),true,512,JSON_THROW_ON_ERROR);
 $s->addCard(new CardInstance(instanceId:1,ukid:'arbitrary_source',owner:'host',zone:'field',row:3,col:3,hp:10,hpMax:10,strikeWeak:2,strikeMedium:3,strikeStrong:4,coins:$coin?1:0,prop:$prop));
 $s->addCard(new CardInstance(instanceId:2,ukid:'enemy',owner:'player',zone:'field',row:3,col:5,hp:20,hpMax:20,strikeWeak:2,strikeMedium:3,strikeStrong:4));return $s;
}
function bbReload(GameState $s):GameState {return GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));}
function bbRoll(string $v):void {$_SESSION=['debug_roll'=>$v];Dice::init();}
function bbChoose(GameState $s,string $choice,string $owner='host',int $target=0):Berserk\Core\Result {return (new Engine())->apply($s,$owner,new Command('choose_coin_ability',['choice'=>$choice,'target_id'=>$target,'choice_id'=>$s->battle['pending_coin_ability']['id']??'']));}
function bbRawStrike(GameState $s,int $a=1,int $t=2):void {$s->battle['strike']=['attacker_id'=>$a,'target_id'=>$t,'defender_id'=>null,'state'=>'results','confirmed'=>[]];(new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'','winner'=>'attack'],false);}
function bbFinish(GameState $s):void {
 $e=new Engine();
 foreach(['host','player'] as $owner){if($s->status==='battle' && ($s->battle['strike']['state']??'')==='results' && !in_array($owner,$s->battle['strike']['confirmed']??[],true)) {
  $r=$e->apply($s,$owner,new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]));bbAssert($r->success,'Combat ACK: '.$r->error);
 }}
}
function bbStrike(GameState $s,int $a=1,int $t=2):void {bbRawStrike($s,$a,$t);bbFinish($s);}
function bbParent(GameState $s):array {$history=$s->battle['coin_post_combat_history']??[];return $history ? end($history)['combat_result'] : [];}
function bbOffer(GameState $s):void {$p=new CoinAbilities($s,new Engine());$p->offerGain($s->getCard(1),$s->getCard(1)->prop['actions'][0]);$p->advance();}
$s=bbState();$a=$s->getCard(1);$coins=new CoinAbilities($s,new Engine());
bbAssert(CardStats::getOva($s,$a,$s->getCard(2))===1,'OVA 1');bbAssert(CardStats::computeArmor($s,$a)===1,'Armor object 1');
bbAssert(!CardStats::canSpendCoins($a),'Chronos excludes external recipient');bbAssert(empty($a->prop['save_coins']),'No save_coins');
bbAssert($coins->gain($a,1,$s->getCard(2))===0,'External gain denied');bbAssert($coins->gain($a,1,$a)===0,'Self effect must be explicit');
bbAssert($coins->gain($a,1,$a,true)===1,'Own effect gain');bbAssert($coins->gain($a,1,$a,true)===0 && $a->coins===1,'Cap enforced');
$a->coins=0;bbAssert($coins->gain($a,1,$a,true)===1,'Can regain after spend');
bbAssert(!BattleHelper::abilities($a)['has_uchr'],'With coin UCHR unavailable');bbAssert(BattleHelper::getAttackTargets($s,$a,'uchr','host')===[],'Forged UCHR mode no targets');
$s=bbState();bbAssert(BattleHelper::abilities($s->getCard(1))['has_uchr'],'Without coin UCHR available');
foreach(['yes','no'] as $choice){$s=bbState();bbRoll('6,4');$e=new Engine();$r=$e->apply($s,'host',new Command('uchr',['card_id'=>1,'target_id'=>2]));
 bbAssert($r->success && $s->getCard(1)->closed,'UCHR taps');bbAssert($s->getCard(2)->hp===18,'UCHR fixed 2');
 bbAssert(!isset($s->battle['pending_coin_ability']),'No gain before UCHR completion');bbFinish($s);bbAssert($s->getCard(1)->coins===0,'Coin not automatic');bbAssert(($s->battle['pending_coin_ability']['kind']??'')==='gain','Optional gain offered');
 $s=bbReload($s);bbAssert(!bbChoose($s,'yes','player')->success,'Opponent cannot choose gain');bbAssert(bbChoose($s,$choice)->success,'Gain choice resumes');
 bbAssert($s->getCard(1)->coins===($choice==='yes'?1:0),'Correct gain choice');bbAssert(!bbChoose($s,$choice)->success,'Replay choice rejected');bbAssert($s->getCard(2)->hp===18,'No replayed damage');
 bbAssert(!isset($s->battle['pending_coin_ability']),'Choice closed');bbAssert($s->getCard(1)->closed,'No reopen on resume');}
$s=bbState(true);bbAssert(!(new Engine())->apply($s,'host',new Command('uchr',['card_id'=>1,'target_id'=>2]))->success,'Coin UCHR rejected by server');
$s=bbState();$s->getCard(2)->col=4;bbAssert(!(new Engine())->apply($s,'host',new Command('uchr',['card_id'=>1,'target_id'=>2]))->success,'UCHR requires distance 2');
$s=bbState();$s->addCard(new CardInstance(instanceId:3,ukid:'middle',owner:'player',zone:'field',row:3,col:4,hp:5,hpMax:5));bbAssert(!(new Engine())->apply($s,'host',new Command('uchr',['card_id'=>1,'target_id'=>2]))->success,'Enemy in middle prevents UCHR');
// Actual wounds, armor, protection, and no recursion.
foreach([0,2,5] as $armor){$s=bbState(true);$s->getCard(2)->armor=$armor;bbStrike($s);bbAssert(isset($s->battle['pending_coin_ability'])===($armor===0),'Only actual wounds trigger');}
$s=bbState();bbStrike($s);bbAssert(!isset($s->battle['pending_coin_ability']),'No coin no discharge');
$s=bbState(true);$s->getCard(2)->prop=['zoa'=>true];bbStrike($s);bbAssert(!isset($s->battle['pending_coin_ability']),'Protected strike no discharge');
foreach([1=>1,4=>2,6=>2] as $roll=>$expected){$s=bbState(true);bbStrike($s);$parent=bbParent($s);$hp=$s->getCard(2)->hp;
 bbAssert(($s->battle['pending_coin_ability']['kind']??'')==='discharge','Strike queues discharge');bbAssert($s->battle['pending_coin_ability']['action']['range']===3,'Range config 3');
 $s=bbReload($s);bbRoll((string)$roll);bbAssert(bbChoose($s,'','host',2)->success,'Discharge resumes');
 bbAssert($s->getCard(2)->hp===$hp-$expected,'Standard dice 1-2-2');bbAssert($s->getCard(1)->coins===1,'Discharge retains coin');
 bbAssert(bbParent($s)['damage_total']===$parent['damage_total'],'Parent result preserved');bbAssert(($s->battle['strike']['kind']??'')==='discharge','One discharge');
 bbAssert(!isset($s->battle['pending_coin_ability']),'No recursive discharge');bbAssert(!bbChoose($s,'','host',2)->success,'No replay discharge');
 $hp=$s->getCard(2)->hp;bbAssert(!bbChoose($s,'','host',2)->success && $s->getCard(2)->hp===$hp,'Applied discharge not repeated');}
$s=bbState(true);$s->getCard(2)->row=6;$s->getCard(2)->col=5;bbStrike($s);bbAssert(!isset($s->battle['pending_coin_ability']),'No in-range targets no stuck pending');
// Defense before dice; persisted owner choice. Source and defending instance swapped.
foreach(['yes','no'] as $choice){$s=bbState(true);$s->getCard(2)->col=4;$s->getCard(2)->closed=false;$s->battle['active']='player';
 $s->battle['strike']=['attacker_id'=>2,'target_id'=>1,'defender_id'=>null,'state'=>'waiting_defender','confirmed'=>[]];
 bbRoll('6,1,4');(new StrikeResolver($s,new Engine()))->resolve();
 bbAssert(($s->battle['pending_coin_ability']['owner']??'')==='host','Defender owns choice');bbAssert(Dice::remaining()===[6,1,4],'No dice before block choice');
 $s=bbReload($s);bbAssert(!bbChoose($s,'yes','player')->success,'Opponent cannot block');bbAssert(bbChoose($s,$choice)->success,'Resolve block choice');
 bbAssert($s->getCard(1)->coins===($choice==='yes'?0:1),'Spend only confirm');
 if($choice==='yes'){bbAssert($s->getCard(1)->hp===10,'Blocked no wounds');bbAssert(Dice::remaining()===[6,1,4],'Blocked no rolls');bbAssert($s->getCard(2)->closed,'Blocked attack consumes action');bbAssert(BattleHelper::abilities($s->getCard(1))['has_uchr'],'Spend switches abilities');}
 else {bbAssert(!isset($s->battle['pending_coin_ability']),'Cancel does not reoffer block');bbAssert(Dice::remaining()!==[6,1,4],'Cancel continues original attack');}
 bbAssert(!bbChoose($s,'yes')->success,'Replay no double spend');}
foreach([['zoa'],['zoa','zoan'],['zoal','zoa']] as $ignore){$s=bbState(true);$s->getCard(2)->prop=['ignore'=>$ignore];bbAssert(!(new CoinAbilities($s,new Engine()))->offerBlock($s->getCard(2),$s->getCard(1),'strike',['kind'=>'strike']),'Defense ignore bypasses coin pending');}
$s=bbState();bbAssert(!(new CoinAbilities($s,new Engine()))->offerBlock($s->getCard(2),$s->getCard(1),'strike',['kind'=>'strike']),'Zero coins no pending');
foreach(['shot','throw','discharge','poison','impact','execute'] as $type){$s=bbState(true);bbAssert(!(new CoinAbilities($s,new Engine()))->offerBlock($s->getCard(2),$s->getCard(1),$type,['kind'=>'damage']),'Not a strike: '.$type);}
// UCHR vs coin defense: serializable continuation, no second tap or roll.
foreach(['yes','no'] as $choice){$s=bbState(true);$s->getCard(2)->prop=['actions'=>[['type'=>'uchr','value'=>2]]];$s->battle['active']='player';bbRoll('4,6');
 bbAssert((new Engine())->apply($s,'player',new Command('uchr',['card_id'=>2,'target_id'=>1]))->success,'Incoming UCHR');$s=bbReload($s);bbAssert(bbChoose($s,$choice)->success,'Incoming UCHR choice');bbAssert($s->getCard(1)->hp===($choice==='yes'?10:8),'Incoming UCHR resolves once');}
// Chronos command path, not only canSpendCoins.
$s=bbState();$s->battle['active']='player';$s->getCard(1)->owner='player';$s->getCard(2)->coins=1;$s->getCard(2)->prop=['actions'=>[['key'=>'give','type'=>'give_coin','coins'=>1]]];
$r=(new Engine())->apply($s,'player',new Command('action',['card_id'=>2,'target_id'=>1,'action_key'=>'give']));bbAssert(!$r->success && $s->getCard(2)->coins===1 && $s->getCard(1)->coins===0,'Chronos cannot transfer');
// Other external effect path (Valhalla).
$s=bbState();$v=new ValhallaProcessor($s,new Engine());$method=new ReflectionMethod($v,'applyEffect');$method->setAccessible(true);$method->invoke($v,$s->getCard(2),['type'=>'get_coins','target'=>'own_creature','value'=>1],'host',$s->getCard(1));bbAssert($s->getCard(1)->coins===0,'Valhalla cannot bypass deny');
// UI labels, registry and ownership.
$s=bbState();bbOffer($s);$h=new CoinAbilityChoice();$spec=$h->spec($s,'host',[],'?game=1','host');
bbAssert($spec->buttons[0]['label']==='Получить монетку' && $spec->buttons[1]['label']==='Не получать монетку','Coin buttons');bbAssert(!$h->spec($s,'player',[],'?game=1','player')->isMine,'Other player sees waiting');bbAssert(ChoiceRegistry::current($s) instanceof CoinAbilityChoice,'Registered choice');
$s=bbState(true);(new CoinAbilities($s,new Engine()))->offerBlock($s->getCard(2),$s->getCard(1),'strike',['kind'=>'strike']);$spec=$h->spec($s,'host',[],'?game=1','host');bbAssert($spec->buttons[0]['label']==='Блокировать атаку' && $spec->buttons[1]['label']==='Отмена','Block buttons');

// Defense keys retain their existing semantics: ignoring zoan alone does not ignore zoa.
$s=bbState(true);$s->getCard(2)->prop=['ignore'=>['zoan']];bbAssert((new CoinAbilities($s,new Engine()))->offerBlock($s->getCard(2),$s->getCard(1),'strike',['kind'=>'strike']),'Ignore only the actual protection');
$s=bbState(true);$s->getCard(1)->prop['coin_block']['defense']='zoan';$s->getCard(2)->type='fly';bbAssert(!(new CoinAbilities($s,new Engine()))->offerBlock($s->getCard(2),$s->getCard(1),'strike',['kind'=>'strike']),'ZOAN does not apply to flying attacker');
$s=bbState(true);$s->getCard(2)->prop=['ignore'=>['zoa'=>true]];bbAssert(!(new CoinAbilities($s,new Engine()))->offerBlock($s->getCard(2),$s->getCard(1),'strike',['kind'=>'strike']),'Associative ignore supported');
// Standard special-strike and ranged-action paths.
foreach(['tap','magic','shot','throw','discharge'] as $type){$s=bbState(true);$s->getCard(2)->col=4;$s->addCard(new CardInstance(instanceId:3,ukid:'ranged_target',owner:'player',zone:'field',row:3,col:5,hp:20,hpMax:20));$s->getCard(1)->prop['actions']=[['key'=>'probe','type'=>$type,'value'=>2,'range'=>3,'near_shot'=>true]];bbRoll('4');
 $r=(new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'probe']));bbAssert($r->success,'Action path '.$type);bbFinish($s);bbAssert(isset($s->battle['pending_coin_ability'])===in_array($type,['tap','magic'],true),'Only wound-causing strikes trigger: '.$type);}
// Incoming special strike continuation and its cancellation.
foreach(['yes','no'] as $choice){$s=bbState(true);$s->battle['active']='player';$s->getCard(2)->col=4;$s->getCard(2)->prop=['actions'=>[['key'=>'tap','type'=>'tap','value'=>2]]];bbRoll('4,6');
 bbAssert((new Engine())->apply($s,'player',new Command('action',['card_id'=>2,'target_id'=>1,'action_key'=>'tap']))->success,'Incoming tap starts');$s=bbReload($s);bbAssert(bbChoose($s,$choice)->success,'Incoming tap resumes');bbAssert($s->getCard(1)->hp===($choice==='yes'?10:8),'Tap resolves once');}
// Exact range boundary, configurable strike values, and invalid target rejection.
$s=bbState(true);$s->getCard(2)->row=5;$s->getCard(2)->col=4;$processor=new CoinAbilities($s,new Engine());$targets=$processor->dischargeTargets($s->getCard(1),$s->getCard(1)->prop['on_wounding_strike']);bbAssert(isset($targets[2]),'Distance 3 allowed');$s->getCard(2)->col=5;bbAssert(!isset($processor->dischargeTargets($s->getCard(1),$s->getCard(1)->prop['on_wounding_strike'])[2]),'Distance 4 excluded');
$s=bbState(true);bbStrike($s);bbAssert(!bbChoose($s,'','host',999)->success && isset($s->battle['pending_coin_ability']),'Invalid discharge target keeps pending');
// UI rendered through the real screen and InfoPanel templates.
$screen=new Berserk\View\Screen\BattleScreen(new Berserk\View\Template(__DIR__.'/../templates'));
$info=['arbitrary_source'=>['name'=>'Бон и Берроу','health'=>10],'enemy'=>['name'=>'Противник','health'=>20]];
foreach([false,true] as $coin){$s=bbState($coin);$_GET=['sel'=>1];$data=$screen->prepare($s,'host','host',null,$info)['data'];$html=implode('',array_filter($data,'is_string'));
 bbAssert(!str_contains($html,'Накопить монетку'),'No accumulate coin UI');bbAssert(str_contains($html,'mode=uchr')===!$coin,'UCHR visibility follows coins');bbAssert(!str_contains($html,'mode=action:triggered_discharge'),'Discharge not a standalone action');bbAssert(str_contains($html,$coin?'При нанесении ран ударом':'Без монетки: доступен удар через ряд'),'Current state description');}
$s=bbState();bbOffer($s);$html=(new Berserk\View\Screen\Battle\InfoPanel(new Berserk\View\Template(__DIR__.'/../templates')))->render($s,'host','host',$info,'?game=1');bbAssert(str_contains($html,'Получить монетку') && str_contains($html,'Не получать монетку'),'Pending rendered in InfoPanel');
// Original combat result survives the normal ranged-redirection pending.
$s=bbState(true);$s->getCard(2)->row=5;$s->getCard(2)->col=3;$s->getCard(2)->prop=['line'=>true];
$s->addCard(new CardInstance(instanceId:3,ukid:'redirect',owner:'player',zone:'field',row:5,col:4,hp:10,hpMax:10,prop:['line'=>true,'attack_redirect'=>['attack_types'=>'ranged']]));
$s->addCard(new CardInstance(instanceId:4,ukid:'redirect_target',owner:'player',zone:'field',row:5,col:5,hp:10,hpMax:10,prop:['line'=>true]));
bbStrike($s);$parent=bbParent($s);bbRoll('4,6');bbAssert(bbChoose($s,'','host',2)->success,'Discharge begins standard redirection');bbAssert(isset($s->battle['pending_ranged_attack_redirect']),'Redirection pending preserved');$s=bbReload($s);
$r=(new Engine())->apply($s,'player',new Command('choose_ranged_redirect',['redirect_option'=>'3:4']));bbAssert($r->success,'Redirection resumes after reload');bbAssert($s->getCard(4)->hp===8,'Redirected discharge damage once');bbAssert(bbParent($s)['target_id']===$parent['target_id'] && bbParent($s)['damage_total']===$parent['damage_total'],'Redirect does not overwrite parent');bbAssert(Dice::remaining()===[6],'Only one discharge roll');


// A repeated request from a previous pending cannot accept a newly opened choice.
$s=bbState();$p=new CoinAbilities($s,new Engine());$p->offerGain($s->getCard(1),$s->getCard(1)->prop['actions'][0]);$p->advance();$oldId=$s->battle['pending_coin_ability']['id'];bbChoose($s,'no');$p->offerGain($s->getCard(1),$s->getCard(1)->prop['actions'][0]);$p->advance();
$r=(new Engine())->apply($s,'host',new Command('choose_coin_ability',['choice'=>'yes','choice_id'=>$oldId]));bbAssert(!$r->success && $s->getCard(1)->coins===0,'Stale choice ID cannot confirm a later pending');


$s=bbState(true);$s->getCard(2)->hp=1;bbStrike($s);bbAssert(!isset($s->battle['pending_coin_ability']),'Lethal hit with no other living target has no pending');
$s=bbState(true);bbStrike($s);bbRoll('4');bbChoose($s,'','host',2);$e=new Engine();bbAssert($e->apply($s,'host',new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success,'Original strike first acknowledgement');bbAssert($e->apply($s,'player',new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success,'Original strike second acknowledgement');bbAssert(empty($s->battle['strike']),'Original lifecycle finishes after discharge');


// Timing: the saved wound result survives the answer, both ACKs and reload.
$s=bbState(true);
$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'state'=>'results','confirmed'=>[]];
(new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'weak','winner'=>'attack'],false);
bbAssert($s->getCard(1)->hp===8 && $s->getCard(2)->hp===18,'Attack and answer resolved before deferred effect');
bbAssert(!isset($s->battle['pending_coin_ability']),'No discharge while primary combat exists');
$deferred=$s->battle['strike']['coin_post_combat_effects'];
bbAssert(count($deferred)===1 && reset($deferred)['wounds']===2,'Actual wounds serialized in originating strike');
$e=new Engine();bbAssert($e->apply($s,'host',new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success,'First primary ACK');
bbAssert(!isset($s->battle['pending_coin_ability']),'One ACK is insufficient');
$s=bbReload($s);bbAssert($e->apply($s,'player',new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success,'Primary ACK after reload');
bbAssert(empty($s->battle['strike']) && $s->battle['pending_coin_ability']['kind']==='discharge','Only completed primary combat opens discharge');
bbAssert(bbParent($s)['defend_damage_total']===2,'Final answer retained in archived primary result');
bbAssert(reset($s->battle['coin_post_combat_history'])['executed']===true,'Continuation recorded as executed');
bbRoll('4,6');bbAssert(bbChoose($s,'','host',2)->success && Dice::remaining()===[6],'One independent discharge roll');
bbAssert($s->battle['strike']['kind']==='discharge' && $s->getCard(1)->closed,'Separate discharge result with no extra action');
$s=bbReload($s);bbFinish($s);bbAssert(!isset($s->battle['pending_coin_ability']),'No recursive discharge after its result ACK');

// Mandatory radio choice includes allies; missing, stale, enemy-owned and cancellation requests fail.
foreach(['own','both','enemy','none'] as $side) {
 $s=bbState(true);
 if(in_array($side,['own','none'],true)){$s->getCard(2)->row=6;$s->getCard(2)->col=5;}
 if(in_array($side,['own','both'],true)) $s->addCard(new CardInstance(instanceId:3,ukid:'ally',owner:'host',zone:'field',row:1,col:3,hp:10,hpMax:10));
 bbStrike($s);
 if($side==='none'){bbAssert(!isset($s->battle['pending_coin_ability']),'No eligible targets creates no impossible pending');continue;}
 $p=$s->battle['pending_coin_ability'];
 bbAssert(in_array(3,$p['candidates'],true)===in_array($side,['own','both'],true),'Own target eligibility '.$side);
 bbAssert(in_array(2,$p['candidates'],true)===in_array($side,['enemy','both'],true),'Enemy target eligibility '.$side);
 $spec=(new CoinAbilityChoice())->spec($s,'host',[],'?game=1','host');
 bbAssert(($spec->form['type']??'')==='radio' && $spec->form['name']==='target_id','Targets use radio');
 bbAssert($spec->form['submit']==='Подтвердить' && !isset($spec->form['cancel']) && $spec->buttons===[],'One confirmation and no skip/cancel buttons');
 $html=(new Berserk\View\Screen\Battle\InfoPanel(new Berserk\View\Template(__DIR__.'/../templates')))->render($s,'host','host',$info,'?game=1');
 bbAssert(str_contains($html,'type="radio"') && str_contains($html,'Подтвердить'),'Actual radio HTML rendered');
 bbAssert(!str_contains($html,'cmd=cancel_pending'),'No cancel link in mandatory choice');
 bbAssert(!(new Engine())->apply($s,'host',new Command('cancel_pending'))->success,'Cannot cancel mandatory discharge');
 bbAssert(!bbChoose($s,'no')->success && !bbChoose($s,'','host',999)->success,'Cannot skip or select invalid target');
 bbAssert(!bbChoose($s,'','player',$side==='own'?3:2)->success,'Other player cannot confirm');
 if($side==='own'){bbRoll('4');bbAssert(bbChoose($s,'','host',3)->success && $s->getCard(3)->hp===8,'Must discharge at own card when enemies unavailable');}
}

// UCHR coin choice waits for primary result ACK even if the UCHR was coin-blocked.
foreach([false,true] as $blocked) {
 $s=bbState();
 if($blocked){$s->getCard(2)->coins=1;$s->getCard(2)->prop['coin_block']=$s->getCard(1)->prop['coin_block'];}
 bbRoll('6,4');$r=(new Engine())->apply($s,'host',new Command('uchr',['card_id'=>1,'target_id'=>2]));bbAssert($r->success,'UCHR starts');
 if($blocked){bbAssert($s->battle['pending_coin_ability']['kind']==='block','Existing block choice remains immediate');bbAssert(bbChoose($s,'yes','player')->success,'Existing block accepted');}
 bbAssert(!isset($s->battle['pending_coin_ability']),'No gain during UCHR result');
 bbAssert($s->getCard(1)->closed && $s->getCard(2)->hp===($blocked?20:18),'Tap and damage already resolved once');
 $s=bbReload($s);$hp=$s->getCard(2)->hp;bbFinish($s);
 bbAssert(($s->battle['pending_coin_ability']['kind']??'')==='gain' && empty($s->battle['strike']),'Gain only after completed UCHR');
 bbAssert(bbChoose($s,'yes')->success && $s->getCard(1)->coins===1,'Own coin gain still allowed');
 bbAssert($s->getCard(2)->hp===$hp && $s->getCard(1)->closed,'Gain does not replay UCHR or action payment');
}

// Real after-combat instant window and result acknowledgement gate both effects.
foreach([false,true] as $coin) {
 $s=bbState($coin);
 $s->addCard(new CardInstance(instanceId:3,ukid:'after_probe',owner:'player',zone:'field',row:5,col:4,hp:9,hpMax:10,
  prop:['instants'=>[['key'=>'after_probe','name'=>'After probe','trigger'=>'turn','target'=>'self','effect'=>['type'=>'heal','value'=>1]]]]));
 if($coin){bbRawStrike($s);}else{bbRoll('6');bbAssert((new Engine())->apply($s,'host',new Command('uchr',['card_id'=>1,'target_id'=>2]))->success,'UCHR with after instant');}
 bbFinish($s);
 bbAssert(!empty($s->battle['turn_instant_stack']['continuation']['coin_origin_strike']) && empty($s->battle['strike']),'Origin retained while after-instant window remains open');
 bbAssert(!isset($s->battle['pending_coin_ability']),'No early coin effect during after-instant window');
 $s=bbReload($s);$e=new Engine();
 bbAssert($e->apply($s,'player',new Command('play_turn_instant',['card_id'=>3,'instant_key'=>'after_probe']))->success,'Play real after instant');
 bbAssert($e->apply($s,'player',new Command('pass_turn_instant'))->success,'Resolve actual after stack');
 bbAssert(!empty($s->battle['turn_instant_result']) && !isset($s->battle['pending_coin_ability']),'Effect waits for after-stack result acknowledgement');
 $s=bbReload($s);bbAssert($e->apply($s,'player',new Command('turn_instant_result_ok'))->success,'Acknowledge actual after-stack result');
 bbAssert(empty($s->battle['strike']) && ($s->battle['pending_coin_ability']['kind']??'')===($coin?'discharge':'gain'),'Deferred choice only after after-instant completion');
 bbAssert(!empty(bbParent($s)['after_instant_summary']),'Completed instant history archived with main result');
}

// Mandatory legacy pending after the combat boundary cannot be overtaken by coin effects.
$s=bbState(true);bbRawStrike($s);
$p=new CoinAbilities($s,new Engine());$p->completeCombat($s->battle['strike']);$s->battle['strike']=null;
$s->battle['pending_prophecy']=['owner'=>'player','sentinel'=>true];$p->advance();
bbAssert(!isset($s->battle['pending_coin_ability']) && !empty($s->battle['coin_post_combat_queue']),'Non-registry mandatory pending gates queue');
$s=bbReload($s);unset($s->battle['pending_prophecy']);$p=new CoinAbilities($s,new Engine());$p->advance();
bbAssert(($s->battle['pending_coin_ability']['kind']??'')==='discharge','Queued effect survives mandatory pending and reload');
$p->completeCombat(bbParent($s));$p->advance();bbAssert(count($s->battle['coin_post_combat_history'])===1,'Completion/promotion remains idempotent');


// A real combat setter instant finishes before damage ACK and post-combat target selection.
$s=bbState(true);$s->getCard(2)->closed=true;
$s->addCard(new CardInstance(instanceId:3,ukid:'combat_probe',owner:'player',zone:'field',row:5,col:4,hp:10,hpMax:10,
 prop:['instants'=>[['key'=>'cap','name'=>'Cap probe','trigger'=>'combat','phase'=>'setter','target'=>'self','effect'=>['type'=>'damage_cap','value'=>1]]]]));
$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'state'=>'waiting_defender','confirmed'=>[]];bbRoll('6,4');
(new StrikeResolver($s,new Engine()))->resolve();bbAssert($s->battle['strike']['state']==='waiting_instant','Real combat instant window opens');
bbAssert(!isset($s->battle['pending_coin_ability']),'No target choice during combat instant window');
$s=bbReload($s);$e=new Engine();bbAssert($e->apply($s,'player',new Command('combat_instant_play',['card_id'=>3,'instant_key'=>'cap']))->success,'Stack real combat setter');
foreach(['player','host'] as $owner){if(($s->battle['strike']['state']??'')==='waiting_instant')bbAssert($e->apply($s,$owner,new Command('combat_instant_pass'))->success,'Pass actual combat window');}
bbAssert(!isset($s->battle['pending_coin_ability']),'No target choice before primary damage/result completion');
$s=bbReload($s);bbFinish($s);bbAssert(($s->battle['pending_coin_ability']['kind']??'')==='discharge','Discharge follows combat instants and result ACK');
bbAssert(bbParent($s)['damage_total']===1 && !empty(bbParent($s)['instant_summary']),'Final setter result and instant history preserved');

// Existing mandatory after-strike execution is resolved before the deferred discharge.
$s=bbState(true);$s->getCard(1)->prop['execute']=1;$s->getCard(1)->prop['after_strike_execute_limit']=1;
foreach([3,4] as $id)$s->addCard(new CardInstance(instanceId:$id,ukid:'finisher_'.$id,owner:'player',zone:'field',row:4,col:$id-1,hp:1,hpMax:1));
bbStrike($s);bbAssert(!empty($s->battle['pending_after_strike_execute']) && !isset($s->battle['pending_coin_ability']),'Mandatory finisher choice takes precedence');
$s=bbReload($s);$e=new Engine();bbAssert($e->apply($s,'host',new Command('choose_after_strike_execute',['target_id'=>3]))->success,'Mandatory finisher resumes');
bbAssert(!isset($s->battle['pending_coin_ability']) && !empty($s->battle['strike']),'Finisher result still precedes discharge');
bbFinish($s);bbAssert(($s->battle['pending_coin_ability']['kind']??'')==='discharge','Discharge follows final mandatory continuation');


// After-instant forced combat must keep its own result and delay the originating continuation.
$s=bbState(true);
$s->addCard(new CardInstance(instanceId:3,ukid:'force_probe',owner:'player',zone:'field',row:5,col:4,hp:10,hpMax:10,
 prop:['instants'=>[['key'=>'force','name'=>'Force probe','trigger'=>'turn','target'=>'enemy_open_creature',
 'effect'=>['type'=>'forced_strike_adjacent','close_attacker_after'=>true]]]]));
$s->addCard(new CardInstance(instanceId:4,ukid:'forced_actor',owner:'host',zone:'field',row:3,col:1,hp:10,hpMax:10,strikeWeak:1,strikeMedium:1,strikeStrong:1));
$s->addCard(new CardInstance(instanceId:5,ukid:'forced_target',owner:'player',zone:'field',row:4,col:1,hp:10,hpMax:10,closed:true));
bbRawStrike($s);bbFinish($s);$e=new Engine();
bbAssert($e->apply($s,'player',new Command('play_turn_instant',['card_id'=>3,'instant_key'=>'force']))->success,'Declare forced after instant');
bbAssert($e->apply($s,'player',new Command('choose_instant_pick',['target_id'=>4]))->success,'Select forced attacker');
bbAssert($e->apply($s,'player',new Command('choose_forced_strike_adjacent',['target_id'=>5]))->success,'Select forced target');
bbRoll('6,4');bbAssert($e->apply($s,'player',new Command('pass_turn_instant'))->success,'Resolve forced-strike after instant');
bbAssert(($s->battle['strike']['attacker_id']??0)===4 && !isset($s->battle['pending_coin_ability']),'Nested combat is real and coin choice still waits');
$s=bbReload($s);bbAssert($e->apply($s,'player',new Command('turn_instant_result_ok'))->success,'Acknowledge after-instant history');
bbAssert(($s->battle['strike']['attacker_id']??0)===4 && !empty($s->battle['coin_post_combat_continuation']),'Origin does not overwrite unfinished forced combat');
$s=bbReload($s);bbFinish($s);
bbAssert(($s->battle['pending_coin_ability']['kind']??'')==='discharge','Origin continuation resumes only after nested combat');
bbAssert(bbParent($s)['attacker_id']===1 && bbParent($s)['damage_total']===2,'Origin result survives nested forced combat');

// Standard magic protection gives the separate post-combat discharge a valid zero-damage result.
$s=bbState(true);$s->getCard(2)->prop['zom']=true;
bbStrike($s);bbAssert(bbChoose($s,'','host',2)->success,'Select protected discharge target');
bbAssert(($s->battle['strike']['state']??'')==='results' && $s->battle['strike']['defended'],'Protected post-combat discharge has valid result lifecycle');
bbFinish($s);bbAssert(empty($s->battle['strike']) && !isset($s->battle['pending_coin_ability']),'Protected discharge completes without repeat');


// Eligibility and targets are evaluated again at completion, not frozen inside applyDamage.
$s=bbState(true);bbRawStrike($s);$s->getCard(1)->coins=0;bbFinish($s);bbAssert(!isset($s->battle['pending_coin_ability']),'Lost coin disables deferred discharge');
$s=bbState(true);$s->getCard(2)->row=6;$s->getCard(2)->col=5;bbRawStrike($s);
$s->addCard(new CardInstance(instanceId:3,ukid:'late_ally',owner:'host',zone:'field',row:1,col:3,hp:10,hpMax:10));bbFinish($s);
bbAssert(($s->battle['pending_coin_ability']['candidates']??[])===[3],'Target set determined after all combat continuations');
$s=bbState();$s->getCard(2)->armor=3;bbRoll('4');bbAssert((new Engine())->apply($s,'host',new Command('uchr',['card_id'=>1,'target_id'=>2]))->success,'Fully armored UCHR resolves');
bbAssert(!isset($s->battle['pending_coin_ability']),'No gain before fully armored UCHR completes');bbFinish($s);
bbAssert(($s->battle['pending_coin_ability']['kind']??'')==='gain' && $s->getCard(2)->hp===20,'Coin choice does not depend on wounds');

echo "Bon and Berrow: $count assertions passed.\n";
