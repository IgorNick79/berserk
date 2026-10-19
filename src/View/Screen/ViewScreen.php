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
        array $elementLabels = [],
        bool $readOnly = false
    ): array {
        $linkParam = $role === 'host' ? 'first' : 'second';
        $baseUrl   = "?{$linkParam}&game={$state->gameId}";
        $me = $state->getPlayer($playerKey);
        $ui = new PrepareUi($this->tpl);
        $deckInstances = $state->getCardsInZone($playerKey, CardInstance::ZONE_DECK);
        $sideboardInstances = $state->getCardsInZone($playerKey, CardInstance::ZONE_SIDEBOARD);
        $usesRuntimeDeck = !empty($deckInstances) || !empty($sideboardInstances);

        if ($usesRuntimeDeck) {
            $deckInfo = $this->buildFromInstances($deckInstances, $cardsInfo, $this->deckTitle($me->deckId, $state->mode));
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
            $cardToken = $usesRuntimeDeck ? 'deck:' . (int) ($c['instance_id'] ?? 0) : $ukid;
            if ($selectedToken === $cardToken) {
                $selectedCard = ['ukid' => $ukid, 'info' => $c, 'instance_id' => (int) ($c['instance_id'] ?? 0)];
                $selectedZone = CardInstance::ZONE_DECK;
            }

            $cardsHtml .= $ui->card([
                'class'    => $this->filterClass($c, $cardsInfo[$ukid] ?? []),
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
                    'class'    => $this->filterClass($c, $cardsInfo[$ukid] ?? []),
                    'ukid'     => $ukid,
                    'info'     => $c,
                    'count'    => (int) ($c['count'] ?? 1),
                    'instance_id' => (int) ($c['instance_id'] ?? 0),
                    'link'     => "{$baseUrl}&card=" . urlencode($cardToken),
                    'selected' => $selectedToken === $cardToken,
                ]);
            }

            if ($sideboardHtml !== '') {
                $sideboardHtml = '<section class="pregame-view-group" data-view-group="sideboard" data-view-label="Сайдборд"><div class="cards">' . $sideboardHtml . '</div></section>';
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
        $isBelowMinimum = $usesRuntimeDeck && $deckCount < GameSettings::MIN_DECK_SIZE;
        $isAboveMaximum = $usesRuntimeDeck && $deckCount > GameSettings::MAX_DECK_SIZE;
        $canSideboard = $usesRuntimeDeck && $deckCount > GameSettings::MIN_DECK_SIZE;

        if ($readOnly) {
            $confirmHtml = '';
        } elseif (!$me->isConfirmed('view') && $isBelowMinimum) {
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
        if (!$readOnly && $selectedCard !== null && !$me->isConfirmed('view') && $usesRuntimeDeck) {
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

        // Legacy system decks may not yet have instances or entries in the render catalog.
        $panelCatalog = $cardsInfo;
        if (!$usesRuntimeDeck && $me->deckId) {
            foreach ($deckInfo['cards'] as $catalogCard) {
                $panelCatalog[$catalogCard['ukid']] ??= $catalogCard;
            }
        }
        $panelHtml = $ui->information($selectedCard, $panelCatalog);
        if ($panelHtml !== '' && $actions !== []) {
            $panelHtml .= '<div class="pregame-card-actions" data-view-card-actions>' . $ui->actions($actions) . '</div>';
        }
        $panelSummary = '<section class="pregame-pool-stats" aria-label="Статистика колоды">'
            . '<div class="pregame-pool-totals"><button type="button" class="pregame-view-zone" data-view-zone="deck" aria-pressed="true">Колода: <b>' . $deckCount . '</b></button> · '
            . '<button type="button" class="pregame-view-zone" data-view-zone="sideboard" aria-pressed="false">Сайдборд: <b>' . $sideboardCount . '</b></button>'
            . ($usesRuntimeDeck ? ' · Минимум: <b>' . GameSettings::MIN_DECK_SIZE . '</b>' : '') . '</div>'
            . '<div class="pregame-pool-icons"><div class="pregame-pool-currencies">'
            . $this->statisticIcon('/assets/images/card/elite.png', 'Золотые карты', (int) $deckInfo['elite'], 'crystal:gold')
            . $this->statisticIcon('/assets/images/card/ordinary.png', 'Серебряные карты', (int) $deckInfo['ordinary'], 'crystal:silver')
            . '</div><div class="pregame-pool-elements">'
            . $this->elementBadgesHtml($deckInfo['elements'], $elementLabels) . '</div></div></section>';

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
                'bottom_panel_html' => '',
                'panel_summary_html' => $panelSummary,
                'panel_actions_html' => '<div class="pregame-view-confirm">' . $confirmHtml . '</div>',
                'preview_html'  => '',
                'panel_html'    => $panelHtml,
                'confirm_html'  => $confirmHtml,
                'message'       => $message ?? '',
            ],
        ];
    }

    /** Reuse the same deck presentation without View commands on later preparation screens. */
    public function readOnlyDeck(GameState $state, string $playerKey, string $role, ?string $message,
        array $cardsInfo = [], array $elementLabels = []): array
    {
        $data = $this->prepare($state, $playerKey, $role, $message, $cardsInfo, $elementLabels, true)['data'];
        return ['deck_html' => (string) $this->tpl->parse('screen/view.tpl', $data),
            'panel_summary_html' => $data['panel_summary_html'] ?? ''];
    }

    private function elementBadgesHtml(array $elements, array $elementLabels): string
    {
        $names = ['dark' => 'Тьма', 'plains' => 'Степи', 'forests' => 'Леса',
            'swamps' => 'Болота', 'mountains' => 'Горы', 'neutral' => 'Нейтральная'];
        $counts = array_fill_keys(array_keys($names), 0);
        foreach ($elements as $code => $count) {
            if (!isset($counts[$code])) $code = array_search($code, $names, true);
            if (is_string($code) && isset($counts[$code])) $counts[$code] += (int) $count;
        }
        $html = '';
        foreach ($names as $code => $name) {
            $html .= $this->statisticIcon('/assets/images/element/' . $code . '.png',
                ElementLabels::label($code, $elementLabels + $names), $counts[$code], 'element:' . $code);
        }
        return $html;
    }

    private function statisticIcon(string $url, string $label, int $count, string $filter): string
    {
        $hint = htmlspecialchars($label . ': ' . $count, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<button type="button" class="pregame-hint pregame-pool-stat" data-view-filter="' . $filter . '" data-view-stat-label="'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" aria-pressed="false" aria-label="' . $hint . '">'
            . '<img src="' . $url . '" alt="" width="24" height="24"><span>' . $count . '</span>'
            . '<template data-pregame-hint>' . $hint . '</template></button>';
    }

    private function filterClass(array $card, array $catalog): string
    {
        $names = ['dark' => 'Тьма', 'plains' => 'Степи', 'forests' => 'Леса',
            'swamps' => 'Болота', 'mountains' => 'Горы', 'neutral' => 'Нейтральная'];
        $code = (string) ($catalog['element_code'] ?? $card['element_code'] ?? $card['element'] ?? 'neutral');
        if (!isset($names[$code])) $code = array_search($code, $names, true) ?: 'neutral';
        return 'view-element-' . $code . ' view-crystal-' . (!empty($card['elite']) ? 'gold' : 'silver');
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

    private function deckTitle(?int $deckId, ?string $mode): string
    {
        if ($deckId === 0) {
            if ($mode === GameSettings::MODE_SEALED) {
                return 'Sealed';
            }
            return 'Драфт';
        }
        if ($deckId === null) {
            return 'Колода';
        }

        try {
            $deckInfo = $this->deckView->forDeck($deckId);
            $name = (string) ($deckInfo['name'] ?? '');
            return $name !== '' ? $name : 'Колода';
        } catch (\Throwable) {
            return 'Колода';
        }
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
