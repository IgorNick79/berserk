<?php
// src/View/Screen/DealScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\Core\CardInstance;
use Berserk\Core\Choice\ChoiceRegistry;
use Berserk\Core\ResourceCalculator;
use Berserk\View\Template;
use Berserk\View\Ui\ElementLabels;
use Berserk\View\Ui\Panel;
use Berserk\View\Ui\PrepareUi;

final class DealScreen
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
        array $elementLabels = []
    ): array {
        $linkParam = $role === 'host' ? 'first' : 'second';
        $baseUrl   = "?{$linkParam}&game={$state->gameId}";
        $me        = $state->getPlayer($playerKey);
        $ui        = new PrepareUi($this->tpl);
        $selectedRaw = (string) ($_GET['card'] ?? '');
        $selectedZone = '';
        $selectedUkid = '';
        if (str_contains($selectedRaw, ':')) {
            [$selectedZone, $selectedUkid] = explode(':', $selectedRaw, 2);
        }
        if (!in_array($selectedZone, ['hand', 'squad'], true)) {
            $selectedZone = '';
            $selectedUkid = '';
        }

        // Группируем руку по ukid
        $handGroups = [];
        foreach ($state->cards as $card) {
            if ($card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_HAND) continue;
            $handGroups[$card->ukid] = ($handGroups[$card->ukid] ?? 0) + 1;
        }

        // Группируем отряд по ukid
        $squadGroups = [];
        foreach ($state->cards as $card) {
            if ($card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_SQUAD) continue;
            $squadGroups[$card->ukid] = ($squadGroups[$card->ukid] ?? 0) + 1;
        }

        // Считаем ресурсы
        $calc = ResourceCalculator::compute($state, $playerKey);
        $goldLeft      = $calc['gold_left'];
        $silverLeft    = $calc['silver_left'];
        $goldTotal     = (int) ($calc['gold_total'] ?? ($me->resources['gold'] ?? 0));
        $silverTotal   = (int) ($calc['silver_total'] ?? ($me->resources['silver'] ?? 0));
        $penalty       = $calc['penalty'];
        $selectedCount = count($state->getCardsInZone($playerKey, CardInstance::ZONE_SQUAD));

        $goldClass = $goldLeft < 0 ? 'negative' : '';

        // Сортируем руки по имени
        $handItems = [];
        foreach ($handGroups as $ukid => $count) {
            $info = $cardsInfo[$ukid] ?? null;
            if (!$info) continue;
            $handItems[] = ['ukid' => $ukid, 'count' => $count, 'info' => $info];
        }
        usort($handItems, fn($a, $b) => strcmp($a['info']['name'], $b['info']['name']));

        $handHtml = '';
        foreach ($handItems as $item) {
            $ukid = $item['ukid'];
            $info = $item['info'];

            $handHtml .= $ui->card([
                'ukid'     => $ukid,
                'info'     => $info,
                'count'    => $item['count'],
                'link'     => "{$baseUrl}&card=hand:" . urlencode($ukid),
                'selected' => $selectedZone === 'hand' && $selectedUkid === $ukid,
            ]);
        }

        // Сортируем отряд по имени
        $squadItems = [];
        foreach ($squadGroups as $ukid => $count) {
            $info = $cardsInfo[$ukid] ?? null;
            if (!$info) continue;
            $squadItems[] = ['ukid' => $ukid, 'count' => $count, 'info' => $info];
        }
        usort($squadItems, fn($a, $b) => strcmp($a['info']['name'], $b['info']['name']));

        $squadHtml = '';
        foreach ($squadItems as $item) {
            $ukid = $item['ukid'];
            $info = $item['info'];

            $squadHtml .= $ui->card([
                'ukid'     => $ukid,
                'info'     => $info,
                'count'    => $item['count'],
                'link'     => "{$baseUrl}&card=squad:" . urlencode($ukid),
                'selected' => $selectedZone === 'squad' && $selectedUkid === $ukid,
            ]);
        }

        // Решафл
        $reshuffleHtml = '';
        if (!$me->isConfirmed('deal') && $me->reshuffles < 3) {
            $reshuffleUrl  = "{$baseUrl}&cmd=reshuffle";
            $reshuffleHtml = '<a class="button reshuffle" href="' . $reshuffleUrl . '"'
                . ' onclick="return confirm(\'Пересдать карты?\')">'
                . 'Пересдать (штраф 1 золото, осталось ' . (3 - $me->reshuffles) . ')'
                . '</a>';
        }

        if (!$me->isConfirmed('deal')) {
            $confirmHtml = '<a class="button" href="' . $baseUrl . '&cmd=confirm_deal"'
                . ' onclick="return confirm(\'Подтвердить отряд?\')">Подтвердить отряд</a>';
        } else {
            $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
        }

        $selectedCard = null;
        $cardActions = [];
        if ($selectedUkid !== '') {
            $selectedInfo = $cardsInfo[$selectedUkid] ?? null;
            if ($selectedInfo) {
                $selectedInfo = $this->selectedPreviewInfo($state, $playerKey, $selectedZone, $selectedUkid, $selectedInfo);
                $selectedCard = ['ukid' => $selectedUkid, 'info' => $selectedInfo];
                if (!$me->isConfirmed('deal') && $selectedZone === 'hand') {
                    $cardActions[] = [
                        'label' => 'В отряд',
                        'url'   => "{$baseUrl}&cmd=pick_card&ukid=" . urlencode($selectedUkid),
                    ];
                } elseif (!$me->isConfirmed('deal') && $selectedZone === 'squad') {
                    $cardActions[] = [
                        'label' => 'Вернуть',
                        'url'   => "{$baseUrl}&cmd=unpick_card&ukid=" . urlencode($selectedUkid),
                    ];
                }
            }
        }
        $previewHtml = $ui->preview($selectedCard, $cardActions, 'Выбери карту');

        $elementsHtml = '';
        foreach ((array) ($calc['elements'] ?? []) as $code => $count) {
            if ($count <= 0) continue;
            $name = ElementLabels::label((string) $code, $elementLabels);
            $elementsHtml .= '<span class="prepare-element">'
                . htmlspecialchars((string) $name, ENT_QUOTES)
                . ': <b>' . (int) $count . '</b>'
                . '</span>';
        }

        $penaltyHtml = '';
        if ($penalty > 0) {
            $penaltyHtml = '<div class="prepare-bottom-row">'
                . '<span class="penalty active">Штраф за стихии: <b>−' . (int) $penalty . ' золото</b></span>'
                . '</div>';
        }

        $bottomPanelHtml = $this->pendingPanelHtml($state, $playerKey, $baseUrl, $role, $cardsInfo);
        if ($bottomPanelHtml === '') {
            $bottomPanelHtml = '<div class="prepare-bottom-summary prepare-bottom-summary--stack">'
                . '<div class="prepare-bottom-row">'
                . '<span>Выбрано: <b>' . $selectedCount . '</b></span>'
                . '<span class="gold ' . htmlspecialchars($goldClass, ENT_QUOTES) . '">Золото: <b>' . $goldLeft . '</b>/' . $goldTotal . '</span>'
                . '<span>Серебро: <b>' . $silverLeft . '</b>/' . $silverTotal . '</span>'
                . '</div>'
                . $penaltyHtml
                . '<div class="prepare-elements">' . $elementsHtml . '</div>'
                . '</div>'
                . '<div class="prepare-bottom-actions">' . $confirmHtml . $reshuffleHtml . '</div>';
        }

        return [
            'screen' => 'deal',
            'data'   => [
                'hand_html'      => $handHtml,
                'squad_html'     => $squadHtml,
                'preview_html'   => $previewHtml,
                'bottom_panel_html' => $bottomPanelHtml,
                'message'        => $message ?? '',
            ],
        ];
    }

    private function pendingPanelHtml(GameState $state, string $playerKey, string $baseUrl, string $role, array $cardsInfo): string
    {
        $activeChoice = ChoiceRegistry::current($state);
        if ($activeChoice === null) return '';

        $spec = $activeChoice->spec($state, $playerKey, $cardsInfo, $baseUrl, $role);
        return $spec === null ? '' : Panel::render($spec);
    }

    private function selectedPreviewInfo(
        GameState $state,
        string $playerKey,
        string $selectedZone,
        string $selectedUkid,
        array $info
    ): array {
        foreach ($state->cards as $card) {
            if ($card->owner !== $playerKey
                || $card->zone !== $selectedZone
                || $card->ukid !== $selectedUkid) {
                continue;
            }

            if ($selectedZone === 'hand') {
                $effectiveCost = ResourceCalculator::effectiveRecruitCost($state, $playerKey, $card);
                $baseCost = (int) ($info['price'] ?? $card->price);
                if ($effectiveCost !== $baseCost) {
                    $info['price'] = $effectiveCost . ' (обычно ' . $baseCost . ')';
                }
            }

            $info['notes_html'] = $this->dealPreviewNotes($state, $playerKey, $card, $selectedZone);
            break;
        }

        return $info;
    }

    private function dealPreviewNotes(GameState $state, string $playerKey, CardInstance $card, string $selectedZone): string
    {
        $notes = [];

        $scout = $card->prop['deal']['scout_recruit'] ?? null;
        if (is_array($scout) && $selectedZone === CardInstance::ZONE_HAND) {
            $revealCount = max(1, (int) ($scout['reveal_count'] ?? 2));
            $discountPerElite = max(0, (int) ($scout['discount_per_elite'] ?? 1));

            $notes[] = 'В начале набора можно раскрыть '
                . $revealCount
                . ' карты оппонента. Каждая золотая карта уменьшает серебряную стоимость на '
                . $discountPerElite
                . '. После подтверждения набор обязателен.';

            if (!empty($scout['requires_empty_squad'])
                && count($state->getCardsInZone($playerKey, CardInstance::ZONE_SQUAD)) > 0) {
                $notes[] = 'Разведка доступна только в начале набора.';
            }
        }

        $appliedScout = $card->flags['deal_scout_recruit'] ?? null;
        if (is_array($appliedScout) && $selectedZone === CardInstance::ZONE_SQUAD) {
            $notes[] = 'Разведка: золотых карт раскрыто '
                . (int) ($appliedScout['elite_count'] ?? 0)
                . ', скидка к серебряной стоимости −'
                . (int) ($appliedScout['silver_discount'] ?? 0)
                . '.';

            if (!empty($appliedScout['return_locked'])) {
                $notes[] = 'После разведки эту карту нельзя вернуть.';
            }
        }

        if (empty($notes)) {
            return '';
        }

        $html = '';
        foreach ($notes as $note) {
            $html .= '<p class="bonus">' . htmlspecialchars($note, ENT_QUOTES) . '</p>';
        }

        return $html;
    }
}
