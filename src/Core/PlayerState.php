<?php
// src/Core/PlayerState.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Состояние одного игрока в партии.
 * Ключ "host" или "player" — задаётся в GameState.
 */
final class PlayerState
{
    public function __construct(
        public int $userId,
        public ?int $deckId = null,
        public array $deckCards = [],
        public ?int $side = null,
        public ?int $dice = null,
        public array $confirmed = [],
        public array $resources = ['gold' => 0, 'silver' => 0],
        public int $reshuffles = 0,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            userId:     (int) $data['user_id'],
            deckId:     isset($data['deck_id']) ? (int) $data['deck_id'] : null,
            deckCards:  (array) ($data['deck_cards'] ?? []),
            side:       isset($data['side'])    ? (int) $data['side']    : null,
            dice:       isset($data['dice'])    ? (int) $data['dice']    : null,
            confirmed:  (array) ($data['confirmed'] ?? []),
            resources:  (array) ($data['resources'] ?? ['gold' => 0, 'silver' => 0]),
            reshuffles: (int) ($data['reshuffles'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'user_id'    => $this->userId,
            'deck_id'    => $this->deckId,
            'deck_cards' => $this->deckCards,
            'side'       => $this->side,
            'dice'       => $this->dice,
            'confirmed'  => $this->confirmed,
            'resources'  => $this->resources,
            'reshuffles' => $this->reshuffles,
        ];
    }

    public function isConfirmed(string $stage): bool
    {
        return (bool) ($this->confirmed[$stage] ?? false);
    }

    public function confirm(string $stage): void
    {
        $this->confirmed[$stage] = true;
    }

    public function clearConfirmations(): void
    {
        $this->confirmed = [];
    }
}