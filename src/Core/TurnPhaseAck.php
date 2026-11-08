<?php
declare(strict_types=1);

namespace Berserk\Core;

/** Shared contract for turn-phase result messages only. */
final class TurnPhaseAck
{
    public static function set(GameState $state, string $owner, mixed $description): void
    {
        if (!in_array($owner, ['host','player'], true)) throw new \LogicException('Invalid turn ACK owner');
        if (empty($description)) {
            $state->battle['turn_phase']['pending_ack'] = null;
            return;
        }
        $ack = is_array($description) ? $description : ['label'=>(string)$description];
        $ack['owner'] = $owner;
        $ack['ack_id'] = bin2hex(random_bytes(16));
        $state->battle['turn_phase']['pending_ack'] = $ack;
    }

    public static function valid(mixed $ack): bool
    {
        return is_array($ack) && in_array($ack['owner'] ?? null,['host','player'],true)
            && is_string($ack['ack_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D',$ack['ack_id'])===1;
    }
}
