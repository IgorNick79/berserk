<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;

use Berserk\Core\{Db,GameRepository};

/** Journal lifecycle is intrinsic to persisted matches. Configuration is for preview only. */
final class HistoryRuntime
{
    private static ?self $requestRuntime = null;
    public function __construct(private array $config, private ?Db $db = null) {}

    public static function load(?Db $db = null): self
    {
        if ($db === null && self::$requestRuntime !== null) return self::$requestRuntime;
        $file = dirname(__DIR__, 3).'/config/battle_history.local.php';
        $runtime = new self(is_file($file) ? require $file : [], $db);
        if ($db !== null) self::$requestRuntime = $runtime;
        return $runtime;
    }

    private function allowed(string $key, int $id): bool
    {
        return $id > 0 && in_array($id, $this->config[$key] ?? [], true);
    }

    /** Metadata or the immutable creation-version marker, never a configuration allowlist. */
    public function records(int $gameId): bool
    {
        if ($gameId <= 0 || $this->db === null) return false;
        $row=$this->db->fetchOne("SELECT g.json, h.game_id AS history_id FROM games g LEFT JOIN battle_history_matches h ON h.game_id=g.ind WHERE g.ind=$gameId");
        return $row !== null && ($row['history_id'] !== null || isset(json_decode($row['json'],true)['journal_schema']));
    }

    public function previews(int $gameId): bool
    {
        return ($this->config['preview_enabled'] ?? false) === true && $this->allowed('preview_game_ids', $gameId);
    }

    public function journals(int $gameId): bool { return $this->records($gameId); }

    /** Identity is supplied only by the existing authenticated account session. */
    public function journalSide(int $gameId, int $hostId, int $playerId, array $session): ?string
    {
        $user=$this->principal($session);
        if ($user > 0 && $user === $hostId) return 'host';
        if ($user > 0 && $user === $playerId) return 'player';
        return null;
    }

    public function admin(int $userId): bool { return $this->allowed('admin_user_ids', $userId); }

    /** This principal must be written by trusted authentication, never by the prototype role route. */
    public function principal(array $session): int
    {
        $value = $session['berserk_authenticated_user_id'] ?? null;
        return is_int($value) && $value > 0 ? $value : 0;
    }

    public function repository(Db $db): GameRepository
    {
        return new GameRepository($db, $this->config['catalog'] ?? []);
    }
}
