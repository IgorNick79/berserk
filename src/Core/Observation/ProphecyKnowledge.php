<?php
declare(strict_types=1);

namespace Berserk\Core\Observation;

use Berserk\Core\{CardInstance, GameState, MatchPause};
use Berserk\Core\Choice\{ChoiceRegistry, TurnSubChoice};

/** Own start-phase prophecy source and ordinary close only. */
final class ProphecyKnowledge
{
    private static function context(GameState $state): ?array
    {
        if ($state->status !== 'battle' || $state->winner !== null || MatchPause::blocking($state)) return null;
        $phase = $state->battle['turn_phase'] ?? null;
        $sub = $phase['sub'] ?? null;
        $owner = $phase['active_key'] ?? null;
        if (($phase['phase'] ?? null) !== 'start' || ($phase['side'] ?? null) !== 'active'
            || !in_array($owner, ['host', 'player'], true) || ($state->battle['active'] ?? null) !== $owner
            || ($phase['passive_key'] ?? null) !== $state->getOpponentKey($owner)
            || !empty($state->battle['strike']) || !empty($phase['pending_ack']) || ($sub['parent_type'] ?? null) !== 'prophecy'
            || ($sub['can_close'] ?? null) !== false || !is_array($sub['remaining'] ?? null)
            || !array_is_list($sub['remaining']) || $sub['remaining'] === []) return null;
        $items = [];
        $sources = [];
        foreach ($sub['remaining'] as $item) {
            $id = $item['id'] ?? null;
            $source = $item['card_id'] ?? null;
            if (!is_string($id) || $id === '' || !is_int($source)
                || isset($items[$id]) || isset($sources[$source])) return null;
            $items[$id] = ['sub_id'=>$id, 'source_id'=>$source];
            $sources[$source] = true;
        }
        return [$owner, $sub['pending_id'] ?? null, $items];
    }

    private static function source(GameState $state, int $id, string $owner, bool $processed): ?CardInstance
    {
        $card = $state->getCard($id);
        $count = $card?->effectiveProp()['prophecy'] ?? null;
        if ($card === null || $card->owner !== $owner || $card->dying || $card->hp <= 0
            || !in_array($card->zone, [CardInstance::ZONE_FIELD, CardInstance::ZONE_FLYING], true)
            || VisibilityPolicy::card($state, $card, $owner) !== 'known'
            || !is_int($count) || $count <= 0
            || (!empty($card->flags['prophecy_done_this_turn'])) !== $processed) return null;
        return $card;
    }

    /** Called only at the resolver's prophecy priority boundary. */
    public static function closeOwner(GameState $state): ?string
    {
        return self::revealOwner($state, false);
    }

    /** A saved initial reveal with exactly the two voluntary alternatives. */
    public static function transformDeclineOwner(GameState $state): ?string
    {
        if ($state->persistenceVersion === null) return null;
        foreach ($state->battle as $key => $value) {
            if (!empty($value) && $key !== 'pending_prophecy'
                && (str_starts_with((string) $key, 'pending_') || in_array($key, [
                    'turn_instant_stack', 'turn_instant_result', 'informational_wait', 'informational_wait_pending',
                    'combat_continuation', 'coin_post_combat_queue', 'coin_post_combat_continuation', 'matriarch_order',
                ], true))) return null;
        }
        $reservation = $state->battle['instant_reservation'] ?? [];
        if (!is_array($reservation) || !empty($reservation['request'])
            || ($reservation['status'] ?? 'idle') !== 'idle') return null;
        return self::revealOwner($state, true);
    }

    private static function revealOwner(GameState $state, bool $transform): ?string
    {
        $context = self::context($state);
        if ($context === null || ChoiceRegistry::current($state) !== null) return null;
        [$owner, $pendingId, $items] = $context;
        $pending = $state->battle['pending_prophecy'] ?? null;
        if (!is_string($pendingId) || !isset($items[$pendingId]) || !is_array($pending)
            || ($pending['owner'] ?? null) !== $owner || ($pending['context'] ?? null) !== 'turn_start'
            || ($pending['source_id'] ?? null) !== $items[$pendingId]['source_id']
            || ($pending['reorder_mode'] ?? false) !== false
            || ($pending['summon_mode'] ?? false) !== false
            || isset($pending['summon_step']) || isset($pending['summon_card_id'])
            || isset($pending['reorder_source_id']) || !empty($pending['reorder_remaining'])) return null;
        if ($transform && (($pending['reorder_mode'] ?? null) !== false
            || array_diff(array_keys($pending), [
                'owner', 'source_id', 'source_ukid', 'shown_ukids', 'title', 'actions',
                'context', 'meta', 'original_ids', 'reorder_mode', 'summon_mode',
            ]) !== [])) return null;
        $count = $pending['meta']['count'] ?? null;
        if (!is_int($count) || $count < 1 || !is_array($pending['shown_ukids'] ?? null)
            || !is_array($pending['original_ids'] ?? null) || count($pending['shown_ukids']) !== $count
            || count($pending['original_ids']) !== $count) return null;
        $actions = $pending['actions'] ?? null;
        $commands = $transform ? ['transform_seeker', 'close_prophecy'] : ['close_prophecy'];
        if (!is_array($actions) || !array_is_list($actions) || count($actions) !== count($commands)) return null;
        foreach ($commands as $index => $command) {
            $action = $actions[$index];
            if (!is_array($action) || ($action['cmd'] ?? null) !== $command || ($action['params'] ?? []) !== []) return null;
        }
        if ($transform && (!array_is_list($pending['original_ids']) || !array_is_list($pending['shown_ukids'])
            || count(array_filter($pending['shown_ukids'], static fn($ukid): bool => is_string($ukid) && $ukid !== '')) !== $count)) return null;
        foreach ($pending['original_ids'] as $id) {
            if (!is_int($id) || !empty($state->getCard($id)?->flags['in_prophecy_pending'])) return null;
        }
        if ($transform && count(array_unique($pending['original_ids'])) !== $count) return null;
        $card = self::source($state, $items[$pendingId]['source_id'], $owner, true);
        $elite = $pending['meta']['all_elite'] ?? null;
        if ($card === null || ($pending['source_ukid'] ?? null) !== $card->ukid || !is_bool($elite)
            || ($elite && !empty($card->effectiveProp()['prophecy_transform'])) !== $transform) return null;
        if ($transform && $count > $card->effectiveProp()['prophecy']) return null;
        return $owner;
    }

    public static function choice(GameState $state, string $viewer, ?array $wait): ?array
    {
        if (($wait['kind'] ?? null) !== 'decision' || $wait['required_players'] !== [$viewer]) return null;
        if ($wait['source'] === 'pending_prophecy.close_only') {
            return ['kind'=>'prophecy_close', 'owner'=>$viewer, 'can_close'=>true];
        }
        if ($wait['source'] === 'pending_prophecy.transform_decline') {
            return ['kind'=>'prophecy_transform_decline', 'owner'=>$viewer, 'can_decline'=>true];
        }
        if ($wait['source'] !== 'turn_phase.sub' || !(ChoiceRegistry::current($state) instanceof TurnSubChoice)) return null;
        $context = self::context($state);
        if ($context === null) return null;
        [$owner, $pendingId, $items] = $context;
        if ($owner !== $viewer || $pendingId !== null || !empty($state->battle['pending_prophecy'])) return null;
        foreach ($items as $item) {
            if (self::source($state, $item['source_id'], $owner, false) === null) return null;
        }
        return ['kind'=>'prophecy_source', 'owner'=>$owner, 'options'=>array_values($items)];
    }
}
