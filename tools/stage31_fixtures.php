<?php
declare(strict_types=1);
// Run only from a disposable copy: Engine writes GameLog next to src.
if (getenv('BERSERK_STAGE31_ISOLATED') !== '1' || file_exists(__DIR__.'/../.git')) {
    throw new RuntimeException('Stage 3.1 tests require an isolated source copy and storage');
}
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,Dice,StrikeResolver,InstantProcessor,WoundTransferProcessor,ValhallaProcessor,InformationalWaitManager,TurnPhaseProcessor};
use Berserk\Core\Choice\ValhallaPickChoice;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$results=[];$traces=[];$case='';$serial=0;
function s31check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function s31test(string $id,Closure $f):void{global $results,$case;$case=$id;try{$detail=$f();$results[]=['case'=>$id,'status'=>'PASS','detail'=>$detail];}catch(Throwable $e){$results[]=['case'=>$id,'status'=>'FAIL','error'=>$e->getMessage()];}}
function s31card(int $id,string $owner,int $r,int $c,array $prop=[],bool $closed=false):CardInstance{return new CardInstance(instanceId:$id,ukid:'s31_'.$id,owner:$owner,zone:'field',row:$r,col:$c,hp:20,hpMax:20,move:1,moveMax:1,strikeWeak:2,strikeMedium:3,strikeStrong:4,prop:$prop,closed:$closed);}
function s31state(array $prop=[]):GameState{global $serial;$s=new GameState(-310000-(++$serial),101,202);$s->status='battle';$s->players['host']->side=1;$s->players['player']->side=2;$s->battle=['turn'=>1,'active'=>'host','strike'=>null,'hidden_row_revealed'=>true];foreach([s31card(1,'host',3,3,$prop),s31card(2,'player',3,4,[],true),s31card(3,'host',2,2),s31card(4,'player',4,4,[],true),s31card(90,'host',1,1),s31card(91,'player',6,5)] as $c)$s->addCard($c);return $s;}
function s31roundtrip(GameState $s):GameState{return GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));}
function s31trace(GameState $s,string $point):void{global $traces,$case;$traces[]=['case'=>$case,'point'=>$point,'state'=>$s->toArray()];}
function s31cmd(GameState &$s,string $p,string $type,array $payload=[]):\Berserk\Core\Result{$r=(new Engine())->apply($s,$p,new Command($type,$payload));s31trace($s,$type.':'.($r->success?'ok':$r->error));$s=s31roundtrip($s);return $r;}
function s31ok(GameState &$s,string $p,string $type,array $payload=[]):void{$r=s31cmd($s,$p,$type,$payload);s31check($r->success,$type.': '.$r->error);}
function s31ack(GameState &$s):void{foreach(['host','player']as$p)if($s->status==='battle'&&($s->battle['strike']['state']??'')==='results'&&!in_array($p,$s->battle['strike']['confirmed']??[],true))s31ok($s,$p,'confirm_strike',['wait_id'=>(string)InformationalWaitManager::token($s)]);}
function s31roll(string $v='6*'):void{$_SESSION=['debug_roll'=>$v];Dice::init();}
function s31combat(GameState $s,int $a=6,int $d=0):void{$s->getCard(2)->closed=$d===0;$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'state'=>'waiting_instant','attack_dice'=>$a,'defend_dice'=>$d,'attack_mod'=>0,'defend_mod'=>0,'result'=>(new StrikeResolver($s,new Engine()))->strikeTable($a,$d),'confirmed'=>[],'instant_phase'=>'combat','combat_instant_window'=>'main','instant_stack'=>[]];}
function s31item(int $id,string $side,array $effect,string $phase,int $sequence=0):array{return ['card_id'=>$id,'target_id'=>$id,'player'=>$side,'effect'=>$effect,'phase'=>$phase,'sequence'=>$sequence,'label'=>'s31_'.$id.'_'.$sequence];}
function s31resolve(GameState &$s):void{(new InstantProcessor($s,new Engine()))->resolveStack();InformationalWaitManager::reconcile($s,time());$s=s31roundtrip($s);if(($s->battle['strike']['state']??'')==='waiting_choice'){s31ok($s,$s->battle['strike']['choice_winner']==='attack'?'host':'player','choose_strike_mode',['mode'=>'normal']);}}


function s31wounds():GameState{$s=s31state();s31combat($s);$s->getCard(1)->hp=18;$s->getCard(1)->flags['damage_taken_this_strike']=2;$s->getCard(3)->coins=3;$s->battle['strike']['state']='results';$s->battle['strike']['damage_applied']=true;$s->battle['strike']['confirmed']=['host','player'];$s->battle['strike']['pending_wounds_resolution']=true;$s->battle['strike']['wounds_after_result_ack']=true;$s->battle['strike']['instant_stack']=[s31item(3,'host',['type'=>'redistribute_wounds'],'wounds')];$s->battle['strike']['instant_summary']=[['effect_type'=>'earlier','applied'=>true]];(new InstantProcessor($s,new Engine()))->resumeCombatWounds();return s31roundtrip($s);}

function s31valhalla(bool $double=true):GameState{$s=s31state();if($double)$s->getCard(3)->prop=['ragnar_valhalla_double'=>true];$v=s31card(5,'host',1,2,['valhalla'=>[['type'=>'damage','value'=>1,'target'=>'enemy_creature']]]);$v->zone='graveyard';$v->flags['valhalla_active']=true;$s->addCard($v);(new ValhallaProcessor($s,new Engine()))->beginExecute($v,'host');return s31roundtrip($s);}
