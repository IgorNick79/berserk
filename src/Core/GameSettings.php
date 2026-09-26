<?php
// src/Core/GameSettings.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Persistent game setup configuration.
 *
 * Runtime draft state lives in GameState::$draft, not here.
 */
final class GameSettings
{
    public const MODE_SYSTEM = 'system';
    public const MODE_DRAFT  = 'draft';
    public const MODE_SEALED = 'sealed';

    public const SYSTEM_DECK_SELECTION_MANUAL = 'manual';

    public const DRAFT_TYPE_GRID = 'grid';
    public const BOOSTER_PROFILE_DEFAULT = 'default';

    public function __construct(
        public array $system = ['deck_selection' => self::SYSTEM_DECK_SELECTION_MANUAL],
        public array $draft = [
            'type'            => self::DRAFT_TYPE_GRID,
            'grid_size'       => 3,
            'boosters'        => 5,
            'booster_profile' => self::BOOSTER_PROFILE_DEFAULT,
        ],
        public array $sealed = [
            'boosters'        => 4,
            'booster_profile' => self::BOOSTER_PROFILE_DEFAULT,
        ],
    ) {}

    public static function defaults(): self
    {
        return new self();
    }

    public static function fromArray(array $data): self
    {
        $defaults = self::defaults();

        return new self(
            system: array_merge($defaults->system, (array) ($data['system'] ?? [])),
            draft: array_merge($defaults->draft, (array) ($data['draft'] ?? [])),
            sealed: array_merge($defaults->sealed, (array) ($data['sealed'] ?? [])),
        );
    }

    public function toArray(): array
    {
        return [
            'system' => $this->system,
            'draft'  => $this->draft,
            'sealed' => $this->sealed,
        ];
    }

    public function systemDeckSelection(): string
    {
        return (string) ($this->system['deck_selection'] ?? self::SYSTEM_DECK_SELECTION_MANUAL);
    }

    public function draftType(): string
    {
        return (string) ($this->draft['type'] ?? self::DRAFT_TYPE_GRID);
    }

    public function draftGridSize(): int
    {
        return (int) ($this->draft['grid_size'] ?? 3);
    }

    public function draftBoosters(): int
    {
        return (int) ($this->draft['boosters'] ?? 5);
    }

    public function draftBoosterProfile(): string
    {
        return (string) ($this->draft['booster_profile'] ?? self::BOOSTER_PROFILE_DEFAULT);
    }
}
