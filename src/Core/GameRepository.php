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

    public function __construct(private Db $db)
    {
        $this->ensurePersistenceVersionColumn();
    }

    public function findById(int $gameId): ?GameState
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM games WHERE ind = " . (int) $gameId
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
        $state->persistenceVersion = 0;

        $this->save($state);

        return $state;
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
