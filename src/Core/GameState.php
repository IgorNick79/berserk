<?php
// src/Core/GameState.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Полное состояние партии.
 * Соответствует games.json в БД.
 *
 * Не знает про HTTP, БД, сессии, шаблоны.
 * Только структура и операции над ней.
 */
final class GameState
{
    public const PLAYER_HOST   = 'host';
    public const PLAYER_PLAYER = 'player';

    public int $version = 0;
    public int $gameId;
    public int $hostId;
    public int $playerId;
    public string $status = 'mode';
    public ?string $mode = null;
    public ?array $draft = null;
    public ?string $firstPlayer = null;
    public int $nextInstanceId = 1;

    /** @var array<string, PlayerState> */
    public array $players = [];

    /** @var array<int, CardInstance> */
    public array $cards = [];

    /** @var array<string, array> */
    public array $cell_markers = [];

    public array $battle = [];
    public array $log = [];

    public ?string $winner = null;

    public function __construct(int $gameId, int $hostId, int $playerId)
    {
        $this->gameId   = $gameId;
        $this->hostId   = $hostId;
        $this->playerId = $playerId;
        $this->players  = [
            self::PLAYER_HOST   => new PlayerState($hostId),
            self::PLAYER_PLAYER => new PlayerState($playerId),
        ];
    }

    public static function fromArray(array $data): self
    {
        $state = new self(
            (int) $data['game_id'],
            (int) $data['host_id'],
            (int) $data['player_id'],
        );

        $state->version        = (int) ($data['version'] ?? 0);
        $state->status         = (string) ($data['status'] ?? 'deck');
        $state->firstPlayer    = $data['first_player'] ?? null;
        $state->nextInstanceId = (int) ($data['next_instance_id'] ?? 1);
        $state->battle         = (array) ($data['battle'] ?? []);
        $state->log            = (array) ($data['log'] ?? []);
        $state->cell_markers   = (array) ($data['cell_markers'] ?? []);
        $state->winner         = $data['winner'] ?? null;
        $state->draft          = $data['draft'] ?? null;

        $state->players = [];
        foreach (($data['players'] ?? []) as $key => $playerData) {
            $state->players[$key] = PlayerState::fromArray($playerData);
        }

        $state->cards = [];
        foreach (($data['cards'] ?? []) as $id => $cardData) {
            $state->cards[(int) $id] = CardInstance::fromArray($cardData);
        }

        return $state;
    }

    public function toArray(): array
    {
        $players = [];
        foreach ($this->players as $key => $player) {
            $players[$key] = $player->toArray();
        }

        $cards = [];
        foreach ($this->cards as $id => $card) {
            $cards[(string) $id] = $card->toArray();
        }

        return [
            'version'          => $this->version,
            'game_id'          => $this->gameId,
            'host_id'          => $this->hostId,
            'player_id'        => $this->playerId,
            'status'           => $this->status,
            'first_player'     => $this->firstPlayer,
            'next_instance_id' => $this->nextInstanceId,
            'players'          => $players,
            'cards'            => $cards,
            'cell_markers'     => $this->cell_markers,
            'battle'           => $this->battle,
            'log'              => $this->log,
            'winner'           => $this->winner,
            'draft'            => $this->draft,
        ];
    }

    // ─── Игроки ──────────────────────────────────────────────

    public function getPlayer(string $key): PlayerState
    {
        if (!isset($this->players[$key])) {
            throw new \InvalidArgumentException("Unknown player key: $key");
        }
        return $this->players[$key];
    }

    public function getPlayerByUserId(int $userId): ?PlayerState
    {
        foreach ($this->players as $player) {
            if ($player->userId === $userId) {
                return $player;
            }
        }
        return null;
    }

    public function getPlayerKeyByUserId(int $userId): ?string
    {
        foreach ($this->players as $key => $player) {
            if ($player->userId === $userId) {
                return $key;
            }
        }
        return null;
    }

    public function getOpponentKey(string $key): string
    {
        return $key === self::PLAYER_HOST ? self::PLAYER_PLAYER : self::PLAYER_HOST;
    }

    public function getFirstPlayerKey(): string
    {
        foreach (['host', 'player'] as $key) {
            if (($this->players[$key]->side ?? 0) === 1) {
                return $key;
            }
        }
        return 'host';
    }

    // ─── Карты ───────────────────────────────────────────────

    public function getCard(int $instanceId): ?CardInstance
    {
        return $this->cards[$instanceId] ?? null;
    }

    public function nextInstanceId(): int
    {
        return $this->nextInstanceId++;
    }

    /**
     * @return CardInstance[]
     */
    public function getCardsInZone(string $owner, string $zone): array
    {
        return array_values(array_filter(
            $this->cards,
            fn (CardInstance $c) => $c->owner === $owner && $c->zone === $zone
        ));
    }

    /**
     * @return CardInstance[]
     */
    public function getCardsOnField(): array
    {
        return array_values(array_filter(
            $this->cards,
            fn (CardInstance $c) => $c->zone === CardInstance::ZONE_FIELD
        ));
    }

    public function addCard(CardInstance $card): void
    {
        $this->cards[$card->instanceId] = $card;
    }

    public function removeCard(int $instanceId): void
    {
        unset($this->cards[$instanceId]);
    }

    // ─── Версия ──────────────────────────────────────────────

    public function bumpVersion(): void
    {
        $this->version++;
    }
}