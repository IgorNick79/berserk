<?php
declare(strict_types=1);

namespace Berserk\Core;

use Berserk\Core\Movement\{MovementResolver, ForcedMovementResolver};

/** A prop-driven first incoming enemy strike protection and its mandatory movement choices. */
final class FirstStrikeJump
{
    public const PENDING = 'pending_first_strike_jump';
    public const FLAG = 'trigger_used_this_turn:first_strike_jump';

    public function __construct(private GameState $state, private Engine $engine) {}

    public static function used(GameState $state, CardInstance $card): bool
    {
        return ($card->flags[self::FLAG] ?? null) === self::turnStamp($state);
    }

    private static function turnStamp(GameState $state): string
    {
        // battle.turn counts rounds, so include the active player as existing per-turn effects do.
        return ($state->battle['turn'] ?? 0) . ':' . ($state->battle['active'] ?? '');
    }

    public function tryBlock(CardInstance $source, CardInstance $target, string $type, array $action = []): bool
    {
        $config = $target->effectiveProp()['first_strike_jump'] ?? null;
        if (!is_array($config)) return false;
        $types = $config['types'] ?? ['strike', 'uchr', 'tap', 'magic', 'answer'];
        if (!is_array($types) || !in_array($type, $types, true)
            || !in_array($type, ['strike', 'uchr', 'tap', 'magic', 'answer', 'shot', 'throw', 'discharge'], true)) return false;
        if ($source->owner === $target->owner || !in_array($source->type, ['creature', 'fly'], true)) return false;
        if ($target->dying || $target->hp <= 0 || self::used($this->state, $target)) return false;

        // The first qualifying strike is consumed even when its protection is ignored.
        $target->flags[self::FLAG] = self::turnStamp($this->state);
        $defenses = $config['defense'] ?? 'zoa';
        $defenses = is_array($defenses) ? $defenses : [$defenses];
        $specific = match ($type) {
            'throw' => ['zot', 'zoda'],
            'shot' => ['zov', 'zoda'],
            'discharge' => ['zoda', 'zom', 'zor'],
            'magic' => ['zom'],
            default => [],
        };
        $applicable = array_merge(['zoa', $source->type === 'fly' ? 'zoal' : 'zoan'], $specific);
        // Action-level ignore follows ActionResolver's existing shot/throw semantics.
        $extraIgnore = in_array($type, ['shot', 'throw'], true) ? (array) ($action['ignore'] ?? []) : [];
        $protected = false;
        foreach ($defenses as $defense) {
            if (!is_string($defense)) continue;
            if (in_array($defense, $applicable, true)
                && !CardStats::isDefenseIgnored($source, $defense, $extraIgnore)) $protected = true;
        }
        if (!$protected) return false;

        if ($type === 'strike') {
            $strike = &$this->state->battle['strike'];
            $strike['first_strike_blocked'] = true;
            $strike['attack_dice'] = $strike['defend_dice'] = 0;
            $strike['result'] = ['attack' => '', 'defend' => '', 'winner' => ''];
            (new StrikeResolver($this->state, $this->engine))->apply($strike['result'], false);
            $strike['state'] = 'results';
            $strike['damage_total'] = 0;
        } elseif ($type === 'answer') {
            $this->state->battle['strike']['first_answer_blocked'] = true;
        } else {
            if (empty($action['no_close'])) $source->closed = true;
            $this->state->battle['strike'] = [
                'kind' => $type, 'attacker_id' => $source->instanceId, 'target_id' => $target->instanceId,
                'defender_id' => null, 'state' => 'results', 'confirmed' => [],
                'attack_dice' => 0, 'defend_dice' => 0, 'damage' => 0, 'damage_total' => 0,
                'first_strike_blocked' => true, 'defended' => true, 'strike_hit' => false,
                'result' => ['attack' => '', 'defend' => '', 'winner' => ''],
                'final' => ['attack' => '', 'defend' => '', 'decreased' => false],
                'damage_applied' => true,
            ];
            if ($type === 'uchr') {
                foreach ($source->effectiveProp()['actions'] ?? [] as $action) {
                    if (($action['type'] ?? '') === 'uchr' && CoinAbilities::actionAvailable($source, $action)) {
                        (new CoinAbilities($this->state, $this->engine))->offerGain($source, $action);
                        break;
                    }
                }
            }
        }
        (new CoinAbilities($this->state, $this->engine))->deferFirstStrikeJump($target, $config);
        $this->state->bumpVersion();
        return true;
    }

    /** Called only by the shared post-combat queue, after ACKs and combat continuations. */
    public function openAfterCombat(CardInstance $target, array $config): bool
    {
        if (EffectiveFeatures::suppressed($target)) return false;
        $range = (int) ($config['range'] ?? 2);
        $cells = (new MovementResolver($this->state, $this->engine))->triggeredJumpCells($target, $range);
        if ($cells) {
            $this->state->battle[self::PENDING] = [
                'id' => bin2hex(random_bytes(12)), 'stage' => 'jump', 'owner' => $target->owner,
                'card_id' => $target->instanceId, 'range' => $range, 'cells' => array_keys($cells),
                'from_row' => $target->row, 'from_col' => $target->col,
                'pull_adjacent_enemy' => !empty($config['pull_adjacent_enemy']),
            ];
        }
        return (bool) $cells;
    }

    public function candidates(array $pending): array
    {
        $source = $this->state->getCard((int) $pending['card_id']);
        if (!$source) return [];
        $origin = clone $source;
        $origin->zone = CardInstance::ZONE_FIELD;
        $origin->row = (int) $pending['from_row'];
        $origin->col = (int) $pending['from_col'];
        $forced = new ForcedMovementResolver($this->state, $this->engine);
        $ids = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $pending['owner'] || !ZoneManager::areAdjacentFieldCards($origin, $card)) continue;
            if ($forced->canMove($card, $origin->row - $card->row, $origin->col - $card->col)) $ids[] = $card->instanceId;
        }
        return $ids;
    }

    public function choose(string $owner, Command $cmd): Result
    {
        $p = $this->state->battle[self::PENDING] ?? null;
        if (!$p || $p['owner'] !== $owner) return Result::error('Не ваш выбор');
        if (!hash_equals($p['id'], (string) $cmd->get('choice_id', ''))) return Result::error('Выбор устарел');
        $card = $this->state->getCard((int) $p['card_id']);
        if ($card && EffectiveFeatures::suppressed($card)) {
            unset($this->state->battle[self::PENDING]);
            $this->state->bumpVersion();
            return Result::ok(['first_strike_movement_suppressed']);
        }
        if ($p['stage'] === 'jump') {
            if (!$card || $card->owner !== $owner || $card->row !== $p['from_row'] || $card->col !== $p['from_col']) {
                return Result::error('Исходная карта недоступна');
            }
            $movement = new MovementResolver($this->state, $this->engine);
            $cells = $movement->triggeredJumpCells($card, (int) $p['range']);
            $row = (int) $cmd->get('row', 0);
            $col = (int) $cmd->get('col', 0);
            if (!isset($cells["{$row}_{$col}"]) || !in_array("{$row}_{$col}", $p['cells'], true)) {
                return Result::error('Недопустимая клетка прыжка');
            }
            $result = $movement->triggeredJump($card, (int) $p['range'], $row, $col);
            if (!$result->success) return $result;
            unset($this->state->battle[self::PENDING]);
            if ($p['pull_adjacent_enemy']) {
                $p['stage'] = 'pull';
                $p['id'] = bin2hex(random_bytes(12));
                $p['owner'] = $this->state->getOpponentKey($card->owner);
                $p['candidates'] = $this->candidates($p);
                if ($p['candidates']) $this->state->battle[self::PENDING] = $p;
            }
        } else {
            $id = (int) $cmd->get('target_id', 0);
            if (!in_array($id, $p['candidates'], true) || !in_array($id, $this->candidates($p), true)) {
                return Result::error('Недопустимое существо');
            }
            $target = $this->state->getCard($id);
            $result = (new ForcedMovementResolver($this->state, $this->engine))->move(
                $target, $p['from_row'] - $target->row, $p['from_col'] - $target->col,
            );
            if (!$result->success) return $result;
            unset($this->state->battle[self::PENDING]);
        }
        $this->state->bumpVersion();
        return Result::ok(['first_strike_movement_chosen']);
    }
}
