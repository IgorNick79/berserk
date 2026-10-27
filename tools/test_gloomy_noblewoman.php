<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,CardInstance,GameState,Engine,Command,Result,CardHealing,EffectiveFeatures,CardStats,ZoneManager,DamageResolver,ActionResolver,InstantProcessor,BattleWaitResolver,InformationalWaitManager};
use Berserk\Core\Choice\ChoiceRegistry;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(static function(int$n,string$m,string$f,int$l):bool{if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
$checks=0;
function gnCheck(bool$ok,string$m):void{global$checks;++$checks;if(!$ok)throw new RuntimeException($m);}
function gnProp():array{return json_decode(file_get_contents(__DIR__.'/../docs/gloomy-noblewoman.prop.json'),true,512,JSON_THROW_ON_ERROR);}
function gnCopy(GameState$s):GameState{return GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));}
function gnState(array$fixture,?array$case=null):GameState{
 $s=new GameState(-2715402,101,202);$s->status='battle';$s->battle=['active'=>$case['active']??'host','turn'=>1,'strike'=>null,'hidden_row_revealed'=>true];
 foreach($fixture['cards']as$i=>$e){$id=$i+1;$p=$id<=2?gnProp():($id<=4?['on_heal_instead_wound'=>['uses_per_turn'=>3]]:($id<=6?['actions'=>[['key'=>'heal','type'=>'heal','value'=>2]]]:[]));
 $s->addCard(new CardInstance(instanceId:$id,ukid:$e['ukid'],owner:$e['owner'],zone:'field',row:$e['row'],col:$e['col'],hp:$e['hp'],hpMax:12,element:'dark',move:2,moveMax:2,strikeWeak:1,strikeMedium:2,strikeStrong:3,prop:$p));}
 if($case){[$s->cards[1]->hp,$s->cards[2]->hp]=$case['noble_hp'];if(!empty($case['instant'])){
 $f=$case['instant'];$source=$s->getCard($f['source_id']);$source->flags['in_stack']=true;
 $s->battle['turn_instant_stack']=['state'=>'ordering','priority'=>'host','participants'=>['host','player'],'passed'=>['player'],
 'phase'=>'turn','continuation'=>['type'=>'manual'],'stack'=>[['card_id'=>$source->instanceId,'target_id'=>$f['target_id'],'player'=>$source->owner,
 'label'=>'Излечение','effect'=>['type'=>$f['type']??'heal','value'=>$f['value']],'close_source'=>true,'sequence'=>1]]];
 }
  foreach($case['overrides']['cards']??[] as $id=>$data) $s->cards[(int)$id]=CardInstance::fromArray(array_replace($s->getCard((int)$id)->toArray(),$data));
  if(isset($case['overrides']['battle']))$s->battle=array_replace($s->battle,$case['overrides']['battle']);
 }return$s;
}
function gnPath(GameState $s,string $path):mixed{
 $data=$s->toArray();foreach(explode('.',$path)as$key){if(!is_array($data)||!array_key_exists($key,$data))return null;$data=$data[$key];}return$data;
}
function gnCmd(GameState$s,string$owner,string$type,array$payload=[]):Result{
 if(($payload['heal_token']??null)==='$current')$payload['heal_token']=$s->battle['pending_card_heal']['token']??$s->battle['pending_heal_instead_wound']['token']??'';
 if(($payload['wait_id']??null)==='$wait')$payload['wait_id']=InformationalWaitManager::token($s);
 if(($payload['heal_token']??null)==='$notice')$payload['heal_token']=$s->battle['pending_card_heal_notice']['token']??'';
 return(new Engine(null,fn()=>1800000000))->apply($s,$owner,new Command($type,$payload));
}
$path=$argv[1]??__DIR__.'/../debug/scenarios/gloomy_noblewoman_satyr_interaction.json';$fixture=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
foreach($fixture['cases']as$case){
 $s=gnState($fixture,$case);$name=$case['name'];
 $_SESSION['debug_roll']=$case['dice']??'off';unset($_SESSION['debug_roll_state']);\Berserk\Core\Dice::init();
 foreach($case['steps']as$step){$s=gnCopy($s);$r=gnCmd($s,$step['owner'],$step['command'],$step['payload']);gnCheck($r->success===($step['success']??true),$name.' '.$step['command'].': '.$r->error);
  foreach($step['expect']??[] as $path=>$value)gnCheck(gnPath($s,$path)===$value,$name.' '.$step['command'].' path '.$path.': '.json_encode(gnPath($s,$path)));
  if(CardHealing::waiting($s)){$pending=ChoiceRegistry::current($s);gnCheck($pending!==null,$name.' registered pending');$wait=BattleWaitResolver::current($s);gnCheck(($wait['kind']??'')==='decision',$name.' decision wait');
   $p=$s->battle[CardHealing::PENDING]??$s->battle['pending_heal_instead_wound'];
   gnCheck($wait['required_players']===[$p['owner']],$name.' exact pending owner');
   foreach(['host','player']as$viewer){$spec=$pending->spec($s,$viewer,[],'?game=1',$viewer);gnCheck($spec!==null&&$spec->isMine===($viewer===$p['owner']),$name.' synchronized panel '.$viewer);}}
 }
 if(isset($case['expect_paths'])) {
  foreach($case['expect_paths']as$path=>$value)gnCheck(gnPath($s,$path)===$value,$name.' final '.$path.': '.json_encode(gnPath($s,$path)));
  if(isset($case['expect_dice_remaining']))gnCheck(\Berserk\Core\Dice::remaining()===$case['expect_dice_remaining'],$name.' dice consumed once');
  gnCheck(gnCopy($s)->toArray()===$s->toArray(),$name.' whole state survives save');continue;
 }
 $expect=$case['expect'];$r=$s->battle['card_healing']['last'];
 foreach(['declared','modified','restored','wounds']as$key)gnCheck($r[$key]===$expect[$key],$name.' '.$key.' '.json_encode($r));
 $events=$s->battle['card_healing']['events'];$owners=array_column(array_values(array_filter($events,fn($e)=>$e['type']==='noble_pending')),'owner');
 gnCheck($owners===$expect['noble_pending_owners'],$name.' pending owner/order');
 $types=['declared','noble_checks'];if($expect['automatic_conflict'])$types[]='automatic_conflict';
 elseif($owners)$types=array_merge($types,['noble_pending','noble_decided']);
 if($expect['wounds']>0)$types=array_merge($types,['satyr_pending','satyr_replaced']);$types[]='completed';
 gnCheck(array_column($events,'type')===$types,$name.' significant event order');
 $checkEvent=array_values(array_filter($events,fn($e)=>$e['type']==='noble_checks'))[0];
 gnCheck($checkEvent['active_owner']===$case['active'],$name.' active owner, independent of healing source');
 if($expect['automatic_conflict'])gnCheck(array_column($checkEvent['checks'],'owner')===[$case['active'],$s->getOpponentKey($case['active'])],$name.' active then passive checks');
 foreach([1,2]as$id)gnCheck(!$s->getCard($id)->closed,$name.' noble passive does not tap '.$id);
 foreach([3,4]as$id){$used=(int)($s->getCard($id)->flags['on_heal_wound_used_this_turn']??0);gnCheck($used===($expect['wounds']>0&&$id===$r['target_id']?1:0),$name.' per-Satyr use '.$id);}

 gnCheck(in_array('automatic_conflict',array_column($events,'type'),true)===$expect['automatic_conflict'],$name.' automatic conflict');
 gnCheck(count(array_filter($events,fn($e)=>$e['type']==='completed'))===1,$name.' exactly one completion');
 gnCheck($s->battle['card_healing']['first_used']===true,$name.' first consumed');
 gnCheck(!CardHealing::waiting($s),$name.' no unfinished operation');
 gnCheck($s->getCard($r['target_id'])->hp===$expect['target_hp'],$name.' final HP');
 gnCheck($s->getCard($case['instant']['source_id']??$case['steps'][0]['payload']['card_id'])->closed,$name.' source tap');
 gnCheck(gnCopy($s)->toArray()===$s->toArray(),$name.' saved whole state');
}
echo 'Gloomy noblewoman JSON: '.count($fixture['cases'])." cases, $checks checks passed\n";
