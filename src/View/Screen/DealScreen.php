<?php
// src/View/Screen/DealScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\Core\CardInstance;
use Berserk\Core\Choice\ChoiceRegistry;
use Berserk\Core\Prepare\SquadRules;
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
        $baseUrl = "?{$linkParam}&game={$state->gameId}";
        $me = $state->getPlayer($playerKey);
        $ui = new PrepareUi($this->tpl);
        $selectedRaw = (string) ($_GET['card'] ?? '');
        [$selectedZone, $selectedUkid] = array_pad(explode(':', $selectedRaw, 2), 2, '');
        if (!in_array($selectedZone, ['hand', 'squad'], true)) $selectedUkid = '';

        $groups = [];
        foreach ($state->cards as $card) {
            if ($card->owner !== $playerKey || !in_array($card->zone, [CardInstance::ZONE_HAND, CardInstance::ZONE_SQUAD], true)) continue;
            if (!isset($cardsInfo[$card->ukid])) continue;
            $groups[$card->ukid] ??= ['hand' => 0, 'squad' => 0, 'card' => $card, 'info' => $cardsInfo[$card->ukid]];
            $groups[$card->ukid][$card->zone]++;
        }
        uasort($groups, fn($a, $b) => strcmp((string) $a['info']['name'], (string) $b['info']['name']));
        // A command may have moved the last copy since its preview URL was generated.
        if (isset($groups[$selectedUkid]) && empty($groups[$selectedUkid][$selectedZone])) {
            $selectedZone = $groups[$selectedUkid]['hand'] > 0 ? 'hand' : 'squad';
        }
        $cardsHtml = '';
        foreach ($groups as $ukid => $group) {
            $zone = $group['hand'] > 0 ? 'hand' : 'squad';
            $info = $group['info'];
            $code = $this->elementCode((string) ($info['element_code'] ?? $group['card']->element));
            $cardsHtml .= $ui->card([
                'ukid' => $ukid, 'info' => $info, 'count' => $group['hand'] + $group['squad'],
                'count_note' => $group['squad'] > 0 ? 'В отряде: ' . $group['squad'] : '',
                'class' => 'view-element-' . $code . ' view-crystal-' . ($group['card']->elite ? 'gold' : 'silver')
                    . ($group['squad'] > 0 ? ' deal-in-squad' : ''),
                'link' => $baseUrl . '&card=' . $zone . ':' . rawurlencode((string) $ukid),
                'selected' => $selectedUkid === (string) $ukid,
            ]);
        }

        $calc = ResourceCalculator::compute($state, $playerKey);
        $selectedCount = count($state->getCardsInZone($playerKey, CardInstance::ZONE_SQUAD));
        $flyingCost = SquadRules::flyingCost($state, $playerKey);
        $elementCounts = array_fill_keys(array_keys(self::ELEMENT_NAMES), 0);
        foreach ($state->getCardsInZone($playerKey, CardInstance::ZONE_SQUAD) as $card) {
            $elementCounts[$this->elementCode($card->element)]++;
        }
        $hasFlying = false;
        $elementTotals = array_fill_keys(array_keys(self::ELEMENT_NAMES), 0);
        foreach ($state->cards as $card) {
            if ($card->owner === $playerKey && in_array($card->zone, [CardInstance::ZONE_HAND, CardInstance::ZONE_SQUAD], true)) {
                $elementTotals[$this->elementCode($card->element)]++;
                if ($card->type === 'fly') $hasFlying = true;
            }
        }
        $summary = '<section class="pregame-pool-stats pregame-deal-stats" aria-label="Статистика отряда">';
        $summary .= '<div class="pregame-deal-resources">'
            . $this->statisticIcon('/assets/images/card/elite.png', 'Золото', $calc['gold_left'] . '/' . $calc['gold_total'], 'crystal:gold')
            . $this->statisticIcon('/assets/images/card/ordinary.png', 'Серебро', $calc['silver_left'] . '/' . $calc['silver_total'], 'crystal:silver')
            . ($hasFlying ? $this->statisticIcon('/assets/images/card/fly.png', 'Стоимость летающих', $flyingCost . '/' . SquadRules::FLYING_COST_LIMIT) : '')
            . ($calc['penalty'] > 0 ? '<span class="penalty active">Штраф за стихии: −' . (int) $calc['penalty'] . ' золото</span>' : '')
            . '</div><div class="pregame-deal-elements">';
        foreach (self::ELEMENT_NAMES as $code => $label) {
            $name = ElementLabels::label($code, $elementLabels + self::ELEMENT_NAMES);
            $summary .= $this->statisticIcon('/assets/images/element/' . $code . '.png', $name,
                $elementCounts[$code] . '/' . $elementTotals[$code], 'element:' . $code,
                $name . ': выбрано ' . $elementCounts[$code] . ', всего ' . $elementTotals[$code]);
        }
        $summary .= '</div></section>';

        $pendingHtml = $this->pendingPanelHtml($state, $playerKey, $baseUrl, $role, $cardsInfo);
        $selectedCard = null;
        $cardActions = [];
        $previewHtml = '';
        if (isset($groups[$selectedUkid])) {
            $info = $this->selectedPreviewInfo($state, $playerKey, $selectedZone, $selectedUkid, $groups[$selectedUkid]['info'], $cardsInfo);
            $selectedCard = ['ukid' => $selectedUkid, 'info' => $info];
            if (!$me->isConfirmed('deal') && $pendingHtml === '') {
                if ($groups[$selectedUkid]['hand'] > 0) {
                    $candidate = $this->selectedCardInstance($state, $playerKey, 'hand', $selectedUkid);
                    $error = $candidate ? SquadRules::validate($state, $playerKey, [$candidate]) : null;
                    $cardActions[] = ['label' => 'В отряд', 'enabled' => $error === null,
                        'url' => $baseUrl . '&cmd=pick_card&ukid=' . rawurlencode($selectedUkid) . '&card=hand:' . rawurlencode($selectedUkid)];
                }
                if ($groups[$selectedUkid]['squad'] > 0 && !$this->selectedSquadCardIsLinkedCompanion($state, $playerKey, $selectedUkid)) {
                    $cardActions[] = ['label' => 'Вернуть',
                        'url' => $baseUrl . '&cmd=unpick_card&ukid=' . rawurlencode($selectedUkid) . '&card=squad:' . rawurlencode($selectedUkid)];
                }
            }
            $actionHtml = $ui->actions($cardActions);
            $notesHtml = (string) ($info['notes_html'] ?? '');
            if ((string) ($info['price'] ?? '') !== (string) ($cardsInfo[$selectedUkid]['price'] ?? '')) {
                $notesHtml = '<p>Цена: ' . htmlspecialchars((string) $info['price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' . $notesHtml;
            }
            if ($actionHtml !== '' || $notesHtml !== '') {
                $previewHtml = '<section class="pregame-deal-card-actions" aria-label="Действия выбранной карты">'
                    . ($actionHtml !== '' ? '<div class="pregame-card-actions" data-deal-actions>' . $actionHtml . '</div>' : '')
                    . ($notesHtml !== '' ? '<div class="pregame-deal-notes">' . $notesHtml . '</div>' : '') . '</section>';
            }
        }
        $panelHtml = $pendingHtml !== '' ? $pendingHtml : $ui->information($selectedCard, $cardsInfo) . $previewHtml;
        $footerHtml = '<div class="pregame-deal-footer">';
        if ($pendingHtml === '') {
            $footerHtml .= !$me->isConfirmed('deal')
                ? '<a class="button" href="' . $baseUrl . '&cmd=confirm_deal" onclick="return confirm(\'Подтвердить отряд?\')">Подтвердить</a>'
                : '<span class="wait">Ожидание оппонента...</span>';
        }
        $footerHtml .= '<button type="button" class="pregame-deal-selected" data-deal-selected aria-pressed="false">Выбрано: <b>' . $selectedCount . '</b></button>';
        if (!$me->isConfirmed('deal') && $me->reshuffles < 3 && $pendingHtml === '') {
            $hint = 'Пересдать колоду (штраф: 1 золото, осталось: ' . (3 - $me->reshuffles) . ')';
            $footerHtml .= '<a class="pregame-hint pregame-reshuffle" data-pregame-hint-action href="' . $baseUrl . '&cmd=reshuffle"'
                . ' aria-label="' . $hint . '" onclick="return confirm(\'Пересдать карты?\')">'
                . '<img src="/assets/images/battle/reshuffle_160.png" alt="" width="40" height="40">'
                . '<template data-pregame-hint>' . $hint . '</template></a>';
        }
        $footerHtml .= '</div>';
        return ['screen' => 'deal', 'data' => [
            'cards_html' => $cardsHtml, 'hand_html' => $cardsHtml, 'squad_html' => '',
            'preview_html' => $previewHtml, 'panel_html' => $panelHtml,
            'panel_summary_html' => $summary, 'panel_actions_html' => $footerHtml,
            'bottom_panel_html' => '', 'message' => $message ?? '',
        ]];
    }

    private const ELEMENT_NAMES = ['dark' => 'Тьма', 'plains' => 'Степи', 'forests' => 'Леса',
        'swamps' => 'Болота', 'mountains' => 'Горы', 'neutral' => 'Нейтральная'];

    private function elementCode(string $code): string
    {
        return isset(self::ELEMENT_NAMES[$code]) ? $code : (array_search($code, self::ELEMENT_NAMES, true) ?: 'neutral');
    }

    private function statisticIcon(string $url, string $label, string $value, string $filter = '', string $hintText = ''): string
    {
        $hint = htmlspecialchars($hintText !== '' ? $hintText : $label . ': ' . $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attrs = $filter === '' ? '' : ' data-view-filter="' . $filter . '" data-view-stat-static aria-pressed="false"';
        return '<button type="button" class="pregame-hint pregame-pool-stat"' . $attrs . ' aria-label="' . $hint . '">'
            . '<img src="' . $url . '" alt="" width="24" height="24"><span>' . htmlspecialchars($value, ENT_QUOTES) . '</span>'
            . '<template data-pregame-hint>' . $hint . '</template></button>';
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
        array $info,
        array $cardsInfo
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

                $squadError = SquadRules::validate($state, $playerKey, [$card]);
                if ($squadError !== null) {
                    $info['notes_html'] = '<p class="bonus">Нельзя взять в отряд: '
                        . htmlspecialchars($squadError, ENT_QUOTES)
                        . '.</p>';
                }
            }

            $info['notes_html'] = ($info['notes_html'] ?? '')
                . $this->dealPreviewNotes($state, $playerKey, $card, $selectedZone, $cardsInfo);
            break;
        }

        return $info;
    }

    private function selectedCardInstance(GameState $state, string $playerKey, string $selectedZone, string $selectedUkid): ?CardInstance
    {
        foreach ($state->cards as $card) {
            if ($card->owner === $playerKey
                && $card->zone === $selectedZone
                && $card->ukid === $selectedUkid) {
                return $card;
            }
        }
        return null;
    }

    private function dealPreviewNotes(GameState $state, string $playerKey, CardInstance $card, string $selectedZone, array $cardsInfo): string
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

        $linkedRecruit = $card->prop['deal']['linked_recruit'] ?? null;
        if (is_array($linkedRecruit) && $selectedZone === CardInstance::ZONE_HAND) {
            $maxCost = max(0, (int) ($linkedRecruit['max_elite_cost'] ?? 7));
            $notes[] = 'В начале набора можно выбрать золотое существо стоимостью до '
                . $maxCost
                . '. Оно набирается за ту же числовую стоимость серебром и становится связанным с этой картой.';

            if (!empty($linkedRecruit['requires_empty_squad'])
                && count($state->getCardsInZone($playerKey, CardInstance::ZONE_SQUAD)) > 0) {
                $notes[] = 'Совместный набор доступен только в начале набора.';
            }
        }

        $appliedLink = $card->flags['deal_linked_recruit'] ?? null;
        if (is_array($appliedLink) && $selectedZone === CardInstance::ZONE_SQUAD) {
            $linked = $state->getCard((int) ($appliedLink['linked_instance_id'] ?? 0));
            $linkedName = $linked ? ($cardsInfo[$linked->ukid]['name'] ?? $linked->ukid) : 'связанная карта';

            if (($appliedLink['role'] ?? null) === 'source') {
                $notes[] = 'Связана с ' . $linkedName . '. При возврате этой карты обе карты вернутся в руку.';
            } elseif (($appliedLink['role'] ?? null) === 'companion') {
                $notes[] = 'Связана с ' . $linkedName . '. Отдельно вернуть нельзя; в бою погибнет, когда связанная карта покинет поле боя.';
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

    private function selectedSquadCardIsLinkedCompanion(GameState $state, string $playerKey, string $selectedUkid): bool
    {
        foreach ($state->cards as $card) {
            if ($card->owner !== $playerKey
                || $card->zone !== CardInstance::ZONE_SQUAD
                || $card->ukid !== $selectedUkid) {
                continue;
            }

            return ($card->flags['deal_linked_recruit']['role'] ?? null) === 'companion';
        }

        return false;
    }
}
