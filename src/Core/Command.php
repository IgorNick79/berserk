<?php
// src/Core/Command.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Команда от клиента — намерение игрока.
 * Не факт, а запрос. Может быть отклонена.
 */
final class Command
{
    public function __construct(
        public string $type,
        public array $payload = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? ''),
            payload: (array) ($data['payload'] ?? []),
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }
}