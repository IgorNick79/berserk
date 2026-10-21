<?php
declare(strict_types=1);
namespace Berserk\Auth;
use Berserk\Core\Db;

final class GameAccess
{
    public static function resolve(Db $db, int $game, int $account): string
    {
        if ($game <= 0 || $account <= 0) throw new \DomainException('forbidden',403);
        $row = $db->fetchOne("SELECT r.creator_account_id,r.accepted_account_id,g.host_id,g.player_id,g.json FROM lobby_rooms r JOIN games g ON g.ind=r.game_id WHERE r.state='started' AND g.ind=".$game);
        if (!$row) throw new \DomainException('forbidden',403);
        $host = (int)$row['creator_account_id']; $player = (int)$row['accepted_account_id'];
        if ($host === $player || !in_array($account,[$host,$player],true)) throw new \DomainException('forbidden',403);
        $json = json_decode($row['json'],true,512,JSON_THROW_ON_ERROR);
        if ((int)$row['host_id'] !== $host || (int)$row['player_id'] !== $player
            || ($json['game_id'] ?? null) !== $game || ($json['host_id'] ?? null) !== $host || ($json['player_id'] ?? null) !== $player
            || ($json['players']['host']['user_id'] ?? null) !== $host || ($json['players']['player']['user_id'] ?? null) !== $player) {
            throw new \DomainException('participant_mismatch',403);
        }
        return $account === $host ? 'host' : 'player';
    }

    public static function legacyJournal(Db $db, int $game): bool
    {
        // A binding is never sufficient for an account-owned game, even with matching IDs.
        return $game > 0 && $db->fetchOne('SELECT id FROM lobby_rooms WHERE game_id='.$game) === null;
    }
}
