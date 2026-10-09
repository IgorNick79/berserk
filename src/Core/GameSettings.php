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
    public const DRAFT_PICK_MODE_MANUAL = 'manual';
    public const DRAFT_PICK_MODE_RANDOM = 'random';
    public const DRAFT_GRID_MODE_CONTINUOUS = 'continuous';
    public const DRAFT_GRID_MODE_DISCRETE = 'discrete';
    public const DRAFT_AUTO_SIDE_BOTH = 'both';
    public const DRAFT_AUTO_SIDE_HOST = 'host';
    public const DRAFT_AUTO_SIDE_PLAYER = 'player';
    public const DRAFT_TIMER_DEFAULT = 'default';
    public const DRAFT_TIMER_CUSTOM = 'custom';
    public const DRAFT_TIMER_UNLIMITED = 'unlimited';
    public const DRAFT_TIMER_DEFAULT_TOTAL_SECONDS = 600;
    public const DRAFT_TIMER_DEFAULT_ACTION_SECONDS = 30;
    public const DRAFT_TIMER_CUSTOM_TOTAL_MIN_SECONDS = 300;
    public const DRAFT_TIMER_CUSTOM_TOTAL_MAX_SECONDS = 1800;
    public const DRAFT_TIMER_CUSTOM_TOTAL_STEP_SECONDS = 60;
    public const DRAFT_TIMER_CUSTOM_ACTION_MIN_SECONDS = 10;
    public const DRAFT_TIMER_CUSTOM_ACTION_MAX_SECONDS = 120;
    public const DRAFT_TIMER_CUSTOM_ACTION_STEP_SECONDS = 5;
    public const DRAFT_BOOSTERS_MIN = 5;
    public const DRAFT_BOOSTERS_MAX = 8;
    public const BOOSTER_PROFILE_DEFAULT = 'default';
    public const MIN_DECK_SIZE = 30;
    public const MAX_DECK_SIZE = 50;

    public function __construct(
        public array $system = ['deck_selection' => self::SYSTEM_DECK_SELECTION_MANUAL],
        public array $draft = [
            'type'            => self::DRAFT_TYPE_GRID,
            'pick_mode'       => self::DRAFT_PICK_MODE_MANUAL,
            'grid_mode'       => self::DRAFT_GRID_MODE_CONTINUOUS,
            'auto_side'       => self::DRAFT_AUTO_SIDE_BOTH,
            'grid_size'       => 3,
            'boosters'        => 5,
            'booster_profile' => self::BOOSTER_PROFILE_DEFAULT,
            'timer_mode'      => self::DRAFT_TIMER_DEFAULT,
            'timer_total'     => self::DRAFT_TIMER_DEFAULT_TOTAL_SECONDS,
            'timer_action'    => self::DRAFT_TIMER_DEFAULT_ACTION_SECONDS,
        ],
        public array $sealed = [
            'boosters'        => 4,
            'booster_profile' => self::BOOSTER_PROFILE_DEFAULT,
        ],
        public array $booster = [
            'common' => BoosterSettings::DEFAULT_COMMON,
            'uncommon' => BoosterSettings::DEFAULT_UNCOMMON,
            'rare_slots' => BoosterSettings::DEFAULT_RARE_SLOTS,
            'ultra_rare_chance' => BoosterSettings::DEFAULT_ULTRA_RARE_CHANCE,
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
            booster: array_merge($defaults->booster, (array) ($data['booster'] ?? [])),
        );
    }

    public function toArray(): array
    {
        return [
            'system' => $this->system,
            'draft'  => $this->draft,
            'sealed' => $this->sealed,
            'booster' => $this->booster,
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

    public function draftPickMode(): string
    {
        return (string) ($this->draft['pick_mode'] ?? self::DRAFT_PICK_MODE_MANUAL);
    }

    public function draftGridMode(): string
    {
        $mode = (string) ($this->draft['grid_mode'] ?? self::DRAFT_GRID_MODE_CONTINUOUS);
        return in_array($mode, [
            self::DRAFT_GRID_MODE_CONTINUOUS,
            self::DRAFT_GRID_MODE_DISCRETE,
        ], true) ? $mode : self::DRAFT_GRID_MODE_CONTINUOUS;
    }

    public function draftAutoSide(): string
    {
        return (string) ($this->draft['auto_side'] ?? self::DRAFT_AUTO_SIDE_BOTH);
    }

    public function draftBoosters(): int
    {
        return (int) ($this->draft['boosters'] ?? self::DRAFT_BOOSTERS_MIN);
    }

    public function draftBoosterProfile(): string
    {
        return (string) ($this->draft['booster_profile'] ?? self::BOOSTER_PROFILE_DEFAULT);
    }

    /**
     * @return array{common:int, uncommon:int, rare_slots:int, ultra_rare_chance:int}
     */
    public function boosterConfig(): array
    {
        return BoosterSettings::normalize($this->booster);
    }

    public function boosterCommon(): int
    {
        return $this->boosterConfig()['common'];
    }

    public function boosterUncommon(): int
    {
        return $this->boosterConfig()['uncommon'];
    }

    public function boosterRareSlots(): int
    {
        return $this->boosterConfig()['rare_slots'];
    }

    public function boosterUltraRareChance(): int
    {
        return $this->boosterConfig()['ultra_rare_chance'];
    }

    public function validateBoosterSettings(): ?string
    {
        return BoosterSettings::validate($this->booster);
    }

    public function draftTimerMode(): string
    {
        $mode = (string) ($this->draft['timer_mode'] ?? self::DRAFT_TIMER_DEFAULT);
        return in_array($mode, [
            self::DRAFT_TIMER_DEFAULT,
            self::DRAFT_TIMER_CUSTOM,
            self::DRAFT_TIMER_UNLIMITED,
        ], true) ? $mode : self::DRAFT_TIMER_DEFAULT;
    }

    public function draftTimerTotalSeconds(): int
    {
        return match ($this->draftTimerMode()) {
            self::DRAFT_TIMER_CUSTOM => (int) ($this->draft['timer_total'] ?? self::DRAFT_TIMER_DEFAULT_TOTAL_SECONDS),
            self::DRAFT_TIMER_UNLIMITED => 0,
            default => self::DRAFT_TIMER_DEFAULT_TOTAL_SECONDS,
        };
    }

    public function draftTimerActionSeconds(): int
    {
        return match ($this->draftTimerMode()) {
            self::DRAFT_TIMER_CUSTOM => (int) ($this->draft['timer_action'] ?? self::DRAFT_TIMER_DEFAULT_ACTION_SECONDS),
            self::DRAFT_TIMER_UNLIMITED => 0,
            default => self::DRAFT_TIMER_DEFAULT_ACTION_SECONDS,
        };
    }

    public function validateDraftTimer(): ?string
    {
        $mode = (string) ($this->draft['timer_mode'] ?? self::DRAFT_TIMER_DEFAULT);
        if (!in_array($mode, [
            self::DRAFT_TIMER_DEFAULT,
            self::DRAFT_TIMER_CUSTOM,
            self::DRAFT_TIMER_UNLIMITED,
        ], true)) {
            return 'Неверный режим таймера драфта';
        }

        if ($mode !== self::DRAFT_TIMER_CUSTOM) {
            return null;
        }

        $total = (int) ($this->draft['timer_total'] ?? 0);
        if ($total < self::DRAFT_TIMER_CUSTOM_TOTAL_MIN_SECONDS
            || $total > self::DRAFT_TIMER_CUSTOM_TOTAL_MAX_SECONDS
            || $total % self::DRAFT_TIMER_CUSTOM_TOTAL_STEP_SECONDS !== 0) {
            return 'Неверное общее время драфта';
        }

        $action = (int) ($this->draft['timer_action'] ?? 0);
        if ($action < self::DRAFT_TIMER_CUSTOM_ACTION_MIN_SECONDS
            || $action > self::DRAFT_TIMER_CUSTOM_ACTION_MAX_SECONDS
            || $action % self::DRAFT_TIMER_CUSTOM_ACTION_STEP_SECONDS !== 0) {
            return 'Неверное время на действие драфта';
        }

        return null;
    }

    public function validateDraftSettings(): ?string
    {
        $boosters = $this->draftBoosters();
        if ($boosters < self::DRAFT_BOOSTERS_MIN || $boosters > self::DRAFT_BOOSTERS_MAX) {
            return 'Неверное количество бустеров';
        }

        $gridMode = (string) ($this->draft['grid_mode'] ?? self::DRAFT_GRID_MODE_CONTINUOUS);
        if (!in_array($gridMode, [
            self::DRAFT_GRID_MODE_CONTINUOUS,
            self::DRAFT_GRID_MODE_DISCRETE,
        ], true)) {
            return 'Неверный режим сетки драфта';
        }

        return $this->validateDraftTimer() ?? $this->validateBoosterSettings();
    }
}
