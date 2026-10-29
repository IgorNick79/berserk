<?php
declare(strict_types=1);
namespace Berserk\Core;
/** Access to the existing address-owned death job continuation. No dispatch or event bus. */
final class DeathEffectContext {
 public static function operation(GameState $s):?array {if (($s->battle['alvalind_end']['stage']??'')==='resolving') return $s->battle['alvalind_end'];return $s->battle['alvalind_recoil']??$s->battle['abyss_movement']['current']??WormIncarnation::operation($s);}
 public static function &current(GameState $s):array {
  if(($s->battle['alvalind_end']['stage']??'')==='resolving')return $s->battle['alvalind_end'];
  if(isset($s->battle['alvalind_recoil']))return $s->battle['alvalind_recoil'];
  if(isset($s->battle['abyss_movement']['current']))return $s->battle['abyss_movement']['current'];
  return $s->battle['pending_incarnation']['worm_operation'];
 }
}
