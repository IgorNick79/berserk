<?php
declare(strict_types=1);
if(file_exists(__DIR__.'/../.git'))throw new RuntimeException('Disposable copy only');
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,CommandJournalRepository,GameState,CardInstance,Engine,Command,Dice,DbQueryException};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$db=new Db(require __DIR__.'/../config/db.php');$identity=$db->fetchOne('SELECT @@port p,DATABASE() d');
if((int)$identity['p']!==33671||!preg_match('/^lobby_[0-9]+$/D',$identity['d']))throw new RuntimeException('Wrong lab');
$repo=new GameRepository($db);$journal=new CommandJournalRepository($db);$game=(int)($argv[1]??0);$s=$repo->findById($game);if(!$s||$s->status!=='battle')throw new RuntimeException('Live lab game required');
$defs=[];foreach(json_decode(file_get_contents(__DIR__.'/special-s1-fixture.json'),true,512,JSON_THROW_ON_ERROR)as$d)$defs[$d['ukid']]=$d;
$cases=[['s1_17','shot',2],['s1_111','throw',2],['s1_2','uchr',2]];$ids=[];
foreach($cases as[$ukid,$type,$distance]){
 $s=$repo->findById($game);
 $entry=$journal->registerProcessing($game,'host','special-http-'.$type,'action',['type'=>$type],$s->persistenceVersion);
 $apply=static function(GameState $s)use($defs,$ukid,$type,$distance){
  // Test position, strictly inside the isolated transaction. The actual action uses Engine.
  $s->cards=[];$s->status='battle';$s->winner=null;$s->battle=['active'=>'host','turn'=>1,'strike'=>null];
  foreach([[1,$ukid,'host',3,3],[2,'s1_128','player',3+$distance,3],[3,'s1_1','host',1,1],[4,'s1_1','player',6,5]]as[$id,$key,$owner,$row,$col]){
   $d=$defs[$key];$s->addCard(new CardInstance(instanceId:$id,ukid:$key,owner:$owner,zone:'field',row:$row,col:$col,hp:(int)$d['health'],hpMax:(int)$d['health'],prop:json_decode($d['prop']??'{}',true)?:[],strikeWeak:(int)$d['strike_weak'],strikeMedium:(int)$d['strike_medium'],strikeStrong:(int)$d['strike_strong']));
  }
  if($type==='throw')$s->cards[1]->revealed=false;
  $_SESSION=['debug_roll'=>'4,6,2,5'];Dice::init();
  return(new Engine())->apply($s,'host',new Command($type==='uchr'?'uchr':'action',['card_id'=>1,'target_id'=>2,'action_key'=>$type]));
 };
 if($type==='shot'){
  $admin=new Db(['host'=>'127.0.0.1:33671','user'=>'root','pass'=>'','name'=>$identity['d']]);$before=$db->fetchOne('SELECT json,version FROM games WHERE ind='.$game);$count=$db->fetchOne('SELECT COUNT(*) n FROM battle_history_events WHERE game_id='.$game);$failed=false;
  $admin->query("ALTER TABLE battle_history_events ADD CONSTRAINT special_fault CHECK(event_type <> 'attack.result')");
  try{$repo->executeLockedCommand($s,$journal,(int)$entry['id'],$apply);}catch(DbQueryException){$failed=true;}finally{$admin->query('ALTER TABLE battle_history_events DROP CONSTRAINT special_fault');}
  if(!$failed||$db->fetchOne('SELECT json,version FROM games WHERE ind='.$game)!==$before||$db->fetchOne('SELECT COUNT(*) n FROM battle_history_events WHERE game_id='.$game)!==$count)throw new RuntimeException('Special rollback failed');
 }
 $repo->executeLockedCommand($s,$journal,(int)$entry['id'],$apply);
 $rows=$db->fetchAll('SELECT event_type,host_json,player_json FROM battle_history_events WHERE journal_id='.(int)$entry['id'].' ORDER BY event_seq');
 foreach(['attack.declared','dice.rolled','dice.final','attack.result','combat.wounds']as$event)if(count(array_filter($rows,fn($r)=>$r['event_type']===$event))!==1)throw new RuntimeException('Missing or duplicate '.$event);
 if($type==='throw')foreach($rows as$r)if(in_array($r['event_type'],['attack.result','attack.declared'],true)){ $p=json_decode($r['player_json'],true);if(isset($p['source_id'])||isset($p['value'])||str_contains($r['player_json'],'Тови'))throw new RuntimeException('Hidden source leak');}
 $retry=$journal->registerProcessing($game,'host','special-http-'.$type,'action',['type'=>$type],$s->persistenceVersion);if($retry['is_new']||$retry['status']!=='committed')throw new RuntimeException('Retry not committed');$ids[]=(int)$entry['id'];
}
echo json_encode(['status'=>'PASS','rollback'=>true,'retry'=>true,'journal_ids'=>$ids]);
