<?php
declare(strict_types=1);
require __DIR__.'/test_action_resolution_persistence.php';
use Berserk\Core\{ActionResolution,CardInstance,Command,Engine,GameState,OchiKronga,StaleStateException,CommandJournalException};
foreach(['cas','write','success']as$case){
 [$s,$db,$repo,$journal]=persistenceFixture();
 $s->addCard(new CardInstance(instanceId:9,ukid:'eyes-persistence-fixture',owner:'host',zone:'field',row:1,col:2,hp:10,hpMax:10,closed:true,prop:[OchiKronga::PROP=>['filter'=>'forests']]));
 $s->getCard(1)->element='forests';$s->getCard(4)->element='forests';$s->getCard(4)->hp=29;$s->getCard(4)->prop['actions']=[['type'=>'heal','key'=>'h','value'=>1,'self'=>true]];
 ActionResolution::effect($s,$s->getCard(1),'strike','ordinary',['hit'=>true]);ActionResolution::effect($s,$s->getCard(1),'shot','ordinary',['hit'=>true]);
 $s->persistenceJson=json_encode($s->toArray(),JSON_THROW_ON_ERROR);$db->row['json']=$s->persistenceJson;
 $before=$db->row;$sourceBefore=$s->toArray();$db->conflict=$case==='cas';$db->failure=$case==='write';$calls=0;
 $op=function($working)use(&$calls){$calls++;$r=(new Engine())->apply($working,'host',new Command('action',['card_id'=>4,'target_id'=>4,'action_key'=>'h']));ca($r->success&&!$working->getCard(9)->closed&&$working->getCard(9)->flags[OchiKronga::STATE]['uses']===1,'candidate actually opened before persistence');return $r;};
 try{$repo->executeLockedCommand($s,$journal,1,$op);ca($case==='success','expected injected failure');}
 catch(StaleStateException $e){ca($case==='cas','only expected CAS');}
 catch(RuntimeException $e){ca($case==='write'&&$e->getMessage()==='injected persistence failure','only injected failure');}
 if($case!=='success'){
  ca($db->row===$before&&$s->toArray()===$sourceBefore,'opening, sequence, limit and HP roll back together');
  $db->conflict=false;$db->failure=false;$repo->executeLockedCommand($s,$journal,1,$op);
 }
 $saved=GameState::fromArray(json_decode($db->row['json'],true,512,JSON_THROW_ON_ERROR));$memory=$saved->getCard(9)->flags[OchiKronga::STATE];
 ca(!$saved->getCard(9)->closed&&$memory['uses']===1&&$memory['sequence']===[]&&$saved->getCard(4)->hp===30,'retry publishes exactly one opening');
 $savedRow=$db->row;$priorCalls=$calls;
 try{$repo->executeLockedCommand($s,$journal,1,$op);throw new RuntimeException('duplicate accepted');}
 catch(CommandJournalException $e){ca($calls===$priorCalls&&$db->row===$savedRow,'committed duplicate cannot reach ability');}
}
echo "Ochi Kronga: repository CAS/write rollback, retry and committed duplicate passed (no DB)\n";
