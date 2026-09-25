<?php
// src/Core/Choice/ChoiceHandlerInterface.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

interface ChoiceHandlerInterface
{
    /** Ключ в state->battle, например 'pending_coin_spend' */
    public function pendingKey(): string;

    /** Типы команд, которые обрабатывает handler, например ['choose_coin_spend'] */
    public function commandTypes(): array;

    /** Вернуть спеку или null (если сейчас не показывается) */
    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec;

    /** Применить команду */
    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result;
}