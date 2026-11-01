<?php
// src/View/Screen/PlaceScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\Core\CardInstance;
use Berserk\Core\ZoneManager;
use Berserk\View\Template;
use Berserk\View\Ui\PrepareUi;
use Berserk\View\Ui\BattlefieldPosition;
use Berserk\View\Ui\Coords;

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
        array $cardsInfo = [],
        array $commandContext = []
    ): array {
        $linkParam = $role === 'host' ? 'first' : 'second';
        $baseUrl   = "?{$linkParam}&game={$state->gameId}";
        if ($commandContext !== []) $baseUrl .= '&'.http_build_query($commandContext);
        $me        = $state->getPlayer($playerKey);
        $ui        = new PrepareUi($this->tpl);

        $isHost   = ($playerKey === 'host');
        $rowOrder = $isHost ? [3, 2, 1] : [4, 5, 6];
        $colOrder = $isHost ? [1, 2, 3, 4, 5] : [5, 4, 3, 2, 1];
        $pf=\Berserk\Core\PathfinderProcessor::active($state);
        if ($pf && !empty($pf['revealed_to'][$playerKey])) array_unshift($rowOrder,$isHost?4:3);
        if ($pf) foreach ($state->cards as $card) {
            if ($card->owner!==$playerKey && $card->zone===CardInstance::ZONE_FIELD && !empty($card->flags['pathfinder_revealed']) && !in_array($card->row,$rowOrder,true)) array_unshift($rowOrder,$card->row);
        }

        // Выбранная карта из ?card — временное UI-состояние, не GameState.
        $selectedCardId = (int) ($_GET['card'] ?? 0);
        $selectedCard = null;
        if ($selectedCardId > 0) {
            $c = $state->getCard($selectedCardId);
            if (!$c
                || $c->owner !== $playerKey
                || !in_array($c->zone, [CardInstance::ZONE_SQUAD, CardInstance::ZONE_FIELD], true)) {
                $selectedCardId = 0;
            } else {
                $selectedCard = $c;
            }
        }

        // Карты на поле
        $fieldMap = [];
        foreach ($state->cards as $card) {
            if ($card->zone === CardInstance::ZONE_FIELD) {
                if ($card->owner!==$playerKey && (!$pf || (empty($card->flags['pathfinder_revealed']) && (empty($pf['revealed_to'][$playerKey]) || $card->row!==($isHost?4:3))))) continue;
                $fieldMap["{$card->row}_{$card->col}"] = $card;
            }
        }

        // Разрешённые клетки
        $allowedCells = [];
        if ($selectedCard && $selectedCard->zone === CardInstance::ZONE_SQUAD && !$me->isConfirmed('place') && \Berserk\Core\PathfinderProcessor::canEdit($state,$playerKey)) {
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
        $columnsHtml = '<div class="battle-columns" aria-label="Столбцы">';
        foreach ($colOrder as $col) $columnsHtml .= '<span>' . BattlefieldPosition::display($rowOrder[0], $col, $playerKey)['col'] . '</span>';
        $columnsHtml .= '</div>';
        $fieldHtml = $columnsHtml;

        foreach ($isHost ? range(6,1) : range(1,6) as $r) {
            if (!in_array($r,$rowOrder,true)) {
                $fieldHtml .= '<div class="field-row battle-numbered-row place-undisclosed-row" aria-hidden="true">' . str_repeat('<div class="field-cell"></div>',5) . '</div>';
                continue;
            }
            $rowCells = '';
            foreach ($colOrder as $c) {
                $key         = "{$r}_{$c}";
                $cellOwner   = ($r <= 3) ? 'host' : 'player';
                $isMine      = ($cellOwner === $playerKey);
                $cellClass   = '';
                $cellContent = '';

                if (isset($fieldMap[$key])) {
                    $card = $fieldMap[$key];
                    if (isset($cardsInfo[$card->ukid])) {
                        $cellContent = $this->miniature($state, $card, $playerKey, $cardsInfo[$card->ukid], $baseUrl, $selectedCardId);

                    }
                } elseif ($selectedCardId > 0 && $isMine && isset($allowedCells[$key])) {
                    $placeUrl    = "{$baseUrl}&cmd=place_card&card_id={$selectedCardId}&row={$r}&col={$c}&card={$selectedCardId}";
                    $cellContent = '<a class="cell-link" aria-label="Поставить карту: ' . BattlefieldPosition::label($r, $c, $playerKey) . '" href="' . $placeUrl . '"></a>';
                    $cellClass  .= ' allowed move-target';
                } elseif ($isMine) {
                    $cellClass .= ' own-empty';
                } else {
                    $cellClass .= ' opp-empty';
                }

                $rowCells .= $this->tpl->parse('includes/place_cell.tpl', [
                    'cl'      => $cellClass,
                    'row' => $r, 'col' => $c, 'position_label' => BattlefieldPosition::label($r, $c, $playerKey),
                    'content' => $cellContent,
                ]);
            }
            $position = BattlefieldPosition::display($r, $colOrder[0], $playerKey);
            $fieldHtml .= '<div class="field-row battle-numbered-row"><span class="battle-row-number coords--' . $position['side'] . '" aria-label="' . ($position['side']==='own'?'Свой':'Вражеский') . ' ' . $position['row'] . '-й ряд">'
                . Coords::rowLabel($position['row']) . '</span>' . $rowCells . '</div>';
        }

        $fieldHtml .= str_replace('class="battle-columns"', 'class="battle-columns battle-columns-bottom"', $columnsHtml);

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
            $selUrl = "{$baseUrl}&card={$card->instanceId}";
            $isSel  = ($selectedCardId === $card->instanceId);

            $squadHtml .= $this->miniature($state, $card, $playerKey, $info, $baseUrl, $selectedCardId);
        }

        $squadCount = count($state->getCardsInZone($playerKey, CardInstance::ZONE_SQUAD));

        if ($pf && $pf['owner'] !== $playerKey) {
            $confirmHtml = '<p>У соперника Следопыт, пожалуйста, подождите</p>';
        } elseif ($pf && $pf['stage']==='choice') {
            $url=$baseUrl.'&cmd=pathfinder_choice&step='.$pf['index'];
            $confirmHtml='<a class="button" href="'.$url.'&choice=start">Начать игру</a> <a class="button" href="'.$url.'&choice=rearrange">Переставить карты</a>';
        } elseif ($me->isConfirmed('place')) {
            $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
        } elseif ($squadCount === 0) {
            $confirmUrl  = "{$baseUrl}&cmd=confirm_place";
            $confirmHtml = '<a class="button" href="' . $confirmUrl . '">Подтвердить</a>';
        } else {
            $confirmHtml = '<p class="wait">Осталось расставить: ' . $squadCount . '</p>';
        }

        $previewCard = null;
        $cardActions = [];
        if ($selectedCard) {
            $info = $cardsInfo[$selectedCard->ukid] ?? null;
            if ($info) {
                $previewCard = [
                    'ukid'        => $selectedCard->ukid,
                    'instance_id' => $selectedCard->instanceId,
                    'info'        => $info,
                ];
                if (!$me->isConfirmed('place') && $selectedCard->zone === CardInstance::ZONE_FIELD) {
                    $cardActions[] = [
                        'label' => 'Убрать с поля',
                        'url'   => "{$baseUrl}&cmd=unplace_card&card_id={$selectedCard->instanceId}&card={$selectedCard->instanceId}",
                    ];
                }
            }
        }
        $previewHtml = $ui->information($previewCard, $cardsInfo);
        if ($previewCard !== null && $previewHtml !== '') $previewHtml = $ui->withCardControls($previewHtml, $previewCard['ukid']);
        $cardActionsHtml = $ui->actions($cardActions);
        if ($cardActionsHtml !== '') $cardActionsHtml = '<div data-place-return>' . str_replace('<a ', '<a data-place-return-link ', $cardActionsHtml) . '</div>';
        $showCard = $previewHtml !== '';
        $panelHtml = $previewHtml . ($cardActionsHtml !== '' ? '<div class="pregame-card-actions pregame-place-actions">' . $cardActionsHtml . '</div>' : '');
        $reserveHtml = '<section class="pregame-place-list"><h3 class="pregame-card-heading panel-card-heading">Нерасставленные карты</h3><div class="squad-cards" data-place-reserve>' . $squadHtml . '</div><nav class="pregame-view-pagination" data-place-pagination aria-label="Страницы нерасставленных карт"></nav></section>';


        return [
            'screen' => 'place',
            'data'   => [
                'field_html'   => $fieldHtml,
                'squad_html'   => $squadHtml,
                'squad_count'  => $squadCount,
                'preview_html' => '',
                'panel_html'   => $panelHtml !== '' ? $panelHtml : '<div class="place-preview"></div>',
                'panel_summary_html' => $reserveHtml,
                'panel_actions_html' => \Berserk\View\Ui\PreGameLayout::actionsFooter($confirmHtml),
                'own_playmat' => \Berserk\Core\PlaymatCatalog::playerUrl($me),
                'place_selected' => $selectedCardId,
                'place_editable' => !$me->isConfirmed('place') && \Berserk\Core\PathfinderProcessor::canEdit($state,$playerKey) ? '1' : '0',
                'place_view' => $showCard ? 'card' : 'list',
                'place_waiting' => $me->isConfirmed('place') ? '1' : '0',
                'card_actions_html' => $cardActionsHtml,
                'confirm_html' => $confirmHtml,
                'message'      => $message ?? '',
            ],
        ];
    }
    private function miniature(GameState $state, CardInstance $card, string $viewer, array $info, string $baseUrl, int $selected): string
    {
        $own = $card->owner === $viewer;
        $source = $own && !$state->getPlayer($viewer)->isConfirmed('place') && \Berserk\Core\PathfinderProcessor::canEdit($state,$viewer);
        $name = htmlspecialchars((string)($info['name'] ?? $card->ukid),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html = \Berserk\View\Ui\LiveBattleCard::render($card,$state,$viewer,false,$name,$card->hp.'/'.$card->hpMax);
        $html = preg_replace('/^(<\w+)/', '$1 data-drag-card="'.$card->instanceId.'" data-drag-owner="'.($own?'own':'enemy').'" data-drag-source="'.($source?'1':'0').'"', $html,1);
        if (!$own) return '<span class="card-link">'.$html.'</span>';
        return '<a class="card-link'.($selected===$card->instanceId?' selected':'').'" data-place-card="'.$card->instanceId.'" href="'.htmlspecialchars($baseUrl.'&card='.$card->instanceId,ENT_QUOTES).'">'.$html.'</a>';
    }
}
