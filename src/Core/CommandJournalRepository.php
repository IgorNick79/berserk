<?php
// src/Core/CommandJournalRepository.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Persistence journal for AJAX gameplay command lifecycle.
 *
 * This is operational recovery/idempotency state, not gameplay state and not
 * a replacement for GameLog.
 */
final class CommandJournalRepository
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_FAILED = 'failed';

    /** @var array<string, bool> */
    private const TERMINAL_STATUSES = [
        self::STATUS_COMMITTED => true,
        self::STATUS_REJECTED => true,
        self::STATUS_CONFLICT => true,
        self::STATUS_FAILED => true,
    ];

    private bool $schemaChecked = false;

    public function __construct(private Db $db)
    {
        $this->ensureSchema();
    }

    /**
     * @return array<string, mixed>
     */
    public function registerProcessing(
        int $gameId,
        string $playerKey,
        string $commandId,
        string $commandType,
        array $payload,
        int $expectedVersion
    ): array {
        $this->validatePlayerKey($playerKey);
        $this->validateIdentifier($commandId, 'command_id', 64);
        $this->validateIdentifier($commandType, 'command_type', 64);

        $payloadHash = self::payloadHash($payload);
        $now = self::sqlNow();

        try {
            $this->db->query(sprintf(
                "INSERT INTO battle_command_journal
                    (game_id, player_key, command_id, command_type, payload_hash, expected_version, status, created_at, updated_at)
                 VALUES
                    (%d, '%s', '%s', '%s', '%s', %d, '%s', %s, %s)",
                $gameId,
                $this->db->escape($playerKey),
                $this->db->escape($commandId),
                $this->db->escape($commandType),
                $this->db->escape($payloadHash),
                $expectedVersion,
                self::STATUS_PROCESSING,
                $now,
                $now
            ));
        } catch (DbQueryException $e) {
            if ($e->getCode() !== 1062) {
                throw $e;
            }

            $record = $this->find($gameId, $playerKey, $commandId);
            if ($record === null) {
                throw $e;
            }

            $this->assertSameCommand($record, $commandType, $payloadHash, $expectedVersion);
            $record['is_new'] = false;
            return $record;
        }

        $record = $this->find($gameId, $playerKey, $commandId);
        if ($record === null) {
            throw new CommandJournalException('Command journal row was not created');
        }

        $this->assertSameCommand($record, $commandType, $payloadHash, $expectedVersion);
        $record['is_new'] = true;

        return $record;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $gameId, string $playerKey, string $commandId): ?array
    {
        $this->validatePlayerKey($playerKey);
        $this->validateIdentifier($commandId, 'command_id', 64);

        return $this->db->fetchOne(sprintf(
            "SELECT * FROM battle_command_journal
             WHERE game_id = %d AND player_key = '%s' AND command_id = '%s'
             LIMIT 1",
            $gameId,
            $this->db->escape($playerKey),
            $this->db->escape($commandId)
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $journalId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM battle_command_journal WHERE id = " . (int) $journalId . " LIMIT 1"
        );
    }

    public function findProcessingAutoAck(int $gameId, int $waitId, int $exceptId = 0): ?array
    {
        $prefix = $this->db->escape('p2a:' . $waitId . ':');
        return $this->db->fetchOne("SELECT * FROM battle_command_journal WHERE game_id = " . (int) $gameId
            . " AND command_id LIKE '" . $prefix . "%' AND status = 'processing' AND id <> " . (int) $exceptId . ' LIMIT 1');
    }

    public function findProcessingTurnEnd(int $gameId, int $periodId, int $exceptId = 0): ?array
    {
        $prefix = $this->db->escape('t3e:' . $periodId . ':');
        return $this->db->fetchOne("SELECT * FROM battle_command_journal WHERE game_id = " . (int) $gameId
            . " AND command_id LIKE '" . $prefix . "%' AND status = 'processing' AND id <> " . (int) $exceptId . ' LIMIT 1');
    }

    public function latestTurnEndAttempt(int $gameId, int $periodId, string $owner, int $version): ?array
    {
        $this->validatePlayerKey($owner);
        $base = $this->db->escape('t3e:' . $periodId . ':' . $owner . ':' . $version);
        return $this->db->fetchOne("SELECT * FROM battle_command_journal WHERE game_id = " . (int) $gameId
            . " AND (command_id = '" . $base . "' OR command_id LIKE '" . $base . ":%') ORDER BY id DESC LIMIT 1");
    }

    public function statusFor(int $gameId, string $playerKey, string $commandId): ?string
    {
        $record = $this->find($gameId, $playerKey, $commandId);
        return $record === null ? null : (string) $record['status'];
    }

    public function usesDb(Db $db): bool
    {
        return $this->db->isSameConnection($db);
    }

    /**
     * @return array<string, mixed>
     */
    public function requireProcessingForCommit(int $journalId, int $gameId, int $expectedVersion): array
    {
        $record = $this->db->fetchOne(
            "SELECT * FROM battle_command_journal WHERE id = " . (int) $journalId . " LIMIT 1 FOR UPDATE"
        );
        if ($record === null) {
            throw new CommandJournalException('Command journal row not found');
        }

        if ((string) $record['status'] !== self::STATUS_PROCESSING) {
            throw new CommandJournalException('Command journal row is not processing');
        }

        if ((int) $record['game_id'] !== $gameId) {
            throw new CommandJournalException('Command journal row belongs to another game');
        }

        if ((int) $record['expected_version'] !== $expectedVersion) {
            throw new CommandJournalException('Command journal expected_version does not match state');
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function markCommitted(int $journalId, int $beforeVersion, int $afterVersion): array
    {
        $record = $this->findById($journalId);
        if ($record === null) {
            throw new CommandJournalException('Command journal row not found');
        }

        if ((int) $record['expected_version'] !== $beforeVersion) {
            throw new CommandJournalException('Command journal expected_version does not match committed before_version');
        }

        return $this->markTerminal(
            $journalId,
            self::STATUS_COMMITTED,
            $beforeVersion,
            $afterVersion,
            null,
            null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function markRejected(int $journalId, int $version, ?string $errorCode = null, ?string $errorMessage = null): array
    {
        return $this->markTerminal(
            $journalId,
            self::STATUS_REJECTED,
            $version,
            $version,
            $errorCode,
            $errorMessage
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function markConflict(int $journalId, int $currentVersion, ?string $errorCode = null, ?string $errorMessage = null): array
    {
        return $this->markTerminal(
            $journalId,
            self::STATUS_CONFLICT,
            $currentVersion,
            $currentVersion,
            $errorCode,
            $errorMessage
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function markFailed(int $journalId, ?int $beforeVersion = null, ?string $errorCode = null): array
    {
        return $this->markTerminal(
            $journalId,
            self::STATUS_FAILED,
            $beforeVersion,
            $beforeVersion,
            $errorCode,
            null
        );
    }

    public function isTerminalStatus(string $status): bool
    {
        return isset(self::TERMINAL_STATUSES[$status]);
    }

    public static function payloadHash(array $payload): string
    {
        self::sortRecursive($payload);
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->assertGamesTableIsTransactional();

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS battle_command_journal (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                game_id INT NOT NULL,
                player_key VARCHAR(16) NOT NULL,
                command_id VARCHAR(64) NOT NULL,
                command_type VARCHAR(64) NOT NULL,
                payload_hash CHAR(64) NOT NULL,
                expected_version INT NOT NULL,
                before_version INT NULL,
                after_version INT NULL,
                status VARCHAR(16) NOT NULL,
                error_code VARCHAR(64) NULL,
                error_message TEXT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                finished_at DATETIME(6) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_battle_command (game_id, player_key, command_id),
                KEY idx_battle_command_status (game_id, status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->assertSchemaCompatible();

        $this->schemaChecked = true;
    }

    private function assertSchemaCompatible(): void
    {
        $journal = $this->db->fetchOne("SHOW TABLE STATUS LIKE 'battle_command_journal'");
        if ($journal === null || strtolower((string) ($journal['Engine'] ?? '')) !== 'innodb') {
            throw new CommandJournalException('battle_command_journal must use transactional InnoDB storage');
        }

        $requiredColumns = [
            'id',
            'game_id',
            'player_key',
            'command_id',
            'command_type',
            'payload_hash',
            'expected_version',
            'before_version',
            'after_version',
            'status',
            'error_code',
            'error_message',
            'created_at',
            'updated_at',
            'finished_at',
        ];

        $columns = $this->db->fetchAll("SHOW COLUMNS FROM battle_command_journal");
        $present = [];
        foreach ($columns as $column) {
            $present[(string) $column['Field']] = true;
        }

        foreach ($requiredColumns as $column) {
            if (!isset($present[$column])) {
                throw new CommandJournalException('battle_command_journal schema is missing required column: ' . $column);
            }
        }

        $indexes = $this->db->fetchAll("SHOW INDEX FROM battle_command_journal");
        $uniqueIndexes = [];
        foreach ($indexes as $index) {
            if ((int) ($index['Non_unique'] ?? 1) !== 0) {
                continue;
            }
            $keyName = (string) ($index['Key_name'] ?? '');
            $uniqueIndexes[$keyName][(int) $index['Seq_in_index']] = (string) $index['Column_name'];
        }

        $hasCommandUnique = false;
        foreach ($uniqueIndexes as $columns) {
            ksort($columns);
            if (array_values($columns) === ['game_id', 'player_key', 'command_id']) {
                $hasCommandUnique = true;
                break;
            }
        }

        if (!$hasCommandUnique) {
            throw new CommandJournalException('battle_command_journal schema is missing unique command constraint');
        }
    }

    private function assertGamesTableIsTransactional(): void
    {
        $games = $this->db->fetchOne("SHOW TABLE STATUS LIKE 'games'");
        if ($games === null) {
            throw new CommandJournalException('games table does not exist');
        }

        if (strtolower((string) ($games['Engine'] ?? '')) !== 'innodb') {
            throw new CommandJournalException('games table must use transactional InnoDB storage for command journal atomicity');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function markTerminal(
        int $journalId,
        string $status,
        ?int $beforeVersion,
        ?int $afterVersion,
        ?string $errorCode,
        ?string $errorMessage
    ): array {
        if (!$this->isTerminalStatus($status)) {
            throw new CommandJournalException('Unsupported terminal command status');
        }

        $beforeSql = $beforeVersion === null ? 'NULL' : (string) $beforeVersion;
        $afterSql = $afterVersion === null ? 'NULL' : (string) $afterVersion;
        $errorCodeSql = $errorCode === null ? 'NULL' : "'" . $this->db->escape($this->limit($errorCode, 64)) . "'";
        $errorMessageSql = $errorMessage === null ? 'NULL' : "'" . $this->db->escape($this->limit($errorMessage, 1000)) . "'";

        $this->db->query(sprintf(
            "UPDATE battle_command_journal
             SET status = '%s',
                 before_version = %s,
                 after_version = %s,
                 error_code = %s,
                 error_message = %s,
                 updated_at = %s,
                 finished_at = %s
             WHERE id = %d AND status = '%s'",
            $status,
            $beforeSql,
            $afterSql,
            $errorCodeSql,
            $errorMessageSql,
            self::sqlNow(),
            self::sqlNow(),
            $journalId,
            self::STATUS_PROCESSING
        ));

        if ($this->db->affectedRows() !== 1) {
            $existing = $this->findById($journalId);
            if ($existing === null) {
                throw new CommandJournalException('Command journal row not found');
            }

            if ($this->isTerminalStatus((string) $existing['status'])) {
                throw new CommandJournalException('Command journal terminal status cannot be changed');
            }

            throw new CommandJournalException('Command journal status transition failed');
        }

        $updated = $this->findById($journalId);
        if ($updated === null) {
            throw new CommandJournalException('Command journal row disappeared after update');
        }

        return $updated;
    }

    private function validatePlayerKey(string $playerKey): void
    {
        if (!in_array($playerKey, [GameState::PLAYER_HOST, GameState::PLAYER_PLAYER], true)) {
            throw new CommandJournalException('Invalid player key');
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function assertSameCommand(array $record, string $commandType, string $payloadHash, int $expectedVersion): void
    {
        if (
            (string) $record['payload_hash'] !== $payloadHash
            || (string) $record['command_type'] !== $commandType
            || (int) $record['expected_version'] !== $expectedVersion
        ) {
            throw new CommandJournalException('Command id already exists with different command data');
        }
    }

    private function validateIdentifier(string $value, string $field, int $maxLength): void
    {
        $length = strlen($value);
        if ($length < 1 || $length > $maxLength || !preg_match('/^[A-Za-z0-9_.:-]+$/', $value)) {
            throw new CommandJournalException('Invalid ' . $field);
        }
    }

    private function limit(string $value, int $maxLength): string
    {
        return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
    }

    private static function sqlNow(): string
    {
        return 'CURRENT_TIMESTAMP(6)';
    }

    private static function sortRecursive(array &$value): void
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
    }
}
