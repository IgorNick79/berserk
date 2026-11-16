<?php
declare(strict_types=1);
if(!defined('C2_FIXTURES_ONLY'))define('C2_FIXTURES_ONLY',true);require_once __DIR__.'/test_single_roll_c2.php';require_once __DIR__.'/migrations/20261115_instant_battle_types.php';
use Berserk\Core\{Dice,Engine,Command,SingleRoll,GameState,InstantProcessor,InformationalWaitManager as IW};
$c=require getcwd().'/config/db.php';$db=new mysqli($c['host'],$c['user'],$c['pass'],$c['name']);$db->set_charset('utf8mb4');$db->query('START TRANSACTION READ ONLY');
$dp=[];foreach($db->query("SELECT ukid,prop FROM cards WHERE ukid IN ('s1_20','s1_157','s1_184')")->fetch_all(MYSQLI_ASSOC) as $r){$next=battleTypesProp($r['ukid'],$r['prop']);$p=json_decode($next,true);$original=json_decode($r['prop'],true);$compare=$p;foreach($compare['instants'] as &$inst)unset($inst['battle_types']);unset($inst);foreach($original['instants'] as &$inst)unset($inst['battle_types']);unset($inst);c1check($compare===$original,'migration preserves every unrelated field');c1check(json_decode(battleTypesProp($r['ukid'],$next),true)===$p,'migration idempotent');$dp[$r['ukid']]=$p;}$db->rollback();
function dAck(GameState $s):void {foreach(['host','player'] as $actor)c2cmd($s,$actor,'confirm_strike',['wait_id'=>IW::token($s)]);}
if(defined('D_FIXTURES_ONLY'))return;
foreach(['tap','magic','uchr','shot','throw','discharge'] as $type){
 $s=c1state($type,false);$s->getCard(3)->owner='player';$s->getCard(3)->prop=$dp['s1_157'];$s->getCard(4)->prop=$dp['s1_20'];
 $s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'running','remaining_seconds'=>180,'anchor_at'=>time(),'pause_reason'=>null];
 $_SESSION=['debug_roll'=>'4,6'];Dice::init();
 (new SingleRoll($s,new Engine()))->begin($s->getCard(1),$s->getCard(2),$type,['type'=>$type,'range'=>6,'strike'=>['weak'=>3,'medium'=>3,'strong'=>3]],$type==='uchr'?'uchr':'damage');
 if(in_array($type,['shot','throw','discharge'],true)) {c1check(empty($s->battle['single_roll'])&&$s->getCard(2)->hp===16,'ranged attacks excluded');continue;}
 c1check(($s->battle['single_roll']['computed_value']??null)===3,'actual computed attack value checkpoint');
 $html=(new \Berserk\View\Screen\Battle\InfoPanel(new \Berserk\View\Template(getcwd().'/templates')))->render($s,'player','player',[],'?game=9919');
 c1check(str_contains($html,'Изменение величины удара')&&!str_contains($html,'cmd=confirm_strike'),'main phase UI never offers premature attack ACK');

 $ip=new InstantProcessor($s,new Engine());$available=$ip->getInstants('player','combat','combat');$keys=array_column(array_column($available,'payload'),'key');
 c1check(in_array('krik',$keys,true),'Banshee offered for strike');c1check(in_array('flash',$keys,true)===($type!=='uchr'),'Glorm type restriction');
 if($type!=='uchr')c2cmd($s,'player','combat_instant_play',['card_id'=>4,'instant_key'=>'flash']);
 c2cmd($s,'player','combat_instant_play',['card_id'=>3,'instant_key'=>'krik']);
 c2drain($s,'plus:own');
 c1check($s->getCard(2)->hp===19,'main phases never apply attack before ACK');
 c1check($s->battle['single_roll']['computed_value']===2&&$s->getCard(3)->coins===1,'Banshee reduces computed value and gains one coin');
 c1check(array_column($s->battle['single_roll']['instant_summary'],'phase')===($type==='uchr'?['value']:['value','setter']),'phase order beats announcement LIFO');
 $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));dAck($s);
 c1check($s->getCard(2)->hp===($type==='uchr'?17:18),'value before setter and one damage application');
 c1check(($s->battle['strike']['wounds_received'][2]??null)===($type==='uchr'?2:1),'only current attack wounds tracked');
 c1check(!isset($s->battle['strike']['wounds_received'][4]),'Glorm self-wound excluded');
 c1check(Dice::remaining()===[6],'main phases never repeat dice');
}
foreach(['tap','magic','uchr'] as $type)foreach(['survive','lethal','zero'] as $case){
 $s=c1state($type,false);$s->getCard(3)->owner='player';$s->getCard(3)->prop=$dp['s1_184'];
 $s->getCard(4)->flags['damage_taken_this_strike']=50;
 if($case==='lethal')$s->getCard(2)->hp=2;
 $s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'running','remaining_seconds'=>180,'anchor_at'=>time(),'pause_reason'=>null];
 $_SESSION=['debug_roll'=>'4,6'];Dice::init();
 (new SingleRoll($s,new Engine()))->begin($s->getCard(1),$s->getCard(2),$type,['type'=>$type,'range'=>6,'value'=>$case==='zero'?0:3],$type==='uchr'?'uchr':'damage');
 IW::reconcile($s,time());dAck($s);
 if($case!=='survive'){
  c1check(($s->battle['strike']['combat_instant_window']??null)!=='wounds','no window for zero/dead donor');
  if($case==='lethal')c1check($s->getCard(2)->zone==='graveyard','death completed before checking donors');continue;
 }
 c1check(($s->battle['strike']['combat_instant_window']??null)==='wounds','late wounds after native result ACK');
 c2cmd($s,'player','combat_instant_play',['card_id'=>3,'instant_key'=>'raneniya']);
 for($i=0;$i<3&&empty($s->battle['pending_wound_transfer']);$i++)c2cmd($s,$s->battle['strike']['instant_priority'],'combat_instant_pass');
 c1check($s->battle['pending_wound_transfer']['donors']===[2=>3],'ledger excludes previous action wounds');
 c2cmd($s,'player','wt_amount',['amount'=>2]);c2cmd($s,'player','wt_target',['target_id'=>4]);c2cmd($s,'player','wt_target_amount',['amount'=>2]);
 c1check($s->getCard(2)->hp===18&&$s->getCard(4)->hp===17,'existing redistribution effect unchanged');
 c1check(($s->battle['strike']['state']??null)==='results','nonempty wounds result awaits ACK');
 $hp=$s->getCard(2)->hp;dAck($s);c1check($s->getCard(2)->hp===$hp&&empty($s->battle['strike']),'wounds ACK resumes once, no repeated damage');
}
$s=c1state('tap',false);foreach([3,4] as $id){$s->getCard($id)->owner='player';$s->getCard($id)->prop=$dp['s1_184'];}
$s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'running','remaining_seconds'=>180,'anchor_at'=>time(),'pause_reason'=>null];
$_SESSION=['debug_roll'=>'4'];Dice::init();
(new SingleRoll($s,new Engine()))->begin($s->getCard(1),$s->getCard(2),'tap',['type'=>'tap','value'=>3],'damage');IW::reconcile($s,time());dAck($s);
foreach([3,4] as $id)c2cmd($s,'player','combat_instant_play',['card_id'=>$id,'instant_key'=>'raneniya']);
for($i=0;$i<3&&empty($s->battle['pending_wound_transfer']);$i++)c2cmd($s,$s->battle['strike']['instant_priority'],'combat_instant_pass');
c1check($s->battle['pending_wound_transfer']['source_id']===4,'wounds LIFO');
c2cmd($s,'player','wt_amount',['amount'=>3]);c2cmd($s,'player','wt_target',['target_id'=>3]);c2cmd($s,'player','wt_target_amount',['amount'=>3]);
c1check(empty($s->battle['pending_wound_transfer']),'second Hermit cannot transfer old donor wounds');
c1check($s->getCard(2)->hp===19&&$s->battle['strike']['wounds_received'][2]===0,'preexisting wound remains with donor');
c1check($s->battle['strike']['wounds_received'][3]===3,'transferred current wounds retain provenance');dAck($s);
foreach(['plains','zero','lost_target','own_source'] as $case){
 $s=c1state('tap',false);$s->getCard(4)->prop=$dp['s1_20'];
 if($case==='own_source')$s->getCard(4)->owner='host';
 if($case==='plains')$s->getCard(2)->element='plains';
 $s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'running','remaining_seconds'=>180,'anchor_at'=>time(),'pause_reason'=>null];
 $_SESSION=['debug_roll'=>'4,6'];Dice::init();
 (new SingleRoll($s,new Engine()))->begin($s->getCard(1),$s->getCard(2),'tap',['type'=>'tap','value'=>$case==='zero'?0:3],'damage');
 if($case==='own_source'){c1check(empty($s->battle['single_roll'])&&$s->getCard(2)->hp===16,'Glorm cannot protect opposing squad');continue;}
 c2cmd($s,'player','combat_instant_play',['card_id'=>4,'instant_key'=>'flash']);c2drain($s,'plus:own');
 c1check($s->getCard(4)->hp===($case==='plains'?19:18),'Glorm original self-wound exception');
 if($case==='lost_target')$s->getCard(2)->zone='graveyard';
 dAck($s);
 c1check($s->getCard(2)->hp===($case==='zero'||$case==='lost_target'?19:18),'zero/target loss/steppe outcome');
 if($case==='lost_target')c1check(empty($s->battle['strike'])&&empty($s->battle['single_roll']),'lost numeric target finishes checkpoint safely');
}
// Ledger excludes independent instant wounds even during an ordinary strike.
$s=c1state('tap',false);$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'wounds_received'=>[]];
(new Engine())->applyDamage($s,$s->getCard(2),1,'damage_on_dice',$s->getCard(4));
c1check($s->battle['strike']['wounds_received']===[],'Mary wounds are not strike wounds');
(new Engine())->applyDamage($s,$s->getCard(2),2,'strike',$s->getCard(1));
c1check($s->battle['strike']['wounds_received']===[2=>2],'ordinary strike records only its own wounds');
$s=c1state('tap',false);$s->getCard(1)->closed=false;$s->getCard(3)->owner='player';$s->getCard(3)->prop=$dp['s1_157'];$s->getCard(4)->prop=$dp['s1_20'];
$_SESSION=['debug_roll'=>'6,1,4'];Dice::init();
$r=(new \Berserk\Core\StrikeResolver($s,new Engine()))->declare('host',new Command('strike',['card_id'=>1,'target_id'=>2]));c1check($r->success,'ordinary strike declaration');
c1check(($s->battle['strike']['combat_instant_window']??null)==='main','ordinary main window unchanged');
c2cmd($s,'player','combat_instant_play',['card_id'=>4,'instant_key'=>'flash']);c2cmd($s,'player','combat_instant_play',['card_id'=>3,'instant_key'=>'krik']);
for($i=0;$i<3&&($s->battle['strike']['state']??null)==='waiting_instant';$i++)c2cmd($s,$s->battle['strike']['instant_priority'],'combat_instant_pass');
c1check($s->getCard(2)->hp===18&&$s->getCard(3)->coins===1,'ordinary value then setter unchanged');
c1check(!isset($s->battle['strike']['wounds_received'][4]),'ordinary Glorm self-wound not a strike wound');
foreach(['strike','tap','magic','uchr','shot','throw','discharge'] as $type)foreach(['s1_20','s1_157','s1_184'] as $ukid){
 c1check(InstantProcessor::allowsBattleType($dp[$ukid]['instants'][0],['kind'=>$type])===in_array($type,$ukid==='s1_20'?['strike','tap','magic']:['strike','tap','magic','uchr'],true),'seven-type restriction matrix '.$ukid.'/'.$type);
}
foreach(['shot','throw','discharge'] as $type){
 $s=c1state($type,false);$s->getCard(3)->owner='player';$s->getCard(3)->prop=$dp['s1_184'];$s->getCard(4)->flags['damage_taken_this_strike']=50;
 $s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'running','remaining_seconds'=>180,'anchor_at'=>time(),'pause_reason'=>null];
 $_SESSION=['debug_roll'=>'4'];Dice::init();(new SingleRoll($s,new Engine()))->begin($s->getCard(1),$s->getCard(2),$type,['type'=>$type,'range'=>6,'value'=>3],'damage');IW::reconcile($s,time());dAck($s);
 c1check(empty($s->battle['strike'])&&empty($s->battle['pending_wound_transfer']),'Hermit excludes ranged attack '.$type);
}
echo "Stage D unit checks: $checks passed\n";
