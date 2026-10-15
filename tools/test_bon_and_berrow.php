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
function bbStrike(GameState $s,int $a=1,int $t=2):void {$s->battle['strike']=['attacker_id'=>$a,'target_id'=>$t,'defender_id'=>null,'state'=>'results','confirmed'=>[]];(new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'','winner'=>'attack'],false);}
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
 bbAssert($s->getCard(1)->coins===0,'Coin not automatic');bbAssert(($s->battle['pending_coin_ability']['kind']??'')==='gain','Optional gain offered');
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
foreach([1=>1,4=>2,6=>2] as $roll=>$expected){$s=bbState(true);bbStrike($s);$parent=$s->battle['strike'];$hp=$s->getCard(2)->hp;
 bbAssert(($s->battle['pending_coin_ability']['kind']??'')==='discharge','Strike queues discharge');bbAssert($s->battle['pending_coin_ability']['action']['range']===3,'Range config 3');
 $s=bbReload($s);bbRoll((string)$roll);bbAssert(bbChoose($s,'','host',2)->success,'Discharge resumes');
 bbAssert($s->getCard(2)->hp===$hp-$expected,'Standard dice 1-2-2');bbAssert($s->getCard(1)->coins===1,'Discharge retains coin');
 bbAssert($s->battle['strike']['damage_total']===$parent['damage_total'],'Parent result preserved');bbAssert(count($s->battle['strike']['triggered_discharges'])===1,'One discharge');
 bbAssert(!isset($s->battle['pending_coin_ability']),'No recursive discharge');bbAssert(!bbChoose($s,'','host',2)->success,'No replay discharge');
 $hp=$s->getCard(2)->hp;(new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'','winner'=>'attack'],false);bbAssert($s->getCard(2)->hp===$hp,'Applied strike not repeated');}
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
$s=bbState();(new CoinAbilities($s,new Engine()))->offerGain($s->getCard(1),$s->getCard(1)->prop['actions'][0]);$h=new CoinAbilityChoice();$spec=$h->spec($s,'host',[],'?game=1','host');
bbAssert($spec->buttons[0]['label']==='Получить монетку' && $spec->buttons[1]['label']==='Не получать монетку','Coin buttons');bbAssert(!$h->spec($s,'player',[],'?game=1','player')->isMine,'Other player sees waiting');bbAssert(ChoiceRegistry::current($s) instanceof CoinAbilityChoice,'Registered choice');
$s=bbState(true);(new CoinAbilities($s,new Engine()))->offerBlock($s->getCard(2),$s->getCard(1),'strike',['kind'=>'strike']);$spec=$h->spec($s,'host',[],'?game=1','host');bbAssert($spec->buttons[0]['label']==='Блокировать атаку' && $spec->buttons[1]['label']==='Отмена','Block buttons');

// Defense keys retain their existing semantics: ignoring zoan alone does not ignore zoa.
$s=bbState(true);$s->getCard(2)->prop=['ignore'=>['zoan']];bbAssert((new CoinAbilities($s,new Engine()))->offerBlock($s->getCard(2),$s->getCard(1),'strike',['kind'=>'strike']),'Ignore only the actual protection');
$s=bbState(true);$s->getCard(1)->prop['coin_block']['defense']='zoan';$s->getCard(2)->type='fly';bbAssert(!(new CoinAbilities($s,new Engine()))->offerBlock($s->getCard(2),$s->getCard(1),'strike',['kind'=>'strike']),'ZOAN does not apply to flying attacker');
$s=bbState(true);$s->getCard(2)->prop=['ignore'=>['zoa'=>true]];bbAssert(!(new CoinAbilities($s,new Engine()))->offerBlock($s->getCard(2),$s->getCard(1),'strike',['kind'=>'strike']),'Associative ignore supported');
// Standard special-strike and ranged-action paths.
foreach(['tap','magic','shot','throw','discharge'] as $type){$s=bbState(true);$s->getCard(2)->col=4;$s->addCard(new CardInstance(instanceId:3,ukid:'ranged_target',owner:'player',zone:'field',row:3,col:5,hp:20,hpMax:20));$s->getCard(1)->prop['actions']=[['key'=>'probe','type'=>$type,'value'=>2,'range'=>3,'near_shot'=>true]];bbRoll('4');
 $r=(new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'probe']));bbAssert($r->success,'Action path '.$type);bbAssert(isset($s->battle['pending_coin_ability'])===in_array($type,['tap','magic'],true),'Only wound-causing strikes trigger: '.$type);}
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
$s=bbState();(new CoinAbilities($s,new Engine()))->offerGain($s->getCard(1),$s->getCard(1)->prop['actions'][0]);$html=(new Berserk\View\Screen\Battle\InfoPanel(new Berserk\View\Template(__DIR__.'/../templates')))->render($s,'host','host',$info,'?game=1');bbAssert(str_contains($html,'Получить монетку') && str_contains($html,'Не получать монетку'),'Pending rendered in InfoPanel');
// Original combat result survives the normal ranged-redirection pending.
$s=bbState(true);$s->getCard(2)->row=5;$s->getCard(2)->col=3;$s->getCard(2)->prop=['line'=>true];
$s->addCard(new CardInstance(instanceId:3,ukid:'redirect',owner:'player',zone:'field',row:5,col:4,hp:10,hpMax:10,prop:['line'=>true,'attack_redirect'=>['attack_types'=>'ranged']]));
$s->addCard(new CardInstance(instanceId:4,ukid:'redirect_target',owner:'player',zone:'field',row:5,col:5,hp:10,hpMax:10,prop:['line'=>true]));
bbStrike($s);$parent=$s->battle['strike'];bbRoll('4,6');bbAssert(bbChoose($s,'','host',2)->success,'Discharge begins standard redirection');bbAssert(isset($s->battle['pending_ranged_attack_redirect']),'Redirection pending preserved');$s=bbReload($s);
$r=(new Engine())->apply($s,'player',new Command('choose_ranged_redirect',['redirect_option'=>'3:4']));bbAssert($r->success,'Redirection resumes after reload');bbAssert($s->getCard(4)->hp===8,'Redirected discharge damage once');bbAssert($s->battle['strike']['target_id']===$parent['target_id'] && $s->battle['strike']['damage_total']===$parent['damage_total'],'Redirect does not overwrite parent');bbAssert(Dice::remaining()===[6],'Only one discharge roll');


// A repeated request from a previous pending cannot accept a newly opened choice.
$s=bbState();$p=new CoinAbilities($s,new Engine());$p->offerGain($s->getCard(1),$s->getCard(1)->prop['actions'][0]);$oldId=$s->battle['pending_coin_ability']['id'];bbChoose($s,'no');$p->offerGain($s->getCard(1),$s->getCard(1)->prop['actions'][0]);
$r=(new Engine())->apply($s,'host',new Command('choose_coin_ability',['choice'=>'yes','choice_id'=>$oldId]));bbAssert(!$r->success && $s->getCard(1)->coins===0,'Stale choice ID cannot confirm a later pending');


$s=bbState(true);$s->getCard(2)->hp=1;bbStrike($s);bbAssert(!isset($s->battle['pending_coin_ability']),'Lethal hit with no other living target has no pending');
$s=bbState(true);bbStrike($s);bbRoll('4');bbChoose($s,'','host',2);$e=new Engine();bbAssert($e->apply($s,'host',new Command('confirm_strike'))->success,'Original strike first acknowledgement');bbAssert($e->apply($s,'player',new Command('confirm_strike'))->success,'Original strike second acknowledgement');bbAssert(empty($s->battle['strike']),'Original lifecycle finishes after discharge');

echo "Bon and Berrow: $count assertions passed.\n";
