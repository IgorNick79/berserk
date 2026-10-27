<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Routes existing workflows only. Resolver access/target checks remain authoritative. */
final class ExpiredCommandGuard
{
    public static function error(GameState $s, string $actor, Command $cmd, int $now): ?string
    {
        if($s->status!=='battle' || $s->winner!==null || !isset($s->battle['turn_timer'])) return null;
        $clock=TurnTimeoutEligibility::clock($s,$now);
        if($clock===null) return 'Некорректные данные таймера. Обновите состояние боя.';
        if(!$clock['expired']) return null;
        if($cmd->type==='resign') return null;
        if (in_array($cmd->type, ['cancel_instant_reservation', 'decline_instant_priority'], true)) return null;
        if($cmd->type==='end_turn') {
            return TurnTimeoutEligibility::check($s,$now)['allowed'] && $actor===$clock['owner'] ? null : 'Сначала завершите обязательные действия текущего хода.';
        }
        $wait=BattleWaitResolver::current($s);
        if(($wait['source']??null)==='turn_phase' && ($wait['reason']??null)==='invalid_phase') return 'Ожидается завершение текущего действия.';
        $choice=Choice\ChoiceRegistry::current($s);
        if($choice!==null) {
            return in_array($cmd->type,$choice->commandTypes(),true) ? null : 'Время хода истекло. Можно только завершить уже начатое действие.';
        }
        // Match the existing legacy panel/BattleWaitResolver priority: prophecy
        // can suspend a strike, so its commands must remain reachable first.
        if(!empty($s->battle['pending_prophecy'])) {
            return in_array($cmd->type,['close_prophecy','transform_seeker','grezy_continue','reorder_start',
                'reorder_card_up','reorder_card_down','summon_start','summon_cell','summon_creature','summon_cancel'],true)
                ? null : 'Время хода истекло. Можно только завершить уже начатое действие.';
        }
        $strike=$s->battle['strike']??null;
        if(is_array($strike) && $strike!==[]) {
            // Nested death decisions take priority even when strike.state is results.
            $commands=!empty($strike['pending_choice']) ? ['choose_death_target'] : match($strike['state']??null) {
                'waiting_defender'=>['choose_defender','choose_defender_option','choose_mage_redirect'],
                'waiting_redirect'=>['choose_redirect','choose_mage_redirect','choose_defender_option'],
                'waiting_instant'=>['combat_instant_play','combat_instant_pass'],
                'waiting_choice'=>['choose_strike_mode'],
                'waiting_auto_target'=>['choose_auto_target'],
                'waiting_push_choice'=>['choose_push_choice'],
                'waiting_close_or_damage'=>['choose_close_or_damage'],
                'waiting_ally_modifier'=>['choose_ally_modifier'],
                'results','waiting_push_ack'=>['confirm_strike'],
                default=>[],
            };
            return in_array($cmd->type,$commands,true) ? null : 'Время хода истекло. Можно только завершить уже начатое действие.';
        }
        if(!empty($s->battle['pending_dive'])) {
            return in_array($cmd->type,['choose_dive_cell','cancel_pending'],true) ? null : 'Время хода истекло. Можно только завершить уже начатое действие.';
        }
        // A compulsory attack is not a new optional action. Preserve the resolver's
        // choice among all adjacent closed enemy targets, rather than picking one.
        // Do not add a FIELD target restriction: declare() also accepts flying
        // targets. Its remaining zone/defense/access checks still run normally.
        if($cmd->type==='strike' && $actor===$clock['owner'] && ($s->battle['active']??null)===$actor
            && empty($s->battle['turn_phase']) && BattleWaitResolver::current($s)===null) {
            $card=$s->getCard((int)$cmd->get('card_id',0));$target=$s->getCard((int)$cmd->get('target_id',0));
            if($card && $card->owner===$actor && CardStats::getForcedStrikeTarget($s,$card)!==null
                && $target && CardStats::isForcedStrikeTarget($card,$target)) return null;
        }
        return 'Время хода истекло. Можно только завершить уже начатое действие.';
    }
}
