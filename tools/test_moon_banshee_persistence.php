<?php
declare(strict_types=1);
// Native repository/journal against the existing process-local database adapter.
require __DIR__.'/test_action_resolution_persistence.php';
require __DIR__.'/test_moon_banshee_edges.php';
use Berserk\Core\{Db,GameRepository,CommandJournalRepository,GameState,StaleStateException,CommandJournalException};
$txStart=$count;
foreach(['horror','scream_stack','scream_resolve']as$kind)foreach(['cas','write','success']as$failure){
 $s=mbState($kind!=='horror');
 if($kind==='scream_resolve'){mbStack($s);mbCheck(mbCmd($s,'combat_instant_pass',[],'player')->success,'first pass');}
 $s->gameId=9901;$s->persistenceVersion=0;$s->persistenceJson=json_encode($s->toArray(),JSON_THROW_ON_ERROR);
 $db=new Db();$db->row=['ind'=>9901,'host_id'=>101,'player_id'=>202,'version'=>0,'status'=>'battle','json'=>$s->persistenceJson];
 $repo=new GameRepository($db);$rc=new ReflectionClass(CommandJournalRepository::class);$journal=$rc->newInstanceWithoutConstructor();$rc->getProperty('db')->setValue($journal,$db);
 $before=$db->row;$source=$s->toArray();$db->conflict=$failure==='cas';$db->failure=$failure==='write';$calls=0;
 $operation=function($working)use(&$calls,$kind){++$calls;
  if($kind==='horror')return (new \Berserk\Core\Engine())->apply($working,'host',new \Berserk\Core\Command('action',['card_id'=>3,'target_id'=>2,'action_key'=>'horror']));
  if($kind==='scream_stack')return (new \Berserk\Core\Engine())->apply($working,'player',new \Berserk\Core\Command('combat_instant_play',['card_id'=>3,'instant_key'=>'krik']));
  return (new \Berserk\Core\Engine())->apply($working,'host',new \Berserk\Core\Command('combat_instant_pass'));
 };
 try{$repo->executeLockedCommand($s,$journal,1,$operation);mbCheck($failure==='success','expected failure');}
 catch(StaleStateException $e){mbCheck($failure==='cas','CAS failure');}
 catch(RuntimeException $e){mbCheck($failure==='write'&&$e->getMessage()==='injected persistence failure','write failure');}
 if($failure!=='success'){
  mbCheck($db->row===$before&&$db->journal['status']==='processing'&&$s->toArray()===$source,'atomic rollback HP/max/coins/pending');
  $db->conflict=false;$db->failure=false;$repo->executeLockedCommand($s,$journal,1,$operation);
 }
 $saved=GameState::fromArray(json_decode($db->row['json'],true,512,JSON_THROW_ON_ERROR));
 if($kind==='horror')mbCheck($saved->getCard(2)->hp===17&&$saved->getCard(2)->hpMax===17&&$saved->getCard(3)->coins===0&&$saved->getCard(3)->closed,'health effect and cost persisted once');
 if($kind==='scream_stack')mbCheck(empty($saved->battle['pending_combat_pick'])&&count($saved->battle['strike']['instant_stack'])===1&&$saved->getCard(3)->coins===1,'automatic target becomes exactly one stack item');
 if($kind==='scream_resolve')mbCheck($saved->getCard(2)->hp===15&&$saved->getCard(3)->coins===2&&count($saved->battle['strike']['instant_summary'])===1,'exactly one strike and coin persisted');
 $savedRow=$db->row;$beforeCalls=$calls;
 try{$repo->executeLockedCommand($s,$journal,1,$operation);throw new RuntimeException('duplicate accepted');}
 catch(CommandJournalException $e){mbCheck($calls===$beforeCalls&&$db->row===$savedRow,'committed duplicate never executes');}
}
echo 'Moon Banshee persistence: '.($count-$txStart)." additional checks passed (native repository, in-memory DB adapter)\n";
