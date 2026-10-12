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

        if ($actionType === 'poison' && $realDamage > 0
            && $target->hp > 0 && !$target->dying) {
            $this->triggerNokamiOnPoison($target);
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
        $this->pruneAnyDeathQueue();

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
            'own_yordling'              => CardStats::hasClass($card, 'Йордлинг'),
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
        $deadWasFlying = CardStats::isFlyingCreature($died);

        foreach ($this->state->cards as $seeder) {
            if ($seeder->zone !== CardInstance::ZONE_FIELD
                && $seeder->zone !== CardInstance::ZONE_FLYING) continue;
            if ($seeder->dying) continue;
            if ($seeder->instanceId === $died->instanceId) continue;

            foreach ($this->normalizeAnyDeathConfigs($seeder->prop['on_any_death'] ?? null) as $configIndex => $config) {
                $sideFilter = $config['side'] ?? 'enemy';
                if ($sideFilter === 'enemy' && $seeder->owner === $died->owner) continue;
                if ($sideFilter === 'own' && $seeder->owner !== $died->owner) continue;

                $configCause = $config['cause'] ?? 'any';
                if ($configCause === 'poison' && $cause !== 'poison') continue;
                if ($configCause !== 'any' && $configCause !== 'poison' && $configCause !== $cause) continue;

                $condition = (string) ($config['condition'] ?? '');
                if ($condition === 'dead_creature_flying' && !$deadWasFlying) continue;
                if ($condition !== '' && $condition !== 'dead_creature_flying') continue;

                $usageKey = $this->anyDeathUsageKey($config, $configIndex);
                if ($this->anyDeathUsedThisTurn($seeder, $usageKey)
                    && !empty($config['once_per_turn'])) continue;
                if (!empty($seeder->flags['any_death_used_once_per_battle'][$usageKey])
                    && !empty($config['once_per_battle'])) continue;

                $effect = $config['effect'] ?? null;
                if ($effect === 'get_coin') {
                    $value = (int) ($config['value'] ?? 1);

                    $filter = $config['filter'] ?? 'any';
                    if ($filter === 'not_flying' && CardStats::isFlyingCreature($died)) continue;

                    $max = (int) ($seeder->prop['coins']['max_value'] ?? 0);
                    $before = $seeder->coins;

                    $seeder->coins += $value;
                    if ($max > 0 && $seeder->coins > $max) $seeder->coins = $max;

                    if ($seeder->coins > $before) {
                        $this->syncCoinBonusViaOwner($seeder);
                    }

                    if (!empty($config['once_per_turn'])) {
                        $this->markAnyDeathUsedThisTurn($seeder, $usageKey);
                    }
                    continue;
                }

                if (!empty($config['optional']) && $this->hasAnyDeathEffects($config)) {
                    $this->enqueueAnyDeath([
                        'type' => 'optional_effect',
                        'source_id' => $seeder->instanceId,
                        'died_id' => $died->instanceId,
                        'died_ukid' => $died->ukid,
                        'usage_key' => $usageKey,
                        'once_per_battle' => !empty($config['once_per_battle']),
                        'once_per_turn' => !empty($config['once_per_turn']),
                        'title' => (string) ($config['title'] ?? 'Сработала способность'),
                        'accept_label' => (string) ($config['accept_label'] ?? 'Применить'),
                        'decline_label' => (string) ($config['decline_label'] ?? 'Закрыть'),
                        'result_message' => (string) ($config['result_message'] ?? ''),
                        'effects' => $this->normalizeAnyDeathEffects($config),
                    ]);
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
                    if ($filter === 'not_flying' && CardStats::isFlyingCreature($t)) continue;

                    $candidates[] = $t->instanceId;
                }

                if (empty($candidates)) continue;

                $this->enqueueAnyDeath([
                    'type' => 'poison_near',
                    'source_id'    => $seeder->instanceId,
                    'died_id'      => $died->instanceId,
                    'died_ukid'    => $died->ukid,
                    'candidates'   => $candidates,
                    'poison_value' => $poisonValue,
                ]);

                if (!empty($config['once_per_turn'])) {
                    $this->markAnyDeathUsedThisTurn($seeder, $usageKey);
                }
            }
        }
    }

    public function chooseAnyDeathTarget(string $playerKey, Command $cmd): Result
    {
        $this->pruneAnyDeathQueue();
        $queue = $this->state->battle['pending_any_death'] ?? [];
        if (empty($queue)) {
            return Result::error('Нет ожидающего выбора');
        }

        $item = $queue[0];
        $sourceCard = $this->state->getCard($item['source_id']);
        if (!$this->isLiveAnyDeathSource($sourceCard) || $sourceCard->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $targetId = (int) $cmd->get('target_id', 0);

        if ($targetId === 0) {
            $this->shiftAnyDeathQueue();
            $this->state->bumpVersion();
            return Result::ok(['any_death_skipped']);
        }

        if (($item['type'] ?? 'poison_near') === 'optional_effect') {
            $this->applyOptionalAnyDeathEffects($sourceCard, $item);
            if (!empty($item['once_per_turn'])) {
                $this->markAnyDeathUsedThisTurn($sourceCard, (string) ($item['usage_key'] ?? 'optional_effect'));
            }
            if (!empty($item['once_per_battle'])) {
                $sourceCard->flags['any_death_used_once_per_battle'][(string) ($item['usage_key'] ?? 'optional_effect')] = true;
            }
            $message = (string) ($item['result_message'] ?? '');
            if ($message !== '') {
                $this->state->battle['any_death_messages'][] = [
                    'source_id' => $sourceCard->instanceId,
                    'message' => $message,
                ];
            }
            $this->shiftAnyDeathQueue();
            $this->state->bumpVersion();
            return Result::ok(['any_death_effect_applied']);
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

        $this->shiftAnyDeathQueue();

        $this->state->bumpVersion();
        return Result::ok(["any_death_target:{$targetId}"]);
    }

    public function pruneAnyDeathQueue(): void
    {
        $queue = $this->state->battle['pending_any_death'] ?? [];
        if (empty($queue)) return;

        $kept = [];
        foreach ($queue as $item) {
            $source = $this->state->getCard((int) ($item['source_id'] ?? 0));
            if (!$this->isLiveAnyDeathSource($source)) {
                continue;
            }
            if (($item['type'] ?? 'poison_near') === 'optional_effect') {
                $usageKey = (string) ($item['usage_key'] ?? 'optional_effect');
                if (!empty($item['once_per_battle'])
                    && !empty($source->flags['any_death_used_once_per_battle'][$usageKey])) {
                    continue;
                }
            }
            $kept[] = $item;
        }

        if (empty($kept)) {
            unset($this->state->battle['pending_any_death']);
            if (empty($this->state->battle['strike'])) {
                (new Engine())->finalizeDying($this->state);
            }
            if (!empty($this->state->battle['turn_phase'])) {
                (new TurnPhaseProcessor($this->state, new Engine()))->resume();
            }
            return;
        }

        $this->state->battle['pending_any_death'] = array_values($kept);
    }

    private function normalizeAnyDeathConfigs(mixed $config): array
    {
        if (!$config || !is_array($config)) {
            return [];
        }
        return array_is_list($config) ? $config : [$config];
    }

    private function anyDeathUsageKey(array $config, int $index): string
    {
        $key = (string) ($config['key'] ?? '');
        return $key !== '' ? $key : 'on_any_death_' . $index;
    }

    private function normalizeAnyDeathEffects(array $config): array
    {
        $effects = $config['effects'] ?? $config['effect'] ?? [];
        if (is_string($effects)) {
            return [['type' => $effects]];
        }
        if (is_array($effects) && !array_is_list($effects)) {
            return [$effects];
        }
        return is_array($effects) ? $effects : [];
    }

    private function hasAnyDeathEffects(array $config): bool
    {
        return !empty($this->normalizeAnyDeathEffects($config));
    }

    private function anyDeathUsedThisTurn(CardInstance $card, string $usageKey): bool
    {
        $used = $card->flags['any_death_used_this_turn'] ?? null;
        if (is_bool($used)) {
            return $used;
        }
        return is_array($used) && !empty($used[$usageKey]);
    }

    private function markAnyDeathUsedThisTurn(CardInstance $card, string $usageKey): void
    {
        $used = $card->flags['any_death_used_this_turn'] ?? [];
        if (!is_array($used)) {
            $used = [];
        }
        $used[$usageKey] = true;
        $card->flags['any_death_used_this_turn'] = $used;
    }

    private function enqueueAnyDeath(array $item): void
    {
        if (!isset($this->state->battle['pending_any_death'])) {
            $this->state->battle['pending_any_death'] = [];
        }
        $this->state->battle['pending_any_death'][] = $item;
    }

    private function shiftAnyDeathQueue(): void
    {
        array_shift($this->state->battle['pending_any_death']);
        $this->pruneAnyDeathQueue();
        if (!isset($this->state->battle['pending_any_death'])) {
            return;
        }
        if (empty($this->state->battle['pending_any_death'])
            && !empty($this->state->battle['turn_phase'])) {
            (new TurnPhaseProcessor($this->state, new Engine()))->resume();
        }
    }

    private function isLiveAnyDeathSource(?CardInstance $source): bool
    {
        if (!$source) return false;
        if ($source->dying || $source->hp <= 0) return false;
        return $source->zone === CardInstance::ZONE_FIELD || $source->zone === CardInstance::ZONE_FLYING;
    }

    private function applyOptionalAnyDeathEffects(CardInstance $source, array $item): void
    {
        foreach ((array) ($item['effects'] ?? []) as $effect) {
            if (!is_array($effect)) continue;
            $type = (string) ($effect['type'] ?? '');
            if ($type === 'gain_flight') {
                $source->type = 'fly';
                (new ZoneManager($this->state))->toFlying($source);
                continue;
            }
            if ($type === 'grant_modifier') {
                $stat = (string) ($effect['stat'] ?? '');
                if ($stat === 'strike') {
                    $stat = 'ability_strike';
                }
                if ($stat === '') continue;
                $source->modifiers[] = [
                    'stat' => $stat,
                    'value' => (int) ($effect['value'] ?? 0),
                    'expire' => (string) ($effect['expire'] ?? 'permanent'),
                    'source' => 'on_any_death',
                ];
            }
        }
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

    private function triggerNokamiOnPoison(CardInstance $poisoned): void
    {
        if ($poisoned->zone !== CardInstance::ZONE_FIELD) return;

        foreach ($this->state->cards as $nokami) {
            if ($nokami->owner === $poisoned->owner) continue;
            if ($nokami->zone !== CardInstance::ZONE_FIELD) continue;
            if ($nokami->dying || $nokami->hp <= 0) continue;

            $config = $nokami->prop['on_poison_damage'] ?? null;
            if (!is_array($config)) continue;

            // Пробуем найти в существующей очереди запись этого Ноками
            $existingIdx = null;
            foreach ((array) ($this->state->battle['pending_nokami_wound'] ?? []) as $i => $item) {
                if ((int) ($item['source_id'] ?? 0) === $nokami->instanceId) {
                    $existingIdx = $i;
                    break;
                }
            }

            // Если очередь для этого Ноками уже создана — дополняем candidates
            if ($existingIdx !== null) {
                $existing = $this->state->battle['pending_nokami_wound'][$existingIdx];
                $candidates = (array) $existing['candidates'];
                $poisonedIds = (array) ($existing['poisoned_ids'] ?? []);
                if (!in_array($poisoned->instanceId, $poisonedIds, true)) {
                    $poisonedIds[] = $poisoned->instanceId;
                }

                $newCandidates = $this->collectNokamiCandidates($poisonedIds);
                $merged = array_values(array_unique(array_merge($candidates, $newCandidates)));
                $this->state->battle['pending_nokami_wound'][$existingIdx]['candidates'] = $merged;
                $this->state->battle['pending_nokami_wound'][$existingIdx]['poisoned_ids'] = $poisonedIds;
                continue;
            }

            // Срабатывание уже потрачено в этом ходу — новую запись не создаём
            if (!empty($nokami->flags['nokami_used_this_turn'])
                && !empty($config['once_per_turn'])) continue;

            $candidates = $this->collectNokamiCandidates([$poisoned->instanceId]);
            if (empty($candidates)) continue;

            if (!isset($this->state->battle['pending_nokami_wound'])) {
                $this->state->battle['pending_nokami_wound'] = [];
            }

            $this->state->battle['pending_nokami_wound'][] = [
                'owner'         => $nokami->owner,
                'source_id'     => $nokami->instanceId,
                'source_ukid'   => $nokami->ukid,
                'poisoned_ids'  => [$poisoned->instanceId],
                'poisoned_ukid' => $poisoned->ukid,
                'candidates'    => $candidates,
                'value'         => (int) ($config['value'] ?? 1),
            ];

            // Пока игрок не выбрал цель, срабатывание остаётся не потраченным.
            // Повторные тики до выбора попадают в существующую очередь выше.
        }
    }

    /**
     * Соседи всех указанных отравленных (8 клеток, живые, на поле).
     * @param int[] $poisonedIds
     * @return int[]
     */
    private function collectNokamiCandidates(array $poisonedIds): array
    {
        $result = [];
        foreach ($poisonedIds as $pid) {
            $poisoned = $this->state->getCard((int) $pid);
            if (!$poisoned) continue;
            if ($poisoned->zone !== CardInstance::ZONE_FIELD) continue;

            foreach ($this->state->cards as $t) {
                if ($t->instanceId === $poisoned->instanceId) continue;
                if ($t->zone !== CardInstance::ZONE_FIELD) continue;
                if ($t->dying || $t->hp <= 0) continue;

                $dr = abs($t->row - $poisoned->row);
                $dc = abs($t->col - $poisoned->col);
                if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;

                $result[$t->instanceId] = true;
            }
        }
        return array_keys($result);
    }
}
