<?php
// src/View/Screen/BattleScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\Core\CardInstance;
use Berserk\Core\ZoneManager;
use Berserk\Core\BattleHelper;
use Berserk\Core\CardStats;
use Berserk\Core\Choice\ChoiceRegistry;
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
        array $cardsInfo,
        ?array $uiState = null
    ): array {
        $oppKey    = $state->getOpponentKey($playerKey);
        $linkParam = $role === 'host' ? 'first' : 'second';
        $baseUrl   = "?{$linkParam}&game={$state->gameId}";
        $isHost    = ($playerKey === 'host');
        $isActive  = ($state->battle['active'] === $playerKey);
        $strike    = $state->battle['strike'] ?? null;

        $pendingAnyDeath = $state->battle['pending_any_death'][0] ?? null;
        $ui = $this->normalizeUiState($state, $playerKey, $uiState);

        // Выбранная карта
        $selectedCardId = $ui['sel'];

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
        $explicitAttackMode = $ui['mode_explicit'];
        $mode = $ui['mode'];

        $attackTargets = [];
        if ($selectedCardId > 0 && $isActive && !$strike) {
            $c = $state->getCard($selectedCardId);
            if ($c && $c->owner === $playerKey) {
                $attackTargets = BattleHelper::getAttackTargets($state, $c, $mode, $playerKey, $explicitAttackMode);
            }
        }

        $pendingDefenderTargets = $this->pendingDefenderTargets($state, $playerKey, $baseUrl);
        $pendingRedirectTargets = $this->pendingRedirectTargets($state, $playerKey, $baseUrl);
        $pendingAutoTargets = $this->pendingAutoTargets($state, $playerKey, $baseUrl);
        $pendingDeathTargets = $this->pendingDeathTargets($state, $playerKey, $baseUrl);
        $pendingAllyModifierTargets = $this->pendingAllyModifierTargets($state, $playerKey, $baseUrl);

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
            $moveCells, $jumpCells, $attackTargets, $pendingDefenderTargets, $pendingRedirectTargets, $pendingAutoTargets, $pendingDeathTargets, $pendingAllyModifierTargets,
            $explicitAttackMode
        );
        $flyZonesHtml   = $this->buildFlyZones(
            $state, $playerKey, $oppKey, $flyMap, $cardsInfo,
            $selectedCardId, $mode, $baseUrl, $attackTargets, $pendingDefenderTargets, $pendingRedirectTargets, $pendingAutoTargets, $pendingDeathTargets, $pendingAllyModifierTargets,
            $explicitAttackMode
        );
        $pilesHtml = $this->buildPiles($state, $playerKey, $oppKey, $baseUrl);
        $panelHtml      = $this->buildPanel(
            $state, $playerKey, $selectedCardId, $cardsInfo,
            $baseUrl, $mode, $isActive, $strike, $attackTargets, $explicitAttackMode
        );
        
        $infoPanelHtml = (new InfoPanel($this->tpl))->render($state, $playerKey, $role, $cardsInfo, $baseUrl, $panelHtml);

        $debugHtml = '';
        if (isset($_SESSION['debug']) && $_SESSION['debug'] == '1') {
            $debugHtml = $this->buildDebug($state, $playerKey, $strike);
        }

        // Debug travels in the existing InfoPanel fragment; no extra AJAX field.
        $infoPanelHtml .= '<aside id="battle-debug" class="battle-debug-overlay" aria-label="Отладка боя" hidden>'
            . $debugHtml . '</aside>';
        $pileRevealHtml = '';
        if ($ui['pile'] !== '') {
            $pileRevealHtml = $this->buildPileReveal($state, $playerKey, $oppKey, $cardsInfo, $ui['pile']);
        }

        return [
            'screen' => 'battle',
            'data'   => [
                'ui' => [
                    'sel'  => $ui['sel'],
                    'mode' => $ui['mode'],
                    'pile' => $ui['pile'],
                    'mode_explicit' => $ui['mode_explicit'],
                ],
                'debug_disabled' => $debugHtml === '' ? 'disabled' : '',
                'field_html'       => $fieldHtml,
                'panel_html'       => $panelHtml,
                'fly_zones_html'    => $flyZonesHtml,
                'piles_html'        => $pilesHtml,
                'pile_reveal_html'  => $pileRevealHtml,
                'info_panel_html'  => $infoPanelHtml,
            ],
        ];
    }

    /**
     * @return array{sel:int,mode:string,pile:string,mode_explicit:bool}
     */
    private function normalizeUiState(
        GameState $state,
        string $playerKey,
        ?array $uiState
    ): array {
        $source = $uiState ?? $_GET;

        $selectedCardId = (int) ($source['sel'] ?? 0);
        if ($selectedCardId > 0) {
            $card = $state->getCard($selectedCardId);
            if (!$card || !$this->isSelectableBattleCard($card, $playerKey)) {
                $selectedCardId = 0;
            }
        }

        $rawMode = (string) ($source['mode'] ?? 'strike');
        $modeExplicit = array_key_exists('mode', $source) && $rawMode !== '';
        $mode = $rawMode === '' ? 'strike' : $rawMode;
        if (!$this->isValidMode($state, $playerKey, $selectedCardId, $mode)) {
            $mode = 'strike';
            $modeExplicit = false;
        }

        $pile = $this->normalizePile((string) ($source['pile'] ?? ''));

        return [
            'sel' => $selectedCardId,
            'mode' => $mode,
            'pile' => $pile,
            'mode_explicit' => $modeExplicit,
        ];
    }

    private function isSelectableBattleCard(CardInstance $card, string $playerKey): bool
    {
        if ($card->zone !== CardInstance::ZONE_FIELD && $card->zone !== CardInstance::ZONE_FLYING) {
            return false;
        }

        return $card->owner === $playerKey || $card->revealed;
    }

    private function isValidMode(
        GameState $state,
        string $playerKey,
        int $selectedCardId,
        string $mode
    ): bool {
        if ($mode === 'strike') {
            return true;
        }

        if ($selectedCardId <= 0) {
            return false;
        }

        $card = $state->getCard($selectedCardId);
        if (!$card || $card->owner !== $playerKey) {
            return false;
        }

        if ($mode === 'uchr') {
            return BattleHelper::abilities($card)['has_uchr'];
        }

        if (!str_starts_with($mode, 'action:')) {
            return false;
        }

        $actionKey = substr($mode, 7);
        if ($actionKey === '') {
            return false;
        }

        foreach ($card->prop['actions'] ?? [] as $action) {
            if (($action['type'] ?? '') === 'uchr') {
                continue;
            }
            $key = (string) ($action['key'] ?? ($action['type'] ?? ''));
            if ($key === $actionKey) {
                return true;
            }
        }

        return false;
    }

    private function normalizePile(string $pile): string
    {
        if ($pile === '') {
            return '';
        }

        $parts = explode('_', $pile, 2);
        if (count($parts) !== 2) {
            return '';
        }

        [$who, $source] = $parts;
        if (!in_array($who, ['own', 'opp'], true)) {
            return '';
        }
        if (!in_array($source, ['grave', 'exile'], true)) {
            return '';
        }

        return $pile;
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
        array $pendingDefenderTargets,
        array $pendingRedirectTargets,
        array $pendingAutoTargets,
        array $pendingDeathTargets,
        array $pendingAllyModifierTargets,
        bool $explicitAttackMode
    ): string {
        $markerTargets = \Berserk\View\Ui\CellMarkerPicker::targets($state, $playerKey, $baseUrl);
        $movementCells = $movementCards = [];
        $movementUrl = '';
        $movementPending = $state->battle[\Berserk\Core\FirstStrikeJump::PENDING] ?? null;
        if ($movementPending && $movementPending['owner'] === $playerKey
            && ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\FirstStrikeJumpChoice) {
            $movementUrl = $baseUrl . '&cmd=choose_first_strike_movement&choice_id=' . rawurlencode($movementPending['id']);
            if ($movementPending['stage'] === 'jump') {
                $source = $state->getCard((int) $movementPending['card_id']);
                if ($source) {
                    $movementCells = array_intersect_key(
                        (new \Berserk\Core\Movement\MovementResolver($state, new \Berserk\Core\Engine()))->triggeredJumpCells($source, (int) $movementPending['range']),
                        array_fill_keys($movementPending['cells'], true),
                    );
                }
            } else {
                $movementCards = array_fill_keys(array_intersect($movementPending['candidates'],
                    (new \Berserk\Core\FirstStrikeJump($state, new \Berserk\Core\Engine()))->candidates($movementPending)), true);
            }
        }
        $html = '<div class="battle-columns" aria-label="Столбцы">';
        foreach ($colOrder as $c) {
            $pos = \Berserk\View\Ui\BattlefieldPosition::display(1, $c, $playerKey);
            $html .= '<span>' . $pos['col'] . '</span>';
        }
        $html .= '</div>';
        $columnsHtml = $html;
        $pickerKey = \Berserk\View\Ui\CellMarkerPicker::identity($state, $playerKey);
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
                        $hpText   = $card->hp . '/' . $this->displayHpMax($card);
                    }

                    $ownerClass  = $card->owner === $playerKey ? 'own' : 'enemy';
                    $closedClass = $card->closed ? 'closed' : '';
                    $cellClass   = "$ownerClass $closedClass";
                    if ($isHiddenForMe) $cellClass .= ' hidden';
                    if ($card->dying)   $cellClass .= ' dying';

                    $cardBody = $this->buildBattleCard($card, $state, $info, $isHiddenForMe, $nameHtml, $hpText);

                    if (isset($movementCards[$card->instanceId])) {
                        $cellClass .= ' attack-target pending-first-strike-target';
                        $url = htmlspecialchars($movementUrl . '&target_id=' . $card->instanceId, ENT_QUOTES, 'UTF-8');
                        $cellContent = '<a class="card-link" href="' . $url . '">' . $cardBody . '</a>';
                    } elseif (isset($pendingDefenderTargets[$card->instanceId])) {
                        $cellClass  .= ' attack-target pending-defender-target';
                        $cellContent = '<a class="card-link" href="' . $pendingDefenderTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                    } elseif (isset($pendingRedirectTargets[$card->instanceId])) {
                        $cellClass  .= ' attack-target pending-redirect-target';
                        $cellContent = '<a class="card-link" href="' . $pendingRedirectTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                    } elseif (isset($pendingAutoTargets[$card->instanceId])) {
                        $cellClass  .= ' attack-target pending-auto-target';
                        $cellContent = '<a class="card-link" href="' . $pendingAutoTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                    } elseif (isset($pendingDeathTargets[$card->instanceId])) {
                        $cellClass  .= ' attack-target pending-death-target';
                        $cellContent = '<a class="card-link" href="' . $pendingDeathTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                    } elseif (isset($pendingAllyModifierTargets[$card->instanceId])) {
                        $cellClass  .= ' attack-target pending-ally-modifier-target';
                        $cellContent = '<a class="card-link" href="' . $pendingAllyModifierTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                    } elseif (isset($attackTargets[$card->instanceId]) && $selectedCardId > 0 && $card->instanceId !== $selectedCardId) {
                        if (str_starts_with($mode, 'action:')) {
                            $actionKey = substr($mode, 7);
                            $atkUrl = "{$baseUrl}&cmd=action&action_key={$actionKey}&card_id={$selectedCardId}&target_id={$card->instanceId}&sel={$selectedCardId}&mode={$mode}";
                        } else {
                            $atkUrl = "{$baseUrl}&cmd={$mode}&card_id={$selectedCardId}&target_id={$card->instanceId}&sel={$selectedCardId}&mode={$mode}";
                        }
                        $isFriendlyAttack = $card->owner === $playerKey
                            && $explicitAttackMode
                            && ($selected = $state->getCard($selectedCardId))
                            && BattleHelper::isFriendlyFireTarget($selected, $card, $mode);
                        $cellClass  .= $isFriendlyAttack ? ' attack-target friendly-attack-target' : ' attack-target';
                        $confirmAttr = $isFriendlyAttack ? $this->friendlyAttackConfirmAttribute($card, $info ?? []) : '';
                        $cellContent = '<a class="card-link" href="' . $atkUrl . '"' . $confirmAttr . '>' . $cardBody . '</a>';
                    } elseif ($card->owner === $playerKey || $card->revealed) {
                        $selUrl      = "{$baseUrl}&sel={$card->instanceId}";
                        $cellContent = '<a class="card-link" href="' . $selUrl . '">' . $cardBody . '</a>';
                    } else {
                        $cellContent = $cardBody;
                    }
                } elseif (isset($movementCells[$key])) {
                    $url = htmlspecialchars($movementUrl . "&row={$r}&col={$c}", ENT_QUOTES, 'UTF-8');
                    $label = htmlspecialchars(\Berserk\View\Ui\BattlefieldPosition::label($r, $c, $playerKey), ENT_QUOTES, 'UTF-8');
                    $cellClass = 'jump-target pending-first-strike-cell';
                    $cellContent = '<a class="cell-link" href="' . $url . '" aria-label="Выбрать клетку прыжка: ' . $label . '"></a>';
                } elseif (isset($moveCells[$key])) {
                    $moveUrl     = "{$baseUrl}&cmd=move&card_id={$selectedCardId}&row={$r}&col={$c}&sel={$selectedCardId}";
                    $cellClass   = 'move-target';
                    $cellContent = '<a class="cell-link" href="' . $moveUrl . '"></a>';
                } elseif (isset($jumpCells[$key])) {
                    $jumpUrl     = "{$baseUrl}&cmd=jump&card_id={$selectedCardId}&row={$r}&col={$c}&sel={$selectedCardId}";
                    $cellClass   = 'jump-target';
                    $cellContent = '<a class="cell-link" href="' . $jumpUrl . '"></a>';
                }

                if (isset($markerTargets[$key]) && !$card) {
                    $cellClass = 'cell-marker-target cell-marker-available';
                    $url = htmlspecialchars($markerTargets[$key]['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $label = htmlspecialchars(\Berserk\View\Ui\BattlefieldPosition::label($r, $c, $playerKey), ENT_QUOTES, 'UTF-8');
                    $pos = \Berserk\View\Ui\BattlefieldPosition::display($r, $c, $playerKey);
                    $groupAttr = $pickerKey !== '' ? ' data-cell-marker-link="' . $key . '" data-picker-group="'
                        . $pos['side'] . ':' . $pos['row'] . '" data-marker-url="' . $url . '"' : '';
                    $cellContent = '<a class="cell-link" href="' . $url . '" aria-label="Выбрать клетку: ' . $label . '"' . $groupAttr . '></a>';
                }

                // ─── ОВЕРЛЕЙ: всегда в конце, на уровне клетки ───
                $cellContent .= $this->buildCellMarkersOverlay($state, $key);

                $rowCells .= $this->tpl->parse('includes/battle_cell.tpl', [
                    'cl'      => $cellClass,
                    'content' => $cellContent,
                ]);
            }
            $pos = \Berserk\View\Ui\BattlefieldPosition::display($r, 1, $playerKey);
            $side = $pos['side'] === 'own' ? 'Свой' : 'Вражеский';
            $html .= '<div class="field-row battle-numbered-row"><span class="battle-row-number coords--'
                . $pos['side'] . '" aria-label="' . $side . ' ' . $pos['row'] . '-й ряд">'
                . \Berserk\View\Ui\Coords::rowLabel($pos['row']) . '</span>' . $rowCells . '</div>';
        }
        $html .= str_replace('class="battle-columns"', 'class="battle-columns battle-columns-bottom"', $columnsHtml);
        return $pickerKey !== '' ? '<div data-cell-marker-field="' . $pickerKey . '">' . $html . '</div>' : $html;
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
        array $pendingDefenderTargets,
        array $pendingRedirectTargets,
        array $pendingAutoTargets,
        array $pendingDeathTargets,
        array $pendingAllyModifierTargets,
        bool $explicitAttackMode
    ): string {
        $html = '';
        foreach (['opp', 'own'] as $who) {
            $ownerKey = $who === 'opp' ? $oppKey : $playerKey;

            ksort($flyMap[$ownerKey]);
            $cardsHtml = [];
            foreach ($flyMap[$ownerKey] as $card) {
                $cellContent = '';
                $cellClass   = 'fly-card';

                $info     = $cardsInfo[$card->ukid] ?? null;
                $isHiddenForMe = (!$card->revealed && $card->owner !== $playerKey);

                if ($isHiddenForMe) {
                    $nameHtml = '???';
                    $hpText   = '?/?';
                } else {
                    $nameHtml = $info ? htmlspecialchars($info['name'], ENT_QUOTES) : '?';
                    $hpText   = $card->hp . '/' . $this->displayHpMax($card);
                }
                $ownerClass = $card->owner === $playerKey ? 'own' : 'enemy';
                $cellClass .= ' ' . $ownerClass;
                if ($card->closed) {
                    $cellClass .= ' closed';
                }
                if ($isHiddenForMe) {
                    $cellClass .= ' hidden';
                }

                $cardBody = $this->buildBattleCard($card, $state, $info, $isHiddenForMe, $nameHtml, $hpText);

                if (isset($pendingDefenderTargets[$card->instanceId])) {
                    $cellClass  .= ' attack-target pending-defender-target';
                    $cellContent = '<a class="card-link" href="' . $pendingDefenderTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                } elseif (isset($pendingRedirectTargets[$card->instanceId])) {
                    $cellClass  .= ' attack-target pending-redirect-target';
                    $cellContent = '<a class="card-link" href="' . $pendingRedirectTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                } elseif (isset($pendingAutoTargets[$card->instanceId])) {
                    $cellClass  .= ' attack-target pending-auto-target';
                    $cellContent = '<a class="card-link" href="' . $pendingAutoTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                } elseif (isset($pendingDeathTargets[$card->instanceId])) {
                    $cellClass  .= ' attack-target pending-death-target';
                    $cellContent = '<a class="card-link" href="' . $pendingDeathTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                } elseif (isset($pendingAllyModifierTargets[$card->instanceId])) {
                    $cellClass  .= ' attack-target pending-ally-modifier-target';
                    $cellContent = '<a class="card-link" href="' . $pendingAllyModifierTargets[$card->instanceId] . '">' . $cardBody . '</a>';
                } elseif (isset($attackTargets[$card->instanceId]) && $selectedCardId > 0 && $card->instanceId !== $selectedCardId) {
                    if (str_starts_with($mode, 'action:')) {
                        $actionKey = substr($mode, 7);
                        $atkUrl = "{$baseUrl}&cmd=action&action_key={$actionKey}&card_id={$selectedCardId}&target_id={$card->instanceId}&sel={$selectedCardId}&mode={$mode}";
                    } else {
                        $atkUrl = "{$baseUrl}&cmd={$mode}&card_id={$selectedCardId}&target_id={$card->instanceId}&sel={$selectedCardId}&mode={$mode}";
                    }
                    $isFriendlyAttack = $card->owner === $playerKey
                        && $explicitAttackMode
                        && ($selected = $state->getCard($selectedCardId))
                        && BattleHelper::isFriendlyFireTarget($selected, $card, $mode);
                    $cellClass  .= $isFriendlyAttack ? ' attack-target friendly-attack-target' : ' attack-target';
                    $confirmAttr = $isFriendlyAttack ? $this->friendlyAttackConfirmAttribute($card, $info ?? []) : '';
                    $cellContent = '<a class="card-link" href="' . $atkUrl . '"' . $confirmAttr . '>' . $cardBody . '</a>';
                } elseif ($card->owner === $playerKey || $card->revealed) {
                    $selUrl      = "{$baseUrl}&sel={$card->instanceId}";
                    $cellContent = '<a class="card-link" href="' . $selUrl . '">' . $cardBody . '</a>';
                } else {
                    $cellContent = $cardBody;
                }

                $cardsHtml[] = $this->tpl->parse('includes/battle_cell.tpl', [
                    'cl'      => $cellClass,
                    'content' => $cellContent,
                ]);
            }

            $slotsHtml = '';
            for ($slot = 0; $slot < 3; $slot++) {
                $contents = '';
                for ($page = 0; $page < 2; $page++) {
                    $contents .= '<div class="fly-page-card" data-fly-page="' . $page . '"'
                        . ($page === 1 ? ' hidden' : '') . '>'
                        . ($cardsHtml[$page * 3 + $slot] ?? '') . '</div>';
                }
                $slotsHtml .= '<div class="fly-slot">' . $contents . '</div>';
            }
            $controls = count($cardsHtml) > 3
                ? $this->tpl->parse('includes/battle/fly_pagination.tpl', [
                    'side' => $who === 'own' ? 'Свои летающие' : 'Летающие противника',
                ]) : '';
            $html .= '<div class="fly-zone ' . $who . '" data-fly-side="' . $who
                . '" data-fly-count="' . count($cardsHtml) . '">' . $slotsHtml . $controls . '</div>';
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
        $details = static function (int $count, string $url, string $label): string {
            $label = htmlspecialchars('Подробнее: ' . $label, ENT_QUOTES);
            return $count > 0
                ? '<a class="pile-open" href="' . htmlspecialchars($url, ENT_QUOTES) . '" aria-label="' . $label . '">Подробнее</a>'
                : '<button class="pile-open" type="button" disabled aria-label="' . $label . '">Подробнее</button>';
        };

        foreach (['opp', 'own'] as $who) {
            $ownerKey = $who === 'opp' ? $oppKey : $playerKey;

            $grave = $zone->countInZone($ownerKey, CardInstance::ZONE_GRAVEYARD);
            $exile = $zone->countInZone($ownerKey, CardInstance::ZONE_EXILE);
            $html .= $this->tpl->parse('includes/battle/side_zones.tpl', [
                'side' => $who,
                'terrain_label' => $who === 'own' ? 'Своя местность: пусто' : 'Местность противника: пусто',
                'grave_count' => $grave,
                'exile_count' => $exile,
                'grave_details_html' => $details($grave, $baseUrl . '&pile=' . $who . '_grave', 'кладбище'),
                'exile_details_html' => $details($exile, $baseUrl . '&pile=' . $who . '_exile', 'изгнание'),
            ]);
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

    private function friendlyAttackConfirmAttribute(CardInstance $card, array $cardInfo): string
    {
        $name = (string) ($cardInfo['name'] ?? $card->ukid);
        $message = 'Атаковать свою карту «' . $name . '»?';
        $json = json_encode(
            $message,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );

        if (!is_string($json)) {
            return '';
        }

        return ' onclick="return confirm(' . htmlspecialchars($json, ENT_QUOTES) . ')"';
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

    /**
     * @return array<int,string>
     */
    private function pendingRedirectTargets(GameState $state, string $playerKey, string $baseUrl): array
    {
        $strike = $state->battle['strike'] ?? null;
        if (!is_array($strike) || ($strike['state'] ?? null) !== 'waiting_redirect') {
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
        foreach ($strike['redirect_candidates'] ?? [] as $targetId) {
            $targetId = (int) $targetId;
            if ($state->getCard($targetId)) {
                $targets[$targetId] = $baseUrl . '&cmd=choose_redirect&target_id=' . $targetId;
            }
        }

        return $targets;
    }

    /**
     * @return array<int,string>
     */
    private function pendingAutoTargets(GameState $state, string $playerKey, string $baseUrl): array
    {
        $strike = $state->battle['strike'] ?? null;
        if (!is_array($strike) || ($strike['state'] ?? null) !== 'waiting_auto_target') {
            return [];
        }

        $pending = $strike['pending_auto'] ?? null;
        if (!is_array($pending)) {
            return [];
        }

        $source = $state->getCard((int) ($pending['source_id'] ?? 0));
        if (!$source || $source->owner !== $playerKey) {
            return [];
        }

        $targets = [];
        foreach ($pending['candidates'] ?? [] as $targetId) {
            $targetId = (int) $targetId;
            if ($state->getCard($targetId)) {
                $targets[$targetId] = $baseUrl . '&cmd=choose_auto_target&target_id=' . $targetId;
            }
        }

        return $targets;
    }

    /**
     * @return array<int,string>
     */
    private function pendingDeathTargets(GameState $state, string $playerKey, string $baseUrl): array
    {
        $strike = $state->battle['strike'] ?? null;
        if (!is_array($strike) || ($strike['state'] ?? null) !== 'results') {
            return [];
        }

        $pending = $strike['pending_choice'] ?? null;
        if (!is_array($pending) || ($pending['source'] ?? null) !== 'on_death') {
            return [];
        }

        $died = $state->getCard((int) ($pending['died_id'] ?? 0));
        if (!$died || $died->owner !== $playerKey) {
            return [];
        }

        $targets = [];
        foreach ($pending['candidates'] ?? [] as $targetId) {
            $targetId = (int) $targetId;
            if ($state->getCard($targetId)) {
                $targets[$targetId] = $baseUrl . '&cmd=choose_death_target&target_id=' . $targetId;
            }
        }

        return $targets;
    }

    /**
     * @return array<int,string>
     */
    private function pendingAllyModifierTargets(GameState $state, string $playerKey, string $baseUrl): array
    {
        $strike = $state->battle['strike'] ?? null;
        if (!is_array($strike) || ($strike['state'] ?? null) !== 'waiting_ally_modifier') {
            return [];
        }

        $pending = $strike['pending_ally_modifier'] ?? null;
        if (!is_array($pending)) {
            return [];
        }

        $source = $state->getCard((int) ($pending['source_id'] ?? 0));
        if (!$source || $source->owner !== $playerKey) {
            return [];
        }

        $targets = [];
        foreach ($pending['candidates'] ?? [] as $targetId) {
            $targetId = (int) $targetId;
            if ($state->getCard($targetId)) {
                $targets[$targetId] = $baseUrl . '&cmd=choose_ally_modifier&target_id=' . $targetId;
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
        array $attackTargets,
        bool $explicitAttackMode
    ): string {
        if ($selectedCardId <= 0) return '';

        $c = $state->getCard($selectedCardId);
        if (!$c) return '';

        $info = $cardsInfo[$c->ukid] ?? null;
        if (!$info) return '';

        $imgHtml = '';
        // Asset names follow the existing ukid convention; check the public root,
        // independently of the process working directory or HTTP document root.
        if (preg_match('/\A[a-zA-Z0-9_-]+\z/D', $c->ukid)
            && is_file(dirname(__DIR__, 3) . '/www/assets/cards/s1/' . $c->ukid . '.webp')) {
            $imgHtml = $this->tpl->parse('includes/battle/card_preview.tpl', [
                'preview_url' => '/assets/cards/s1/' . rawurlencode($c->ukid) . '.webp',
                'original_url' => '/assets/cards/s1/original/' . rawurlencode($c->ukid) . '.png',
                'name' => htmlspecialchars($info['name'], ENT_QUOTES),


            ]);
        }

        // Кнопки режимов
        $modeButtons = '';
        $closedButtonShown = false;
        if ($isActive && !$strike && $c->owner === $playerKey) {
            if (CardStats::isDisabled($c)) {
                $reason = CardStats::disabledReason($c);
                $closedButtonShown = $c->closed;
                $modeButtons .= '<span class="button small disabled">'
                    . htmlspecialchars($reason !== '' ? $reason : 'Карта не может действовать', ENT_QUOTES)
                    . '</span> ';
            } else {
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
                    'row_spell' => 'Цветущие руны',
                    'mark_opponent_row' => 'Заклинание ряда',
                    'apply_delayed_marker' => 'Воздействие',
                    'freeze_moves' => 'Ледяной дождь',
                    'particle'  => 'Частица души',
                    'life_gift' => 'Предсмертный дар',
                    'damage_ranged'   => 'Пламя бездны',
                    'teleport_target' => 'Дверь измерений',
                    'blood_storm' => 'Буря крови',
                    'attack_series' => 'Серия атак',
                ];

                foreach ($c->prop['actions'] ?? [] as $a) {
                    if (!\Berserk\Core\CoinAbilities::actionAvailable($c, $a)) continue;
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

                    // Универсальное условие действия — просто disabled
                    if (!empty($a['condition'])
                        && !CardStats::checkCondition($a['condition'], $state, $c)) {

                        $modeButtons .= '<span class="button small disabled">'
                            . htmlspecialchars($label, ENT_QUOTES)
                            . '</span> ';
                        continue;
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

                    $immediate = $this->isImmediateBattleAction($a);

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

                // Выданные действия (Дверг и подобные)
                foreach ($c->flags as $flagKey => $flagValue) {
                    if (!is_string($flagKey) || !str_starts_with($flagKey, 'granted_action:')) continue;
                    if (!is_array($flagValue)) continue;
                    if (!empty($flagValue['used'])) continue;

                    $grantKey = (string) ($flagValue['key'] ?? '');
                    if ($grantKey === '') continue;
                    $grantLabel = (string) ($flagValue['name'] ?? 'Действие');

                    $link = "{$baseUrl}&sel={$c->instanceId}&mode=action:{$grantKey}";
                    $modeButtons .= $this->tpl->parse('includes/battle/mode_button.tpl', [
                        'label'  => htmlspecialchars($grantLabel, ENT_QUOTES),
                        'link'   => $link,
                        'active' => $mode === "action:{$grantKey}" ? ' active' : '',
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
        }

        if ($this->canCancelTargetMode($state, $c, $playerKey, $mode, $isActive, $strike, $explicitAttackMode)) {
            $modeButtons .= $this->tpl->parse('includes/battle/mode_button.tpl', [
                'label'  => 'Отменить действие',
                'link'   => $baseUrl,
                'active' => ' cancel',
            ]);
        }

        // Статус-строка
        $statusHtml = '';
        if ($c->closed) {
            if (!$closedButtonShown) {
                $statusHtml = $this->tpl->parse('includes/battle/panel_status.tpl', ['text' => 'Карта закрыта']);
            }
        } elseif (isset($c->markers['spider_web'])) {
            $statusHtml = $this->tpl->parse('includes/battle/panel_status.tpl', ['text' => 'Карта находится в сети']);
        } elseif ($isActive && !$strike && $c->owner === $playerKey) {
            if (empty($attackTargets)) {
                $statusHtml = $this->tpl->parse('includes/battle/panel_status.tpl', ['text' => 'Нет целей']);
            } else {
                $statusHtml = '<p>Кликни по существу оппонента для атаки.</p>';
            }
        }
        if (!empty($c->prop['on_wounding_strike'])) {
            $effect = $c->prop['on_wounding_strike'];
            $values = array_map('intval', (array) ($effect['strike'] ?? []));
            $hint = $c->coins >= (int) ($effect['min_coins'] ?? 1)
                ? 'При нанесении ран ударом — разряд ' . implode('–', $values) . ', дальность ' . (int) ($effect['range'] ?? 0) . '. Монетка сохраняется.'
                : 'Без монетки: доступен удар через ряд; после него можно получить монетку.';
            $statusHtml .= '<p>' . htmlspecialchars($hint, ENT_QUOTES) . '</p>';
        }
        $statusHtml .= $this->linkedRecruitBattleHint($state, $c, $cardsInfo);
        if (!empty($c->prop['first_strike_jump'])) {
            $statusHtml .= '<p>Защита первого удара: '
                . (\Berserk\Core\FirstStrikeJump::used($state, $c) ? 'использована в этом ходу' : 'доступна') . '.</p>';
        }

        $hasCoins = !empty($c->prop['save_coins']) || $c->coins > 0 || !empty($c->prop['coin_block']);

        $statValue = fn(string $stat, string $icon, string $value, string $label): string =>
            $this->tpl->parse('includes/battle/stat_value.tpl', [
                'stat' => $stat, 'icon' => $icon,
                'value' => htmlspecialchars($value, ENT_QUOTES),
                'label' => htmlspecialchars($label, ENT_QUOTES),
            ]);
        $hpMax = $this->displayHpMax($c, $info);
        $statValues = $statValue('hp', 'heart', $c->hp . '/' . $hpMax,
            'Здоровье: ' . $c->hp . '/' . $hpMax);
        if (!CardStats::isFlyingCreature($c)) {
            $statValues .= $statValue('move', 'move', $c->move . '/' . $c->moveMax,
                'Ходов: ' . $c->move . '/' . $c->moveMax);
        }
        $statValues .= $statValue('strike', 'sword', $c->strikeWeak . '-' . $c->strikeMedium . '-' . $c->strikeStrong,
            'Простой удар: ' . $c->strikeWeak . '/' . $c->strikeMedium . '/' . $c->strikeStrong);
        foreach ([
            ['ova', 'ova', CardStats::getOva($state, $c), 'Опыт в атаке'],
            ['ovz', 'ovz', CardStats::getOvz($state, $c), 'Опыт в защите'],
            ['regen', 'regen', CardStats::getStat($c, 'regeneration'), 'Регенерация'],
        ] as [$stat, $icon, $value, $label]) {
            if ($value !== 0) $statValues .= $statValue($stat, $icon, (string) $value, $label . ': ' . $value);
        }

        if ($hasCoins) {
            $coinMax = (int) ($c->prop['coins']['max_value'] ?? 0);
            $statValues .= $statValue('coins', 'counter', (string) $c->coins,
                'Монеты: ' . $c->coins . ($coinMax > 0 ? '/' . $coinMax : ''));
        }
        if (CardStats::isFlyingCreature($c)) {
            $statValues .= $this->tpl->parse('includes/battle/stat_icon.tpl', [
                'url' => '/assets/images/card/fly.png',
                'label' => 'Летающее существо',
            ]);
        }
        // Element and rarity finish the stats row, in that order.
        $statIcons = '';
        $elementIcon = $this->elementIconPath($c);
        if ($elementIcon !== null) {
            $statIcons .= $this->tpl->parse('includes/battle/stat_icon.tpl', [
                'url' => $elementIcon,
                'label' => htmlspecialchars('Стихия: ' . ($info['element'] ?? $c->element), ENT_QUOTES),
            ]);
        }
        $rarity = (string) ($info['rarity'] ?? '');
        $rarityLabels = ['common' => 'Обычная', 'uncommon' => 'Необычная', 'rare' => 'Редкая', 'ultrarare' => 'Ультраредкая'];
        if (isset($rarityLabels[$rarity]) && preg_match('/\As([0-9]+)_/', $c->ukid, $set)) {
            $statIcons .= $this->tpl->parse('includes/battle/stat_icon.tpl', [
                'url' => '/assets/images/rarity/s' . $set[1] . '_' . $rarity . '.png',
                'label' => 'Редкость: ' . $rarityLabels[$rarity],
            ]);
        }

        $descriptionHtml = \Berserk\View\Ui\CardDescription::render((string) ($info['descr'] ?? ''));
        $infoIcon = '';
        if ($descriptionHtml !== '') {
            $infoIcon = $this->tpl->parse('includes/battle/info_icon.tpl', ['description_html' => $descriptionHtml]);
        }

        // While combat is pending this card is informational only.
        if ($strike) {
            $modeButtons = '<button type="button" class="button small cancel" data-combat-back>Закрыть</button>';
            $statusHtml = '';
        }

        return $this->tpl->parse('includes/battle/panel.tpl', [
            'card_id' => $c->instanceId,
            'image_html'   => $imgHtml,
            'stat_values_html' => $statValues,
            'stat_icons_html' => $statIcons,
            'cost_icon_url' => '/assets/images/card/' . (!empty($info['elite']) ? 'elite' : 'ordinary') . (!empty($info['single']) ? '-unique' : '') . '.png',
            'cost_icon_label' => (!empty($info['elite']) ? 'Элитная' : 'Обычная') . (!empty($info['single']) ? ', уникальная' : ''),
            'price' => (int) ($info['price'] ?? $c->price),
            'name'         => htmlspecialchars($info['name'], ENT_QUOTES),
            'info_icon_html' => $infoIcon,
            'class_html' => trim((string) ($info['class'] ?? implode(', ', $c->classes))) !== ''
                ? $this->tpl->parse('includes/battle/stat_icon.tpl', [
                    'url' => '/assets/images/card/class.png',
                    'label' => htmlspecialchars('Класс существа: ' . trim((string) ($info['class'] ?? implode(', ', $c->classes))), ENT_QUOTES),
                ]) : '',
            'mode_buttons' => $modeButtons,
            'hp'           => $c->hp,
            'hp_max'       => $this->displayHpMax($c, $info),
            'move'         => $c->move,
            'move_max'     => $c->moveMax,
            'strike'       => $c->strikeWeak . '-' . $c->strikeMedium . '-' . $c->strikeStrong,
            'status_html'  => $statusHtml,
        ]);
    }

    private function canCancelTargetMode(
        GameState $state,
        CardInstance $card,
        string $playerKey,
        string $mode,
        bool $isActive,
        ?array $strike,
        bool $explicitAttackMode
    ): bool {
        if (!$explicitAttackMode || !$isActive || $strike !== null) return false;
        if ($card->owner !== $playerKey || CardStats::isDisabled($card)) return false;
        if ($this->hasActiveServerPending($state)) return false;

        $abil = BattleHelper::abilities($card);
        if ($mode === 'strike') return (bool) ($abil['has_strike'] ?? false);
        if ($mode === 'uchr') return (bool) ($abil['has_uchr'] ?? false);
        if (!str_starts_with($mode, 'action:')) return false;

        $key = substr($mode, 7);
        foreach ($card->prop['actions'] ?? [] as $action) {
            $type = $action['type'] ?? '';
            $actionKey = $action['key'] ?? $type;
            if ($actionKey === $key) {
                return !$this->isImmediateBattleAction($action);
            }
        }

        return false;
    }

    private function hasActiveServerPending(GameState $state): bool
    {
        if (ChoiceRegistry::current($state) !== null) return true;

        return !empty($state->battle['pending_dive']);
    }

    private function isImmediateBattleAction(array $action): bool
    {
        $type = $action['type'] ?? '';

        return !empty($action['self'])
            || !empty($action['self_destroy'])
            || !empty($action['transfer_wounds'])
            || $type === 'blood_storm'
            || $type === 'prophecy'
            || $type === 'revive'
            || $type === 'grezy_prophecy'
            || $type === 'grant_prop'
            || $type === 'damage_poisoned'
            || $type === 'place_cell_marker'
            || $type === 'wound_transfer'
            || $type === 'become_fly'
            || $type === 'row_spell'
            || $type === 'mark_opponent_row'
            || $type === 'destroy_self_and_target'
            || $type === 'freeze_moves'
            || $type === 'multi_discharge'
            || $type === 'particle'
            || $type === 'damage_ranged'
            || $type === 'life_gift'
            || !empty($action['max_targets']);
    }

    private function displayHpMax(CardInstance $card, ?array $info = null): string
    {
        if ($card->hpMax === 999 && (int) ($card->prop['hp_max_override'] ?? 0) === 999) {
            return '∞';
        }

        if ($info !== null && isset($info['health'])) {
            return (string) $info['health'];
        }

        return (string) $card->hpMax;
    }

    private function buildBattleCard(
        CardInstance $card,
        GameState $state,
        ?array $info,
        bool $hidden,
        string $nameHtml,
        string $hpText
    ): string {
        $iconHtml = '';
        if (!$hidden) {
            $elementIcon = $this->elementIconPath($card);
            $elementLabel = $card->element;
            if ($elementIcon !== null) {
                $iconHtml = '<img src="' . htmlspecialchars($elementIcon, ENT_QUOTES) . '"'
                    . ' alt="' . htmlspecialchars($elementLabel, ENT_QUOTES) . '"'
                    . ' class="battle-card__icon"'
                    . '>';
            }
        }

        $coinsBadge = '';
        if (!$hidden && $card->coins > 0) {
            $coinsBadge = '<div class="bcoins">' . $card->coins . '</div>';
        }

        $armorBadge = '';
        if (!$hidden && $card->armor > 0) {
            $armorBadge = '<div class="barmor">' . $card->armor . '</div>';
        }

        $markersHtml = $hidden ? '' : $this->buildCardBadges($card, $state);

        return '<div class="battle-card">'
            . '<div class="battle-card__main">'
            . $iconHtml
            . '<div class="battle-card__text">'
            . '<div class="bname">' . $nameHtml . '</div>'
            . '<div class="bhp"><svg role="img" viewBox="0 0 512 512"><path fill="currentColor" d="M47.6 300.4L228.3 469.1c7.5 7 17.4 10.9 27.7 10.9s20.2-3.9 27.7-10.9L464.4 300.4c30.4-28.3 47.6-68 47.6-109.5v-5.8c0-69.9-50.5-129.5-119.4-141C347 36.5 300.6 51.4 268 84L256 96 244 84c-32.6-32.6-79-47.5-124.6-39.9C50.5 55.6 0 115.2 0 185.1v5.8c0 41.5 17.2 81.2 47.6 109.5z"></path></svg><span>' . htmlspecialchars($hpText, ENT_QUOTES) . '</span></div>'
            . '</div>'
            . '</div>'
            . '<div class="battle-card__bottom">' . $markersHtml . '</div>'
            . $coinsBadge
            . $armorBadge
            . '</div>';
    }

    private function elementIconPath(CardInstance $card): ?string
    {
        return match ($card->element) {
            'plains' => '/assets/images/element/plains.png',
            'mountains' => '/assets/images/element/mountains.png',
            'forests' => '/assets/images/element/forests.png',
            'swamps' => '/assets/images/element/swamps.png',
            'dark' => '/assets/images/element/dark.png',
            'neutral' => '/assets/images/element/neutral.png',
            default => null,
        };
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
        array $cardsInfo,
        string $pile
    ): string {
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

            if (!empty($card->prop)) {
                $line .= ' | prop=' . json_encode($card->prop, JSON_UNESCAPED_UNICODE);
            }

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
