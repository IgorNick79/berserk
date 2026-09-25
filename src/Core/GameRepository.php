<?php
// src/Core/GameRepository.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Загрузка и сохранение партии в БД.
 * Таблица games: ind, host_id, player_id, status, json, date.
 */
final class GameRepository
{
    public function __construct(private Db $db) {}

    public function findById(int $gameId): ?GameState
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM games WHERE ind = " . (int) $gameId
        );

        if (!$row) {
            return null;
        }

        $data = json_decode($row['json'], true);
        if (!is_array($data)) {
            $data = [];
        }

        // Гарантируем наличие обязательных полей
        $data['game_id']   ??= (int) $row['ind'];
        $data['host_id']   ??= (int) $row['host_id'];
        $data['player_id'] ??= (int) $row['player_id'];

        return GameState::fromArray($data);
    }

    public function save(GameState $state): void
    {
        $json = json_encode(
            $state->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $sql = sprintf(
            "UPDATE games SET json = '%s', status = '%s' WHERE ind = %d",
            $this->db->escape($json),
            $this->db->escape($state->status),
            $state->gameId
        );

        $this->db->query($sql);
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

        $this->save($state);

        return $state;
    }
}