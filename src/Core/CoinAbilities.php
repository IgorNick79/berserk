<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Prop-driven coin choices; continuations contain only serializable data. */
final class CoinAbilities
{
    public function __construct(private GameState $state, private Engine $engine) {}

    public static function actionAvailable(CardInstance $card, array $action): bool
    {
        return !isset($action['coins_required']) || $card->coins === (int) $action['coins_required'];
    }

    public function gain(CardInstance $target, int $value, ?CardInstance $source = null, bool $selfEffect = false): int
    {
        if ($value <= 0) return 0;
        if (!empty($target->prop['coins_receive_deny'])
            && (!$selfEffect || !$source || $source->instanceId !== $target->instanceId)) return 0;
        $max = (int) ($target->prop['coins']['max_value'] ?? 0);
        $before = $target->coins;
        $target->coins = $max > 0 ? min($max, $before + $value) : $before + $value;
        $this->engine->syncCoinBonus($target);
        return $target->coins - $before;
    }

    public function offerGain(CardInstance $card, array $action): void
    {
        $value = (int) ($action['optional_self_coin'] ?? 0);
        if ($value <= 0 || $card->coins > 0 || $card->dying || $card->hp <= 0) return;
        $this->defer([
            'kind' => 'gain', 'owner' => $card->owner,
            'card_id' => $card->instanceId, 'value' => $value,
        ]);
    }

    /** Record an effect, never open a choice inside the originating combat. */
    private function defer(array $effect): void
    {
        $effect['id'] = bin2hex(random_bytes(12));
        $effect['executed'] = false;
        if (!empty($this->state->battle['strike'])) {
            $this->state->battle['strike']['coin_post_combat_effects'][$effect['id']] = $effect;
        } else {
            // A prophecy-blocked UCHR has no strike, but its mandatory prophecy UI still gates advance().
            $effect['combat_result'] = null;
            $this->state->battle['coin_post_combat_queue'][$effect['id']] = $effect;
        }
    }

    /** Called by existing combat completion paths, before they discard the resolved context. */
    public function completeCombat(array $strike): void
    {
        $effects = $strike['coin_post_combat_effects'] ?? [];
        if (!$effects) return;
        unset($strike['coin_post_combat_effects']);
        foreach ($effects as $id => $effect) {
            if (isset($this->state->battle['coin_post_combat_history'][$id])
                || isset($this->state->battle['coin_post_combat_queue'][$id])) continue;
            $effect['combat_result'] = $strike;
            $this->state->battle['coin_post_combat_queue'][$id] = $effect;
        }
    }

    public function offerBlock(CardInstance $source, CardInstance $target, string $type, array $continuation): bool
    {
        $config = $target->prop['coin_block'] ?? null;
        if (!is_array($config) || !in_array($type, $config['types'] ?? [], true)) return false;
        if ($target->coins < (int) ($config['cost'] ?? 1) || $target->dying || $target->hp <= 0) return false;
        // Universal attack protection has the same bypass rules as zoa/zoan/zoal.
        $defense = (string) ($config['defense'] ?? 'zoa');
        if (CardStats::isDefenseIgnored($source, $defense)) return false;
        $typedDefense = $source->type === 'fly' ? 'zoal' : 'zoan';
        if (!in_array($defense, ['zoa', $typedDefense], true)) return false;
        $this->state->battle['pending_coin_ability'] = [
            'id' => bin2hex(random_bytes(12)), 'kind' => 'block', 'owner' => $target->owner, 'card_id' => $target->instanceId,
            'cost' => (int) ($config['cost'] ?? 1), 'source_id' => $source->instanceId,
            'type' => $type, 'continuation' => $continuation,
        ];
        return true;
    }

    public function dischargeTargets(CardInstance $source, array $action): array
    {
        // Standard target rules, without paying a second action or counting the completed strike.
        $probe = clone $source;
        $probe->closed = false;
        $probe->flags['attacks_used_this_turn'] = 0;
        $probe->prop['actions'] = [array_merge($action, ['key' => 'triggered_discharge'])];
        $targets = BattleHelper::getAttackTargets($this->state, $probe, 'action:triggered_discharge', $source->owner, true);
        return array_filter($targets, function ($allowed, $id): bool {
            $target = $this->state->getCard((int) $id);
            return $target && !$target->dying && $target->hp > 0;
        }, ARRAY_FILTER_USE_BOTH);
    }

    public function triggerDischarge(CardInstance $source, int $wounds, string $type): void
    {
        $config = $source->prop['on_wounding_strike'] ?? null;
        if ($wounds <= 0 || !in_array($type, ['strike', 'answer', 'uchr', 'tap', 'magic'], true)) return;
        if (!is_array($config) || $source->coins < (int) ($config['min_coins'] ?? 1)) return;
        if ($source->dying || $source->hp <= 0) return;
        $strike = &$this->state->battle['strike'];
        if (isset($strike['coin_discharge_sources'][$source->instanceId])) return;
        $strike['coin_discharge_sources'][$source->instanceId] = true;
        $this->defer([
            'kind' => 'discharge', 'owner' => $source->owner, 'card_id' => $source->instanceId,
            'wounds' => $wounds, 'strike_type' => $type, 'action' => $config,
        ]);
    }

    public function choose(string $owner, Command $cmd): Result
    {
        $p = $this->state->battle['pending_coin_ability'] ?? null;
        if (!$p || $p['owner'] !== $owner) return Result::error('Не ваш выбор');
        if (!hash_equals($p['id'], (string) $cmd->get('choice_id', ''))) return Result::error('Выбор устарел');
        $card = $this->state->getCard((int) $p['card_id']);
        if (!$card || $card->owner !== $owner) return Result::error('Карта недоступна');
        $choice = (string) $cmd->get('choice', '');
        if ($p['kind'] === 'discharge') {
            $targetId = (int) $cmd->get('target_id', 0);
            $targets = $this->dischargeTargets($card, $p['action']);
            if ($targets && (!in_array($targetId, $p['candidates'], true) || !isset($targets[$targetId]))) {
                return Result::error('Неверная цель');
            }
            unset($this->state->battle['pending_coin_ability']);
            if ($targets && !$card->dying && $card->hp > 0) {
                (new ActionResolver($this->state, $this->engine))->triggeredDischarge($card,
                    $this->state->getCard($targetId), $p['action']);
            }
        } else {
            if (!in_array($choice, ['yes', 'no'], true)) return Result::error('Неверный выбор');
            if ($p['kind'] === 'block' && $choice === 'yes' && $card->coins < $p['cost']) {
                return Result::error('Не хватает монет');
            }
            unset($this->state->battle['pending_coin_ability']);
            if ($p['kind'] === 'gain') {
                if ($choice === 'yes' && !$card->dying && $card->hp > 0) $this->gain($card, $p['value'], $card, true);
            } else {
                $context = $p['continuation'];
                if ($choice === 'yes') {
                    $card->coins -= $p['cost'];
                    $this->engine->syncCoinBonus($card);
                    $this->block($p);
                } elseif ($context['kind'] === 'strike') {
                    $this->state->battle['strike']['coin_block_checked'] = true;
                    (new StrikeResolver($this->state, $this->engine))->resolve();
                } elseif ($context['kind'] === 'uchr') {
                    (new ActionResolver($this->state, $this->engine))->uchr($context['player'],
                        new Command('uchr', ['card_id' => $context['card_id'], 'target_id' => $context['target_id']]), true);
                } else {
                    (new ActionResolver($this->state, $this->engine))->resumeCoinAttack($context);
                }
            }
        }
        $this->advance();
        $this->state->bumpVersion();
        return Result::ok(['coin_ability_chosen']);
    }

    public function advance(): void
    {
        if (empty($this->state->battle['coin_post_combat_queue'])
            && empty($this->state->battle['coin_post_combat_continuation'])) return;
        if ($this->state->status !== 'battle' || $this->state->winner !== null) return;
        if (!empty($this->state->battle['strike'])
            || Choice\ChoiceRegistry::current($this->state) !== null) return;
        // Some older mandatory continuations are not ChoiceHandlers (notably prophecy).
        foreach ($this->state->battle as $key => $value) {
            if (str_starts_with((string) $key, 'pending_') && !empty($value)) return;
        }
        if (!empty($this->state->battle['turn_instant_stack'])
            || !empty($this->state->battle['turn_instant_result'])) return;

        if (!empty($this->state->battle['coin_post_combat_continuation'])) {
            $origin = $this->state->battle['coin_post_combat_continuation'];
            unset($this->state->battle['coin_post_combat_continuation']);
            $origin['instant_phase'] = 'after';
            $this->state->battle['strike'] = $origin;
            (new StrikeResolver($this->state, $this->engine))->continueAfterStrikeResultAck($origin);
            $this->advance();
            return;
        }
        while (!empty($this->state->battle['coin_post_combat_queue'])) {
            $id = array_key_first($this->state->battle['coin_post_combat_queue']);
            $effect = $this->state->battle['coin_post_combat_queue'][$id];
            unset($this->state->battle['coin_post_combat_queue'][$id]);
            if (isset($this->state->battle['coin_post_combat_history'][$id])) continue;
            $effect['executed'] = true;
            $this->state->battle['coin_post_combat_history'][$id] = $effect;
            $source = $this->state->getCard((int) $effect['card_id']);
            if (!$source || $source->owner !== $effect['owner'] || $source->dying || $source->hp <= 0
                || !in_array($source->zone, [CardInstance::ZONE_FIELD, CardInstance::ZONE_FLYING], true)) continue;
            if ($effect['kind'] === 'discharge') {
                if ($effect['wounds'] <= 0 || $source->coins < (int) ($effect['action']['min_coins'] ?? 1)) continue;
                $targets = $this->dischargeTargets($source, $effect['action']);
                if (!$targets) continue;
                $effect['candidates'] = array_keys($targets);
            } elseif ($source->coins > 0) {
                continue;
            }
            $this->state->battle['pending_coin_ability'] = $effect;
            return;
        }
    }

    private function block(array $pending): void
    {
        $context = $pending['continuation'];
        $source = $this->state->getCard((int) $pending['source_id']);
        if ($context['kind'] === 'strike') {
            $s = &$this->state->battle['strike'];
            $s['coin_blocked'] = true;
            $s['coin_block_checked'] = true;
            $s['attack_dice'] = 0;
            $s['defend_dice'] = 0;
            $s['result'] = ['attack' => '', 'defend' => '', 'winner' => ''];
            (new StrikeResolver($this->state, $this->engine))->apply($s['result'], false);
            $s['state'] = 'results';
            $s['damage_total'] = 0;
        } else {
            $source->closed = true;
            $this->state->battle['strike'] = [
                'kind' => $pending['type'], 'attacker_id' => $source->instanceId,
                'target_id' => $pending['card_id'], 'defender_id' => null, 'state' => 'results',
                'attack_dice' => 0, 'defend_dice' => 0, 'damage' => 0, 'damage_total' => 0,
                'coin_blocked' => true, 'defended' => true, 'strike_hit' => false,
                'result' => ['attack' => '', 'defend' => '', 'winner' => ''],
                'final' => ['attack' => '', 'defend' => '', 'decreased' => false], 'confirmed' => [],
            ];
            if ($pending['type'] === 'uchr') {
                foreach ($source->prop['actions'] ?? [] as $a) {
                    if (($a['type'] ?? '') === 'uchr' && self::actionAvailable($source, $a)) {
                        $this->offerGain($source, $a);
                        break;
                    }
                }
            }
        }
    }
}
