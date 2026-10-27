<?php
// src/Core/WoundTransferProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Общий процессор перераспределения ран.
 * Используется: Отшельница (combat wounds-инстант), Волхв (main phase action).
 *
 * Шаги: source → amount → (target ⇄ target_amount)* → finish
 */
final class WoundTransferProcessor
{
    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    /**
     * @param array $options {
     *   kind:          'hermit'|'volkhv',
     *   donor_filter:  'damaged_this_strike'|'wounded',
     *   target_filter: 'own'|'enemy',
     *   max_transfer:  int (0 = без лимита сверх ран донора),
     *   coins_cost:    int,
     *   on_finish:     'strike_after'|'main_phase',
     *   action_type:    optional attack type for target defenses, e.g. 'cast',
     * }
     */
    public function start(string $playerKey, CardInstance $source, array $options): Result
    {
        if (EffectiveFeatures::suppressed($source)) return Result::error('Потеря памяти');
        $kind         = (string) ($options['kind'] ?? 'hermit');
        $maxTransfer  = (int) ($options['max_transfer'] ?? 0);
        $cost         = (int) ($options['coins_cost'] ?? 0);
        $onFinish     = (string) ($options['on_finish'] ?? 'main_phase');

        if ($source->owner !== $playerKey) {
            return Result::error('Не ваша карта');
        }
        if ($onFinish === 'main_phase' && $source->closed) {
            return Result::error('Карта закрыта');
        }
        if ($source->zone !== CardInstance::ZONE_FIELD
            && $source->zone !== CardInstance::ZONE_FLYING) {
            return Result::error('Карта не на поле');
        }
        if ($cost > 0 && $source->coins < $cost) {
            return Result::error('Не хватает монет');
        }

        $donors = $this->collectDonors($playerKey, $options, $source->instanceId);
        if (empty($donors)) {
            if ($kind === 'volkhv') {
                $this->state->battle['any_death_messages'][] = [
                    'type' => 'message',
                    'message' => 'Волхвование при луне: нет раненых союзников',
                ];
            }
            return Result::error('Нет подходящих доноров');
        }

        if ($cost > 0) {
            $source->coins -= $cost;
            $this->engine->syncCoinBonus($source);
        }

        $this->state->battle['pending_wound_transfer'] = [
            'owner'     => $playerKey,
            'source_id' => $source->instanceId,
            'kind'      => $kind,
            'options'   => $options,
            'donors'    => $donors,
            'step'      => 'source',
            'donor_id'  => null,
            'amount'    => 0,
            'remaining' => 0,
            'transfers' => [],
            'target_id' => null,
        ];

        $this->state->bumpVersion();
        return Result::ok(['wt_started:' . $kind]);
    }

    /** Admission only; the existing transfer choices and effect keep their rules. */
    public function canRedistributeCombatWounds(CardInstance $source): bool
    {
        foreach ($this->collectDonors($source->owner, ['donor_filter'=>'damaged_this_strike'], $source->instanceId) as $id=>$amount) {
            $donor=$this->state->getCard((int)$id);
            if (!$donor || $donor->hp >= $donor->hpMax) continue;
            foreach ($this->state->cards as $recipient) {
                if ($recipient->owner === $source->owner && $recipient->instanceId !== $id
                    && !$recipient->dying && $recipient->hp > 0
                    && in_array($recipient->zone, [CardInstance::ZONE_FIELD,CardInstance::ZONE_FLYING],true)) return true;
            }
        }
        return false;
    }

    private function collectDonors(string $playerKey, array $options, int $sourceId): array
    {
        $filter      = (string) ($options['donor_filter'] ?? 'wounded');
        $maxTransfer = (int)    ($options['max_transfer'] ?? 0);
        $nearOnly    = !empty($options['donor_near']);
        $element     = $options['donor_element'] ?? null;

        $source = $sourceId > 0 ? $this->state->getCard($sourceId) : null;

        $donors = [];
        foreach ($this->state->cards as $c) {
            if ($c->owner !== $playerKey) continue;
            if ($c->dying || $c->hp <= 0) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->instanceId === $sourceId) continue;
            if ($element !== null && $c->element !== $element) continue;

            if ($nearOnly && $source !== null) {
                $dr = abs($c->row - $source->row);
                $dc = abs($c->col - $source->col);
                if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;
            }

            $max = match ($filter) {
                'damaged_this_strike' => (int) ($c->flags['damage_taken_this_strike'] ?? 0),
                'wounded'             => $c->hpMax - $c->hp,
                default               => 0,
            };
            if ($max <= 0) continue;

            if ($maxTransfer > 0 && $max > $maxTransfer) {
                $max = $maxTransfer;
            }
            $donors[$c->instanceId] = $max;
        }
        return $donors;
    }

    public function chooseSource(string $playerKey, Command $cmd): Result
    {
        if ($skipped = $this->finishSuppressed($playerKey)) return $skipped;
        $pw = $this->state->battle['pending_wound_transfer'] ?? null;
        if (!$pw || $pw['step'] !== 'source') return Result::error('Не тот шаг');
        if ($pw['owner'] !== $playerKey)     return Result::error('Не ваш выбор');

        $donorId = (int) $cmd->get('donor_id', 0);
        if (!isset($pw['donors'][$donorId])) {
            return Result::error('Неверный донор');
        }

        $max = (int) $pw['donors'][$donorId];

        $this->state->battle['pending_wound_transfer']['donor_id'] = $donorId;
        $this->state->battle['pending_wound_transfer']['amount']   = $max;
        $this->state->battle['pending_wound_transfer']['step']     = 'amount';
        $this->state->bumpVersion();
        return Result::ok(['wt_source_chosen']);
    }

    public function chooseAmount(string $playerKey, Command $cmd): Result
    {
        if ($skipped = $this->finishSuppressed($playerKey)) return $skipped;
        $pw = $this->state->battle['pending_wound_transfer'] ?? null;
        if (!$pw || $pw['step'] !== 'amount') return Result::error('Не тот шаг');
        if ($pw['owner'] !== $playerKey)      return Result::error('Не ваш выбор');

        $amount = (int) $cmd->get('amount', 0);
        $max    = (int) $pw['donors'][$pw['donor_id']];

        if ($amount < 1 || $amount > $max) {
            return Result::error('Неверное количество');
        }

        $this->state->battle['pending_wound_transfer']['amount']    = $amount;
        $this->state->battle['pending_wound_transfer']['remaining'] = $amount;

        $targetFilter = $this->state->battle['pending_wound_transfer']['options']['target_filter'] ?? 'own';

        // Цель — сам источник (Осклизг, Рэккен): финализируем сразу
        if ($targetFilter === 'source') {
            $this->state->battle['pending_wound_transfer']['transfers'][] = [
                'target_id' => $pw['source_id'],
                'amount'    => $amount,
            ];
            $this->state->battle['pending_wound_transfer']['remaining'] = 0;

            return $this->finish($playerKey);
        }

        $this->state->battle['pending_wound_transfer']['step'] = 'target';
        $this->state->bumpVersion();
        return Result::ok(['wt_amount_chosen']);
    }

    public function chooseTarget(string $playerKey, Command $cmd): Result
    {
        if ($skipped = $this->finishSuppressed($playerKey)) return $skipped;
        $pw = $this->state->battle['pending_wound_transfer'] ?? null;
        if (!$pw || $pw['step'] !== 'target') return Result::error('Не тот шаг');
        if ($pw['owner'] !== $playerKey)      return Result::error('Не ваш выбор');

        $targetId = (int) $cmd->get('target_id', 0);

        if ($targetId === 0) {
            return $this->finish($playerKey);
        }

        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if ($target->instanceId === $pw['donor_id']) {
            return Result::error('Донор не может быть получателем');
        }
        if ($target->dying || $target->hp <= 0) {
            return Result::error('Цель мертва');
        }
        if ($target->zone !== CardInstance::ZONE_FIELD
            && $target->zone !== CardInstance::ZONE_FLYING) {
            return Result::error('Цель не на поле');
        }

        $targetFilter = $pw['options']['target_filter'] ?? 'own';
        if ($targetFilter === 'own' && $target->owner !== $playerKey) {
            return Result::error('Только на своих');
        }
        if ($targetFilter === 'enemy' && $target->owner === $playerKey) {
            return Result::error('Только на врагов');
        }

        $this->state->battle['pending_wound_transfer']['step']      = 'target_amount';
        $this->state->battle['pending_wound_transfer']['target_id'] = $targetId;
        $this->state->bumpVersion();
        return Result::ok(['wt_target_chosen']);
    }

    public function chooseTargetAmount(string $playerKey, Command $cmd): Result
    {
        if ($skipped = $this->finishSuppressed($playerKey)) return $skipped;
        $pw = $this->state->battle['pending_wound_transfer'] ?? null;
        if (!$pw || $pw['step'] !== 'target_amount') return Result::error('Не тот шаг');
        if ($pw['owner'] !== $playerKey)             return Result::error('Не ваш выбор');

        $amount    = (int) $cmd->get('amount', 0);
        $remaining = (int) $pw['remaining'];

        if ($amount < 1 || $amount > $remaining) {
            return Result::error('Неверное количество');
        }

        $this->state->battle['pending_wound_transfer']['transfers'][] = [
            'target_id' => $pw['target_id'],
            'amount'    => $amount,
        ];
        $this->state->battle['pending_wound_transfer']['remaining'] -= $amount;
        $this->state->battle['pending_wound_transfer']['target_id'] = null;

        if ($this->state->battle['pending_wound_transfer']['remaining'] <= 0) {
            return $this->finish($playerKey);
        }

        $this->state->battle['pending_wound_transfer']['step'] = 'target';
        $this->state->bumpVersion();
        return Result::ok(['wt_target_amount_done']);
    }

    /** A fizzled ability, not the refundable cancellation chosen by its owner. */
    private function finishSuppressed(string $owner): ?Result
    {
        $pending = $this->state->battle['pending_wound_transfer'] ?? null;
        if (!$pending || $pending['owner'] !== $owner) return null;
        $source = $this->state->getCard((int) $pending['source_id']);
        if (!$source || !EffectiveFeatures::suppressed($source)) return null;
        $onFinish = $pending['options']['on_finish'] ?? 'main_phase';
        if ($onFinish === 'combat_resolution') {
            $item = $this->state->battle['strike']['instant_resolution']['queue'][0] ?? null;
            if (!$item || (int) ($item['card_id'] ?? 0) !== $source->instanceId
                || ($item['effect']['type'] ?? '') !== 'redistribute_wounds') {
                return Result::error('Нет приостановленного переноса ран');
            }
        }
        unset($this->state->battle['pending_wound_transfer']);
        // Follow normal completion's tap commitment; never undo a prior cost.
        if (in_array($onFinish, ['strike_after', 'combat', 'main_phase'], true)) {
            $source->closed = true;
            unset($source->flags['in_stack']);
        }
        if ($onFinish === 'combat_resolution') {
            (new InstantProcessor($this->state, $this->engine))->completePausedCombatItem(
                false, 'Потеря памяти', ['transferred' => 0, 'transfers' => []]
            );
        } else {
            if (in_array($this->state->battle['strike']['instant_phase'] ?? '', ['after', 'combat'], true)) {
                $this->state->battle['strike']['instant_passed'] = [];
            }
            if (!empty($this->state->battle['turn_phase'])) {
                (new TurnPhaseProcessor($this->state, $this->engine))->resume();
            }
        }
        $this->state->bumpVersion();
        return Result::ok(['wound_transfer_suppressed']);
    }

    public function cancel(string $playerKey): Result
    {
        $pending = $this->state->battle['pending_wound_transfer'] ?? null;
        if (!$pending) return Result::error('Нет ожидающего выбора');
        if ($pending['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $combat = ($pending['options']['on_finish'] ?? 'main_phase') === 'combat_resolution';
        if ($combat) {
            $item = $this->state->battle['strike']['instant_resolution']['queue'][0] ?? null;
            if (!$item || (int) ($item['card_id'] ?? 0) !== (int) $pending['source_id']
                || ($item['effect']['type'] ?? '') !== 'redistribute_wounds') {
                return Result::error('Нет приостановленного переноса ран');
            }
        }

        $source = $this->state->getCard((int) $pending['source_id']);
        if ($source) {
            $cost = (int) ($pending['options']['coins_cost'] ?? 0);
            if ($cost > 0) {
                $source->coins += $cost;
                $this->engine->syncCoinBonus($source);
            }
            $source->closed = false;
            unset($source->flags['in_stack']);
        }
        unset($this->state->battle['pending_wound_transfer']);

        if ($combat) {
            (new InstantProcessor($this->state, $this->engine))->completePausedCombatItem(
                false, 'отменено игроком', ['transferred' => 0], false
            );
        } elseif (!empty($this->state->battle['turn_phase'])) {
            (new TurnPhaseProcessor($this->state, $this->engine))->resume();
        }
        $this->state->bumpVersion();
        return Result::ok(['cancelled:wound_transfer']);
    }

    public function finish(string $playerKey): Result
    {
        if ($skipped = $this->finishSuppressed($playerKey)) return $skipped;
        $pw = $this->state->battle['pending_wound_transfer'] ?? null;
        if (!$pw) return Result::error('Нет ожидающего выбора');
        if ($pw['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $donor  = $this->state->getCard($pw['donor_id']);
        $source = $this->state->getCard($pw['source_id']);

        if (!$donor) {
            unset($this->state->battle['pending_wound_transfer']);
            return Result::error('Донор не найден');
        }

        $actionType = (string) ($pw['options']['action_type'] ?? '');
        $damageType = $actionType !== '' ? $actionType : 'wound_transfer';

        $appliedTransfers = [];
        $blockedTransfers = 0;
        foreach ($pw['transfers'] as $t) {
            $target = $this->state->getCard($t['target_id']);
            if (!$target) continue;

            if ($actionType !== ''
                && $target->owner !== $playerKey
                && CardStats::hasDefense($this->state, $target, $actionType, $source)) {
                $blockedTransfers++;
                continue;
            }

            $appliedTransfers[] = $t;
        }

        if ($blockedTransfers > 0) {
            $message = $actionType === 'cast'
                ? 'Волхвование при луне: цель защищена от заклинания'
                : 'Волхвование при луне: цель защищена';
            $this->state->battle['any_death_messages'][] = [
                'type' => 'message',
                'message' => $message,
            ];
        }

        $resolutionParent = $source ? ActionResolution::effect($this->state, $source, 'wound_transfer',
            ($pw['options']['on_finish'] ?? 'main_phase') === 'combat_resolution' ? 'instant' : 'ordinary') : null;
        $resolutionHpBefore = $donor->hp;
        // Лечим донора ровно на реально переданное (фикс утечки HP)
        $transferred = 0;
        foreach ($appliedTransfers as $t) {
            $transferred += (int) $t['amount'];
        }
        if ($transferred > 0) {
            $donor->hp += $transferred;
            if ($donor->hp > $donor->hpMax) $donor->hp = $donor->hpMax;
        }

        if ($source) ActionResolution::healed($this->state, $source, $donor, $resolutionHpBefore, 'wound_transfer', $resolutionParent);
        foreach ($appliedTransfers as $t) {
            $target = $this->state->getCard($t['target_id']);
            if (!$target) continue;

            $this->engine->applyDamage($this->state, $target, (int) $t['amount'], $damageType, $source);
        }

        // Закрываем источник только для отложенных flow (для main_phase уже закрыт в start)
        $onFinish = $pw['options']['on_finish'] ?? 'main_phase';
        if ($source && in_array($onFinish, ['strike_after', 'combat', 'main_phase'], true)) {
            $source->closed = true;
            unset($source->flags['in_stack']);
        }

        unset($this->state->battle['pending_wound_transfer']);

        $this->engine->checkGameOver($this->state);
        $this->engine->refreshArmor($this->state);

        if ($onFinish === 'combat_resolution') {
            (new InstantProcessor($this->state, $this->engine))->completePausedCombatItem(true, '', [
                'transferred' => $transferred,
                'transfers' => (array) ($pw['transfers'] ?? []),
            ]);
            $this->state->bumpVersion();
            return Result::ok(['wt_done']);
        }

        // Возврат в старое окно after/combat — сброс «пас» обоих
        $strike = $this->state->battle['strike'] ?? null;
        if ($strike && in_array($strike['instant_phase'] ?? '', ['after', 'combat'], true)) {
            $this->state->battle['strike']['instant_passed'] = [];
        }

        $this->state->bumpVersion();
        return Result::ok(['wt_done']);
    }
}
