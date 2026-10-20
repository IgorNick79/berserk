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

    public function __construct(private Db $db, private ?\Berserk\Core\BattleHistory\ExperimentalHistory $history = null)
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

    public function save(GameState $state): void
    {
        if ($this->history?->includes($state->gameId) && in_array($state->status,['battle','game_over'],true) && $state->historyRecorder === null) {
            throw new \LogicException('Experimental history matches require locked journal execution');
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

        $this->db->beginTransaction();
        try {
            $journal->requireProcessingForCommit($journalId, $state->gameId, $beforeVersion);
            $working = $state;
            if ($apply !== null) {
                $working = $this->findById($state->gameId, true);
                if ($working === null || $working->persistenceVersion !== $beforeVersion) throw new StaleStateException('State changed before locked execution');
                if ($this->history?->includes($working->gameId)) {
                    $historyRepo = new \Berserk\Core\BattleHistory\HistoryRepository($this->db);
                    $meta = $this->db->fetchOne('SELECT * FROM battle_history_matches WHERE game_id='.$working->gameId.' FOR UPDATE');
                    if ($meta === null) {
                        if ($working->status !== 'place') throw new \LogicException('Cannot enable history in an existing battle');
                        $needsHistoryInitialization = true;
                    } elseif ($meta['recording_status'] !== 'recording' || (bool)$meta['has_final']) {
                        throw new \LogicException('Experimental history match is not recording');
                    } elseif ($working->status !== 'place' && !(bool)$meta['has_initial']) {
                        throw new \LogicException('Cannot resume experimental history without initial capture');
                    }
                    $open = $this->db->fetchAll('SELECT * FROM battle_history_actions WHERE game_id='.$working->gameId." AND lifecycle='open' ORDER BY started_event_seq");
                    $working->historyRecorder = new \Berserk\Core\BattleHistory\Recorder($working->gameId,$journalId,$this->history->catalog,$open);
                }
                $result = $apply($working);
                if (!$result instanceof Result) throw new \LogicException('Locked command must return Result');
                if (!$result->success) {
                    $journal->markRejected($journalId, $beforeVersion, $rejectionCode, $result->error);
                    $this->db->commit();
                    return;
                }
            } elseif ($this->history?->includes($working->gameId)) {
                throw new \LogicException('Experimental history requires execution under game lock');
            }
            if (!empty($needsHistoryInitialization)) $historyRepo->initialize($working->gameId,'recording','incomplete');
            $this->save($working);
            $afterVersion = $working->persistenceVersion;
            if ($afterVersion === null) {
                throw new \RuntimeException('Committed state did not receive persistence version');
            }

            if ($working->historyRecorder !== null) {
                $historyRepo->append($working->gameId,$journalId,$afterVersion,$working->historyRecorder->batch($working));
            }
            $journal->markCommitted($journalId, $beforeVersion, $afterVersion);
            $this->db->commit();
        } catch (TransactionCommitUnknownException $e) {
            $state->persistenceJson = $beforeJson;
            $state->persistenceVersion = $beforeVersion;
            throw $e;
        } catch (\Throwable $e) {
            $rollbackError = null;
            if ($this->db->inTransaction()) {
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
        foreach ($state->players as $participant) PlaymatCatalog::assign($participant);
        $state->persistenceVersion = 0;

        $this->save($state);

        return $state;
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
            if ($state->status !== 'battle' || $state->winner !== null) throw new \InvalidArgumentException('battle_not_active');
            if ($state->getPlayer($actor)->playmatId !== $id) {
                $state->getPlayer($actor)->playmatId = $id;
                $this->save($state);
            }
            $this->db->commit();
            return $state;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollback();
            throw $error;
        }
    }

    private function ensurePersistenceVersionColumn(): void
    {
        if (self::$schemaChecked) {
            return;
        }

        $column = $this->db->fetchOne("SHOW COLUMNS FROM games LIKE 'version'");
        if ($column === null) {
            try {
                $this->db->query("ALTER TABLE games ADD COLUMN version INT NOT NULL DEFAULT 0 AFTER json");
            } catch (\RuntimeException $e) {
                $column = $this->db->fetchOne("SHOW COLUMNS FROM games LIKE 'version'");
                if ($column === null) {
                    throw $e;
                }
            }
        }

        self::$schemaChecked = true;
    }
}
