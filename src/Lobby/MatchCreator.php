<?php
declare(strict_types=1);
namespace Berserk\Lobby;

use Berserk\Core\{GameState,PlaymatCatalog};

/** Minimal initial-state adapter. Caller owns the SINGLE room/game transaction. */
final class MatchCreator
{
    public static function create(RoomTransaction $transaction, int $roomId, int $creator, int $accepted, ?callable $catalogFactory = null): int
    {
        if ($creator <= 0 || $accepted <= 0 || $creator === $accepted) throw new \LogicException('Invalid participants');
        $db = $transaction->claimMatch($roomId, $creator, $accepted);
        $stmt = $db->prepare("INSERT INTO games(host_id,player_id,status,json,version,date,active) VALUES(?,?,'mode','{}',1,UTC_TIMESTAMP(),1)");
        try { $stmt->bind_param('ii',$creator,$accepted); $stmt->execute(); } finally { $stmt->close(); }
        $id = (int)$db->insert_id;
        $state = new GameState($id,$creator,$accepted);
        foreach ($state->players as $participant) PlaymatCatalog::assign($participant);
        $setup=$transaction->setup();
        $history = \Berserk\Core\BattleHistory\HistoryRuntime::load();
        if ($history->controlsRoom($roomId)) {
            \Berserk\Core\Db::withTransaction($db, static function (\Berserk\Core\Db $connection) use ($id): void {
                (new \Berserk\Core\BattleHistory\HistoryRepository($connection))->initialize($id,'recording','incomplete');
            });
            $history=$history->withControlGame($id);
        }
        if ($setup !== null && $setup['mode'] === 'lucktest' && ($setup['settings']['lucktest']['quick_game'] ?? false) === true && $history->records($id)) {
            // Private uncommitted seed for the existing locked executor; only final Battle is committed.
            $result = (new \Berserk\Core\Prepare\PrepareProcessor($state))->chooseMode('host', new \Berserk\Core\Command('choose_mode', ['mode'=>'lucktest']));
            if (!$result->success) throw new \LogicException('Cannot initialize LuckTest');
            $state->settings = \Berserk\Core\GameSettings::fromArray($setup['settings']);
            RoomSetup::attachDebug($state,$setup);
            $seed = json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $stmt = $db->prepare("UPDATE games SET status='settings',json=? WHERE ind=?");
            try { $stmt->bind_param('si', $seed, $id); $stmt->execute(); } finally { $stmt->close(); }
            \Berserk\Core\Db::withTransaction($db, function (\Berserk\Core\Db $connection) use ($transaction, $history, $id, $roomId): void {
                $transaction->assertActive();
                $repo = $history->repository($connection);
                $working = $repo->findById($id);
                $journal = new \Berserk\Core\CommandJournalRepository($connection);
                $cmd = new \Berserk\Core\Command('confirm_settings');
                $entry = $journal->registerProcessing($id, 'host', 'lobby:'.$roomId.':'.$id, $cmd->type, [], $working->persistenceVersion);
                $repo->executeLockedCommand($working, $journal, (int) $entry['id'],
                    fn(GameState $locked) => (new \Berserk\Core\Engine($connection))->apply($locked, 'host', $cmd), 'prepare_rejected');
                if (($journal->findById((int) $entry['id'])['status'] ?? null) !== 'committed') throw new \DomainException('Не удалось завершить подготовку LuckTest', 400);
            });
            $transaction->recordMatch($id);
            return $id;
        }
        if ($setup!==null) RoomSetup::apply($state,$setup,$setup['mode']==='system'?null:($catalogFactory ? $catalogFactory() : null));
        // Same persisted initial state as GameRepository::create(), including CAS version 1.
        $json = json_encode($state->toArray(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $stmt = $db->prepare('UPDATE games SET status=?,json=? WHERE ind=?');
        try { $stmt->bind_param('ssi',$state->status,$json,$id); $stmt->execute(); } finally { $stmt->close(); }
        $transaction->recordMatch($id);
        return $id;
    }
}
