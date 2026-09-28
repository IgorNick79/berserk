<?php
// src/View/Screen/ViewScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\CardInstance;
use Berserk\Core\GameState;
use Berserk\Core\GameSettings;
use Berserk\Core\DeckView;
use Berserk\View\Template;
use Berserk\View\Ui\ElementLabels;
use Berserk\View\Ui\PrepareUi;

final class ViewScreen
{
    public function __construct(
        private DeckView $deckView,
        private Template $tpl,
    ) {}

    /**
     * @return array{screen: string, data: array}
     */
    public function prepare(
        GameState $state,
        string $playerKey,
        string $role,
        ?string $message,
        array $cardsInfo = [],
        array $elementLabels = []
    ): array {
        $linkParam = $role === 'host' ? 'first' : 'second';
        $baseUrl   = "?{$linkParam}&game={$state->gameId}";
        $me = $state->getPlayer($playerKey);
        $ui = new PrepareUi($this->tpl);
        $isDraftDeck = $me->deckId === 0;
        $deckInstances = $isDraftDeck ? $state->getCardsInZone($playerKey, CardInstance::ZONE_DECK) : [];
        $sideboardInstances = $isDraftDeck ? $state->getCardsInZone($playerKey, CardInstance::ZONE_SIDEBOARD) : [];

        if ($isDraftDeck && (!empty($deckInstances) || !empty($sideboardInstances))) {
            $deckInfo = $this->buildFromInstances($deckInstances, $cardsInfo, 'Драфт');
            $sideboardInfo = $this->buildFromInstances($sideboardInstances, $cardsInfo, 'Сайдборд') ?? [
                'name' => 'Сайдборд',
                'cards' => [],
                'elements' => [],
                'total' => 0,
                'elite' => 0,
                'ordinary' => 0,
            ];
        } elseif ($me->deckId) {
            $deckInfo = $this->deckView->forDeck($me->deckId);
            $sideboardInfo = null;
        } else {
            $deckInfo = $this->buildFromDeckCards($me->deckCards ?? [], $cardsInfo);
            $sideboardInfo = null;
        }

        if (!$deckInfo) {
            return [
                'screen' => 'view',
                'data'   => [
                    'deck_name'     => '—',
                    'total'         => 0,
                    'elite'         => 0,
                    'ordinary'      => 0,
                    'elements_html' => '',
                    'cards_html'    => '',
                    'sideboard_html'=> '',
                    'bottom_panel_html' => '',
                    'preview_html'  => '',
                    'confirm_html'  => '',
                    'message'       => 'Дека не найдена',
                ],
            ];
        }

        $selectedToken = (string) ($_GET['card'] ?? '');
        $selectedCard = null;
        $selectedZone = '';

        $cardsHtml = '';
        foreach ($deckInfo['cards'] as $c) {
            $ukid = (string) ($c['ukid'] ?? '');
            $cardToken = $isDraftDeck ? 'deck:' . (int) ($c['instance_id'] ?? 0) : $ukid;
            if ($selectedToken === $cardToken) {
                $selectedCard = ['ukid' => $ukid, 'info' => $c, 'instance_id' => (int) ($c['instance_id'] ?? 0)];
                $selectedZone = CardInstance::ZONE_DECK;
            }

            $cardsHtml .= $ui->card([
                'ukid'     => $ukid,
                'info'     => $c,
                'count'    => (int) ($c['count'] ?? 1),
                'instance_id' => isset($c['instance_id']) ? (int) $c['instance_id'] : null,
                'link'     => "{$baseUrl}&card=" . urlencode($cardToken),
                'selected' => $selectedToken === $cardToken,
            ]);
        }

        $sideboardHtml = '';
        if ($sideboardInfo !== null) {
            foreach ($sideboardInfo['cards'] as $c) {
                $ukid = (string) ($c['ukid'] ?? '');
                $cardToken = 'sideboard:' . (int) ($c['instance_id'] ?? 0);
                if ($selectedToken === $cardToken) {
                    $selectedCard = ['ukid' => $ukid, 'info' => $c, 'instance_id' => (int) ($c['instance_id'] ?? 0)];
                    $selectedZone = CardInstance::ZONE_SIDEBOARD;
                }

                $sideboardHtml .= $ui->card([
                    'ukid'     => $ukid,
                    'info'     => $c,
                    'count'    => (int) ($c['count'] ?? 1),
                    'instance_id' => (int) ($c['instance_id'] ?? 0),
                    'link'     => "{$baseUrl}&card=" . urlencode($cardToken),
                    'selected' => $selectedToken === $cardToken,
                ]);
            }

            if ($sideboardHtml !== '') {
                $sideboardHtml = '<h2>Сайдборд</h2><div class="cards">' . $sideboardHtml . '</div>';
            }
        }

        $elementsHtml = '';
        foreach ($deckInfo['elements'] as $code => $count) {
            $elementsHtml .= $this->tpl->parse('includes/deck_element.tpl', [
                'code'  => $code,
                'count' => $count,
            ]);
        }

        $deckCount = (int) ($deckInfo['total'] ?? 0);
        $sideboardCount = (int) ($sideboardInfo['total'] ?? 0);
        $isBelowMinimum = $isDraftDeck && $deckCount < GameSettings::MIN_DECK_SIZE;
        $isAboveMaximum = $isDraftDeck && $deckCount > GameSettings::MAX_DECK_SIZE;
        $canSideboard = $isDraftDeck && $deckCount > GameSettings::MIN_DECK_SIZE;

        if (!$me->isConfirmed('view') && $isBelowMinimum) {
            $confirmHtml = '<span class="button disabled">Недостаточно карт</span>';
        } elseif (!$me->isConfirmed('view') && $isAboveMaximum) {
            $confirmHtml = '<span class="button disabled">Слишком много карт</span>';
        } elseif (!$me->isConfirmed('view')) {
            $confirmUrl  = "?{$linkParam}&game={$state->gameId}&cmd=confirm_view";
            $confirmHtml = '<a class="button" href="' . $confirmUrl . '">Подтвердить</a>';
        } else {
            $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
        }

        $actions = [];
        if ($selectedCard !== null && !$me->isConfirmed('view') && $isDraftDeck) {
            $selectedInstanceId = (int) ($selectedCard['instance_id'] ?? 0);
            if ($selectedZone === CardInstance::ZONE_DECK && $canSideboard) {
                $actions[] = [
                    'label' => 'В сайдборд',
                    'url' => "{$baseUrl}&cmd=view_to_sideboard&card_id={$selectedInstanceId}&card=" . urlencode($selectedToken),
                ];
            } elseif ($selectedZone === CardInstance::ZONE_SIDEBOARD) {
                $actions[] = [
                    'label' => 'В колоду',
                    'url' => "{$baseUrl}&cmd=view_to_deck&card_id={$selectedInstanceId}&card=" . urlencode($selectedToken),
                ];
            }
        }

        $previewHtml = $ui->preview($selectedCard, $actions, 'Выбери карту');
        $bottomPanelHtml = '<div class="prepare-bottom-summary prepare-bottom-summary--stack">'
            . '<div class="prepare-bottom-row">'
            . '<span>Колода: <b>' . $deckCount . '</b></span>'
            . '<span>Сайдборд: <b>' . $sideboardCount . '</b></span>'
            . ($isDraftDeck ? '<span>Минимум: <b>' . GameSettings::MIN_DECK_SIZE . '</b></span>' : '')
            . '</div>'
            . '<div class="prepare-elements">' . $this->elementBadgesHtml($deckInfo['elements'], $elementLabels) . '</div>'
            . '</div>'
            . '<div class="prepare-bottom-actions">' . $confirmHtml . '</div>';

        return [
            'screen' => 'view',
            'data'   => [
                'deck_name'     => $deckInfo['name'],
                'total'         => $deckInfo['total'],
                'elite'         => $deckInfo['elite'],
                'ordinary'      => $deckInfo['ordinary'],
                'elements_html' => $elementsHtml,
                'cards_html'    => $cardsHtml,
                'sideboard_html'=> $sideboardHtml,
                'bottom_panel_html' => $bottomPanelHtml,
                'preview_html'  => $previewHtml,
                'confirm_html'  => $confirmHtml,
                'message'       => $message ?? '',
            ],
        ];
    }

    private function elementBadgesHtml(array $elements, array $elementLabels): string
    {
        $html = '';
        foreach ($elements as $code => $count) {
            if ($count <= 0) continue;
            $name = ElementLabels::label((string) $code, $elementLabels);
            $html .= '<span class="prepare-element">'
                . htmlspecialchars((string) $name, ENT_QUOTES)
                . ': <b>' . (int) $count . '</b>'
                . '</span>';
        }
        return $html;
    }

    private function buildFromInstances(array $instances, array $cardsInfo, string $name): ?array
    {
        if (empty($instances)) return null;

        $cards = [];
        $total = 0;
        $elite = 0;
        $ordinary = 0;
        $elements = [];

        foreach ($instances as $card) {
            if (!$card instanceof CardInstance) continue;

            $ukid = $card->ukid;
            $info = $cardsInfo[$ukid] ?? [];
            if (!isset($cards[$ukid])) {
                $cards[$ukid] = [
                    'ukid' => $ukid,
                    'instance_id' => $card->instanceId,
                    'name' => $info['name'] ?? $ukid,
                    'count' => 0,
                    'price' => $card->price,
                    'health' => $card->hpMax,
                    'move' => $card->moveMax,
                    'strike' => [
                        'weak' => $card->strikeWeak,
                        'medium' => $card->strikeMedium,
                        'strong' => $card->strikeStrong,
                    ],
                    'elite' => $card->elite,
                    'element' => $info['element'] ?? $card->element,
                ];
            }

            $cards[$ukid]['count']++;
            $total++;
            if ($card->elite) $elite++;
            else             $ordinary++;

            $el = $card->element;
            if ($el !== '' && $el !== '—') {
                $elements[$el] = ($elements[$el] ?? 0) + 1;
            }
        }

        return [
            'name' => $name,
            'cards' => array_values($cards),
            'elements' => $elements,
            'total' => $total,
            'elite' => $elite,
            'ordinary' => $ordinary,
        ];
    }

    private function buildFromDeckCards(array $deckCards, array $cardsInfo): ?array
    {
        if (empty($deckCards)) return null;

        $cards     = [];
        $total     = 0;
        $elite     = 0;
        $ordinary  = 0;
        $elements  = [];

        foreach ($deckCards as $c) {
            $ukid = $c['ukid'] ?? '';
            if ($ukid === '') continue;

            $info    = $cardsInfo[$ukid] ?? null;
            $count   = (int) ($c['count'] ?? 1);
            $isElite = !empty($c['elite']);

            $cards[] = [
                'ukid'   => $ukid,
                'name'   => $info['name'] ?? $ukid,
                'count'  => $count,
                'price'  => (int) ($c['price']  ?? $info['price']  ?? 0),
                'health' => (int) ($c['health'] ?? $info['health'] ?? 0),
                'move'   => (int) ($c['move']   ?? $info['move']   ?? 0),
                'strike' => [
                    'weak'   => (int) ($c['strike_weak']   ?? $info['strike']['weak']   ?? 0),
                    'medium' => (int) ($c['strike_medium'] ?? $info['strike']['medium'] ?? 0),
                    'strong' => (int) ($c['strike_strong'] ?? $info['strike']['strong'] ?? 0),
                ],
                'elite'  => $isElite,
            ];

            $total += $count;
            if ($isElite) $elite += $count;
            else          $ordinary += $count;

            $el = $c['element'] ?? ($info['element'] ?? null);
            if ($el && $el !== '—') {
                $elements[$el] = ($elements[$el] ?? 0) + $count;
            }
        }

        return [
            'name'     => 'Драфт',
            'cards'    => $cards,
            'elements' => $elements,
            'total'    => $total,
            'elite'    => $elite,
            'ordinary' => $ordinary,
        ];
    }
}
