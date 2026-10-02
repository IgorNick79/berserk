<?php
// src/Core/View.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Проекция FullState → PlayerView.
 *
 * Из полного состояния партии делает то, что можно отдать клиенту
 * конкретного игрока. Скрывает:
 *   - руку оппонента (только количество)
 *   - колоду оппонента (только количество)
 *   - ukid закрытых карт оппонента на поле
 *
 * Карты возвращает в системе координат зрителя:
 *   - ряды 1-3 — всегда его половина
 *   - ряды 4-6 — всегда половина оппонента
 *   - колонки 1-5 всегда слева направо от зрителя
 */
final class View
{
    public function __construct(private GameState $state) {}

    public function forPlayer(int $userId): array
    {
        $playerKey = $this->state->getPlayerKeyByUserId($userId);
        if ($playerKey === null) {
            throw new \InvalidArgumentException("User $userId not in game");
        }

        $opponentKey = $this->state->getOpponentKey($playerKey);
        $isHost = $playerKey === GameState::PLAYER_HOST;

        return [
            'version'      => $this->state->version,
            'game_id'      => $this->state->gameId,
            'status'       => $this->state->status,
            'first_player' => $this->state->firstPlayer,
            'you'          => $playerKey,
            'players'      => $this->players($playerKey, $opponentKey),
            'cards'        => $this->cards($playerKey, $isHost),
            'cell_markers' => $this->cellMarkers($isHost),
            'battle'       => $this->state->battle,
        ];
    }

    private function players(string $you, string $opp): array
    {
        return [
            'you' => $this->state->getPlayer($you)->toArray(),
            'opp' => $this->publicPlayerData($this->state->getPlayer($opp)),
        ];
    }

    /**
     * Данные оппонента, безопасные для показа.
     */
    private function publicPlayerData(PlayerState $player): array
    {
        return [
            'user_id'    => $player->userId,
            'deck_id'    => $player->deckId,
            'side'       => $player->side,
            'confirmed'  => $player->confirmed,
            'resources'  => $player->resources,
        ];
    }

    /**
     * Все карты, видимые игроку.
     * Закрытые карты оппонента — без ukid, hp, markers.
     */
    private function cards(string $you, bool $isHost): array
    {
        $result = [];

        foreach ($this->state->cards as $card) {
            $isOwn = $card->owner === $you;

            // Карты в колоде и руке оппонента — не показываем вообще
            if (!$isOwn && in_array($card->zone, [
                CardInstance::ZONE_DECK,
                CardInstance::ZONE_HAND,
                CardInstance::ZONE_SQUAD,
            ], true)) {
                continue;
            }

            $view = $card->toArray();


            // Закрытая карта оппонента на поле — скрываем лицо
            if (!$isOwn && $card->closed && $card->zone === CardInstance::ZONE_FIELD) {
                $view = [
                    'instance_id' => $card->instanceId,
                    'owner'       => $card->owner,
                    'zone'        => $card->zone,
                    'row'         => $this->flipRow($card->row, $isHost),
                    'col'         => $this->flipCol($card->col, $isHost),
                    'closed'      => true,
                ];
            } elseif ($card->zone === CardInstance::ZONE_FIELD) {
                // Открытая карта — координаты в системе зрителя
                $view['row'] = $this->flipRow($card->row, $isHost);
                $view['col'] = $this->flipCol($card->col, $isHost);
            }

            $result[(string) $card->instanceId] = $view;
        }

        return $result;
    }

    /**
     * Маркеры на клетках — в системе зрителя.
     */
    private function cellMarkers(bool $isHost): array
    {
        $result = [];
        foreach (array_keys($this->state->cell_markers) as $key) {
            [$row, $col] = explode('_', $key);
            $newRow = $this->flipRow((int) $row, $isHost);
            $newCol = $this->flipCol((int) $col, $isHost);
            $result["{$newRow}_{$newCol}"] = ZoneManager::markersAt($this->state, $key);
        }
        return $result;
    }

    /**
     * Хост видит ряды как есть (1-3 свои), игрок — перевёрнуто (1-3 его).
     */
    private function flipRow(?int $row, bool $isHost): ?int
    {
        if ($row === null) {
            return null;
        }
        return $isHost ? $row : (7 - $row);
    }

    private function flipCol(?int $col, bool $isHost): ?int
    {
        if ($col === null) {
            return null;
        }
        return $isHost ? $col : (6 - $col);
    }
}