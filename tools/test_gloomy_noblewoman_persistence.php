<?php
declare(strict_types=1);
// Uses the existing process-local Db adapter and the native lock/CAS/journal implementation.
require __DIR__.'/test_action_resolution_persistence.php';
require __DIR__.'/test_gloomy_noblewoman.php';
use Berserk\Core\{Db,GameRepository,CommandJournalRepository,GameState,StaleStateException,CommandJournalException};
$transactionStart=$checks;
foreach(['cas','write','success']as$case){
 $s=gnState($fixture);$s->getCard(2)->hp=8;$s->getCard(5)->coins=3;$s->getCard(5)->prop['actions'][0]['coins']=1;
 gnCheck(gnCmd($s,'host','action',['card_id'=>5,'target_id'=>7,'action_key'=>'heal'])->success,'paid operation starts');
 $s->gameId=9901;$s->persistenceVersion=0;$s->persistenceJson=json_encode($s->toArray(),JSON_THROW_ON_ERROR);
 $db=new Db();$db->row=['ind'=>9901,'host_id'=>101,'player_id'=>202,'version'=>0,'status'=>'battle','json'=>$s->persistenceJson];
 $repo=new GameRepository($db);$rc=new ReflectionClass(CommandJournalRepository::class);$journal=$rc->newInstanceWithoutConstructor();$rc->getProperty('db')->setValue($journal,$db);
 $before=$db->row;$source=$s->toArray();$db->conflict=$case==='cas';$db->failure=$case==='write';$calls=0;
 $operation=function($working)use(&$calls){++$calls;return gnCmd($working,'host','choose_card_heal',['apply'=>'yes','heal_token'=>'$current']);};
 try{$repo->executeLockedCommand($s,$journal,1,$operation);gnCheck($case==='success','expected injected failure');}
 catch(StaleStateException $e){gnCheck($case==='cas','expected CAS conflict');}
 catch(RuntimeException $e){gnCheck($case==='write'&&$e->getMessage()==='injected persistence failure','expected write failure');}
 if($case!=='success'){
  gnCheck($db->row===$before&&$db->journal['status']==='processing','pending/HP/events/journal roll back together');
  gnCheck($s->toArray()===$source,'failed candidate not published');
  $db->conflict=false;$db->failure=false;$repo->executeLockedCommand($s,$journal,1,$operation);
 }
 $saved=GameState::fromArray(json_decode($db->row['json'],true,512,JSON_THROW_ON_ERROR));
 gnCheck($saved->getCard(7)->hp===7&&$saved->getCard(5)->coins===2&&$saved->getCard(5)->closed,'one healing and one initial payment');
 gnCheck(empty($saved->battle['pending_card_heal'])&&$saved->battle['card_healing']['last']['modified']===4,'complete continuation persisted');
 $beforeCalls=$calls;$savedRow=$db->row;
 try{$repo->executeLockedCommand($s,$journal,1,$operation);throw new RuntimeException('duplicate accepted');}
 catch(CommandJournalException $e){gnCheck($calls===$beforeCalls&&$db->row===$savedRow,'committed duplicate never executes');}
}
echo 'Gloomy noblewoman persistence: '.($checks-$transactionStart)." checks passed (native repository, in-memory DB adapter)\n";
