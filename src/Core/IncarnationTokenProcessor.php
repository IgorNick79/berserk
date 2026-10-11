<?php
// src/Core/IncarnationTokenProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Триггер "получил жетон инкарнации" из prop.on_incarnation_token.
 * Вызывается из processIncarnation() и addIncarnationToken() — после
 * инкремента значения, до сброса в 0 и до flags.incarnation_ready.
 */
final class IncarnationTokenProcessor
{
    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    public function onTokenGranted(CardInstance $card, int $newValue): void
    {
        $config = $card->prop['on_incarnation_token'] ?? null;
        if (!is_array($config)) return;

        $value = (int) ($config['value'] ?? 1);
        if ($value <= 0) return;

        // Все живые существа на поле, кроме источника
        $candidates = [];
        foreach ($this->state->cards as $c) {
            if ($c->instanceId === $card->instanceId) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;
            $candidates[] = $c->instanceId;
        }

        $T = count($candidates);
        $N = $newValue;

        if ($T <= 1) return;    // игра завершена / осталось 1 существо
        if ($T < $N) return;    // нельзя набрать N целей

        if ($T === $N) {
            $this->applyAuto($card, $candidates, $value);
            return;
        }

        // T > N — очередь pending
        if (!isset($this->state->battle['pending_incarnation_wound'])) {
            $this->state->battle['pending_incarnation_wound'] = [];
        }

        $this->state->battle['pending_incarnation_wound'][] = [
            'owner'       => $card->owner,
            'source_id'   => $card->instanceId,
            'source_ukid' => $card->ukid,
            'value'       => $value,
            'count'       => $N,
            'candidates'  => $candidates,
        ];
    }

    private function applyAuto(CardInstance $card, array $candidateIds, int $value): void
    {
        $hits = [];

        foreach ($candidateIds as $targetId) {
            $target = $this->state->getCard((int) $targetId);
            if (!$target) continue;
            if ($target->dying || $target->hp <= 0) continue;

            $hpBefore = $target->hp;
            $this->engine->applyDamage($this->state, $target, $value, 'impact', $card);
            $hits[] = [
                'target_id'   => $target->instanceId,
                'target_ukid' => $target->ukid,
                'damage'      => max(0, $hpBefore - $target->hp),
            ];
        }

        if (empty($hits)) return;

        $this->state->battle['any_death_messages'][] = [
            'source_id'   => $card->instanceId,
            'source_ukid' => $card->ukid,
            'type'        => 'incarnation_token_wound',
            'targets'     => $hits,
            'message'     => 'Могильная хватка: ранено ' . count($hits) . ' существ',
        ];
    }
}