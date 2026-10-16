<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Read-only timeout and completion checks; ownership stays in BattleWaitResolver. */
final class TurnTimeoutEligibility
{
    /** Null means absent or malformed metadata, never permission to auto-end. */
    public static function clock(GameState $state, int $now): ?array
    {
        if($now<1) return null;
        $t=TurnTimer::snapshot($state,$now);
        if($state->status==='battle' && $state->winner===null && ($t['pause_reason']??null)==='battle_finished') return null;
        return $t===null ? null : ['period_id'=>$t['period_id'],'owner'=>$t['owner'],'expired'=>$t['status']==='expired'];
    }

    public static function forcedAttack(GameState $state, string $owner): bool
    {
        foreach($state->cards as $card) {
            if($card->owner===$owner && $card->zone===CardInstance::ZONE_FIELD
                && CardStats::getForcedStrikeTarget($state,$card)!==null) return true;
        }
        return false;
    }

    /** Pure. Journal concurrency is supplied by the executor after its locking read. */
    public static function check(GameState $state, int $now, ?int $periodId=null, ?string $owner=null, bool $processing=false): array
    {
        $clock=self::clock($state,$now);
        $result=['allowed'=>false,'reason'=>'','period_id'=>$clock['period_id']??null,'owner'=>$clock['owner']??null];
        if($state->status!=='battle' || $state->winner!==null) $reason='battle_not_active';
        elseif($clock===null) $reason='invalid_or_missing_timer';
        elseif(($periodId!==null && $periodId!==$clock['period_id']) || ($owner!==null && $owner!==$clock['owner'])) $reason='period_changed';
        elseif(!$clock['expired']) $reason='not_expired';
        elseif(($state->battle['active']??null)!==$clock['owner']) $reason='active_owner_mismatch';
        elseif(!empty($state->battle['turn_phase'])) $reason='turn_phase_in_progress';
        elseif(!empty($state->battle['strike'])) $reason='combat_in_progress';
        elseif(!empty($state->battle['pending_prophecy']) || !empty($state->battle['pending_dive'])) $reason='legacy_pending';
        elseif(($wait=BattleWaitResolver::current($state))!==null) $reason='wait_'.$wait['kind'];
        elseif(Choice\ChoiceRegistry::current($state)!==null) $reason='active_choice';
        elseif(!empty($state->battle['informational_wait_pending']) || !empty($state->battle['informational_wait'])) $reason='orphan_informational_wait';
        elseif(self::forcedAttack($state,$clock['owner'])) $reason='forced_attack';
        elseif($processing) $reason='processing';
        else {$result['allowed']=true;$reason='eligible';}
        $result['reason']=$reason;return $result;
    }
}
