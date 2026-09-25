<?php
// src/View/Screen/DealScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\Core\CardInstance;
use Berserk\Core\ResourceCalculator;
use Berserk\View\Template;

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
        array $cardsInfo = []
    ): array {
        $linkParam = $role === 'host' ? 'first' : 'second';
        $baseUrl   = "?{$linkParam}&game={$state->gameId}";
        $me        = $state->getPlayer($playerKey);

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
        $goldTotal     = (int) ($me->resources['gold'] ?? 0);
        $silverTotal   = (int) ($me->resources['silver'] ?? 0);
        $penalty       = $calc['penalty'];
        $elementsCount = $calc['elements_count'];

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

            $handHtml .= $this->tpl->parse('includes/deal_card.tpl', [
                'ukid'   => $ukid,
                'name'   => $info['name'],
                'count'  => $item['count'],
                'price'  => $info['price'],
                'health' => $info['health'],
                'move'   => $info['move'],
                'weak'   => $info['strike']['weak'],
                'medium' => $info['strike']['medium'],
                'strong' => $info['strike']['strong'],
                'elite'  => $info['elite'] ? 'elite' : '',
                'link'   => "{$baseUrl}&cmd=pick_card&ukid={$ukid}",
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

            $squadHtml .= $this->tpl->parse('includes/deal_card.tpl', [
                'ukid'   => $ukid,
                'name'   => $info['name'],
                'count'  => $item['count'],
                'price'  => $info['price'],
                'health' => $info['health'],
                'move'   => $info['move'],
                'weak'   => $info['strike']['weak'],
                'medium' => $info['strike']['medium'],
                'strong' => $info['strike']['strong'],
                'elite'  => $info['elite'] ? 'elite' : '',
                'link'   => "{$baseUrl}&cmd=unpick_card&ukid={$ukid}",
            ]);
        }

        // Решафл
        $reshuffleHtml = '';
        if (!$me->isConfirmed('deal') && $me->reshuffles < 3) {
            $reshuffleUrl  = "{$baseUrl}&cmd=reshuffle";
            $reshuffleHtml = '<a class="button reshuffle" href="' . $reshuffleUrl . '">'
                . 'Пересдать (штраф 1 золото, осталось ' . (3 - $me->reshuffles) . ')'
                . '</a>';
        }

        if (!$me->isConfirmed('deal')) {
            $confirmHtml = '<a class="button" href="' . $baseUrl . '&cmd=confirm_deal">Подтвердить отряд</a>';
        } else {
            $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
        }

        return [
            'screen' => 'deal',
            'data'   => [
                'hand_html'      => $handHtml,
                'squad_html'     => $squadHtml,
                'gold_left'      => $goldLeft,
                'silver_left'    => $silverLeft,
                'gold_total'     => $goldTotal,
                'silver_total'   => $silverTotal,
                'gold_class'     => $goldClass,
                'penalty'        => $penalty,
                'elements_count' => $elementsCount,
                'penalty_class'  => $penalty > 0 ? 'active' : '',
                'confirm_html'   => $confirmHtml,
                'reshuffle_html' => $reshuffleHtml,
                'message'        => $message ?? '',
            ],
        ];
    }
}