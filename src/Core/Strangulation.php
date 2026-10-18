<?php

declare(strict_types=1);
namespace Berserk\Core;

/** Shared victim markers; no link to a particular strangler is retained. */
final class Strangulation
{
    public const PENDING = 'pending_strangulation';
    public static function count(CardInstance $card): int
    {
        return max(0, (int) ($card->markers['strangulation']['value'] ?? 0));
    }
    public static function isStrangler(CardInstance $card): bool
    {
        foreach ($card->prop['actions'] ?? [] as $action) {
            if (($action['type'] ?? '') === 'strangle') return true;
        }
        return false;
    }
    public static function onField(CardInstance $card): bool
    {
        return $card->zone === CardInstance::ZONE_FIELD && !$card->dying && $card->hp > 0
            && $card->row !== null && $card->col !== null;
    }
    public static function adjacent(CardInstance $a, CardInstance $b): bool
    {
        return self::onField($a) && self::onField($b) && $a->instanceId !== $b->instanceId
            && abs($a->row - $b->row) <= 1 && abs($a->col - $b->col) <= 1;
    }
    public static function validTarget(CardInstance $source, ?CardInstance $target): bool
    {
        return $target !== null && in_array($target->type, ['creature', 'fly'], true)
            && !$target->elite && $target->owner !== $source->owner && self::adjacent($source, $target);
    }
    public static function targets(GameState $state, CardInstance $source): array
    {
        $ids = [];
        foreach ($state->cards as $card) {
            if (self::validTarget($source, $card)) $ids[] = $card->instanceId;
        }
        return $ids;
    }
    /** Called only when the battlefield changes, before movement reactions. */
    public static function refresh(GameState $state): void
    {
        foreach ($state->cards as $victim) {
            if (!isset($victim->markers['strangulation'])) continue;
            if (self::onField($victim)) {
                foreach ($state->cards as $source) {
                    if ($source->owner !== $victim->owner && self::isStrangler($source)
                        && self::adjacent($source, $victim)) continue 2;
                }
            }
            unset($victim->markers['strangulation']);
        }
    }
    public static function blocksAttack(CardInstance $card, string $type): bool
    {
        return self::count($card) >= 2 && (CardStats::isOffensiveAction($type)
            || in_array($type, ['strangle', 'multi_discharge', 'blood_tap', 'self_wound_strike',
                'attack_series', 'damage_ranged', 'row_spell', 'dive'], true));
    }
    public static function hint(CardInstance $card): string
    {
        $n = self::count($card);
        return $n === 0 ? '' : 'Маркеры удушения: ' . $n . '. Самостоятельное передвижение запрещено.'
            . ($n >= 2 ? ' Атаки запрещены.' : '');
    }
    public static function available(GameState $state, CardInstance $source): bool
    {
        return $state->status === 'battle' && ($state->battle['active'] ?? null) === $source->owner
            && empty($state->battle['strike']) && self::onField($source)
            && in_array($source->type, ['creature', 'fly'], true) && !CardStats::isDisabled($source)
            && !CardStats::hasCannotAttack($source) && self::isStrangler($source);
    }
    public static function start(GameState $state, CardInstance $source, string $key): Result
    {
        if (!self::available($state, $source) || !empty($state->battle[self::PENDING])) {
            return Result::error('Удушение недоступно');
        }
        $ids = self::targets($state, $source);
        if (!$ids) return Result::error('Нет доступных целей');
        $state->battle[self::PENDING] = ['owner' => $source->owner, 'source_id' => $source->instanceId,
            'action_key' => $key, 'target_ids' => $ids, 'id' => bin2hex(random_bytes(16))];
        $state->bumpVersion();
        return Result::ok(['strangulation_started:' . $source->instanceId]);
    }
    public static function choose(GameState $state, Engine $engine, string $owner, Command $cmd): Result
    {
        $p = $state->battle[self::PENDING] ?? null;
        if (!$p || $p['owner'] !== $owner || !hash_equals($p['id'], (string) $cmd->get('choice_id', ''))) {
            return Result::error('Выбор удушения устарел');
        }
        if ($cmd->type === 'cancel_pending') {
            unset($state->battle[self::PENDING]);
            $state->bumpVersion();
            return Result::ok(['strangulation_cancelled']);
        }
        $source = $state->getCard((int) $p['source_id']);
        if (!$source || $source->owner !== $owner || !self::available($state, $source)) {
            return Result::error('Источник недоступен');
        }
        $action = null;
        foreach ($source->prop['actions'] ?? [] as $a) {
            if (($a['key'] ?? $a['type'] ?? '') === $p['action_key'] && ($a['type'] ?? '') === 'strangle') $action = $a;
        }
        if (!$action || !CoinAbilities::actionAvailable($source, $action)) return Result::error('Действие недоступно');
        $target = $state->getCard((int) $cmd->get('target_id', 0));
        if (!self::validTarget($source, $target) || !in_array($target->instanceId, $p['target_ids'], true)) {
            return Result::error('Неверная цель удушения');
        }
        unset($state->battle[self::PENDING]);
        $source->closed = true;
        $engine->revealCard($state, $target);
        $n = min(3, self::count($target) + 1);
        $target->markers['strangulation'] = ['value' => $n];
        // Standard ability result/ack, without declaring or rolling an ordinary strike.
        InformationalWaitManager::publish($state);
        $state->battle['strike'] = [
            'kind' => 'strangle', 'action_name' => 'Удушить', 'attacker_id' => $source->instanceId,
            'target_id' => $target->instanceId, 'defender_id' => null, 'state' => 'results',
            'attack_dice' => 0, 'defend_dice' => 0,
            'result' => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final' => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage' => 0, 'strangulation_count' => $n, 'confirmed' => [],
        ];
        if ($n === 3) {
            $state->battle['strike']['destruction'] = ['source_id' => $source->instanceId,
                'target_id' => $target->instanceId, 'cause' => 'strangulation'];
            $engine->forceDeath($state, $target, 'strangulation', $source);
            $engine->flushDeadeatQueue($state);
            $engine->finalizeDying($state);
        }
        $state->bumpVersion();
        return Result::ok(['strangulation:' . $source->instanceId . ':' . $target->instanceId . ':' . $n]);
    }
}
