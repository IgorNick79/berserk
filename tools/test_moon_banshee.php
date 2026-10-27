<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,TurnProcessor,BattleHelper,InstantProcessor,InformationalWaitManager};
Autoloader::register(); Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$scenario=json_decode(file_get_contents($argv[1]??__DIR__.'/../debug/scenarios/moon_banshee.json'),true,512,JSON_THROW_ON_ERROR);
$prop=json_decode(file_get_contents(__DIR__.'/../docs/moon-banshee.prop.json'),true,512,JSON_THROW_ON_ERROR);
$count=0;
function mbCheck(bool $ok,string $message):void {global $count;$count++;if(!$ok)throw new RuntimeException($message);}
function mbRead(array $data,string $path):mixed {foreach(explode('.',$path)as$key){if(!is_array($data)||!array_key_exists($key,$data))return null;$data=$data[$key];}return $data;}
foreach($scenario['cases'] as $case){
    $s=new GameState(9157,101,202);$s->status='battle';$s->players['host']->side=1;$s->players['player']->side=2;
    $s->battle=['turn'=>1,'active'=>'host','strike'=>null,'hidden_row_revealed'=>true];
    foreach($scenario['cards'] as $raw){$id=$raw['instance_id'];$raw=array_replace($raw,$case['cards'][$id]??[]);if($raw['ukid']==='s1_157')$raw['prop']=$raw['prop']??$prop;$s->addCard(CardInstance::fromArray($raw));}
    if(isset($case['combat'])){
        $dice=$case['combat']['dice']??6;$level=$dice<=3?'weak':($dice<=5?'medium':'strong');
        $s->getCard(2)->closed=$case['combat']['target_closed']??true;
        $s->battle['strike']=array_replace(['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'state'=>'waiting_instant',
          'attack_dice'=>$dice,'defend_dice'=>0,'attack_mod'=>0,'defend_mod'=>0,
          'result'=>['attack'=>$level,'defend'=>'','winner'=>'attack'],'confirmed'=>[],
          'instant_phase'=>'combat','combat_instant_window'=>'main','instant_priority'=>'host',
          'instant_participants'=>['host','player'],'instant_passed'=>[],'instant_next_sequence'=>0,'instant_stack'=>[]],$case['combat']['strike']??[]);
    }
    $e=new Engine();
    if(!empty($case['start_effects']))(new TurnProcessor($s,$e))->applyStartEffects();
    foreach($case['commands']as$i=>$step){
        // Simulate a fresh request (including pending), never reuse runtime objects.
        $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));
        $params=$step['params']??[];
        foreach($params as$key=>$v){if($v==='$pick')$params[$key]=$s->battle['pending_combat_pick']['pick_token']??'';if($v==='$wait')$params[$key]=InformationalWaitManager::token($s);}
        $r=$e->apply($s,$step['player']??'host',new Command($step['type'],$params));
        mbCheck($r->success===($step['success']??true),$case['name']." command $i ".$step['type'].' '.($r->error??''));
        foreach($step['expect']??[]as$path=>$value)mbCheck(mbRead($s->toArray(),$path)===$value,$case['name']." step $i $path expected ".json_encode($value).' got '.json_encode(mbRead($s->toArray(),$path)));
    }
    foreach($case['expect']??[]as$path=>$value)mbCheck(mbRead($s->toArray(),$path)===$value,$case['name']." $path expected ".json_encode($value).' got '.json_encode(mbRead($s->toArray(),$path)));
    if(isset($case['targets'])){
        $targets=array_keys(BattleHelper::getAttackTargets($s,$s->getCard(3),'action:horror','host'));sort($targets);
        mbCheck($targets===$case['targets'],$case['name'].' legal targets '.json_encode($targets));
    }
    // Both clients reload the same authoritative public card facts.
    foreach(['host','player']as$viewer){$copy=GameState::fromArray($s->toArray());mbCheck($copy->getCard(2)->hp===$s->getCard(2)->hp&&$copy->getCard(2)->hpMax===$s->getCard(2)->hpMax,$case['name'].' sync '.$viewer);}
}
echo 'Moon Banshee JSON: '.count($scenario['cases'])." cases, $count checks passed\n";
