<?php
// src/Core/Db.php

declare(strict_types=1);

namespace Berserk\Core;

use mysqli;
use mysqli_result;
use mysqli_sql_exception;

/**
 * Тонкая обёртка над mysqli.
 * Никакой логики — только подключение и выполнение запросов.
 */
final class Db
{
    private mysqli $connection;
    private bool $transactionActive = false;

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
        try {
            $result = $this->connection->query($sql);
        } catch (mysqli_sql_exception $e) {
            throw new DbQueryException('Query failed', (int) $e->getCode(), $e);
        }

        if ($result === false) {
            throw new DbQueryException('Query failed', (int) $this->connection->errno);
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

    public function beginTransaction(): void
    {
        if ($this->transactionActive) {
            throw new \RuntimeException('Nested transactions are not supported');
        }

        try {
            $started = $this->connection->begin_transaction();
        } catch (mysqli_sql_exception $e) {
            throw new \RuntimeException('Begin transaction failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        if (!$started) {
            throw new \RuntimeException('Begin transaction failed: ' . $this->connection->error);
        }

        $this->transactionActive = true;
    }

    public function commit(): void
    {
        if (!$this->transactionActive) {
            throw new \RuntimeException('No active transaction to commit');
        }

        try {
            $committed = $this->connection->commit();
        } catch (mysqli_sql_exception $e) {
            $this->transactionActive = false;
            throw new TransactionCommitUnknownException('Commit result is unknown: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        if (!$committed) {
            $this->transactionActive = false;
            throw new TransactionCommitUnknownException('Commit result is unknown: ' . $this->connection->error);
        }

        $this->transactionActive = false;
    }

    public function rollback(): void
    {
        if (!$this->transactionActive) {
            throw new \RuntimeException('No active transaction to roll back');
        }

        try {
            $rolledBack = $this->connection->rollback();
        } catch (mysqli_sql_exception $e) {
            $this->transactionActive = false;
            throw new TransactionRollbackUnknownException('Rollback result is unknown', (int) $e->getCode(), $e);
        }

        if (!$rolledBack) {
            $this->transactionActive = false;
            throw new TransactionRollbackUnknownException('Rollback result is unknown', (int) $this->connection->errno);
        }

        $this->transactionActive = false;
    }

    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }

    public function isSameConnection(self $other): bool
    {
        return $this === $other;
    }
}
