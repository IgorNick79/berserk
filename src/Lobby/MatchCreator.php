<?php
declare(strict_types=1);
namespace Berserk\Lobby;

use Berserk\Core\{GameState,PlaymatCatalog};

/** Minimal initial-state adapter. Caller owns the SINGLE room/game transaction. */
final class MatchCreator
{
    public static function create(RoomTransaction $transaction, int $roomId, int $creator, int $accepted): int
    {
        if ($creator <= 0 || $accepted <= 0 || $creator === $accepted) throw new \LogicException('Invalid participants');
        $db = $transaction->claimMatch($roomId, $creator, $accepted);
        $stmt = $db->prepare("INSERT INTO games(host_id,player_id,status,json,version,date,active) VALUES(?,?,'mode','{}',1,UTC_TIMESTAMP(),1)");
        try { $stmt->bind_param('ii',$creator,$accepted); $stmt->execute(); } finally { $stmt->close(); }
        $id = (int)$db->insert_id;
        $state = new GameState($id,$creator,$accepted);
        foreach ($state->players as $participant) PlaymatCatalog::assign($participant);
        // Same persisted initial state as GameRepository::create(), including CAS version 1.
        $json = json_encode($state->toArray(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $stmt = $db->prepare('UPDATE games SET json=? WHERE ind=?');
        try { $stmt->bind_param('si',$json,$id); $stmt->execute(); } finally { $stmt->close(); }
        $transaction->recordMatch($id);
        return $id;
    }
}
