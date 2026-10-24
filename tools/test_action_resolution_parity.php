<?php
declare(strict_types=1);
require __DIR__.'/test_combat_continuation.php';
use Berserk\Core\{ActionResolver,Command,Engine,StrikeResolver};
function nativeOnly(array $value):array{unset($value['action_resolution'],$value['_resolution_id']);foreach($value as &$v)if(is_array($v))$v=nativeOnly($v);return $value;}
$results=[];
foreach(['hit','miss','zero','blocked','vampire']as$case){
 $s=cs();$s->getCard(1)->hp=20;
 if($case==='zero')$s->getCard(2)->prop['damage_reduction']=[['value'=>99]];
 if($case==='vampire')$s->getCard(1)->prop['vampire']=true;
 $s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'confirmed'=>[],'state'=>'waiting_choice'];
 if($case==='blocked')$s->battle['strike']['coin_blocked']=true;
 (new StrikeResolver($s,new Engine()))->apply(['attack'=>in_array($case,['miss','blocked'],true)?'':'weak','defend'=>'','winner'=>'attack'],false);
 $results[$case]=nativeOnly(['cards'=>$s->toArray()['cards'],'strike'=>$s->battle['strike']]);
}
foreach(['shot','heal','multi_heal']as$kind){
 $s=cs();$s->getCard(2)->row=6;$s->getCard(1)->hp=28;
 $action=$kind==='shot'?['type'=>'shot','value'=>2,'range'=>5]:['type'=>'heal','value'=>2,'self'=>true];$action['key']='a';
 if($kind==='multi_heal')$action['max_targets']=3;
 $s->getCard(1)->prop['actions']=[$action];$_SESSION['debug_roll']='6';unset($_SESSION['debug_roll_state']);\Berserk\Core\Dice::init();
 $r=(new ActionResolver($s,new Engine()))->handle('host',new Command('action',['card_id'=>1,'target_id'=>$kind==='shot'?2:1,'action_key'=>'a']));ca($r->success,'native parity action');
 if($kind==='multi_heal'){$s=cr($s);$r=(new ActionResolver($s,new Engine()))->chooseMultiHeal('host',new Command('choose_multi_heal',['target_ids'=>[1,5]]));ca($r->success,'native parity multi');}
 $results[$kind]=nativeOnly(['cards'=>$s->toArray()['cards'],'strike'=>$s->battle['strike']]);
}
echo 'PARITY '.hash('sha256',json_encode($results,JSON_THROW_ON_ERROR))."\n";
