<?php
declare(strict_types=1);
define('C1_FIXTURES_ONLY',true);require __DIR__.'/test_single_roll_c1.php';
use Berserk\Core\{Engine,Command,InstantProcessor};
$c=require getcwd().'/config/db.php';$d=new mysqli($c['host'],$c['user'],$c['pass'],$c['name']);$d->set_charset('utf8mb4');$d->query('START TRANSACTION READ ONLY');$rows=$d->query("SELECT ukid,name,prop FROM cards WHERE ukid IN ('s1_25','s1_31','s1_65','s1_118','s1_147')")->fetch_all(MYSQLI_ASSOC);$d->rollback();c1check(count($rows)===5,'five current card definitions');
foreach($rows as $row){
 $s=c1state('tap',false);$s->getCard(3)->prop=json_decode($row['prop'],true,512,JSON_THROW_ON_ERROR);$s->getCard(3)->coins=4;
 $s->getCard(1)->flags['damage_taken_this_turn']=1;$s->getCard(2)->row=3;$s->getCard(2)->col=4;
 $e=new Engine();$ip=new InstantProcessor($s,$e);$list=$ip->getInstants('host','turn','turn');c1check(count($list)===1,'available '.$row['ukid']);
 $key=$list[0]['payload']['key'];$ip->openTurnStackWindow('host',['type'=>'manual'],'turn');
 c1check($e->apply($s,'host',new Command('play_turn_instant',['card_id'=>3,'instant_key'=>$key]))->success,'declare '.$row['ukid']);
 if(isset($s->battle['pending_instant_pick'])){
  $target=in_array($row['ukid'],['s1_25','s1_31'],true)?1:2;
  $r=$e->apply($s,'host',new Command('choose_instant_pick',['target_id'=>$target]));c1check($r->success,'target '.$row['ukid'].' '.($r->error??''));
 }
 if($row['ukid']!=='s1_118'){
  for($i=0;$i<3&&!empty($s->battle['turn_instant_stack']);$i++)c1check($e->apply($s,$s->battle['turn_instant_stack']['priority'],new Command('pass_turn_instant'))->success,'pass '.$row['ukid']);
  $ok=match($row['ukid']){'s1_25'=>$s->getCard(1)->hp===20,'s1_31'=>!$s->getCard(1)->closed&&$s->getCard(1)->hp===18,'s1_65'=>$s->getCard(2)->closed,'s1_147'=>$s->getCard(2)->hp===18};
  c1check($ok,'actual effect '.$row['ukid']);
  $ack=\Berserk\Core\TurnInstantResult::current($s->battle['turn_instant_result']);
  c1check($e->apply($s,$ack['owner'],new Command('turn_instant_result_ok',['ack_id'=>$ack['ack_id']]))->success,'actual result ACK '.$row['ukid']);
 }
 echo $row['name'].': '.($row['ukid']==='s1_118'?'availability/declaration/target':'one effect + ACK').' passed (not full-card coverage)',PHP_EOL;
}
echo "Real turn card checks: $checks passed\n";
