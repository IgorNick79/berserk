<?php
// src/Core/DamageResolver.php

declare(strict_types=1);

namespace Berserk\Core;

final class DamageResolver
{
    public function __construct(
        private GameState $state,
        private ?\Closure $syncCoinBonus = null,
    ) {}

    public function applyDamage(
        CardInstance $target,
        int $val,
        string $actionType = 'strike',
        ?CardInstance $attacker = null
    ): void {
        if ($val <= 0) return;

        if ($actionType === 'answer' && !empty($target->prop['block_strike_answer'])) {
            if (!empty($this->state->battle['strike'])) {
                $this->state->battle['strike']['answer_blocked'][] = [
                    'target_id' => $target->instanceId,
                    'attacker_id' => $attacker?->instanceId,
                ];
            }
            return;
        }

        foreach ($target->modifiers as $m) {
            if (($m['stat'] ?? '') === 'shield_light') {
                $magicTypes = [
                    'magic', 'cast', 'discharge', 'poison',
                    'damage_on_dice', 'self_wound', 'transfer_wounds',
                    'wound_transfer', 'whip', 'blood_tap',
                ];
                if (!in_array($actionType, $magicTypes, true)) {
                    return;
                }
                break;
            }
        }

        $armorIgnores = [
            'cast', 'magic', 'discharge', 'poison', 'heal',
            'damage_on_dice', 'self_wound', 'transfer_wounds',
            'wound_transfer', 'whip', 'blood_tap',
        ];
        if (!in_array($actionType, $armorIgnores, true)) {
            if ($target->armor > 0) {
                if ($val <= $target->armor) {
                    $target->armor -= $val;
                    $val = 0;
                } else {
                    $val -= $target->armor;
                    $target->armor = 0;
                }
            }
        }

        if ($val <= 0) return;

        $hpBefore = $target->hp;
        $target->hp -= $val;

        $realDamage = $hpBefore - $target->hp;
        if ($realDamage > 0) {
            $target->flags['damage_taken_this_strike'] =
                ((int) ($target->flags['damage_taken_this_strike'] ?? 0)) + $realDamage;
        }
        $target->flags['damage_taken_this_turn'] =
            ((int) ($target->flags['damage_taken_this_turn'] ?? 0)) + $realDamage;

        if ($attacker
            && $attacker->instanceId !== $target->instanceId
            && in_array($actionType, ['shot', 'throw', 'uchr'], true)) {
            $target->flags['ranged_hits_this_turn'] =
                ((int) ($target->flags['ranged_hits_this_turn'] ?? 0)) + 1;
        }

        $vampireTypes = ['strike', 'tap', 'magic', 'execute'];
        if ($attacker
            && !empty($attacker->prop['vampire'])
            && in_array($actionType, $vampireTypes, true)
            && $attacker->instanceId !== $target->instanceId
            && $attacker->hp > 0) {

            $offset = (int) ($attacker->prop['vampire_offset'] ?? 0);
            $healAmount = $val + $offset;
            if ($healAmount < 0) $healAmount = 0;

            $maxHp = (int) ($attacker->prop['hp_max_override'] ?? $attacker->hpMax);
            $heal = min($healAmount, $maxHp - $attacker->hp);

            if ($heal > 0) {
                $attacker->hp += $heal;

                if (!empty($this->state->battle['strike'])) {
                    $this->state->battle['strike']['vampire_heal'][] = [
                        'instance_id' => $attacker->instanceId,
                        'ukid'        => $attacker->ukid,
                        'heal'        => $heal,
                    ];
                }
            }
        }

        if ($attacker
            && $attacker->type === 'fly'
            && isset($target->markers['hunt'])) {

            $huntBonus = (int) ($target->markers['hunt']['bonus'] ?? 2);
            unset($target->markers['hunt']);

            if (!empty($this->state->battle['strike'])) {
                $this->state->battle['strike']['hunt_trigger'][] = [
                    'target_id'   => $target->instanceId,
                    'attacker_id' => $attacker->instanceId,
                    'bonus'       => $huntBonus,
                ];
            }

            $target->hp -= $huntBonus;
        }

        if ($target->hp <= 0) {
            $this->resolveDeath($target, $actionType, $attacker);
        }

        $this->checkGameOver();
    }

    public function forceDeath(CardInstance $target, string $cause, ?CardInstance $source = null): void
    {
        $this->resolveDeath($target, $cause, $source);
        $this->checkGameOver();
    }

    private function resolveDeath(CardInstance $target, string $cause, ?CardInstance $source = null): void
    {
        if ($target->dying) return;
        if ($target->zone !== CardInstance::ZONE_FIELD
            && $target->zone !== CardInstance::ZONE_FLYING) {
            return;
        }

        $target->hp = 0;
        $target->dying = true;

        (new ValhallaProcessor($this->state, damage: $this))->markPending($target, $cause);

        $this->refreshArmor();
        $this->clearRootedBySource($target->instanceId);

        if ($source
            && !empty($source->prop['deadeat'])
            && $this->shouldTriggerDeadeat($cause)
            && $source->hp > 0) {

            if (!empty($this->state->battle['strike'])) {
                $this->state->battle['strike']['deadeat_queue'][] = [
                    'instance_id' => $source->instanceId,
                ];
            } else {
                if (!empty($source->prop['deadeat_hp_bonus'])) {
                    $source->hpMax += (int) $source->prop['deadeat_hp_bonus'];
                }
                $source->hp = $source->hpMax;
            }
        }

        $this->triggerLineDeathNextStrikeBonus($target);
        $this->triggerOnAnyDeath($target, $cause);
        if ($this->shouldTriggerOnDeath($cause)) {
            $this->triggerOnDeath($target);
        }

        if (empty($this->state->battle['strike'])) {
            (new ZoneManager($this->state))->toGraveyard($target);
        }
    }

    private function shouldTriggerDeadeat(string $cause): bool
    {
        return CardStats::isMeleeAction($cause);
    }

    private function shouldTriggerOnDeath(string $cause): bool
    {
        return in_array($cause,
            ['strike', 'uchr', 'shot', 'throw', 'tap', 'discharge', 'answer', 'execute', 'self_destroy', 'destroy'],
            true
        );
    }

    private function triggerLineDeathNextStrikeBonus(CardInstance $died): void
    {
        if (!CardStats::hasLine($died)) return;

        $activeKey = $this->state->battle['active'] ?? null;
        if ($activeKey === null || $activeKey === $died->owner) return;

        $lineGroup = CardStats::getLineGroup($this->state, $died);
        if (count($lineGroup) <= 1) return;

        $recipientIds = [];
        foreach ($lineGroup as $participant) {
            if ($participant->instanceId === $died->instanceId) continue;
            if ($participant->dying || $participant->hp <= 0) continue;
            $recipientIds[] = $participant->instanceId;
        }
        if (empty($recipientIds)) return;

        foreach ($this->state->cards as $source) {
            if ($source->owner !== $died->owner) continue;
            if ($source->zone !== CardInstance::ZONE_FIELD
                && $source->zone !== CardInstance::ZONE_FLYING) continue;
            if ($source->instanceId !== $died->instanceId && $source->dying) continue;

            $config = $source->prop['line_death_next_strike_bonus'] ?? null;
            if (!is_array($config)) continue;

            $value = (int) ($config['value'] ?? 0);
            if ($value <= 0) continue;

            foreach ($recipientIds as $targetId) {
                $target = $this->state->getCard($targetId);
                if (!$target) continue;

                $target->modifiers[] = [
                    'stat' => 'next_strike_bonus',
                    'value' => $value,
                    'source' => $source->instanceId,
                ];

                if (!empty($this->state->battle['strike'])) {
                    $this->state->battle['strike']['line_death_next_strike_bonus'][] = [
                        'source_id' => $source->instanceId,
                        'died_id' => $died->instanceId,
                        'target_id' => $target->instanceId,
                        'value' => $value,
                    ];
                }
            }
        }
    }

    public function checkGameOver(): void
    {
        if ($this->state->winner !== null) return;

        foreach (['host', 'player'] as $key) {
            if (!$this->hasCreaturesOnField($key)) {
                $this->state->winner = $this->state->getOpponentKey($key);
                $this->state->status = 'game_over';
                return;
            }
        }
    }

    private function hasCreaturesOnField(string $playerKey): bool
    {
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $playerKey) continue;
            if ($card->dying) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->type !== 'creature' && $card->type !== 'fly') continue;
            return true;
        }
        return false;
    }

    public function refreshArmor(): void
    {
        foreach ($this->state->cards as $card) {
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;

            $newMax = CardStats::computeArmor($this->state, $card);

            if ($newMax !== $card->armorMax) {
                $card->armorMax = $newMax;
                $card->armor    = $newMax;
            }
        }
    }

    public function triggerOnDeath(CardInstance $died): void
    {
        if (empty($died->prop['on_death'])) return;

        foreach ($died->prop['on_death'] as $effect) {
            $this->applyDeathEffect($died, $effect);
        }
    }

    private function applyDeathEffect(CardInstance $died, array $effect): void
    {
        $targetType = $effect['target'] ?? 'near';

        if ($targetType === 'choice') {
            $candidates = $this->findDeathTargets($died, $effect);
            if (empty($candidates)) return;

            $this->state->battle['strike']['pending_choice'] = [
                'source'     => 'on_death',
                'died_ukid'  => $died->ukid,
                'died_id'    => $died->instanceId,
                'effect'     => $effect,
                'candidates' => array_map(fn ($c) => $c->instanceId, $candidates),
            ];
            return;
        }

        $type  = $effect['type'] ?? '';
        $value = (int) ($effect['value'] ?? 0);

        $targets = $this->findDeathTargets($died, $effect);
        if (empty($targets)) return;

        $applied = [];

        foreach ($targets as $target) {
            $hpBefore = $target->hp;

            switch ($type) {
                case 'tap':
                case 'damage':
                    $this->applyDamage($target, $value, 'tap');
                    break;
                case 'heal':
                    $target->hp += $value;
                    if ($target->hp > $target->hpMax) $target->hp = $target->hpMax;
                    break;
                case 'poison':
                    $this->applyPoison($target, $value, $died->owner);
                    break;
            }

            $applied[] = [
                'target_id' => $target->instanceId,
                'damage'    => max(0, $hpBefore - $target->hp),
                'heal'      => max(0, $target->hp - $hpBefore),
            ];
        }

        if (!empty($this->state->battle['strike'])) {
            $this->state->battle['strike']['death_triggers'][] = [
                'died_ukid' => $died->ukid,
                'died_id'   => $died->instanceId,
                'effect'    => $type,
                'value'     => $value,
                'targets'   => $applied,
            ];
        }
    }

    private function findDeathTargets(CardInstance $died, array $effect): array
    {
        $targetType = $effect['target'] ?? 'near';
        $filter     = $effect['filter'] ?? null;

        $result = [];

        foreach ($this->state->cards as $card) {
            if ($card->instanceId === $died->instanceId) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->owner === $died->owner) continue;

            if ($filter && !$this->matchFilter($card, $filter)) continue;

            if ($targetType === 'near') {
                if ($died->zone !== CardInstance::ZONE_FIELD) continue;
                if ($card->zone !== CardInstance::ZONE_FIELD) continue;

                $dr = abs($card->row - $died->row);
                $dc = abs($card->col - $died->col);
                if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;
            }

            $result[] = $card;
        }

        return $result;
    }

    private function matchFilter(CardInstance $card, string $filter): bool
    {
        return match ($filter) {
            'enemy_creature_not_flying' => $card->type === 'creature',
            'own_creature'              => true,
            'own_yordling'              => $card->class === 'Йордлинг',
            default                     => true,
        };
    }

    public function chooseDeathTarget(string $playerKey, Command $cmd): Result
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || empty($strike['pending_choice'])) {
            return Result::error('Нет ожидающего выбора');
        }

        $pc = $strike['pending_choice'];
        $candidates = $pc['candidates'] ?? [];

        $died = $this->state->getCard($pc['died_id']);
        if (!$died || $playerKey !== $died->owner) {
            return Result::error('Не ваш выбор');
        }

        $targetId = (int) $cmd->get('target_id', 0);
        if (!in_array($targetId, $candidates, true)) {
            return Result::error('Неверная цель');
        }

        $target = $this->state->getCard($targetId);
        $effect = $pc['effect'];
        $value  = (int) ($effect['value'] ?? 0);
        $type   = $effect['type'] ?? '';

        $hpBefore = $target->hp;
        switch ($type) {
            case 'tap':
            case 'damage':
                $this->applyDamage($target, $value, 'tap');
                break;
            case 'heal':
                $target->hp += $value;
                if ($target->hp > $target->hpMax) $target->hp = $target->hpMax;
                break;
            case 'poison':
                $this->applyPoison($target, $value, $died->owner);
                break;
        }

        $this->state->battle['strike']['death_triggers'][] = [
            'died_ukid' => $pc['died_ukid'],
            'died_id'   => $pc['died_id'],
            'effect'    => $type,
            'value'     => $value,
            'targets'   => [[
                'target_id' => $target->instanceId,
                'damage'    => max(0, $hpBefore - $target->hp),
                'heal'      => max(0, $target->hp - $hpBefore),
            ]],
        ];

        unset($this->state->battle['strike']['pending_choice']);

        $this->state->bumpVersion();
        return Result::ok(["death_choice:{$targetId}"]);
    }

    public function flushDeadeatQueue(): void
    {
        if (empty($this->state->battle['strike']['deadeat_queue'])) return;

        $queue = $this->state->battle['strike']['deadeat_queue'];

        foreach ($queue as $item) {
            $card = $this->state->getCard($item['instance_id']);
            if (!$card) continue;
            if ($card->hp <= 0) continue;

            if (!empty($card->prop['deadeat_hp_bonus'])) {
                $card->hpMax += (int) $card->prop['deadeat_hp_bonus'];
            }

            $hpBefore = $card->hp;
            $card->hp = $card->hpMax;

            if ($card->hp > $hpBefore) {
                $this->state->battle['strike']['deadeat'][] = [
                    'instance_id' => $card->instanceId,
                    'ukid'        => $card->ukid,
                    'heal'        => $card->hp - $hpBefore,
                ];
            }
        }
        unset($this->state->battle['strike']['deadeat_queue']);
    }

    public function applyAnswer(
        CardInstance $defender,
        CardInstance $attacker,
        string $actionType
    ): void {
        $answer = $defender->prop['answer'] ?? null;
        if (!$answer || !is_array($answer)) return;

        if ($defender->hp <= 0) return;

        $types = $answer['types'] ?? null;
        if ($types !== null && !in_array($actionType, $types, true)) return;

        if (!empty($answer['marker']['type'])) {
            $this->applyMarker($attacker, $answer['marker'], $defender->owner);
        }

        $val = (int) ($answer['value'] ?? 0);
        if ($val > 0) {
            $this->applyDamage($attacker, $val, 'answer');

            if (!empty($this->state->battle['strike'])) {
                $this->state->battle['strike']['answer_damage'][] = [
                    'defender_id' => $defender->instanceId,
                    'attacker_id' => $attacker->instanceId,
                    'value'       => $val,
                ];
            }
        }
    }

    public function clearRootedBySource(int $sourceId): void
    {
        foreach ($this->state->cards as $card) {
            if (!isset($card->markers['rooted'])) continue;

            $sources = $card->markers['rooted']['sources'] ?? [];
            $sources = array_values(array_filter($sources, fn($id) => $id !== $sourceId));

            if (empty($sources)) {
                unset($card->markers['rooted']);
            } else {
                $card->markers['rooted']['sources'] = $sources;
            }
        }
    }

    public function triggerOnAnyDeath(CardInstance $died, string $cause = 'any'): void
    {
        $poisonValue = (int) ($died->markers['poison']['value'] ?? 0);

        foreach ($this->state->cards as $seeder) {
            if ($seeder->zone !== CardInstance::ZONE_FIELD
                && $seeder->zone !== CardInstance::ZONE_FLYING) continue;
            if ($seeder->dying) continue;
            if ($seeder->instanceId === $died->instanceId) continue;

            $config = $seeder->prop['on_any_death'] ?? null;
            if (!$config || !is_array($config)) continue;

            $sideFilter = $config['side'] ?? 'enemy';
            if ($sideFilter === 'enemy' && $seeder->owner === $died->owner) continue;

            $configCause = $config['cause'] ?? 'any';
            if ($configCause === 'poison' && $cause !== 'poison') continue;
            if ($configCause !== 'any' && $configCause !== 'poison' && $configCause !== $cause) continue;

            if (!empty($seeder->flags['any_death_used_this_turn'])
                && !empty($config['once_per_turn'])) continue;

            $effect = $config['effect'] ?? null;
            if ($effect === 'get_coin') {
                $value = (int) ($config['value'] ?? 1);

                $filter = $config['filter'] ?? 'any';
                if ($filter === 'not_flying' && $died->type === 'fly') continue;

                $max = (int) ($seeder->prop['coins']['max_value'] ?? 0);
                $before = $seeder->coins;

                $seeder->coins += $value;
                if ($max > 0 && $seeder->coins > $max) $seeder->coins = $max;

                if ($seeder->coins > $before) {
                    $this->syncCoinBonusViaOwner($seeder);
                }

                if (!empty($config['once_per_turn'])) {
                    $seeder->flags['any_death_used_this_turn'] = true;
                }
                continue;
            }

            $candidates = [];
            foreach ($this->state->cards as $t) {
                if ($t->zone !== CardInstance::ZONE_FIELD) continue;
                if ($t->instanceId === $died->instanceId) continue;
                if ($t->dying) continue;
                // if ($t->owner === $seeder->owner) continue;

                $dr = abs($t->row - $died->row);
                $dc = abs($t->col - $died->col);
                if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;

                $filter = $config['filter'] ?? 'any';
                if ($filter === 'not_flying' && $t->type === 'fly') continue;

                $candidates[] = $t->instanceId;
            }

            if (empty($candidates)) continue;

            if (!isset($this->state->battle['pending_any_death'])) {
                $this->state->battle['pending_any_death'] = [];
            }

            $this->state->battle['pending_any_death'][] = [
                'source_id'    => $seeder->instanceId,
                'died_id'      => $died->instanceId,
                'died_ukid'    => $died->ukid,
                'candidates'   => $candidates,
                'poison_value' => $poisonValue,
            ];

            if (!empty($config['once_per_turn'])) {
                $seeder->flags['any_death_used_this_turn'] = true;
            }
        }
    }

    public function chooseAnyDeathTarget(string $playerKey, Command $cmd): Result
    {
        $queue = $this->state->battle['pending_any_death'] ?? [];
        if (empty($queue)) {
            return Result::error('Нет ожидающего выбора');
        }

        $item = $queue[0];
        $sourceCard = $this->state->getCard($item['source_id']);
        if (!$sourceCard || $sourceCard->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $targetId = (int) $cmd->get('target_id', 0);

        if ($targetId === 0) {
            array_shift($this->state->battle['pending_any_death']);
            if (empty($this->state->battle['pending_any_death'])
                && !empty($this->state->battle['turn_phase'])) {
                (new TurnPhaseProcessor($this->state, new Engine()))->resume();
            }
            $this->state->bumpVersion();
            return Result::ok(['any_death_skipped']);
        }

        if (!in_array($targetId, $item['candidates'], true)) {
            return Result::error('Неверная цель');
        }

        $target = $this->state->getCard($targetId);
        if (!$target) {
            return Result::error('Цель не найдена');
        }

        $poisonValue = (int) $item['poison_value'];
        $this->applyPoison($target, $poisonValue, $sourceCard->owner);

        array_shift($this->state->battle['pending_any_death']);

        if (empty($this->state->battle['pending_any_death'])
            && !empty($this->state->battle['turn_phase'])) {
            (new TurnPhaseProcessor($this->state, new Engine()))->resume();
        }

        $this->state->bumpVersion();
        return Result::ok(["any_death_target:{$targetId}"]);
    }

    private function applyPoison(CardInstance $target, int $value, string $sourceKey): void
    {
        if ($value <= 0) return;
        if (!empty($target->prop['zoo'])) return;

        if (!isset($target->markers['poison'])) {
            $target->markers['poison'] = [
                'value'  => $value,
                'source' => $sourceKey,
                'timing' => 'permanent',
            ];
        } elseif ($value > $target->markers['poison']['value']) {
            $target->markers['poison']['value'] = $value;
        }
    }

    private function applyMarker(CardInstance $target, array $marker, string $sourceKey): void
    {
        $type = $marker['type'];
        $timing = $marker['timing'] ?? 'source_turn';

        if (!isset($target->markers[$type])) {
            $target->markers[$type] = [
                'value'  => 1,
                'expire' => $marker['expire'] ?? null,
                'source' => $sourceKey,
                'timing' => $timing,
            ];

            if (!empty($marker['skip_first_tick'])) {
                $target->markers[$type]['skip_first_tick'] = true;
            }
        } else {
            $target->markers[$type]['value']++;
            if (isset($marker['expire'])) {
                $target->markers[$type]['expire'] = $marker['expire'];
            }
            if (!empty($marker['skip_first_tick'])) {
                $target->markers[$type]['skip_first_tick'] = true;
            }
        }
    }

    private function syncCoinBonusViaOwner(CardInstance $card): void
    {
        if (empty($card->prop['coin_strike_bonus'])) return;

        if ($this->syncCoinBonus === null) {
            throw new \LogicException('Coin bonus synchronization is required for this damage lifecycle path');
        }

        ($this->syncCoinBonus)($card);
    }
}
