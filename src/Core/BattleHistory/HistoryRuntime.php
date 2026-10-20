<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;

use Berserk\Core\{Db,GameRepository};

/** Local server configuration only. No request parameter can enable recording or preview. */
final class HistoryRuntime
{
    public function __construct(private array $config) {}

    public static function load(): self
    {
        $file = dirname(__DIR__, 3).'/config/battle_history.local.php';
        return new self(is_file($file) ? require $file : []);
    }

    private function allowed(string $key, int $id): bool
    {
        return $id > 0 && in_array($id, $this->config[$key] ?? [], true);
    }

    public function records(int $gameId): bool
    {
        return ($this->config['recording_enabled'] ?? false) === true && $this->allowed('record_game_ids', $gameId);
    }

    public function previews(int $gameId): bool
    {
        return ($this->config['preview_enabled'] ?? false) === true && $this->allowed('preview_game_ids', $gameId);
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
        $gate = ($this->config['recording_enabled'] ?? false) === true
            ? new ExperimentalHistory($this->config['record_game_ids'] ?? [], $this->config['catalog'] ?? []) : null;
        return new GameRepository($db, $gate);
    }
}
