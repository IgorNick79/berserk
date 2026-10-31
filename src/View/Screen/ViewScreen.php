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
                'playmat_url' => \Berserk\Core\PlaymatCatalog::playerUrl($state->getPlayer($playerKey)),
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

            $cardsHtml .= $this->thumbnail($ui, $cardsInfo, [
                'legacy'   => $readOnly,
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

                $sideboardHtml .= $this->thumbnail($ui, $cardsInfo, [
                    'legacy'   => $readOnly,
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
                    'class' => 'yellow',
                    'url' => "{$baseUrl}&cmd=view_to_sideboard&card_id={$selectedInstanceId}&card=" . urlencode($selectedToken),
                ];
            } elseif ($selectedZone === CardInstance::ZONE_SIDEBOARD) {
                $actions[] = [
                    'label' => 'В колоду',
                    'class' => 'yellow',
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
        if (!$readOnly && $panelHtml !== '' && $selectedCard !== null) {
            $original='/assets/cards/s1/'.rawurlencode($selectedCard['ukid']).'.webp';
            $panelHtml=str_replace('<div class="pregame-card-media">', '<div class="pregame-card-media panel-card-media"><button type="button" class="panel-card-preview" data-card-original="'.$original.'" aria-label="Увеличить изображение">', $panelHtml);
            $panelHtml=preg_replace('~(</div>\s*<div class="pregame-card-stats")~', '</button><button type="button" class="panel-card-close" data-view-close aria-label="Закрыть карту"><img src="/assets/images/battle/close.png" width="28" height="28" alt=""></button>$1', $panelHtml, 1);
        }
        if (!$readOnly) $panelHtml=str_replace(
            ['pregame-card-heading"','pregame-card-cost"','pregame-card-heading-icons"','pregame-card-stats"'],
            ['pregame-card-heading panel-card-heading"','pregame-card-cost panel-card-cost"','pregame-card-heading-icons panel-card-heading-icons"','pregame-card-stats panel-card-stats"'], $panelHtml);
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
                'playmat_url' => \Berserk\Core\PlaymatCatalog::playerUrl($state->getPlayer($playerKey)),
                'deck_name'     => $deckInfo['name'],
                'total'         => $deckInfo['total'],
                'elite'         => $deckInfo['elite'],
                'ordinary'      => $deckInfo['ordinary'],
                'elements_html' => $elementsHtml,
                'cards_html'    => $cardsHtml,
                'sideboard_html'=> $sideboardHtml,
                'bottom_panel_html' => '',
                'panel_summary_html' => $panelSummary,
                'panel_actions_html' => '<div class="pregame-view-confirm info-actions">' . $confirmHtml . '<div class="battle-icon-actions"><button type="button" class="battle-icon-control" data-open-pause aria-label="Пауза игры" title="Пауза игры"><img src="/assets/images/battle/pause_160.png" width="28" height="28" alt=""><span class="battle-icon-tooltip" aria-hidden="true">Пауза игры</span></button><span class="battle-chat-anchor" data-chat-anchor></span></div></div><div data-view-notice role="status"></div>',
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

    private function thumbnail(PrepareUi $ui, array $catalog, array $data): string
    {
        if (!empty($data['legacy'])) return $ui->card($data);
        $info = array_replace($data['info'], $catalog[$data['ukid']] ?? []);
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ukid = $data['ukid'];
        $path = '/assets/cards/s1/' . rawurlencode($ukid) . '.webp';
        $art = '<img class="view-art" src="'.$e($path).'" width="400" height="250" alt="">';
        $element = explode(' ', $data['class'])[0];
        $element = substr($element, strlen('view-element-'));
        $cost = (!empty($info['elite']) ? 'elite' : 'ordinary') . (!empty($info['single']) ? '-unique' : '');
        return '<a aria-label="'.$e($info['name']??$ukid).'" class="prepare-card-link '.$e($data['class']).'" href="'.$e($data['link']).'" data-ukid="'.$e($ukid).'">'
            . '<div data-mini-art-only data-mini-card="view:'.$e($data['link']).'" data-mini-name="'.$e($info['name']??$ukid).'" class="prepare-card view-thumbnail'.($data['selected']?' prepare-card--selected':'').'">'.$art
            . '<img class="view-frame" src="/assets/images/battle/mini/frame_friendly_102x92_'.(!empty($info['elite'])?'plain':'silver').'.svg" alt="">'
            . '<span class="view-element" data-mini-hint data-view-element tabindex="0" role="button" aria-label="'.$e($info['element_label']??$info['element']??$element).'"><img src="/assets/images/element/'.$e($element).'.png" alt=""><span class="mini-hint-text" hidden>'.$e($info['element_label']??$info['element']??$element).'</span></span>'
            . '<span class="view-cost"><img src="/assets/images/card/'.$cost.'.png" alt="'.(!empty($info['elite'])?'Золотой':'Серебряный').(!empty($info['single'])?', уникальный':'').' кристалл">'.$e($info['price']??$data['info']['price']??0)
            . '<span class="prepare-card__count">×'.(int)$data['count'].'</span></span></div></a>';
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
                    'single' => $card->single,
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
                'single' => (bool) ($c['single'] ?? $info['single'] ?? false),
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
