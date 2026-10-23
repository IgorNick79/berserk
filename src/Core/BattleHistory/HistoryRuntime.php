<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;

use Berserk\Core\{Db,GameRepository};

/** Local server configuration only. No request parameter can enable recording or preview. */
final class HistoryRuntime
{
    private static ?self $requestRuntime = null;
    public function __construct(private array $config) {}

    public static function load(?Db $db = null): self
    {
        if ($db === null && self::$requestRuntime !== null) return self::$requestRuntime;
        $file = dirname(__DIR__, 3).'/config/battle_history.local.php';
        $runtime = new self(is_file($file) ? require $file : []);
        if ($db !== null) {
            $rooms=$runtime->controlRooms();
            if ((($runtime->config['recording_enabled']??false)===true || $runtime->journalEnabled()) && $rooms) {
                // Only games enrolled at creation qualify. Adding an already-started room cannot start history.
                $rows=$db->fetchAll("SELECT r.game_id FROM lobby_rooms r JOIN battle_history_matches h ON h.game_id=r.game_id WHERE r.state='started' AND r.id IN (".implode(',',$rooms).")");
                foreach ($rows as $row) $runtime=$runtime->withControlGame((int)$row['game_id']);
            }
            self::$requestRuntime=$runtime;
        }
        return $runtime;
    }

    public function journalEnabled(): bool { return ($this->config['journal_enabled'] ?? false) === true; }
    private function controlRooms(): array
    {
        $rooms=$this->config['control_room_ids']??[];
        if (!is_array($rooms)) throw new \InvalidArgumentException('Explicit control room IDs required');
        foreach ($rooms as $id) if (!is_int($id) || $id<1) throw new \InvalidArgumentException('Explicit control room IDs required');
        return $rooms;
    }
    public function controlsRoom(int $room): bool
    {
        return ($this->config['recording_enabled']??false)===true && $this->journalEnabled()
            && in_array($room,$this->controlRooms(),true);
    }
    public function withControlGame(int $game): self
    {
        if ($game<1) throw new \InvalidArgumentException('Positive game ID required');
        $config=$this->config;
        foreach (['record_game_ids','journal_game_ids'] as $key) $config[$key]=array_values(array_unique([...($config[$key]??[]),$game]));
        return new self($config);
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

    public function journals(int $gameId): bool
    {
        return ($this->config['journal_enabled'] ?? false) === true && $this->allowed('journal_game_ids', $gameId);
    }

    public function protects(int $gameId): bool
    {
        return $this->allowed('record_game_ids',$gameId) || $this->allowed('journal_game_ids',$gameId);
    }
    public function invitations(): ?JournalInvitations
    {
        if (($this->config['journal_access_enabled']??false)!==true) return null;
        $path=$this->config['journal_access_directory']??null;
        return is_string($path) ? new JournalInvitations($path) : null;
    }
    public function localAccessHttp(): bool
    {
        return ($this->config['journal_access_local_http']??false)===true;
    }

    /** Never accepts an HTTP side/user ID. Test bindings are provisioned server-side only. */
    public function journalSide(int $gameId, int $hostId, int $playerId, array $session): ?string
    {
        if (!$this->journals($gameId)) return null;
        $user = $this->principal($session);
        if ($user > 0 && $user === $hostId) return 'host';
        if ($user > 0 && $user === $playerId) return 'player';
        $token = $session['berserk_battle_journal_binding'] ?? null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        if (($this->config['journal_access_enabled']??false)===true) {
            try { return $this->invitations()?->side($token,$gameId); }
            catch (\Throwable) { return null; } // Missing/corrupt private ledger denies access.
        }
        $binding = $this->config['journal_test_bindings'][$token] ?? null;
        if (!is_array($binding) || ($binding['game_id'] ?? null) !== $gameId
            || !is_int($binding['expires_at'] ?? null) || $binding['expires_at'] < time()) return null;
        return in_array($binding['side'] ?? null,['host','player'],true) ? $binding['side'] : null;
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
            ? new ExperimentalHistory($this->config['record_game_ids'] ?? [], $this->config['catalog'] ?? [], ($this->config['journal_enabled'] ?? false) === true ? ($this->config['journal_game_ids'] ?? []) : []) : null;
        return new GameRepository($db, $gate);
    }
}
