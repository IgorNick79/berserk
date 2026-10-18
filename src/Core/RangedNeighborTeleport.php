<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Records wound-time facts; the existing post-combat queue owns scheduling. */
final class RangedNeighborTeleport
{
    public const PENDING = 'pending_ranged_neighbor_teleport';
    public function __construct(private GameState $state, private Engine $engine) {}

    public static function record(GameState $state, CardInstance $attacker, CardInstance $target, string $type, int $wounds): void
    {
        if ($wounds < 1 || !in_array($type, ['shot', 'throw', 'discharge'], true)
            || !in_array($target->type, ['creature', 'fly'], true) || empty($state->battle['strike'])) return;
        foreach ($state->cards as $card) {
            $config = $card->prop['on_ranged_neighbor_wounded'] ?? null;
            if (!is_array($config) || !in_array($type, $config['types'] ?? [], true)
                || empty($config['optional']) || empty($config['once_per_battle'])
                || ($config['destination'] ?? '') !== 'adjacent_attacker'
                || empty($config['actual_wounds']) || empty($config['exclude_self'])
                || $card->instanceId === $target->instanceId || !self::available($card)
                || !ZoneManager::areAdjacentFieldCards($card, $target)) continue;
            // One proposal per instance and combat, even with several wounded neighbours.
            foreach ($state->battle['strike']['coin_post_combat_effects'] ?? [] as $effect) {
                if (($effect['kind'] ?? '') === 'ranged_neighbor_teleport'
                    && ($effect['card_id'] ?? null) === $card->instanceId) continue 2;
            }
            (new CoinAbilities($state, new Engine()))->defer([
                'kind' => 'ranged_neighbor_teleport', 'owner' => $card->owner, 'card_id' => $card->instanceId,
                'attacker_id' => $attacker->instanceId, 'attacker_row' => $attacker->row,
                'attacker_col' => $attacker->col, 'attacker_zone' => $attacker->zone,
                'target_id' => $target->instanceId, 'target_row' => $target->row,
                'target_col' => $target->col, 'wounds' => $wounds, 'attack_type' => $type,
            ]);
        }
    }

    private static function available(CardInstance $card): bool
    {
        return $card->zone === CardInstance::ZONE_FIELD && !$card->dying && $card->hp > 0
            && empty($card->flags['ranged_neighbor_teleport_used']);
    }

    /** A moved/departed attacker invalidates the anchor; never guess a new position. */
    public function cells(array $context): array
    {
        $source = $this->state->getCard((int) $context['card_id']);
        $attacker = $this->state->getCard((int) $context['attacker_id']);
        if (!$source || !self::available($source) || $source->owner !== $context['owner']
            || !$attacker || $attacker->zone !== CardInstance::ZONE_FIELD || $attacker->dying || $attacker->hp <= 0
            || $context['attacker_zone'] !== CardInstance::ZONE_FIELD
            || $attacker->row !== $context['attacker_row'] || $attacker->col !== $context['attacker_col']) return [];
        $zone = new ZoneManager($this->state);
        $cells = [];
        for ($r = max(1, $attacker->row - 1); $r <= min(6, $attacker->row + 1); $r++) {
            for ($c = max(1, $attacker->col - 1); $c <= min(5, $attacker->col + 1); $c++) {
                if ($zone->isFieldOccupied($r, $c) || ZoneManager::hasBlockingMarker($this->state, "{$r}_{$c}")) continue;
                $cells[] = "{$r}_{$c}";
            }
        }
        return $cells;
    }

    public function open(array $effect): bool
    {
        if (!$this->cells($effect)) return false;
        $effect['stage'] = 'offer';
        $effect['origin_effect_id'] = $effect['id'];
        $this->state->battle[self::PENDING] = $effect;
        return true;
    }

    public function choose(string $player, Command $cmd): Result
    {
        $pending = $this->state->battle[self::PENDING] ?? null;
        if (!$pending || $pending['owner'] !== $player || $cmd->get('choice_id') !== $pending['id']) {
            return Result::error('Выбор недоступен или устарел');
        }
        $operation = $cmd->get('operation');
        if ($operation === 'cancel') {
            unset($this->state->battle[self::PENDING]);
            $this->state->bumpVersion();
            return Result::ok(['ranged_neighbor_teleport_cancelled']);
        }
        $cells = $this->cells($pending);
        if (!$cells) {
            unset($this->state->battle[self::PENDING]);
            $this->state->bumpVersion();
            return Result::ok(['ranged_neighbor_teleport_unavailable']);
        }
        if ($operation === 'start' && $pending['stage'] === 'offer') {
            $this->state->battle[self::PENDING]['stage'] = 'cell';
            // Distinct identity for the second stage rejects replayed offer commands.
            $this->state->battle[self::PENDING]['id'] = bin2hex(random_bytes(12));
            $this->state->bumpVersion();
            return Result::ok(['ranged_neighbor_teleport_cell']);
        }
        $cell = (string) $cmd->get('cell', '');
        if ($operation === 'select' && $pending['stage'] === 'cell' && in_array($cell, $cells, true)) {
            $this->state->battle[self::PENDING]['selected'] = $cell;
            $this->state->bumpVersion();
            return Result::ok(['ranged_neighbor_teleport_selected']);
        }
        if ($operation !== 'confirm' || $pending['stage'] !== 'cell' || ($pending['selected'] ?? null) !== $cell || !in_array($cell, $cells, true)) {
            return Result::error('Недопустимая клетка');
        }
        [$row, $col] = array_map('intval', explode('_', $cell));
        $card = $this->state->getCard((int) $pending['card_id']);
        $oldRow = $card->row;
        $oldCol = $card->col;
        $card->row = $row;
        $card->col = $col;
        $card->flags['moved_this_turn'] = true;
        $card->flags['ranged_neighbor_teleport_used'] = true;
        unset($this->state->battle[self::PENDING]);
        (new Movement\MovementEffectResolver($this->state, $this->engine))->applyForcedPositionChange(
            new Movement\MovementContext($card, $player, $oldRow, $oldCol, $row, $col, Movement\MovementContext::TYPE_FORCED_MOVE)
        );
        $this->state->battle['coin_post_combat_history'][$pending['origin_effect_id']]['teleported_to'] = $cell;
        $this->state->bumpVersion();
        return Result::ok(["ranged_neighbor_teleport:{$card->instanceId}:{$cell}"]);
    }
}
