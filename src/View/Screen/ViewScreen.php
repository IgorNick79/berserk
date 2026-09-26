<?php
// src/View/Screen/ViewScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\Core\DeckView;
use Berserk\View\Template;
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
        array $cardsInfo = []
    ): array {
        $linkParam = $role === 'host' ? 'first' : 'second';
        $baseUrl   = "?{$linkParam}&game={$state->gameId}";
        $me = $state->getPlayer($playerKey);
        $ui = new PrepareUi($this->tpl);
        if ($me->deckId) {
            $deckInfo = $this->deckView->forDeck($me->deckId);
        } else {
            $deckInfo = $this->buildFromDeckCards($me->deckCards ?? [], $cardsInfo);
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
                    'confirm_html'  => '',
                    'message'       => 'Дека не найдена',
                ],
            ];
        }

        $selectedUkid = (string) ($_GET['card'] ?? '');
        $selectedCard = null;

        $cardsHtml = '';
        foreach ($deckInfo['cards'] as $c) {
            $ukid = (string) ($c['ukid'] ?? '');
            if ($selectedUkid === $ukid) {
                $selectedCard = ['ukid' => $ukid, 'info' => $c];
            }

            $cardsHtml .= $ui->card([
                'ukid'     => $ukid,
                'info'     => $c,
                'count'    => (int) ($c['count'] ?? 1),
                'link'     => "{$baseUrl}&card=" . urlencode($ukid),
                'selected' => $selectedUkid === $ukid,
            ]);
        }

        $elementsHtml = '';
        foreach ($deckInfo['elements'] as $code => $count) {
            $elementsHtml .= $this->tpl->parse('includes/deck_element.tpl', [
                'code'  => $code,
                'count' => $count,
            ]);
        }

        if (!$me->isConfirmed('view')) {
            $confirmUrl  = "?{$linkParam}&game={$state->gameId}&cmd=confirm_view";
            $confirmHtml = '<a class="button" href="' . $confirmUrl . '">Подтвердить</a>';
        } else {
            $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
        }

        $panelHtml = $ui->panel($selectedCard, [], 'Выбери карту');

        return [
            'screen' => 'view',
            'data'   => [
                'deck_name'     => $deckInfo['name'],
                'total'         => $deckInfo['total'],
                'elite'         => $deckInfo['elite'],
                'ordinary'      => $deckInfo['ordinary'],
                'elements_html' => $elementsHtml,
                'cards_html'    => $cardsHtml,
                'panel_html'    => $panelHtml,
                'confirm_html'  => $confirmHtml,
                'message'       => $message ?? '',
            ],
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
