<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Durable independent markers, placement and opponent-start movement. */
final class Tremor
{
    public const PENDING = 'pending_tremor';
    public const USED = 'tremor_created';

    public static function enteredGraveyard(GameState $state, CardInstance $card): void
    {
        if (empty($card->effectiveProp()['tremor_on_graveyard']) || !empty($card->flags[self::USED])) return;
        // Reserve once at the event, before another transition can enqueue this instance again.
        $card->flags[self::USED] = true;
        $id = 'tremor-' . ($state->battle['tremor_sequence'] = (int)($state->battle['tremor_sequence'] ?? 0) + 1);
        $item = ['id'=>$id, 'owner'=>$card->owner];
        if (empty($state->battle[self::PENDING])) $state->battle[self::PENDING] = $item + ['queue'=>[]];
        else $state->battle[self::PENDING]['queue'][] = $item;
    }

    public static function incarnationEnabled(?CardInstance $card): bool
    {
        $inc = $card?->effectiveProp()['incarnation'] ?? null;
        return is_array($inc) && !empty($inc['tremor']);
    }

    /** Incarnation tunnels, including occupied cells. Values identify one stable marker. */
    public static function incarnationCells(GameState $state): array
    {
        $cells = [];
        foreach (array_keys($state->cell_markers) as $key) {
            if (!preg_match('/^([1-6])_([1-5])$/', (string)$key, $match)) continue;
            if (ZoneManager::hasBlockingMarker($state, $key)) continue;
            $markers = array_values(array_filter(ZoneManager::markersAt($state, $key),
                static fn($m)=>($m['type'] ?? '') === 'tremor' && is_string($m['id'] ?? null)));
            usort($markers, static fn($a,$b)=>strcmp($a['id'],$b['id']));
            if ($markers) $cells[$key] = $markers[0]['id'];
        }
        ksort($cells); return $cells;
    }

    public static function consume(GameState $state, string $cell, string $id): void
    {
        $markers = ZoneManager::markersAt($state, $cell);
        foreach ($markers as $index=>$marker) {
            if (($marker['type'] ?? '') === 'tremor' && ($marker['id'] ?? null) === $id) {
                unset($markers[$index]); break;
            }
        }
        if ($markers) $state->cell_markers[$cell] = array_values($markers);
        else unset($state->cell_markers[$cell]);
    }

    public static function hasWorm(GameState $state, string $owner): bool
    {
        foreach ($state->cards as $card) {
            if ($card->owner === $owner && $card->zone === CardInstance::ZONE_GRAVEYARD
                && !empty($card->effectiveProp()['tremor_on_graveyard'])) return true;
        }
        return false;
    }

    public static function ownedMarkers(GameState $state, string $owner): array
    {
        $result = [];
        foreach (array_keys($state->cell_markers) as $key) {
            foreach (ZoneManager::markersAt($state, $key) as $marker) {
                if (($marker['type'] ?? '') === 'tremor' && ($marker['owner'] ?? null) === $owner) {
                    $result[] = $marker + ['cell'=>$key];
                }
            }
        }
        return $result;
    }

    public static function startMoves(GameState $state, string $owner): array
    {
        $description = ['label'=>'Перемещение Дрожи', 'items'=>[]];
        $turn = (string)($state->battle['turn'] ?? 0) . ':' . ($state->battle['active'] ?? '');
        if (!self::hasWorm($state, $owner) || ($state->battle['tremor_move_turn'][$owner] ?? null) === $turn) return $description;
        $state->battle['tremor_move_turn'][$owner] = $turn;
        foreach (self::ownedMarkers($state, $owner) as $marker) {
            $sequence = $state->battle['tremor_choice_sequence'] = (int)($state->battle['tremor_choice_sequence'] ?? 0) + 1;
            $item = ['id'=>$marker['id'], 'owner'=>$owner, 'mode'=>'move', 'choice_id'=>'move-' . $sequence,
                'from'=>$marker['cell']];
            if (empty($state->battle[self::PENDING])) $state->battle[self::PENDING] = $item + ['queue'=>[], 'resume_turn'=>true];
            else $state->battle[self::PENDING]['queue'][] = $item;
        }
        return $description;
    }

    public static function choose(GameState $state, Engine $engine, string $owner, Command $cmd): Result
    {
        $p = $state->battle[self::PENDING] ?? null;
        if (!$p || $owner !== $p['owner'] || $cmd->get('marker_id') !== $p['id']) return Result::error('Выбор Дрожи устарел или принадлежит другому игроку');
        $row = filter_var($cmd->get('row'), FILTER_VALIDATE_INT);
        $col = filter_var($cmd->get('col'), FILTER_VALIDATE_INT);
        if ($row === false || $col === false || $row < 1 || $row > 6 || $col < 1 || $col > 5) return Result::error('Клетка вне поля');
        $moving = ($p['mode'] ?? '') === 'move';
        if ($moving) {
            if ($cmd->get('choice_id') !== $p['choice_id']) return Result::error('Выбор перемещения устарел');
            [$oldRow, $oldCol] = array_map('intval', explode('_', $p['from']));
            if (max(abs($row-$oldRow), abs($col-$oldCol)) > 1) return Result::error('Можно переместиться только на соседнюю клетку');
            $staying = $row === $oldRow && $col === $oldCol;
            if (!$staying && !self::hasWorm($state, $owner)) return Result::error('На кладбище нет Скелетного червя');
            $markers = ZoneManager::markersAt($state, $p['from']);
            $found = null;
            foreach ($markers as $index=>$marker) {
                if (($marker['id'] ?? null) === $p['id'] && ($marker['type'] ?? '') === 'tremor'
                    && ($marker['owner'] ?? null) === $owner) { $found = $index; break; }
            }
            if ($found === null && !$staying) return Result::error('Маркер больше не находится на выбранной клетке');
            if ($found !== null && !$staying) {
                $marker = $markers[$found]; unset($markers[$found]);
                if ($markers) $state->cell_markers[$p['from']] = array_values($markers);
                else unset($state->cell_markers[$p['from']]);
                $marker['row']=$row; $marker['col']=$col;
                ZoneManager::addMarker($state, $row . '_' . $col, $marker);
            }
        } else {
            ZoneManager::addMarker($state, $row . '_' . $col,
                ['type'=>'tremor', 'id'=>$p['id'], 'owner'=>$owner, 'source'=>$owner, 'row'=>$row, 'col'=>$col]);
        }
        $queue = $p['queue'];
        if ($queue) {
            $next = array_shift($queue);
            $state->battle[self::PENDING] = $next + ['queue'=>$queue, 'resume_turn'=>$p['resume_turn'] ?? false];
        } else {
            unset($state->battle[self::PENDING]);
            if (!empty($p['resume_turn']) && $state->status === 'battle') (new TurnPhaseProcessor($state, $engine))->resume();
        }
        $state->bumpVersion();
        return Result::ok([($moving ? 'tremor_move_resolved:' : 'tremor_created:') . $p['id']]);
    }
}
