<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;

/** Server-owned explicit allowlist. Never constructed from HTTP or enabled by environment defaults. */
final class ExperimentalHistory
{
    public function __construct(private array $gameIds, public readonly array $catalog, public readonly array $journalGameIds = [])
    {
        foreach ($gameIds as $id) if (!is_int($id) || $id <= 0) throw new \InvalidArgumentException('Explicit positive match IDs required');
    }
    public function includes(int $gameId): bool { return in_array($gameId,$this->gameIds,true); }
}
