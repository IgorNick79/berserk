<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Synchronous resolution boundary. No history, I/O, callbacks or card filtering. */
final class ActionResolution
{
    public const KEY = 'action_resolution';
    public const ID = '_resolution_id';

    /** Start a command-local diagnostic batch and retain only tokens still owned by gameplay. */
    public static function beginCommand(GameState $state): void
    {
        if (!isset($state->battle[self::KEY])) return;
        $ids = [];
        $walk = static function(array $value) use (&$walk, &$ids): void {
            if (isset($value[self::ID])) $ids[(int) $value[self::ID]] = true;
            foreach ($value as $item) if (is_array($item)) $walk($item);
        };
        $battle = $state->battle;
        unset($battle[self::KEY]);
        $walk($battle);
        $context = &$state->battle[self::KEY];
        $context['tokens'] = array_intersect_key($context['tokens'], $ids);
        $context['notifications'] = [];
    }

    public static function bind(GameState $state, array &$context, CardInstance $actor, string $type, string $origin = 'ordinary', ?int $parent = null): int
    {
        if (isset($context[self::ID])) return (int) $context[self::ID];
        $state->battle[self::KEY] ??= ['next_id'=>0, 'sequence'=>0, 'tokens'=>[], 'notifications'=>[]];
        $data = &$state->battle[self::KEY];
        $id = ++$data['next_id'];
        $parent ??= self::parent($state);
        $context[self::ID] = $id;
        $data['tokens'][$id] = ['action_id'=>$id, 'parent_id'=>$parent,
            'type'=>$type, 'actor_id'=>$actor->instanceId, 'owner'=>$actor->owner,
            'origin'=>$origin, 'delivered'=>false];
        return $id;
    }

    public static function parent(GameState $state): ?int
    {
        if (isset($state->battle[self::KEY]['scope_id'])) return (int)$state->battle[self::KEY]['scope_id'];
        $strike = $state->battle['strike'] ?? [];
        if (isset($strike[self::ID])) return (int) $strike[self::ID];
        $origin = $state->battle['turn_instant_stack']['continuation']['combat_origin_strike'] ?? [];
        if (isset($origin[self::ID])) return (int) $origin[self::ID];
        $stacks = $state->battle['combat_continuation']['stacks'] ?? [];
        if ($stacks) {
            $last = $stacks[array_key_last($stacks)];
            return isset($last['continuation']['combat_origin_strike'][self::ID])
                ? (int) $last['continuation']['combat_origin_strike'][self::ID] : null;
        }
        return null;
    }

    public static function resolve(GameState $state, array $context, string $outcome, array $facts = []): void
    {
        $id = $context[self::ID] ?? null;
        if ($id === null) throw new \LogicException('Resolution without action identity');
        $data = &$state->battle[self::KEY];
        if (!isset($data['tokens'][$id])) throw new \LogicException('Resolution identity no longer owned by gameplay');
        $token = &$data['tokens'][$id];
        if ($token['delivered']) return;
        $token['delivered'] = true;
        $event = $token;
        unset($event['delivered']);
        $actor = $state->getCard($event['actor_id']);
        $event['owner'] = $actor?->owner ?? $event['owner'];
        $event['actor_type'] = $actor?->type;
        $event['element'] = $actor?->element;
        $event['nested'] = $event['parent_id'] !== null;
        $event['outcome'] = $outcome;
        $event['sequence'] = ++$data['sequence'];
        $event['hit'] = $facts['hit'] ?? null;
        $event['hp_restored'] = (int) ($facts['hp_restored'] ?? 0);
        $event['facts'] = $facts;
        // Stage 2 consumes this boundary synchronously. This batch is replaced next command.
        $data['notifications'][] = $event;
    }

    public static function effect(GameState $state, CardInstance $actor, string $type, string $origin, array $facts = [], ?int $parent = null): int
    {
        $context = [];
        self::bind($state, $context, $actor, $type, $origin, $parent);
        $outcome = !empty($facts['blocked_before_execution']) ? 'blocked'
            : (isset($facts['hit']) ? ($facts['hit'] ? 'hit' : 'miss')
                : ($type === 'heal' && ($facts['hp_restored'] ?? 0) === 0 ? 'no_effect' : 'performed'));
        self::resolve($state, $context, $outcome, $facts);
        return $context[self::ID];
    }

    public static function performed(GameState $state, CardInstance $actor, array $action, string $origin = 'ordinary'): int
    {
        self::bind($state, $action, $actor, (string)($action['type'] ?? 'ability'), (string)($action['_resolution_origin'] ?? $origin));
        if (($state->battle['strike']['attacker_id'] ?? null) === $actor->instanceId
            && !isset($state->battle['strike'][self::ID])) $state->battle['strike'][self::ID] = $action[self::ID];
        self::resolve($state, $action, 'performed');
        return $action[self::ID];
    }

    public static function healed(GameState $state, CardInstance $actor, CardInstance $target, int $before, string $ability, ?int $parent = null): void
    {
        $amount = max(0, $target->hp - $before);
        if ($amount > 0) self::effect($state, $actor, 'heal', 'passive',
            ['hp_restored'=>$amount, 'target_id'=>$target->instanceId, 'ability'=>$ability], $parent);
    }
}
