<?php
declare(strict_types=1);

namespace Berserk\Core\Observation;

use Berserk\Core\{BattleWaitResolver, Command, ExpiredCommandGuard, GameState, InstantReservation, MatchPause};
use Berserk\Core\Choice\ChoiceRegistry;

/** Read-only proof for the narrow closing pass in an empty main window. */
final class CombatMainPassKnowledge
{
    public static function window(GameState $s, string $side, ?int $now = null): ?array
    {
        if ($s->status!=='battle' || $s->winner!==null || MatchPause::blocking($s)) return null;
        $wait=BattleWaitResolver::current($s);
        $strike=$s->battle['strike']??[];
        if (($wait['source']??null)!=='strike.waiting_instant' || $wait['kind']!=='decision'
            || $wait['required_players']!==[$side] || ChoiceRegistry::current($s)!==null
            || ($strike['state']??null)!=='waiting_instant' || ($strike['instant_phase']??null)!=='combat'
            || ($strike['combat_instant_window']??null)!=='main' || ($strike['instant_priority']??null)!==$side
            || ($strike['instant_stack']??null)!==[] || ($strike['instant_played']??null)!==[]
            || !empty($strike['instant_resolution'])
            || !empty($strike['pending_wounds_resolution']) || !empty($strike['instant_result_stage'])
            || !empty($strike['damage_applied']) || !empty($strike['continuation_frame'])
            || !empty($strike['friendly_fire']) || !empty($strike['matriarch_order'])
            || !empty($s->battle['matriarch_order']) || !in_array($strike['kind']??'strike',['','strike'],true)) return null;
        $participants=$strike['instant_participants']??[];
        $passed=$strike['instant_passed']??null;
        $other=$s->getOpponentKey($side);
        // First subset admits only a closing pass. Transfer and nonempty stacks remain outside scope.
        if (!is_array($participants) || count($participants)!==2 || !in_array($side,$participants,true)
            || !in_array($other,$participants,true) || $passed!==[$other]) return null;
        foreach ([$strike['attacker_id']??0, ($strike['defender_id']??0)?:($strike['target_id']??0)] as $id) {
            $card=$s->getCard((int)$id);
            if ($card===null || VisibilityPolicy::card($s,$card,$side)!=='known' || $card->hp<=0 || $card->dying) return null;
        }
        $attacker = $s->getCard((int)$strike['attacker_id']);
        if (!empty($attacker->flags['attack_series_active']) || !empty($attacker->effectiveProp()['attack_series'])) return null;
        foreach (['attack_dice','attack_mod','defend_dice','defend_mod'] as $key) {
            if (!is_int($strike[$key]??null)) return null;
        }
        if (CombatPowerKnowledge::window($s,$side)!==null
            || InstantReservation::blocks($s,'combat_instant_pass')
            || ExpiredCommandGuard::error($s,$side,new Command('combat_instant_pass',[]),$now ?? time())!==null) return null;
        return ['kind'=>'combat_main_pass','owner'=>$side,'can_pass'=>true];
    }
}
