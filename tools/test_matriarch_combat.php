<?php
declare(strict_types=1);
require __DIR__.'/test_combat_continuation.php';
use Berserk\Core\{Command,Dice,Engine,StrikeResolver,CardStats};
foreach([false,true] as $closed){
 $s=cs();$a=$s->getCard(1);$d=$s->getCard(2);$a->closed=$closed;$d->closed=true;$a->hp=20;
 $a->flags=['attacks_used_this_turn'=>1,'first_attack_target_id'=>99,'attack_series_active'=>true,'attack_series_hit_ids'=>[99]];
 $a->prop=['attacks_per_turn'=>2,'on_strike_self_heal'=>['value'=>2],'ability'=>[['value'=>10,'only'=>['strike'],'closed'=>true]]];
 $_SESSION['debug_roll']='6,4';unset($_SESSION['debug_roll_state']);Dice::init();$saved=$a->flags;
 $r=(new StrikeResolver($s,new Engine()))->declareForcedAdjacent($a,$d,false,['kind'=>'test','step'=>1]);ca($r->success,'order combat');
 ca($s->battle['strike']['defend_dice']===4,'closed defender rolls');
 ca(CardStats::getAbilityBonus($s,$d,$a,'strike','weak')===0,'attacker effectively open');
 ca($s->battle['strike']['state']==='waiting_choice','real two-sided strike');
 cmd($s,'host','choose_strike_mode',['mode'=>'normal']);
 ca($s->getCard(2)->hp===28,'closed-target bonus not applied');
 ca($a->hp===21,'independent self heal and answer');
 foreach($saved as $k=>$v)ca($a->flags[$k]===$v,'resource unchanged '.$k);
 ca($a->closed===$closed&&$d->closed,'no artificial physical open or tap');
 finish($s);ca(empty($s->battle['pending_attack_series_choice']),'no ordinary series progression');
 ca(CardStats::combatClosed($s,$s->getCard(1))===$closed,'context removed');
}
echo "order combat: closed states, response, abilities, healing, resource preservation passed\n";
