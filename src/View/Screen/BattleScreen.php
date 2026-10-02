<?php
// src/View/Screen/BattleScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\Core\CardInstance;
use Berserk\Core\ZoneManager;
use Berserk\Core\BattleHelper;
use Berserk\Core\CardStats;
use Berserk\View\Template;
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Ui\Badge;

final class BattleScreen
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
        array $cardsInfo
    ): array {
        $oppKey    = $state->getOpponentKey($playerKey);
        $linkParam = $role === 'host' ? 'first' : 'second';
        $baseUrl   = "?{$linkParam}&game={$state->gameId}";
        $isHost    = ($playerKey === 'host');
        $isActive  = ($state->battle['active'] === $playerKey);
        $strike    = $state->battle['strike'] ?? null;

        $pendingAnyDeath = $state->battle['pending_any_death'][0] ?? null;

        // Выбранная карта
        $selectedCardId = (int) ($_GET['sel'] ?? 0);
        if ($selectedCardId > 0) {
            $c = $state->getCard($selectedCardId);
            if (!$c
                || ($c->zone !== CardInstance::ZONE_FIELD
                    && $c->zone !== CardInstance::ZONE_FLYING)) {
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

        // Возможные ходы/прыжки
        $moveCells = [];
        $jumpCells = [];
        if ($selectedCardId > 0 && $isActive && !$strike) {
            $c = $state->getCard($selectedCardId);
            if ($c && $c->owner === $playerKey) {
                $moveCells = BattleHelper::getMoveCells($state, $c);
                $jumpCells = BattleHelper::getJumpCells($state, $c);
            }
        }

        // Режим и цели
        $mode = (string) ($_GET['mode'] ?? 'strike');
        if ($mode === '') $mode = 'strike';

        $attackTargets = [];
        if ($selectedCardId > 0 && $isActive && !$strike) {
            $c = $state->getCard($selectedCardId);
            if ($c && $c->owner === $playerKey) {
                $attackTargets = BattleHelper::getAttackTargets($state, $c, $mode, $playerKey);
            }
        }

        $pendingDefenderTargets = $this->pendingDefenderTargets($state, $playerKey, $baseUrl);

        // Порядок осей
        $rowOrder = $isHost ? [6, 5, 4, 3, 2, 1] : [1, 2, 3, 4, 5, 6];
        $colOrder = $isHost ? [1, 2, 3, 4, 5] : [5, 4, 3, 2, 1];

        // Зона летающих
        $flyMap = ['host' => [], 'player' => []];
        foreach ($state->cards as $card) {
            if ($card->zone === CardInstance::ZONE_FLYING) {
                $flyMap[$card->owner][$card->slot] = $card;
            }
        }

        // ─── Собираем HTML-блоки ─────────────────────────────
        $fieldHtml      = $this->buildField(
            $state, $playerKey, $rowOrder, $colOrder, $fieldMap,
            $cardsInfo, $selectedCardId, $mode, $baseUrl,
            $moveCells, $jumpCells, $attackTargets, $pendingDefenderTargets
        );
        $flyZonesHtml   = $this->buildFlyZones(
            $state, $playerKey, $oppKey, $flyMap, $cardsInfo,
            $selectedCardId, $mode, $baseUrl, $attackTargets, $pendingDefenderTargets
        );
        $pilesHtml = $this->buildPiles($state, $playerKey, $oppKey, $baseUrl);
        $panelHtml      = $this->buildPanel(
            $state, $playerKey, $selectedCardId, $cardsInfo,
            $baseUrl, $mode, $isActive, $strike, $attackTargets
        );
        
        $infoPanelHtml = (new InfoPanel($this->tpl))->render($state, $playerKey, $role, $cardsInfo, $baseUrl);

        $debugHtml = '';
        if (isset($_SESSION['debug']) && $_SESSION['debug'] == '1') {
            $debugHtml = $this->buildDebug($state, $playerKey, $strike);
        }

        $pileRevealHtml = '';
        if (isset($_GET['pile']) && $_GET['pile'] !== '') {
            $pileRevealHtml = $this->buildPileReveal($state, $playerKey, $oppKey, $cardsInfo);
        }

        return [
            'screen' => 'battle',
            'data'   => [
                'debug_html' => $debugHtml,
                'field_html'       => $fieldHtml,
                'panel_html'       => $panelHtml,
                'fly_zones_html'    => $flyZonesHtml,
                'piles_html'        => $pilesHtml,
                'pile_reveal_html'  => $pileRevealHtml,
                'info_panel_html'  => $infoPanelHtml,
            ],
        ];
    }

    // ─── Поле ────────────────────────────────────────────────

    private function buildField(
        GameState $state,
        string $playerKey,
        array $rowOrder,
        array $colOrder,
        array $fieldMap,
        array $cardsInfo,
        int $selectedCardId,
        string $mode,
        string $baseUrl,
        array $moveCells,
        array $jumpCells,
        array $attackTargets,
        array $pendingDefenderTargets
    ): string {
        $html = '';
        foreach ($rowOrder as $r) {
            $rowCells = '';
            foreach ($colOrder as $c) {
                $key         = "{$r}_{$c}";
                $cellContent = '';
                $cellClass   = '';
                $card        = $fieldMap[$key] ?? null;

                if ($card) {
                    $info = $cardsInfo[$card->ukid] ?? null;

                    $isHiddenForMe = (!$card->revealed && $card->owner !== $playerKey);

                    if ($isHiddenForMe) {
                        $nameHtml = '???';
                        $hpText   = '?/?';
                    } else {
                        $nameHtml = $info ? htmlspecialchars($info['name'], ENT_QUOTES) : '?';
                        $hpText   = $card->hp . '/' . $card->hpMax;;
                    }

                    $ownerClass  = $card->owner === $playerKey ? 'own' : 'enemy';
                    $closedClass = $card->closed ? 'closed' : '';
                    $cellClass   = "$ownerClass $closedClass";
                    if ($isHiddenForMe) $cellClass .= ' hidden';
                    if ($card->dying)   $cellClass .= ' dying';

                    $coinsBadge = '';
                    if ($card->coins > 0) {
                        $coinsBadge = '<div class="bcoins">' . $card->coins . '</div>';
                    }

                    $armorBadge = '';
                    if ($card->armor > 0) {
                        $armorBadge = '<div class="barmor">' . $card->armor . '</div>';
                    }

                    $markersHtml = $this->buildCardBadges($card, $state);

                    $cardBody = '<div class="battle-card">'
                        . '<div class="bname">' . $nameHtml . '</div>'
                        . '<div class="bhp">' . $hpText . '</div>'
                        . $coinsBadge
                        . $markersHtml
                        . $armorBadge
                        . '</div>';

                    if (isset($pendingDefenderTargets[$card->instanceId])) {
                        $cellClass  .= ' attack-target pending-defender-target';
                        $cellContent = '<a class="card-link" href="' . $pendingDefenderTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                    } elseif (isset($attackTargets[$card->instanceId]) && $selectedCardId > 0 && $card->instanceId !== $selectedCardId) {
                        if (str_starts_with($mode, 'action:')) {
                            $actionKey = substr($mode, 7);
                            $atkUrl = "{$baseUrl}&cmd=action&action_key={$actionKey}&card_id={$selectedCardId}&target_id={$card->instanceId}&sel={$selectedCardId}&mode={$mode}";
                        } else {
                            $atkUrl = "{$baseUrl}&cmd={$mode}&card_id={$selectedCardId}&target_id={$card->instanceId}&sel={$selectedCardId}&mode={$mode}";
                        }
                        $cellClass  .= ' attack-target';
                        $cellContent = '<a class="card-link" href="' . $atkUrl . '">' . $cardBody . '</a>';
                    } elseif ($card->owner === $playerKey || $card->revealed) {
                        $selUrl      = "{$baseUrl}&sel={$card->instanceId}";
                        $cellContent = '<a class="card-link" href="' . $selUrl . '">' . $cardBody . '</a>';
                    } else {
                        $cellContent = $cardBody;
                    }
                } elseif (isset($moveCells[$key])) {
                    $moveUrl     = "{$baseUrl}&cmd=move&card_id={$selectedCardId}&row={$r}&col={$c}&sel={$selectedCardId}";
                    $cellClass   = 'move-target';
                    $cellContent = '<a class="cell-link" href="' . $moveUrl . '"></a>';
                } elseif (isset($jumpCells[$key])) {
                    $jumpUrl     = "{$baseUrl}&cmd=jump&card_id={$selectedCardId}&row={$r}&col={$c}&sel={$selectedCardId}";
                    $cellClass   = 'jump-target';
                    $cellContent = '<a class="cell-link" href="' . $jumpUrl . '"></a>';
                }

                // ─── ОВЕРЛЕЙ: всегда в конце, на уровне клетки ───
                $cellContent .= $this->buildCellMarkersOverlay($state, $key);

                $rowCells .= $this->tpl->parse('includes/battle_cell.tpl', [
                    'cl'      => $cellClass,
                    'content' => $cellContent,
                ]);
            }
            $html .= '<div class="field-row">' . $rowCells . '</div>';
        }
        return $html;
    }

    // ─── Летающие ────────────────────────────────────────────

    private function buildFlyZones(
        GameState $state,
        string $playerKey,
        string $oppKey,
        array $flyMap,
        array $cardsInfo,
        int $selectedCardId,
        string $mode,
        string $baseUrl,
        array $attackTargets,
        array $pendingDefenderTargets
    ): string {
        $html = '';
        foreach (['opp', 'own'] as $who) {
            $ownerKey = $who === 'opp' ? $oppKey : $playerKey;

            ksort($flyMap[$ownerKey]);
            $slotsHtml = '';
            foreach ($flyMap[$ownerKey] as $card) {
                $cellContent = '';
                $cellClass   = 'fly-slot';

                $info     = $cardsInfo[$card->ukid] ?? null;
                $nameHtml = $info ? htmlspecialchars($info['name'], ENT_QUOTES) : '?';
                $hpText   = $card->hp . '/' . $card->hpMax;
                $ownerClass = $card->owner === $playerKey ? 'own' : 'enemy';
                $cellClass .= ' ' . $ownerClass;
                if ($card->closed) {
                    $cellClass .= ' closed';
                }

                $coinsBadge = $card->coins > 0 ? '<div class="bcoins">' . $card->coins . '</div>' : '';

                $armorBadge = '';
                if ($card->armor > 0) {
                    $armorBadge = '<div class="barmor">' . $card->armor . '</div>';
                }

                $markersHtml = $this->buildCardBadges($card, $state);

                $cardBody = '<div class="battle-card">'
                    . '<div class="bname">' . $nameHtml . '</div>'
                    . '<div class="bhp">' . $hpText . '</div>'
                    . $coinsBadge
                    . $markersHtml
                    . $armorBadge
                    . '</div>';

                if (isset($pendingDefenderTargets[$card->instanceId])) {
                    $cellClass  .= ' attack-target pending-defender-target';
                    $cellContent = '<a class="card-link" href="' . $pendingDefenderTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                } elseif (isset($attackTargets[$card->instanceId]) && $selectedCardId > 0 && $card->instanceId !== $selectedCardId) {
                    if (str_starts_with($mode, 'action:')) {
                        $actionKey = substr($mode, 7);
                        $atkUrl = "{$baseUrl}&cmd=action&action_key={$actionKey}&card_id={$selectedCardId}&target_id={$card->instanceId}&sel={$selectedCardId}&mode={$mode}";
                    } else {
                        $atkUrl = "{$baseUrl}&cmd={$mode}&card_id={$selectedCardId}&target_id={$card->instanceId}&sel={$selectedCardId}&mode={$mode}";
                    }
                    $cellClass  .= ' attack-target';
                    $cellContent = '<a class="card-link" href="' . $atkUrl . '">' . $cardBody . '</a>';
                } else {
                    $selUrl      = "{$baseUrl}&sel={$card->instanceId}";
                    $cellContent = '<a class="card-link" href="' . $selUrl . '">' . $cardBody . '</a>';
                }

                $slotsHtml .= $this->tpl->parse('includes/battle_cell.tpl', [
                    'cl'      => $cellClass,
                    'content' => $cellContent,
                ]);
            }

            $html .= '<div class="fly-zone ' . $who . '">' . $slotsHtml . '</div>';
        }
        return $html;
    }

    // ─── Показ произвольного набор карт ────────────────────────────────────────────

    private function buildPiles(
        GameState $state,
        string $playerKey,
        string $oppKey,
        string $baseUrl
    ): string {
        $zone = new ZoneManager($state);
        $html = '';

        foreach (['opp', 'own'] as $who) {
            $ownerKey = $who === 'opp' ? $oppKey : $playerKey;

            $grave = $zone->countInZone($ownerKey, CardInstance::ZONE_GRAVEYARD);
            $exile = $zone->countInZone($ownerKey, CardInstance::ZONE_EXILE);
            $total = $grave + $exile;

            $html .= '<div class="piles ' . $who . '">'
                . '<div class="piles-label">Вне игры</div>'
                . '<div class="piles-count">' . $total . '</div>'
                . '<div class="piles-buttons">'
                . '<a class="pile-open" href="' . $baseUrl . '&pile=' . $who . '_grave">Кладбище ' . $grave . '</a>'
                . '<a class="pile-open" href="' . $baseUrl . '&pile=' . $who . '_exile">Изгнание ' . $exile . '</a>'
                . '</div>'
                . '</div>';
        }
        return $html;
    }

    /**
     * Собирает HTML-бейджи маркеров и модификаторов для карты.
     * Используется в buildField и buildFlyZones.
     */
    private function buildCardBadges(CardInstance $card, GameState $state): string
    {
        return Badge::forCard($card, $state);
    }

    /**
     * @return array<int,string>
     */
    private function pendingDefenderTargets(GameState $state, string $playerKey, string $baseUrl): array
    {
        $strike = $state->battle['strike'] ?? null;
        if (!is_array($strike) || ($strike['state'] ?? null) !== 'waiting_defender') {
            return [];
        }

        $attacker = $state->getCard((int) ($strike['attacker_id'] ?? 0));
        if (!$attacker) {
            return [];
        }

        if ($playerKey !== $state->getOpponentKey($attacker->owner)) {
            return [];
        }

        $targets = [];
        foreach ($strike['defenders'] ?? [] as $defenderId) {
            $defenderId = (int) $defenderId;
            if ($state->getCard($defenderId)) {
                $targets[$defenderId] = $baseUrl . '&cmd=choose_defender&defender_id=' . $defenderId;
            }
        }

        return $targets;
    }

    // ─── Панель выбранной карты ──────────────────────────────

    private function buildPanel(
        GameState $state,
        string $playerKey,
        int $selectedCardId,
        array $cardsInfo,
        string $baseUrl,
        string $mode,
        bool $isActive,
        ?array $strike,
        array $attackTargets
    ): string {
        if ($selectedCardId <= 0) return '';

        $c = $state->getCard($selectedCardId);
        if (!$c) return '';

        $info = $cardsInfo[$c->ukid] ?? null;
        if (!$info) return '';

        $imgHtml = '<img src="/assets/cards/s1/' . htmlspecialchars($c->ukid, ENT_QUOTES) . '.jpg"'
            . ' alt="' . htmlspecialchars($info['name'], ENT_QUOTES) . '"'
            . ' class="panel-card-img"'
            . ' onerror="this.style.display=\'none\'">';

        // Кнопки режимов
        $modeButtons = '';
        if ($isActive && !$strike && $c->owner === $playerKey) {
            $abil = BattleHelper::abilities($c);

            $attackLimit = (int) ($c->prop['attacks_per_turn'] ?? 1);
            $attacksUsed = (int) ($c->flags['attacks_used_this_turn'] ?? 0);
            $strikeBlocked = $attacksUsed >= $attackLimit;

            if ($abil['has_strike']) {
                if ($strikeBlocked) {
                    $modeButtons .= '<span class="button small disabled">Простой удар (уже атаковал)</span> ';
                } else {
                    $modeButtons .= $this->tpl->parse('includes/battle/mode_button.tpl', [
                        'label'  => 'Простой удар',
                        'link'   => "{$baseUrl}&sel={$c->instanceId}&mode=strike",
                        'active' => $mode === 'strike' ? ' active' : '',
                    ]);
                }
            }
            if ($abil['has_uchr']) {
                $modeButtons .= $this->tpl->parse('includes/battle/mode_button.tpl', [
                    'label'  => 'УЧР',
                    'link'   => "{$baseUrl}&sel={$c->instanceId}&mode=uchr",
                    'active' => $mode === 'uchr' ? ' active' : '',
                ]);
            }

            $labels = [
                'shot'      => 'Выстрел',
                'throw'     => 'Метание',
                'discharge' => 'Разряд',
                'tap'       => 'Особый удар',
                'magic'     => 'Магический удар',
                'cast'      => 'Заклинание',
                'heal'      => 'Излечение',
                'impact'    => 'Воздействие',
                'execute'   => 'Добивание',
                'jump'      => 'Прыжок',
                'magic' => 'Магический удар',
                'steal_strike' => 'Украсть оружие',
                'sand_claws' => 'Песчаные когти',
                'blood_tap' => 'Кровавый разряд',
                'poison_target'   => 'Сладострастная аура',
                'damage_poisoned' => 'Власть Ундины',
                'dissonance' => 'Диссонанс',
                'steal_coin' => 'Уловка',
                'give_coin' => 'Передать монету',
                'become_fly' => 'Получить полёт',
                'dive' => 'Пикирование',
                'bomb_shot' => 'Бомба',
            ];

            foreach ($c->prop['actions'] ?? [] as $a) {
                $type = $a['type'] ?? '';
                if ($type === 'uchr') continue;
                $key  = $a['key'] ?? $type;

                $label = $a['name'] ?? ($labels[$type] ?? $type);

                // Ведьма слуа — блокируем если нет доп. жизней
                if ($type === 'blood_tap') {
                    $base = (int) ($a['base_hp'] ?? 8);
                    if (($c->hp - $base) <= 0) {
                        $modeButtons .= '<span class="button small disabled">Кровавый разряд (нет доп. жизней)</span> ';
                        continue;
                    }
                }

                if ($type === 'steal_coin' && !empty($c->flags['steal_coin_used'])) {
                    $modeButtons .= '<span class="button small disabled">Уловка (использована)</span> ';
                    continue;
                }

                if ($type === 'dive' && !empty($c->flags['dive_used'])) {
                    $modeButtons .= '<span class="button small disabled">Пикирование (использовано)</span> ';
                    continue;
                }

                if ($type === 'give_coin' && $c->coins < 1) {
                    $modeButtons .= '<span class="button small disabled">Передать монету (нет монет)</span> ';
                    continue;
                }
                
                if ($type === 'shot'
                    && !empty($a['condition'])
                    && ($a['condition']['type'] ?? '') === 'ally_price_near') {

                    $moved   = !empty($c->flags['moved_this_turn']);
                    $used    = !empty($c->flags['shot_used_this_turn']);
                    $hasAlly = CardStats::hasAllyPriceNear($state, $c, (int) $a['condition']['min']);

                    if (!$moved || $used || !$hasAlly) {
                        $modeButtons .= '<span class="button small disabled">'
                            . htmlspecialchars($label . ' (нужно подойти к 7+)', ENT_QUOTES)
                            . '</span> ';
                        continue;
                    }
                }
                
                $cost = (int) ($a['coins'] ?? 0);
                if ($cost > 0) {
                    $label .= ' (' . $cost . ' мон.)';
                    if ($c->coins < $cost) {
                        $modeButtons .= '<span class="button small disabled">' . htmlspecialchars($label, ENT_QUOTES) . '</span> ';
                        continue;
                    }
                }

                // Проверка монет через prop.coins[type] (Мастер топора, Пустотник)
                $coinConfig = $c->prop['coins'][$type] ?? null;
                if (is_array($coinConfig) && ($coinConfig['spend'] ?? '') === 'choice') {
                    $min = (int) ($coinConfig['min_value'] ?? 0);
                    if ($min > 0 && $c->coins < $min) {
                        $modeButtons .= '<span class="button small disabled">'
                            . htmlspecialchars($label . ' (нужно ' . $min . ' мон.)', ENT_QUOTES)
                            . '</span> ';
                        continue;
                    }
                }

                $immediate = !empty($a['self']) 
                    || !empty($a['self_destroy']) 
                    || !empty($a['transfer_wounds'])
                    || ($a['type'] ?? '') === 'prophecy'
                    || ($a['type'] ?? '') === 'revive'
                    || ($a['type'] ?? '') === 'grezy_prophecy'
                    || ($a['type'] ?? '') === 'damage_poisoned'
                    || ($a['type'] ?? '') === 'place_cell_marker'
                    || ($a['type'] ?? '') === 'wound_transfer'
                    || ($a['type'] ?? '') === 'become_fly'
                    || !empty($a['max_targets']);

                if ($immediate) {
                    $link = "{$baseUrl}&cmd=action&action_key={$key}&card_id={$c->instanceId}&target_id={$c->instanceId}&sel={$c->instanceId}&mode=action:{$key}";
                } else {
                    $link = "{$baseUrl}&sel={$c->instanceId}&mode=action:{$key}";
                }

                $modeButtons .= $this->tpl->parse('includes/battle/mode_button.tpl', [
                    'label'  => htmlspecialchars($label, ENT_QUOTES),
                    'link'   => $link,
                    'active' => $mode === "action:{$key}" ? ' active' : '',
                ]);
            }

            if (!empty($c->prop['save_coins'])) {
                $max     = (int) ($c->prop['coins']['max_value'] ?? 0);
                $canGain = ($max === 0 || $c->coins < $max);

                if ($canGain) {
                    $coinUrl = "{$baseUrl}&cmd=gain_coin&card_id={$c->instanceId}&sel={$c->instanceId}";
                    $modeButtons .= $this->tpl->parse('includes/battle/mode_button.tpl', [
                        'label'  => 'Накопить монету',
                        'link'   => $coinUrl,
                        'active' => '',
                    ]);
                } else {
                    $modeButtons .= '<span class="button small disabled">Монет максимум</span> ';
                }
            }
        }

        // Статус-строка
        $statusHtml = '';
        if ($c->closed) {
            $statusHtml = $this->tpl->parse('includes/battle/panel_status.tpl', ['text' => 'Карта закрыта']);
        } elseif ($isActive && !$strike && $c->owner === $playerKey) {
            if (empty($attackTargets)) {
                $statusHtml = $this->tpl->parse('includes/battle/panel_status.tpl', ['text' => 'Нет целей']);
            } else {
                $statusHtml = '<p>Кликни по существу оппонента для атаки.</p>';
            }
        }
        $statusHtml .= $this->linkedRecruitBattleHint($state, $c, $cardsInfo);

        $coinsLine = '';
        if (!empty($c->prop['save_coins']) || $c->coins > 0) {
            $max = (int) ($c->prop['coins']['max_value'] ?? 0);
            $coinsLine = ' | Монеты: ' . $c->coins . ($max > 0 ? ' / ' . $max : '');
        }

        return $this->tpl->parse('includes/battle/panel.tpl', [
            'image_html'   => $imgHtml,
            'name'         => htmlspecialchars($info['name'], ENT_QUOTES),
            'mode_buttons' => $modeButtons,
            'hp'           => $c->hp,
            'hp_max'       => $info['health'],
            'move'         => $c->move,
            'move_max'     => $c->moveMax,
            'coins_line'   => $coinsLine,
            'status_html'  => $statusHtml,
        ]);
    }

    private function linkedRecruitBattleHint(GameState $state, CardInstance $card, array $cardsInfo): string
    {
        $link = $card->flags['deal_linked_recruit'] ?? null;
        if (!is_array($link)) {
            return '';
        }

        $linked = $state->getCard((int) ($link['linked_instance_id'] ?? 0));
        $linkedName = $linked ? ($cardsInfo[$linked->ukid]['name'] ?? $linked->ukid) : 'связанная карта';

        if (($link['role'] ?? null) === 'source') {
            return '<p class="bonus">Связь: если эта карта покинет поле боя, '
                . htmlspecialchars($linkedName, ENT_QUOTES)
                . ' погибнет.</p>';
        }

        if (($link['role'] ?? null) === 'companion') {
            return '<p class="bonus">Связь: погибает, когда '
                . htmlspecialchars($linkedName, ENT_QUOTES)
                . ' покидает поле боя.</p>';
        }

        return '';
    }


    // ─── Actions (Завершить ход / Сдаться) ───────────────────

    private function buildActions(
        GameState $state,
        bool $isActive,
        ?array $strike,
        string $baseUrl
    ): string {
        if ($state->winner !== null) return '';

        if ($isActive && !$strike) {
            $endUrl    = "{$baseUrl}&cmd=end_turn";
            $resignUrl = "{$baseUrl}&cmd=resign";
            return '<a class="button wide" href="' . $endUrl . '">Завершить ход</a> '
                . '<a class="button wide resign" href="' . $resignUrl . '" onclick="return confirm(\'Сдаться?\')">Сдаться</a>';
        }

        if ($isActive && $strike) {
            return '<p class="wait">Идёт сражение</p>';
        }

        return '<p class="wait">Ход оппонента</p>';
    }

    private function buildPileReveal(
        GameState $state,
        string $playerKey,
        string $oppKey,
        array $cardsInfo
    ): string {
        $pile = (string) ($_GET['pile'] ?? '');
        if ($pile === '') return '';

        $parts = explode('_', $pile, 2);
        if (count($parts) !== 2) return '';
        [$who, $source] = $parts;
        if (!in_array($who, ['own', 'opp'], true)) return '';
        if (!in_array($source, ['grave', 'exile'], true)) return '';

        $ownerKey = $who === 'own' ? $playerKey : $oppKey;
        $zoneName = $source === 'grave' ? CardInstance::ZONE_GRAVEYARD : CardInstance::ZONE_EXILE;

        $cards = [];
        foreach ($state->cards as $card) {
            if ($card->owner !== $ownerKey) continue;
            if ($card->zone !== $zoneName) continue;
            $cards[] = $card;
        }

        $whoLabel    = $who === 'own' ? 'Ваше' : 'Чужое';
        $sourceLabel = $source === 'grave' ? 'кладбище' : 'изгнание';
        $title       = $whoLabel . ' ' . $sourceLabel . ' (' . count($cards) . ')';

        $html = '<div class="pile-reveal">';
        $html .= '<div class="pile-reveal-title">' . $title . '</div>';

        if (empty($cards)) {
            $html .= '<p class="wait">Пусто</p>';
        } else {
            $html .= '<div class="pile-reveal-cards">';
            foreach ($cards as $card) {
                $info = $cardsInfo[$card->ukid] ?? null;
                $name = $info ? htmlspecialchars($info['name'], ENT_QUOTES) : $card->ukid;

                $markersHtml = '';
                if (!empty($card->flags['incarnation_ready'])) {
                    $markersHtml .= '<span class="pile-marker pile-marker-ready">Готов</span>';
                }
                foreach ($card->markers as $type => $m) {
                    if ($type === 'incarnation') {
                        $value = !empty($card->flags['incarnation_ready']) ? '—' : $m['value'];
                        $label = 'Инкарнация: ' . $value . '/' . $m['threshold'];
                    } elseif ($type === 'valhalla') {
                        $label = 'Вальхалла';
                    } else {
                        $label = $type;
                    }
                    $markersHtml .= '<span class="pile-marker pile-marker-'
                        . htmlspecialchars($type, ENT_QUOTES) . '">'
                        . htmlspecialchars($label, ENT_QUOTES) . '</span>';
                }

                $html .= '<div class="pile-card">'
                    . '<div class="pile-card-name">' . $name . '</div>'
                    . '<div class="pile-card-markers">' . $markersHtml . '</div>'
                    . '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function buildDebug(GameState $state, string $playerKey, ?array $strike): string
    {
        $lines = [];
        $lines[] = '=== DEBUG ===';
        $lines[] = 'player: ' . $playerKey;
        $lines[] = 'active: ' . ($state->battle['active'] ?? '?');
        $lines[] = 'turn: ' . ($state->battle['turn'] ?? '?');
        $lines[] = 'version: ' . $state->version;
        $lines[] = 'status: ' . $state->status;
        $lines[] = 'winner: ' . ($state->winner ?? '—');
        $lines[] = 'first_player: ' . ($state->getFirstPlayerKey() ?? '?');

        // ─── Все карты на поле / в полёте ────────────────────
        $lines[] = '';
        $lines[] = '--- cards on field ---';
        foreach ($state->cards as $card) {
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;

            $pos = $card->zone === CardInstance::ZONE_FLYING
                ? "fly#{$card->slot}"
                : "({$card->row},{$card->col})";

            $flags = [];
            foreach (['damage_taken_this_turn', 'damage_taken_this_strike',
                      'ranged_hits_this_turn', 'attacks_used_this_turn',
                      'moved_this_turn', 'any_death_used_this_turn'] as $fk) {
                $v = $card->flags[$fk] ?? null;
                if ($v !== null && $v !== false && $v !== 0) {
                    $flags[] = "{$fk}={$v}";
                }
            }

            $line = "#{$card->instanceId} {$card->ukid} [{$card->owner}] {$pos}"
                . " hp={$card->hp}/{$card->hpMax}";

            if ($card->coins > 0)      $line .= " coins={$card->coins}";
            if ($card->armor > 0)      $line .= " armor={$card->armor}";
            if ($card->closed)         $line .= " closed";
            if (!$card->revealed)      $line .= " hidden";
            if ($card->dying)          $line .= " DYING";

            if (!empty($flags))        $line .= ' | ' . implode(' ', $flags);
            if (!empty($card->markers)) {
                $mk = [];
                foreach ($card->markers as $t => $m) {
                    $v = $m['value'] ?? '?';
                    $mk[] = "{$t}={$v}";
                }
                $line .= ' | mk:' . implode(',', $mk);
            }
            if (!empty($card->modifiers)) {
                $line .= ' | mods=' . count($card->modifiers);
            }

            $lines[] = $line;
        }

        // ─── Пайлы ─────────────────────────────────────────
        $counts = ['deck' => [], 'graveyard' => [], 'exile' => []];
        foreach ($state->cards as $card) {
            if (isset($counts[$card->zone])) {
                $counts[$card->zone][$card->owner] =
                    ($counts[$card->zone][$card->owner] ?? 0) + 1;
            }
        }
        $lines[] = '';
        $lines[] = '--- piles ---';
        foreach (['deck', 'graveyard', 'exile'] as $zone) {
            $host = $counts[$zone]['host']   ?? 0;
            $plr  = $counts[$zone]['player'] ?? 0;
            $lines[] = "{$zone}: host={$host} player={$plr}";
        }

        // ─── Cell markers ──────────────────────────────────
        if (!empty($state->cell_markers)) {
            $lines[] = '';
            $lines[] = '--- cell_markers ---';
            foreach (array_keys($state->cell_markers) as $key) {
                $list = ZoneManager::markersAt($state, $key);
                foreach ($list as $m) {
                    $lines[] = "  {$key}: " . json_encode($m, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        // ─── Strike ────────────────────────────────────────
        if ($strike) {
            $lines[] = '';
            $lines[] = '--- strike ---';
            $lines[] = json_encode([
                'kind'        => $strike['kind']        ?? 'strike',
                'state'       => $strike['state']       ?? '?',
                'attacker_id' => $strike['attacker_id'] ?? null,
                'target_id'   => $strike['target_id']   ?? null,
                'defender_id' => $strike['defender_id'] ?? null,
                'attack_dice' => $strike['attack_dice'] ?? null,
                'defend_dice' => $strike['defend_dice'] ?? null,
                'final'       => $strike['final']       ?? null,
            ], JSON_UNESCAPED_UNICODE);
        }

        // ─── Turn phase ────────────────────────────────────
        if (!empty($state->battle['turn_phase'])) {
            $tp = $state->battle['turn_phase'];
            $lines[] = '';
            $lines[] = '--- turn_phase ---';
            $lines[] = 'phase=' . ($tp['phase'] ?? '?')
                . ' side=' . ($tp['side'] ?? '?')
                . ' active=' . ($tp['active_key'] ?? '?')
                . ' passive=' . ($tp['passive_key'] ?? '?');

            foreach (['passive_queue', 'active_queue'] as $qk) {
                if (empty($tp[$qk])) continue;
                $lines[] = "  {$qk}:";
                foreach ($tp[$qk] as $task) {
                    $lines[] = '    - ' . ($task['type'] ?? '?')
                        . ' id=' . ($task['id'] ?? '?')
                        . ' label=' . ($task['label'] ?? '');
                }
            }
            if (!empty($tp['sub'])) {
                $sub = $tp['sub'];
                $lines[] = '  sub: parent=' . ($sub['parent_type'] ?? '?')
                    . ' remaining=' . count($sub['remaining'] ?? []);
            }
            if (!empty($tp['pending_ack'])) {
                $lines[] = '  pending_ack: ' . json_encode(
                    $tp['pending_ack'], JSON_UNESCAPED_UNICODE
                );
            }
        }

        // ─── Все pending_* ─────────────────────────────────
        $lines[] = '';
        $lines[] = '--- pending ---';
        $anyPending = false;
        foreach ($state->battle as $key => $val) {
            if (!str_starts_with((string) $key, 'pending_')) continue;
            if (empty($val)) continue;
            $anyPending = true;
            $lines[] = "{$key}: " . json_encode($val, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        if (!$anyPending) {
            $lines[] = '  (нет)';
        }

        return '<pre class="debug-panel">'
            . htmlspecialchars(implode("\n", $lines), ENT_QUOTES)
            . '</pre>';
    }

    private function buildCellMarkersOverlay(GameState $state, string $key): string
    {
        $markers = ZoneManager::markersAt($state, $key);
        if (empty($markers)) return '';

        $html = '<div class="cell-markers">';
        foreach ($markers as $m) {
            $type = htmlspecialchars((string) ($m['type'] ?? ''), ENT_QUOTES);
            $html .= '<div class="cell-marker cell-marker-' . $type . '"></div>';
        }
        $html .= '</div>';

        return $html;
    }

}
