<?php
declare(strict_types=1);
// Process-local adapter: no credentials, connection or real SQL execution.
namespace Berserk\Core {
 final class Db {
  public array $row=[]; public array $journal=['id'=>1,'game_id'=>9901,'expected_version'=>0,'status'=>'processing'];
  public bool $conflict=false; public bool $failure=false; private bool $tx=false;private array $snapshot=[];private int $affected=0;
  public function fetchOne(string $sql):?array {
   if(str_starts_with($sql,'SHOW COLUMNS'))return ['Field'=>'version'];
   if(str_contains($sql,'SELECT game_id FROM battle_history_matches'))return null;
   if(str_contains($sql,'SELECT * FROM games'))return $this->row;
   if(str_contains($sql,'SELECT * FROM battle_command_journal'))return $this->journal;
   throw new \RuntimeException('Unexpected adapter SELECT '.$sql);
  }
  public function query(string $sql):bool {
   if(str_starts_with($sql,'UPDATE games')){
    if($this->failure)throw new \RuntimeException('injected persistence failure');
    if(!preg_match("/SET json = '(.*)', status = '([^']+)', version = (\d+) WHERE ind = (\d+) AND version = (\d+)$/s",$sql,$m))throw new \RuntimeException('Unexpected UPDATE');
    $this->affected=(int)(!$this->conflict&&$this->row['version']===(int)$m[5]);
    if($this->affected){$this->row['json']=stripslashes($m[1]);$this->row['version']=(int)$m[3];$this->row['status']=$m[2];}
   }elseif(str_starts_with($sql,'UPDATE battle_command_journal')){
    preg_match("/SET status = '([^']+)'/",$sql,$m);$this->journal['status']=$m[1];$this->affected=1;
   }else throw new \RuntimeException('Unexpected adapter write '.$sql);
   return true;
  }
  public function escape(string $s):string{return addslashes($s);}public function affectedRows():int{return $this->affected;}
  public function isBorrowedTransaction():bool{return false;}public function isSameConnection(self $db):bool{return $db===$this;}
  public function inTransaction():bool{return $this->tx;}
  public function beginTransaction():void{$this->snapshot=[$this->row,$this->journal];$this->tx=true;}
  public function commit():void{$this->tx=false;}
  public function rollback():void{[$this->row,$this->journal]=$this->snapshot;$this->tx=false;}
 }
}
namespace {
 require __DIR__.'/test_combat_continuation.php';
 use Berserk\Core\{ActionResolution,Command,CommandJournalRepository,Db,Engine,GameRepository,GameState,StaleStateException};
 function persistenceFixture():array{
  $s=cs();$s->getCard(1)->prop['save_coins']=true;$s->persistenceVersion=0;$s->persistenceJson=json_encode($s->toArray(),JSON_THROW_ON_ERROR);
  $db=new Db();$db->row=['ind'=>9901,'host_id'=>101,'player_id'=>202,'version'=>0,'status'=>'battle','json'=>$s->persistenceJson];
  $repo=new GameRepository($db);$rc=new ReflectionClass(CommandJournalRepository::class);$journal=$rc->newInstanceWithoutConstructor();
  $rc->getProperty('db')->setValue($journal,$db); // Schema is outside this process-local transaction test.
  return [$s,$db,$repo,$journal];
 }
 foreach(['cas','write','success']as$case){
  [$s,$db,$repo,$journal]=persistenceFixture();$before=$db->row;$source=$s->toArray();$db->conflict=$case==='cas';$db->failure=$case==='write';$calls=0;
  $operation=function($working)use(&$calls){$calls++;return (new Engine())->apply($working,'host',new Command('gain_coin',['card_id'=>1]));};
  try{$repo->executeLockedCommand($s,$journal,1,$operation);if($case!=='success')throw new RuntimeException('failure not raised');}
  catch(StaleStateException $e){ca($case==='cas','only CAS conflict expected');}
  catch(RuntimeException $e){ca($case==='write'&&$e->getMessage()==='injected persistence failure','only injected write failure expected:'.$e->getMessage());}
  ca($calls===1,'native execution once');
  if($case!=='success'){
   ca($db->row===$before&&$db->journal['status']==='processing','atomic rollback including delivery');
   ca($s->toArray()===$source,'source DTO never publishes failed candidate');
   $db->conflict=false;$db->failure=false;$repo->executeLockedCommand($s,$journal,1,$operation);
  }
  $saved=GameState::fromArray(json_decode($db->row['json'],true,512,JSON_THROW_ON_ERROR));
  ca($saved->getCard(1)->coins===1&&$saved->battle[ActionResolution::KEY]['sequence']===1,'retry publishes exactly one action and delivery');
  $beforeCalls=$calls;$savedRow=$db->row;
  try{$repo->executeLockedCommand($s,$journal,1,$operation);throw new RuntimeException('duplicate accepted');}
  catch(\Berserk\Core\CommandJournalException $e){ca($calls===$beforeCalls&&$db->row===$savedRow,'committed duplicate never invokes Engine');}
 }
 echo "resolution persistence: actual repository lock/CAS, write rollback, retry and committed duplicate passed (in-memory adapter, no DB)\n";
}
