<?php
declare(strict_types=1);
namespace Berserk\Core;
final class TempleWarrior {
 public static function rangedBlock(GameState $s,CardInstance $target,string $type):bool {
  $config=$target->effectiveProp()['ranged_block_roll']??null;
  if(!is_array($config)||!in_array($type,$config['types']??[],true))return false;
  $die=CardRoll::roll($s,$target);$blocked=in_array($die,$config['block_on']??[],true);
  $s->battle['strike']['temple_defense']=['card_id'=>$target->instanceId,'die'=>$die,'blocked'=>$blocked];
  if($blocked){$s->battle['strike']['damage']=0;$s->battle['strike']['damage_total']=0;$s->battle['strike']['defended']=true;}
  return $blocked;
 }
}
