<?php
// src/Core/GameRepository.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Загрузка и сохранение партии в БД.
 * Таблица games: ind, host_id, player_id, status, json, version, date.
 */
final class GameRepository
{
    private static bool $schemaChecked = false;

    public function __construct(private Db $db, private array $historyCatalog = [])
    {
        $this->ensurePersistenceVersionColumn();
    }

    public function findById(int $gameId, bool $forUpdate = false): ?GameState
    {
        if ($forUpdate && !$this->db->inTransaction()) throw new \LogicException('A locking read requires a transaction');
        $row = $this->db->fetchOne(
            "SELECT * FROM games WHERE ind = " . (int) $gameId . ($forUpdate ? ' FOR UPDATE' : '')
        );

        if (!$row) {
            return null;
        }

        $rawJson = (string) $row['json'];
        $data = json_decode($rawJson, true);
        if (!is_array($data)) {
            $data = [];
        }

        // Гарантируем наличие обязательных полей
        $data['game_id']   ??= (int) $row['ind'];
        $data['host_id']   ??= (int) $row['host_id'];
        $data['player_id'] ??= (int) $row['player_id'];

        $state = GameState::fromArray($data);
        $state->persistenceJson = $rawJson;
        $state->persistenceVersion = (int) ($row['version'] ?? 0);
        return $state;
    }

    private bool $presentationSave = false;
    private bool $creationSave = false;
    private bool $commandSave = false;

    private function requiresHistory(GameState $state): bool
    {
        return $state->journalSchema !== null || $this->db->fetchOne('SELECT game_id FROM battle_history_matches WHERE game_id='.$state->gameId) !== null;
    }

    public function save(GameState $state): void
    {
        if ($this->requiresHistory($state) && !$this->presentationSave && !$this->creationSave && !($this->commandSave && $state->historyRecorder !== null && $this->db->inTransaction())) {
            throw new \LogicException('Journal matches require locked command execution');
        }
        $json = json_encode(
            $state->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($state->persistenceVersion !== null) {
            $expectedVersion = $state->persistenceVersion;
            $newPersistenceVersion = $expectedVersion + 1;
            $sql = sprintf(
                "UPDATE games SET json = '%s', status = '%s', version = %d WHERE ind = %d AND version = %d",
                $this->db->escape($json),
                $this->db->escape($state->status),
                $newPersistenceVersion,
                $state->gameId,
                $expectedVersion
            );
        } else {
            $newPersistenceVersion = 0;
            $sql = sprintf(
                "UPDATE games SET json = '%s', status = '%s' WHERE ind = %d",
                $this->db->escape($json),
                $this->db->escape($state->status),
                $state->gameId
            );
        }

        $this->db->query($sql);
        if ($state->persistenceVersion !== null && $this->db->affectedRows() !== 1) {
            throw new StaleStateException('Game state was changed by another request');
        }
        $state->persistenceJson = $json;
        $state->persistenceVersion = $newPersistenceVersion;
    }

    public function saveCommittedCommand(
        GameState $state,
        CommandJournalRepository $journal,
        int $journalId
    ): void {
        $this->commitJournalCommand($state, $journal, $journalId, null);
    }

    /** Same journal/CAS transaction, with validation and Engine execution under the game lock. */
    public function executeLockedCommand(GameState $state, CommandJournalRepository $journal, int $journalId, \Closure $apply, string $rejectionCode = 'automatic_command_rejected'): void
    {
        $this->commitJournalCommand($state, $journal, $journalId, $apply, $rejectionCode);
    }

    private function commitJournalCommand(GameState $state, CommandJournalRepository $journal, int $journalId, ?\Closure $apply, string $rejectionCode = 'automatic_command_rejected'): void
    {
        if (!$journal->usesDb($this->db)) {
            throw new \RuntimeException('GameRepository and CommandJournalRepository must use the same DB connection');
        }

        if ($state->persistenceVersion === null) {
            throw new \RuntimeException('Cannot commit command for a state without persistence version');
        }

        $beforeJson = $state->persistenceJson;
        $beforeVersion = $state->persistenceVersion;

        $borrowed = $this->db->isBorrowedTransaction();
        if (!$borrowed) $this->db->beginTransaction();
        try {
            $journalEntry = $journal->requireProcessingForCommit($journalId, $state->gameId, $beforeVersion);
            $working = $state;
            if ($apply !== null) {
                $working = $this->findById($state->gameId, true);
                if ($working === null || $working->persistenceVersion !== $beforeVersion) throw new StaleStateException('State changed before locked execution');
                if ($this->requiresHistory($working)) {
                    $historyRepo = new \Berserk\Core\BattleHistory\HistoryRepository($this->db);
                    $meta = $this->db->fetchOne('SELECT * FROM battle_history_matches WHERE game_id='.$working->gameId.' FOR UPDATE');
                    if ($working->journalSchema !== null && $working->journalSchema !== 1) throw new \LogicException('Unsupported mandatory journal version');
                    if ($meta === null) throw new \LogicException('Missing mandatory journal metadata');
                    if ($meta['recording_status'] !== 'recording' || (bool)$meta['has_final']) {
                        throw new \LogicException('Journal match is not writable');
                    }
                    $open = $this->db->fetchAll("SELECT a.*,EXISTS(SELECT 1 FROM battle_history_events e WHERE e.game_id=a.game_id AND e.event_type='recording_gap.started' AND e.event_seq>=a.started_event_seq) AS contains_gap FROM battle_history_actions a WHERE a.game_id=".$working->gameId." AND a.lifecycle='open' ORDER BY a.started_event_seq");
                    $names=new \Berserk\Core\BattleHistory\JournalCardNames($this->db);
                    $working->historyRecorder = new \Berserk\Core\BattleHistory\Recorder($working->gameId,$journalId,$this->historyCatalog,$open,true,$names->name(...));
                }
                $result = $apply($working);
                if (!$result instanceof Result) throw new \LogicException('Locked command must return Result');
                if (!$result->success) {
                    $journal->markRejected($journalId, $beforeVersion, $rejectionCode, $result->error);
                    if (!$borrowed) $this->db->commit();
                    return;
                }
            } elseif ($this->requiresHistory($working)) {
                throw new \LogicException('Journal requires execution under game lock');
            }
            $this->commandSave=true;
            try { $this->save($working); } finally { $this->commandSave=false; }
            $afterVersion = $working->persistenceVersion;
            if ($afterVersion === null) {
                throw new \RuntimeException('Committed state did not receive persistence version');
            }

            if ($working->historyRecorder !== null) {
                $historyRepo->append($working->gameId,$journalId,$afterVersion,$working->historyRecorder->batch($working));
            }
            $journal->markCommitted($journalId, $beforeVersion, $afterVersion);
            if (!$borrowed) $this->db->commit();
        } catch (TransactionCommitUnknownException $e) {
            $state->persistenceJson = $beforeJson;
            $state->persistenceVersion = $beforeVersion;
            throw $e;
        } catch (\Throwable $e) {
            $rollbackError = null;
            if (!$borrowed && $this->db->inTransaction()) {
                try {
                    $this->db->rollback();
                } catch (TransactionRollbackUnknownException $rollbackException) {
                    $rollbackError = $rollbackException;
                }
            }
            $state->persistenceJson = $beforeJson;
            $state->persistenceVersion = $beforeVersion;
            if ($rollbackError !== null) {
                throw $rollbackError;
            }
            throw $e;
        } finally {
            if (isset($working)) $working->historyRecorder = null;
        }
    }

    /**
     * Создать новую партию. Возвращает GameState с присвоенным game_id.
     */
    public function create(int $hostId, int $playerId): GameState
    {
        $borrowed=$this->db->isBorrowedTransaction();
        if (!$borrowed) $this->db->beginTransaction();
        try {
            $sql = sprintf(
                "INSERT INTO games (host_id, player_id, status, json, date, active)
                 VALUES (%d, %d, 'deck', '{}', NOW(), 1)",
                $hostId,
                $playerId
            );
            $this->db->query($sql);

            $gameId = $this->db->lastInsertId();
            $state = new GameState($gameId, $hostId, $playerId);
            $state->status = 'mode';
            $state->journalSchema = 1;
            foreach ($state->players as $participant) PlaymatCatalog::assign($participant);
            $state->persistenceVersion = 0;

            $this->creationSave=true;
            try { $this->save($state); } finally { $this->creationSave=false; }

            (new \Berserk\Core\BattleHistory\HistoryRepository($this->db))->initialize($gameId,'recording','incomplete');
            if (!$borrowed) $this->db->commit();
            return $state;
        } catch (\Throwable $error) {
            if (!$borrowed && $this->db->inTransaction()) $this->db->rollback();
            throw $error;
        }
    }

    /** Lock the latest JSON, alter only the identified participant, retain CAS protection. */
    public function setPlaymat(int $gameId, int $userId, string $id): GameState
    {
        if (!isset(PlaymatCatalog::ITEMS[$id])) throw new \InvalidArgumentException('invalid_playmat');
        $this->db->beginTransaction();
        try {
            $state = $this->findById($gameId, true);
            $actor = $state?->getPlayerKeyByUserId($userId);
            if ($state === null || $actor === null || !in_array($userId, [$state->hostId, $state->playerId], true))
                throw new \InvalidArgumentException('not_participant');
            if (!in_array($state->status,['mode','settings','draft','deck','view','turn','side','deal','place','battle'],true) || $state->winner !== null) throw new \InvalidArgumentException('match_finished');
            if ($state->getPlayer($actor)->playmatId !== $id) {
                $state->getPlayer($actor)->playmatId = $id;
                $this->presentationSave=true;
                try { $this->save($state); } finally { $this->presentationSave=false; }
            }
            $this->db->commit();
            return $state;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollback();
            throw $error;
        }
    }

    /** Cosmetic preparation preference: no Engine, clocks, Draft or battle command bypass. */
    public function setPreparationSound(int $gameId, int $userId, bool $enabled): GameState
    {
        $this->db->beginTransaction();
        try {
            $state=$this->findById($gameId,true);
            $actor=$state?->getPlayerKeyByUserId($userId);
            if (!$state || !$actor || !in_array($userId,[$state->hostId,$state->playerId],true)) throw new \InvalidArgumentException('not_participant');
            if (!in_array($state->status,['mode','settings','draft','deck','view','turn','side','deal','place'],true) || $state->winner!==null) throw new \InvalidArgumentException('not_preparation');
            if ($state->getPlayer($actor)->soundEnabled!==$enabled) {
                $state->getPlayer($actor)->soundEnabled=$enabled;
                $this->presentationSave=true;
                try { $this->save($state); } finally { $this->presentationSave=false; }
            }
            $this->db->commit();
            return $state;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollback();
            throw $e;
        }
    }

    private function ensurePersistenceVersionColumn(): void
    {
        if (self::$schemaChecked) {
            return;
        }

        $column = $this->db->fetchOne("SHOW COLUMNS FROM games LIKE 'version'");
        if ($column === null) {
            throw new \RuntimeException('Missing games.version; manual migration required');
        }

        self::$schemaChecked = true;
    }
}
