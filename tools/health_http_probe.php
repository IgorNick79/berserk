<?php
declare(strict_types=1);
if(file_exists(__DIR__.'/../.git'))throw new RuntimeException('Disposable copy only');
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,CommandJournalRepository,GameState,CardInstance,CardHealing,DamageResolver,WoundTransferProcessor,TurnPhaseProcessor,Engine,Command,Result};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$db=new Db(require __DIR__.'/../config/db.php');$identity=$db->fetchOne('SELECT @@port p,DATABASE() d');if((int)$identity['p']!==33671||!preg_match('/^lobby_[0-9]+$/D',$identity['d']))throw new RuntimeException('Wrong lab');
$game=(int)($argv[1]??0);$repo=new GameRepository($db);$s=$repo->findById($game);if(!$s||$s->status!=='battle')throw new RuntimeException('Live lab game required');
$defs=[];foreach(json_decode(file_get_contents(__DIR__.'/health-s1-fixture.json'),true,512,JSON_THROW_ON_ERROR)as$d)$defs[$d['ukid']]=$d;
$j=new CommandJournalRepository($db);$entry=$j->registerProcessing($game,'host','health-http-fixture','health_fixture',[],$s->persistenceVersion);
$repo->executeLockedCommand($s,$j,(int)$entry['id'],function(GameState $s)use($defs):Result{
 $s->historyRecorder->commandStarted($s,'host',new Command('health_fixture'));
 $s->cards=[];
 foreach([[1,'s1_71','host',3,1],[2,'s1_128','host',3,2],[3,'s1_83','host',2,1],[4,'s1_1','player',4,2],[5,'s1_179','host',2,2],[6,'s1_1','player',5,3],[7,'s1_1','player',6,4]] as [$id,$ukid,$owner,$row,$col]){
  $d=$defs[$ukid];$s->addCard(new CardInstance(instanceId:$id,ukid:$ukid,owner:$owner,zone:'field',row:$row,col:$col,hp:(int)$d['health'],hpMax:(int)$d['health'],prop:json_decode($d['prop']??'{}',true)?:[]));
 }
 $s->cards[1]->revealed=false;$s->cards[2]->hp-=3;$s->cards[5]->hp=1;$s->cards[3]->closed=true;
 $e=new Engine();$d=new DamageResolver($s);$h=new CardHealing($s,$e);
 $h->begin($s->cards[1],$s->cards[2],2,'heal');
 $h->begin($s->cards[1],$s->cards[3],2,'heal',[],['active'=>true,'allow_open'=>true]);
 $s->cards[2]->modifiers[]=['stat'=>'shield_light','value'=>1];$d->applyDamage($s->cards[2],2,'shot',$s->cards[4]);$s->cards[2]->modifiers=[];
 $s->cards[2]->armor=1;$d->applyDamage($s->cards[2],2,'shot',$s->cards[4]);
 $d->forceDeath($s->cards[6],'strike',$s->cards[5]);
 $s->battle['pending_wound_transfer']=['owner'=>'host','source_id'=>1,'donor_id'=>2,'transfers'=>[['target_id'=>4,'amount'=>1]],'options'=>['on_finish'=>'main_phase']];
 (new WoundTransferProcessor($s,$e))->finish('host');
 $result=Result::ok();$s->historyRecorder->commandFinished($s,$result);return$result;
});
$types=$db->fetchAll("SELECT event_type,host_json,player_json FROM battle_history_events WHERE journal_id=".(int)$entry['id']." ORDER BY event_seq");
foreach(['health.maximum','health.replaced','health.transfer_out','health.prevented','health.armor']as$type){if(count(array_filter($types,fn($x)=>$x['event_type']===$type))!==1)throw new RuntimeException('Missing or duplicate '.$type);}
echo json_encode(['status'=>'PASS','journal_id'=>(int)$entry['id'],'types'=>array_column($types,'event_type')]);
