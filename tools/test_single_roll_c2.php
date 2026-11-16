<?php
declare(strict_types=1);
define('C1_FIXTURES_ONLY',true);require __DIR__.'/test_single_roll_c1.php';
use Berserk\Core\{Dice,Engine,Command,SingleRoll,GameState,InformationalWaitManager as IW,InstantProcessor};
$c=require getcwd().'/config/db.php';$db=new mysqli($c['host'],$c['user'],$c['pass'],$c['name']);$db->set_charset('utf8mb4');$db->query('START TRANSACTION READ ONLY');
$props=[];foreach($db->query("SELECT ukid,prop FROM cards WHERE ukid IN ('s1_181','s1_174','s1_185','s1_96')")->fetch_all(MYSQLI_ASSOC) as $row)$props[$row['ukid']]=json_decode($row['prop'],true,512,JSON_THROW_ON_ERROR);$db->rollback();
function c2cmd(GameState $s,string $actor,string $cmd,array $payload=[]):void {$r=(new Engine())->apply($s,$actor,new Command($cmd,$payload));c1check($r->success,$cmd.' '.$actor.' '.($r->error??''));}
function c2drain(GameState $s,string $choice):void {
 for($i=0;$i<12;$i++){
  if(!empty($s->battle['pending_dice_choice'])){c2cmd($s,$s->battle['pending_dice_choice']['owner'],'choose_dice_choice',['choice'=>$choice]);continue;}
  $r=$s->battle['single_roll']??null;if(!$r||$r['stage']==='results')return;
  c2cmd($s,$r['instant_priority'],'combat_instant_pass');
 }throw new RuntimeException('stuck');
}
function c2ack(GameState &$s):void {
 $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));
 $token=IW::token($s);$before=$s->toArray();
 c1check(!(new Engine())->apply($s,'host',new Command('confirm_strike',['wait_id'=>'stale']))->success&&$s->toArray()===$before,'stale ACK inert');
 c2cmd($s,'host','confirm_strike',['wait_id'=>$token]);c1check(isset($s->battle['single_roll']),'one ACK keeps continuation');
 c1check(!(new Engine())->apply($s,'host',new Command('confirm_strike',['wait_id'=>$token]))->success,'duplicate ACK rejected');
 c2cmd($s,'player','confirm_strike',['wait_id'=>$token]);
 if (($s->battle['single_roll']['state']??null)==='waiting_instant') {
  c1check(empty($s->battle['single_roll']['instant_stack']),'reroll reopens with fresh stack');
  c1check($s->getCard(2)->hp===19,'reroll response precedes original damage');
  $remaining=Dice::remaining();
  $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));
  c2drain($s,'plus:own');
  c1check(Dice::remaining()===$remaining,'response passes never reroll');
 }
 $before=$s->toArray();c1check(!(new Engine())->apply($s,'player',new Command('confirm_strike',['wait_id'=>$token]))->success&&$s->toArray()===$before,'old ACK cannot acknowledge resumed action');
}
if(defined('C2_FIXTURES_ONLY'))return;
foreach(['tap','magic','shot','throw','discharge','uchr'] as $type)foreach(['plus:own','minus:own','reroll:any','mary_hit','mary_miss','luck_first','mary_first','luck_creates_one'] as $case){
 $s=c1state($type,false);$s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'running','remaining_seconds'=>180,'anchor_at'=>time(),'pause_reason'=>null];$s->getCard(3)->prop=$props['s1_181'];$s->getCard(4)->prop=$props['s1_174'];
 $_SESSION=['debug_roll'=>(in_array($case,['mary_hit','luck_first','mary_first','luck_creates_one'],true)?'1':'4').',6,2'];Dice::init();
 (new SingleRoll($s,new Engine()))->begin($s->getCard(1),$s->getCard(2),$type,['type'=>$type,'range'=>6,'strike'=>['weak'=>1,'medium'=>2,'strong'=>3]],$type==='uchr'?'uchr':'damage');
 if(in_array($case,['luck_first','luck_creates_one'],true)){
  c2cmd($s,'host','combat_instant_pass');c2cmd($s,'player','combat_instant_play',['card_id'=>4,'instant_key'=>'black_mark']);
  c2cmd($s,'player','combat_instant_pass');c2cmd($s,'host','combat_instant_play',['card_id'=>3,'instant_key'=>'udacha']);
 }elseif($case==='mary_first'){
  c2cmd($s,'host','combat_instant_play',['card_id'=>3,'instant_key'=>'udacha']);c2cmd($s,'host','combat_instant_pass');
  c2cmd($s,'player','combat_instant_play',['card_id'=>4,'instant_key'=>'black_mark']);
 }elseif(str_starts_with($case,'mary_')){
  c2cmd($s,'host','combat_instant_pass');c2cmd($s,'player','combat_instant_play',['card_id'=>4,'instant_key'=>'black_mark']);
 }else c2cmd($s,'host','combat_instant_play',['card_id'=>3,'instant_key'=>'udacha']);
 c2drain($s,$case==='luck_creates_one'?'minus:own':(str_contains($case,':')?$case:'plus:own'));
 c1check(($s->battle['single_roll']['stage']??null)==='results','nonempty result persists');
 c1check($s->getCard(2)->hp===19,'attack not applied before ACK');
 c1check($s->getCard(1)->hp===(in_array($case,['mary_hit','mary_first','luck_creates_one'],true)?17:19),'Mary uses LIFO current die');
 $die=$s->battle['single_roll']['attack_dice'];$remaining=Dice::remaining();c2ack($s);
 c1check(empty($s->battle['single_roll'])&&$s->battle['strike']['attack_dice']===$die,'tail receives changed die');
 c1check(Dice::remaining()===$remaining,'ACK never rerolls');
 c1check($s->getCard(1)->coins===2,'resources unchanged');
}
foreach(['shaksi','row_spell'] as $passive) foreach(['plus','reroll','mary'] as $passiveCase) {
 $s=c1state('shot',false);$s->getCard(1)->closed=false;$s->getCard(1)->move=$s->getCard(1)->moveMax=3;
 $s->getCard(3)->prop=$props['s1_181'];$s->getCard(4)->prop=$props['s1_174'];
 $s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'running','remaining_seconds'=>180,'anchor_at'=>time(),'pause_reason'=>null];
 $_SESSION=['debug_roll'=>($passiveCase==='mary'?'1':($passive==='shaksi'?'2':'3')).',4,1'];Dice::init();
 if($passive==='shaksi'){
  $s->getCard(1)->prop=$props['s1_185'];
  c2cmd($s,'host','jump',['card_id'=>1,'row'=>4,'col'=>4]);
 }else{
  $s->getCard(1)->prop=$props['s1_96'];$s->getCard(1)->hp=20;
  $action=$props['s1_96']['actions'][0];
  $s->getCard(1)->flags['row_spell_pending']=['row'=>3,'action'=>$action];
  (new \Berserk\Core\TurnPhaseProcessor($s,new Engine()))->beginStartPhase('host');
  if(!empty($s->battle['turn_phase']['sub']))c2cmd($s,'player','turn_sub_close');
 }
 c1check(($s->battle['single_roll']['kind']??null)===$passive,'passive opens real window '.$passive.' '.json_encode($s->battle));
 if($passiveCase==='mary'){
  c2cmd($s,'host','combat_instant_pass');c2cmd($s,'player','combat_instant_play',['card_id'=>4,'instant_key'=>'black_mark']);
 }else c2cmd($s,'host','combat_instant_play',['card_id'=>3,'instant_key'=>'udacha']);
 c2drain($s,$passiveCase==='reroll'?'reroll:any':'plus:own');
 c1check(empty($s->battle[$passive==='shaksi'?'pending_shaksi_roll':'pending_row_spell_pick']),'passive choice waits for ACK');
 c2ack($s);
 $key=$passive==='shaksi'?'pending_shaksi_roll':'pending_row_spell_pick';
 c1check(($s->battle[$key]['dice']??null)===($passiveCase==='mary'?1:($passiveCase==='reroll'?4:($passive==='shaksi'?3:4))),'passive tail uses changed die '.$passive);
 if($passive==='shaksi') {
  c1check($s->battle[$key]['effect']['type']===($passiveCase==='mary'?'damage':'heal'),'changed Shaksi branch');
  c2cmd($s,'host','choose_shaksi_roll',['target_id'=>$passiveCase==='mary'?2:1]);
 }else {
  c1check($s->battle[$key]['val']===($passiveCase==='mary'?1:2),'changed Tergala damage');
  c2cmd($s,'host','choose_row_spell_targets',['target_ids'=>[2]]);
 }
 c1check(empty($s->battle[$key])&&empty($s->battle['single_roll']),'passive checkpoint consumed once');
 c1check(Dice::remaining()===($passiveCase==='reroll'?[1]:[4,1]),'passive never repeats primary roll');
}
$s=c1state('shot',false);$s->getCard(3)->prop=$props['s1_181'];$s->getCard(1)->prop['next_six_on_enemy_death_or_ally_incarnation']=true;
$s->getCard(1)->flags[\Berserk\Core\CardRoll::READY]=true;
$_SESSION=['debug_roll'=>'2,3'];Dice::init();
(new SingleRoll($s,new Engine()))->begin($s->getCard(1),$s->getCard(2),'shot',['type'=>'shot','range'=>6,'strike'=>['weak'=>1,'medium'=>2,'strong'=>3]],'damage');
c1check($s->battle['single_roll']['original_dice']===6,'prepared six is the original actual die');
$before=$s->toArray();$queue=Dice::remaining();
c1check(!(new SingleRoll($s,new Engine()))->begin($s->getCard(1),$s->getCard(2),'shot',[],'damage')->success&&$s->toArray()===$before&&Dice::remaining()===$queue,'nested independent roll rejected without overwriting parent');
c2cmd($s,'host','combat_instant_play',['card_id'=>3,'instant_key'=>'udacha']);
c2cmd($s,'host','combat_instant_pass');
if(empty($s->battle['pending_dice_choice']))c2cmd($s,$s->battle['single_roll']['instant_priority'],'combat_instant_pass');
$s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));
$spec=(new \Berserk\Core\Choice\DiceChoiceChoice())->spec($s,'host',[],'?game=9919','host');
c1check(count($spec->buttons)===4,'F5 single-die UI has two operations, reroll and cancel');
c1check(!(new Engine())->apply($s,'host',new Command('choose_dice_choice',['choice'=>'plus:enemy']))->success,'forged nonexistent die rejected');
c2cmd($s,'host','choose_dice_choice',['choice'=>'minus:own']);
c1check($s->battle['single_roll']['attack_dice']===5&&Dice::remaining()===[2,3],'prepared six modified without repeated primary roll');
foreach(['reroll','mary'] as $normalCase){
 $s=c1state('tap',false);$s->getCard(1)->closed=false;$s->getCard(3)->prop=$props['s1_181'];$s->getCard(4)->prop=$props['s1_174'];$s->getCard(4)->owner='host';
 $_SESSION=['debug_roll'=>'2,1,5,2,6'];Dice::init();
 $r=(new \Berserk\Core\StrikeResolver($s,new Engine()))->declare('host',new Command('strike',['card_id'=>1,'target_id'=>2]));c1check($r->success,'ordinary combat declaration');
 c1check(($s->battle['strike']['combat_instant_window']??null)==='dice','ordinary native dice window');
 c2cmd($s,'host','combat_instant_play',['card_id'=>$normalCase==='reroll'?3:4,'instant_key'=>$normalCase==='reroll'?'udacha':'black_mark']);
 c2cmd($s,'host','combat_instant_pass');c2cmd($s,'player','combat_instant_pass');
 if($normalCase==='reroll'){
  c2cmd($s,'host','choose_dice_choice',['choice'=>'reroll:any']);
  c1check($s->battle['strike']['attack_dice']===5&&$s->battle['strike']['defend_dice']===2&&Dice::remaining()===[6],'ordinary reroll still consumes both real dice');
 }else c1check($s->getCard(2)->hp===17,'ordinary Mary still checks opposing defender die');
 c1check(empty($s->battle['single_roll'])&&$s->battle['strike']['instant_result_stage']==='dice','ordinary ACK order unchanged');
}
foreach(['modifier','lethal'] as $edge){
 $s=c1state('shot',false);$s->getCard(4)->prop=$props['s1_174'];
 $s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'running','remaining_seconds'=>180,'anchor_at'=>time(),'pause_reason'=>null];
 if($edge==='lethal')$s->getCard(1)->hp=2;
 $_SESSION=['debug_roll'=>($edge==='modifier'?'2':'1').',6'];Dice::init();
 (new SingleRoll($s,new Engine()))->begin($s->getCard(1),$s->getCard(2),'shot',['type'=>'shot','range'=>6,'value'=>2],'damage');
 if($edge==='modifier')$s->battle['single_roll']['attack_mod']=-1;
 c2cmd($s,'player','combat_instant_play',['card_id'=>4,'instant_key'=>'black_mark']);c2drain($s,'plus:own');
 c1check($s->getCard(1)->hp===($edge==='modifier'?17:0),'Mary modifier/lethal result');
 c2ack($s);
 if($edge==='lethal')c1check($s->getCard(2)->hp===19&&empty($s->battle['single_roll']),'dead roller never applies original attack');
}
echo "C2 real-card unit checks: $checks passed\n";
