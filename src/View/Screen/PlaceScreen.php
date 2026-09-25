<?php
// src/View/Screen/PlaceScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\Core\CardInstance;
use Berserk\Core\ZoneManager;
use Berserk\View\Template;

final class PlaceScreen
{
    public function __construct(private Template $tpl) {}

    /**
     * @return array{screen: string, data: array}
     */
    public function prepare(
        GameState $state,
        string $playerKey,
        string $role,
        ?string $message,
        array $cardsInfo = []
    ): array {
        $linkParam = $role === 'host' ? 'first' : 'second';
        $baseUrl   = "?{$linkParam}&game={$state->gameId}";
        $me        = $state->getPlayer($playerKey);

        $isHost   = ($playerKey === 'host');
        $rowOrder = $isHost ? [3, 2, 1] : [4, 5, 6];
        $colOrder = $isHost ? [1, 2, 3, 4, 5] : [5, 4, 3, 2, 1];

        // Выбранная карта из ?sel
        $selectedCardId = (int) ($_GET['sel'] ?? 0);
        if ($selectedCardId > 0) {
            $c = $state->getCard($selectedCardId);
            if (!$c || $c->owner !== $playerKey || $c->zone !== CardInstance::ZONE_SQUAD) {
                $selectedCardId = 0;
            }
        }

        // Карты на поле
        $fieldMap = [];
        foreach ($state->cards as $card) {
            if ($card->zone === CardInstance::ZONE_FIELD) {
                $fieldMap["{$card->row}_{$card->col}"] = $card;
            }
        }

        // Разрешённые клетки
        $allowedCells = [];
        if ($selectedCardId > 0) {
            $levels   = ZoneManager::cellLevels($playerKey);
            $occupied = [];
            foreach ($state->cards as $card) {
                if ($card->owner === $playerKey && $card->zone === CardInstance::ZONE_FIELD) {
                    $occupied["{$card->row}_{$card->col}"] = true;
                }
            }
            $prevFilled = true;
            foreach ($levels as $level) {
                foreach ($level as $cell) {
                    if ($prevFilled) $allowedCells[$cell] = true;
                }
                foreach ($level as $cell) {
                    if (!isset($occupied[$cell])) { $prevFilled = false; break; }
                }
            }
        }

        // Рендер поля
        $fieldHtml = '';
        foreach ($rowOrder as $r) {
            $rowCells = '';
            foreach ($colOrder as $c) {
                $key         = "{$r}_{$c}";
                $cellOwner   = ($r <= 3) ? 'host' : 'player';
                $isMine      = ($cellOwner === $playerKey);
                $cellClass   = $isMine ? 'own' : 'opp';
                $cellContent = '';

                if (isset($fieldMap[$key])) {
                    $card = $fieldMap[$key];
                    $info = $cardsInfo[$card->ukid] ?? null;
                    if ($info) {
                        $eliteCls = $info['elite'] ? 'elite' : '';
                        $nameHtml = htmlspecialchars($info['name'], ENT_QUOTES);
                        if ($card->owner === $playerKey) {
                            $unplaceUrl  = "{$baseUrl}&cmd=unplace_card&card_id={$card->instanceId}";
                            $cellContent = '<a class="card-link" href="' . $unplaceUrl . '">'
                                . '<div class="card on-field ' . $eliteCls . '">' . $nameHtml . '</div></a>';
                            $cellClass  .= ' placed';
                        } else {
                            $cellContent = '<div class="card on-field ' . $eliteCls . '">' . $nameHtml . '</div>';
                            $cellClass  .= ' enemy';
                        }
                    }
                } elseif ($selectedCardId > 0 && $isMine && isset($allowedCells[$key])) {
                    $placeUrl    = "{$baseUrl}&cmd=place_card&card_id={$selectedCardId}&row={$r}&col={$c}&sel={$selectedCardId}";
                    $cellContent = '<a class="cell-link" href="' . $placeUrl . '"></a>';
                    $cellClass  .= ' allowed';
                } elseif ($isMine) {
                    $cellClass .= ' own-empty';
                } else {
                    $cellClass .= ' opp-empty';
                }

                $rowCells .= $this->tpl->parse('includes/place_cell.tpl', [
                    'cl'      => $cellClass,
                    'content' => $cellContent,
                ]);
            }
            $fieldHtml .= '<div class="field-row">' . $rowCells . '</div>';
        }

        // Отряд
        $squadCards = [];
        foreach ($state->cards as $card) {
            if ($card->owner === $playerKey && $card->zone === CardInstance::ZONE_SQUAD) {
                $info = $cardsInfo[$card->ukid] ?? null;
                if ($info) $squadCards[] = ['card' => $card, 'info' => $info];
            }
        }
        usort($squadCards, fn($a, $b) => strcmp($a['info']['name'], $b['info']['name']));

        $squadHtml = '';
        foreach ($squadCards as $item) {
            $card  = $item['card'];
            $info  = $item['info'];
            $selUrl = "{$baseUrl}&sel={$card->instanceId}";
            $isSel  = ($selectedCardId === $card->instanceId);

            $squadHtml .= $this->tpl->parse('includes/place_squad_card.tpl', [
                'name'     => $info['name'],
                'price'    => $info['price'],
                'health'   => $info['health'],
                'move'     => $info['move'],
                'weak'     => $info['strike']['weak'],
                'medium'   => $info['strike']['medium'],
                'strong'   => $info['strike']['strong'],
                'elite'    => $info['elite'] ? 'elite' : '',
                'link'     => $selUrl,
                'selected' => $isSel ? 'selected' : '',
            ]);
        }

        $squadCount = count($squadCards);

        if ($me->isConfirmed('place')) {
            $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
        } elseif ($squadCount === 0) {
            $confirmUrl  = "{$baseUrl}&cmd=confirm_place";
            $confirmHtml = '<a class="button" href="' . $confirmUrl . '">Подтвердить расстановку</a>';
        } else {
            $confirmHtml = '<p class="wait">Осталось расставить: ' . $squadCount . '</p>';
        }

        return [
            'screen' => 'place',
            'data'   => [
                'field_html'   => $fieldHtml,
                'squad_html'   => $squadHtml,
                'squad_count'  => $squadCount,
                'confirm_html' => $confirmHtml,
                'message'      => $message ?? '',
            ],
        ];
    }
}