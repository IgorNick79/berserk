<?php
// src/Core/WoundTransferProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Общий процессор перераспределения ран.
 * Используется: Отшельница (aftermath-инстант), Волхв (main phase action).
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
     * }
     */
    public function start(string $playerKey, CardInstance $source, array $options): Result
    {
        $kind         = (string) ($options['kind'] ?? 'hermit');
        $donorFilter  = (string) ($options['donor_filter'] ?? 'wounded');
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

        $donors = $this->collectDonors($playerKey, $donorFilter, $maxTransfer);
        if (empty($donors)) {
            return Result::error('Нет подходящих доноров');
        }

        if ($cost > 0) {
            $source->coins -= $cost;
            $this->engine->syncCoinBonus($source);
        }

        if ($onFinish === 'main_phase') {
            $source->closed = true;
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

    private function collectDonors(string $playerKey, string $filter, int $maxTransfer): array
    {
        $donors = [];
        foreach ($this->state->cards as $c) {
            if ($c->owner !== $playerKey) continue;
            if ($c->dying || $c->hp <= 0) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;

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
        $this->state->battle['pending_wound_transfer']['step']      = 'target';
        $this->state->bumpVersion();
        return Result::ok(['wt_amount_chosen']);
    }

    public function chooseTarget(string $playerKey, Command $cmd): Result
    {
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

    public function finish(string $playerKey): Result
    {
        $pw = $this->state->battle['pending_wound_transfer'] ?? null;
        if (!$pw) return Result::error('Нет ожидающего выбора');
        if ($pw['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $donor  = $this->state->getCard($pw['donor_id']);
        $source = $this->state->getCard($pw['source_id']);

        if (!$donor) {
            unset($this->state->battle['pending_wound_transfer']);
            return Result::error('Донор не найден');
        }

        // Лечим донора ровно на переданное (фикс утечки HP)
        $transferred = 0;
        foreach ($pw['transfers'] as $t) {
            $transferred += (int) $t['amount'];
        }
        if ($transferred > 0) {
            $donor->hp += $transferred;
            if ($donor->hp > $donor->hpMax) $donor->hp = $donor->hpMax;
        }

        foreach ($pw['transfers'] as $t) {
            $target = $this->state->getCard($t['target_id']);
            if (!$target) continue;

            $target->hp -= (int) $t['amount'];
            if ($target->hp <= 0) {
                $target->hp = 0;
                $target->dying = true;
                (new ZoneManager($this->state))->toGraveyard($target);
            }
        }

        // Закрываем источник только для after-инстанта (для main_phase уже закрыт в start)
        $onFinish = $pw['options']['on_finish'] ?? 'main_phase';
        if ($source && in_array($onFinish, ['strike_after', 'combat'], true)) {
            $source->closed = true;
            unset($source->flags['in_stack']);
        }

        unset($this->state->battle['pending_wound_transfer']);

        $this->engine->checkGameOver($this->state);
        $this->engine->refreshArmor($this->state);

        // Возврат в окно aftermath — сброс «пас» обоих
        $strike = $this->state->battle['strike'] ?? null;
        if ($strike && in_array($strike['instant_phase'] ?? '', ['after', 'combat'], true)) {
            $this->state->battle['strike']['instant_passed'] = [];
        }

        $this->state->bumpVersion();
        return Result::ok(['wt_done']);
    }
}