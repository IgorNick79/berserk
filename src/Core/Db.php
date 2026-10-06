<?php
// src/Core/Db.php

declare(strict_types=1);

namespace Berserk\Core;

use mysqli;
use mysqli_result;

/**
 * Тонкая обёртка над mysqli.
 * Никакой логики — только подключение и выполнение запросов.
 */
final class Db
{
    private mysqli $connection;

    public function __construct(array $config)
    {
        $this->connection = new mysqli(
            $config['host'],
            $config['user'],
            $config['pass'],
            $config['name'],
        );

        if ($this->connection->connect_errno) {
            throw new \RuntimeException(
                'DB connect failed: ' . $this->connection->connect_error
            );
        }

        $this->connection->set_charset('utf8mb4');
    }

    public function query(string $sql): mysqli_result|bool
    {
        $result = $this->connection->query($sql);
        if ($result === false) {
            throw new \RuntimeException('Query failed: ' . $this->connection->error . ' | SQL: ' . $sql);
        }
        return $result;
    }

    /**
     * Одна строка или null.
     */
    public function fetchOne(string $sql): ?array
    {
        $result = $this->query($sql);
        if ($result === true || $result === false) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return $row ?: null;
    }

    /**
     * Все строки.
     */
    public function fetchAll(string $sql): array
    {
        $result = $this->query($sql);
        if ($result === true || $result === false) {
            return [];
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    /**
     * Экранирование строки для вставки в SQL.
     * Для чисел используй (int), для имён — этот метод.
     */
    public function escape(string $value): string
    {
        return $this->connection->real_escape_string($value);
    }

    public function lastInsertId(): int
    {
        return (int) $this->connection->insert_id;
    }

    public function affectedRows(): int
    {
        return $this->connection->affected_rows;
    }
}
