<?php
// src/View/Screen/Battle/InfoPanel.php

declare(strict_types=1);

namespace Berserk\View\Screen\Battle;

use Berserk\Core\GameState;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\BattleHelper;
use Berserk\Core\ValhallaProcessor;
use Berserk\Core\Engine;

use Berserk\Core\Choice\ChoiceRegistry;

use Berserk\View\Template;
use Berserk\View\Ui\Form;
use Berserk\View\Ui\TaskCard;
use Berserk\View\Ui\Panel;
use Berserk\View\Ui\CardButton;

use Berserk\View\Screen\Battle\Choice\RadioChoice;
use Berserk\View\Screen\Battle\Choice\CheckboxChoice;
use Berserk\View\Screen\Battle\Choice\ButtonChoice;
use Berserk\View\Screen\Battle\Choice\MultiRadioChoice;

final class InfoPanel
{
    private GameState $state;
    private string    $playerKey;
    private string    $role;
    private array     $cardsInfo;
    private string    $baseUrl;

    public function __construct(private Template $tpl) {}

    public function render(
        GameState $state,
        string $playerKey,
        string $role,
        array $cardsInfo,
        string $baseUrl
    ): string {
        $this->state     = $state;
        $this->playerKey = $playerKey;
        $this->role      = $role;
        $this->cardsInfo = $cardsInfo;
        $this->baseUrl   = $baseUrl;

        return $this->renderStatusHeader() . $this->renderBody();
    }

    private function renderStatusHeader(): string
    {
        $state     = $this->state;
        $playerKey = $this->playerKey;

        $turn  = (int) ($state->battle['turn'] ?? 0);
        $phase = $state->battle['turn_phase']['phase'] ?? null;
        $phaseActiveKey = $state->battle['turn_phase']['active_key'] ?? null;

        if ($phase === 'end') {
            $isMyTurn = ($phaseActiveKey === $playerKey);
        } else {
            $isMyTurn = (($state->battle['active'] ?? null) === $playerKey);
        }

        $phaseLabel = match ($phase) {
            'start' => 'Фаза начала хода',
            'end'   => 'Фаза конца хода',
            default => 'Основная фаза',
        };

        if ($isMyTurn) {
            $text = '#' . $turn . ' Мой ход — ' . $phaseLabel;
        } else {
            $text = '#' . $turn . ' Ход оппонента — ' . $phaseLabel;
        }

        return '<h1 class="info-status">' . htmlspecialchars($text, ENT_QUOTES) . '</h1>';
    }

    private function renderBody(): string
    {
        $state     = $this->state;
        $playerKey = $this->playerKey;
        $role      = $this->role;
        $cardsInfo = $this->cardsInfo;
        $baseUrl   = $this->baseUrl;

        file_put_contents(
            __DIR__ . '/../../../../debug.log',
            date('[Y-m-d H:i:s] ') . 'InfoPanel: pending=' 
                . (!empty($state->battle['pending_cell_marker_pick']) ? 'Y' : 'N')
                . "\n",
            FILE_APPEND
        );
        // Реестр ChoiceHandler — новая ветка
        $handler = \Berserk\Core\Choice\ChoiceRegistry::current($state);
        if ($handler !== null) {
            $spec = $handler->spec($state, $playerKey, $cardsInfo, $baseUrl, $role);
            if ($spec !== null) {
                return \Berserk\View\Ui\Panel::render($spec);
            }
        }

        if (!empty($state->battle['pending_prophecy'])) {
            return $this->renderProphecy();
        }

        if (!empty($state->battle['strike'])) {
            return $this->renderStrike();
        }

        if (!empty($state->battle['pending_dive'])) {
            return $this->renderDiveChoice();
        }

        $instantResultHtml = '';
        if (!empty($state->battle['instant_result'])) {
            $instantResultHtml = '<div class="info-box">';
            foreach ((array) $state->battle['instant_result'] as $r) {
                if (($r['type'] ?? '') !== 'open_damage') continue;
                $target = $state->getCard((int) ($r['target_id'] ?? 0));
                $info = $target ? ($cardsInfo[$target->ukid] ?? null) : null;
                $name = $info ? htmlspecialchars($info['name'], ENT_QUOTES) : '?';
                $died = !empty($r['died']);
                $instantResultHtml .= '<p><b>' . $name . '</b> '
                    . ($died ? 'получает 1 рану и погибает' : 'открыта и получает 1 рану')
                    . '</p>';
            }
            $instantResultHtml .= '</div>';
        }

        // Ничего не показали — показываем кнопки хода
        if ($state->winner !== null) {
            return '';
        }

        $isActive = ($state->battle['active'] === $playerKey);

        if ($isActive && empty($state->battle['strike'])) {
            // Проверяем, есть ли у активного turn-инстанты
            $sr = new \Berserk\Core\StrikeResolver($state, new \Berserk\Core\Engine());
            $hasInstants = !empty($sr->getCombatInstants($playerKey, 'before'));

            $instantBtn = '';
            if ($hasInstants) {
                $instantBtn = '<span style="flex: 1"><a class="button instant" href="' . $baseUrl . '&cmd=open_turn_instants">Сыграть инстант</a></span>';
            }

            return $instantResultHtml . '<div class="info-actions">'
                . '<a class="button wide" href="' . $baseUrl . '&cmd=end_turn">Завершить ход</a>'
                . $instantBtn
                . '<a class="button wide resign" href="' . $baseUrl . '&cmd=resign" '
                . 'onclick="return confirm(\'Сдаться?\')">Сдаться</a>'
                . '</div>';
        }

        return $instantResultHtml;

    }

    private function cancelUrl(): string
    {
        return $this->baseUrl . '&cmd=cancel_pending';
    }

    // ─── Окно сражения ───────────────────────────────────────

    private function renderStrike(): string {
        $state     = $this->state;
        $playerKey = $this->playerKey;
        $role      = $this->role;
        $cardsInfo = $this->cardsInfo;
        $baseUrl   = $this->baseUrl;
        $strike    = $state->battle['strike'];

        if (!$strike) return '';

        $attackerCard = $state->getCard($strike['attacker_id']);
        $targetCard   = $state->getCard($strike['target_id']);
        $attackerInfo = $attackerCard ? ($cardsInfo[$attackerCard->ukid] ?? null) : null;
        $targetInfo   = $targetCard   ? ($cardsInfo[$targetCard->ukid]   ?? null) : null;

        $an = $attackerInfo ? htmlspecialchars($attackerInfo['name'], ENT_QUOTES) : '?';
        $tn = $targetInfo   ? htmlspecialchars($targetInfo['name'], ENT_QUOTES)   : '?';

        $contentHtml = '';
        $kind = $strike['kind'] ?? 'strike';

        if ($strike['state'] === 'results' && !empty($strike['cell_marker'])) {
            $cm = $strike['cell_marker'];
            $label = $strike['action_name'] ?? 'Маркер';
            $contentHtml = '<p>' . htmlspecialchars($label, ENT_QUOTES)
                . ': клетка (' . $cm['row'] . ',' . $cm['col'] . ')</p>';

            $confirmed = $strike['confirmed'] ?? [];
            if (!in_array($playerKey, $confirmed, true)) {
                $contentHtml .= '<a class="button" href="' . $baseUrl . '&cmd=confirm_strike">Продолжить</a>';
            } else {
                $contentHtml .= '<p class="wait">Ожидание оппонента...</p>';
            }

            // Сразу возвращаем — пропускаем всё остальное
            return $this->tpl->parse('includes/battle/strike.tpl', [
                'attacker_name' => $an,
                'target_name'   => $tn,
                'header_text'   => 'Костёр',
                'content_html'  => $contentHtml,
            ]);
        }

        // Статьи без сражения — упрощённая панель
        if (($strike['kind'] ?? '') === 'become_fly') {
            $attackerCard = $state->getCard($strike['attacker_id']);
            $name = $attackerCard
                ? ($cardsInfo[$attackerCard->ukid]['name'] ?? '?')
                : '?';

            $confirmed = $strike['confirmed'] ?? [];
            if (!in_array($playerKey, $confirmed, true)) {
                $confirmHtml = '<a class="button" href="' . $baseUrl . '&cmd=confirm_strike">Продолжить</a>';
            } else {
                $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
            }

            return '<div class="card-choice">'
                . '<h3>' . htmlspecialchars($name, ENT_QUOTES) . ' — получен полёт</h3>'
                . '<div class="death-choice-buttons">' . $confirmHtml . '</div>'
                . '</div>';
        }

        if ($strike['state'] === 'waiting_redirect') {
            $stackHtml = '';
            $stack = $strike['instant_stack'] ?? [];
            if (!empty($stack)) {
                $stack = array_reverse($stack);   // разрешение сверху вниз
                $stackHtml = '<div class="instant-stack">'
                    . '<div class="instant-stack__title">Стек (сверху разрешается первым):</div>'
                    . '<ul class="instant-stack__list">';

                foreach ($stack as $i => $item) {
                    $srcCard = $state->getCard($item['card_id']);
                    $srcName = $srcCard ? ($cardsInfo[$srcCard->ukid]['name'] ?? '?') : '?';
                    $tgtCard = $state->getCard($item['target_id']);
                    $tgtName = $tgtCard ? ($cardsInfo[$tgtCard->ukid]['name'] ?? '?') : '?';

                    $isTop = ($i === 0) ? ' instant-stack__item--top' : '';
                    $whoLabel = ($item['player'] ?? '') === $playerKey ? 'Ты' : 'Оппонент';

                    $stackHtml .= '<li class="instant-stack__item' . $isTop . '">'
                        . '<span class="instant-stack__who">' . htmlspecialchars($whoLabel, ENT_QUOTES) . '</span> '
                        . '<b>' . htmlspecialchars($srcName, ENT_QUOTES) . '</b> — '
                        . htmlspecialchars($item['label'], ENT_QUOTES)
                        . ' <span class="instant-stack__target">→ '
                        . htmlspecialchars($tgtName, ENT_QUOTES) . '</span>'
                        . '</li>';
                }

                $stackHtml .= '</ul></div>';
            }

            $chooserKey = $state->getOpponentKey($attackerCard->owner);

            if ($playerKey === $chooserKey) {
                $origTarget = $state->getCard($strike['target_id']);
                $origInfo   = $origTarget ? ($cardsInfo[$origTarget->ukid] ?? null) : null;
                $origName   = $origInfo ? htmlspecialchars($origInfo['name'], ENT_QUOTES) : '?';

                $buttons = '';
                foreach ($strike['redirect_candidates'] ?? [] as $tid) {
                    $tc = $state->getCard($tid);
                    if (!$tc) continue;
                    $ti = $cardsInfo[$tc->ukid] ?? null;
                    $tn = $ti ? htmlspecialchars($ti['name'], ENT_QUOTES) : '?';
                    $url = "{$baseUrl}&cmd=choose_redirect&target_id={$tid}";
                    $buttons .= '<a class="button" href="' . $url . '">' . $tn . '</a> ';
                }
                $skipUrl = "{$baseUrl}&cmd=choose_redirect&target_id=0";
                $buttons .= '<a class="button skip" href="' . $skipUrl . '">Не перенаправлять (' . $origName . ')</a>';

                $contentHtml = '<p>' . $an . ' атакует. Перенаправить удар на другое существо рядом?</p>'
                    . '<div class="death-choice-buttons">' . $buttons . '</div>';
            } else {
                $contentHtml = '<p class="wait">Ожидание выбора перенаправления...</p>';
            }

            $contentHtml .= $stackHtml;
        } elseif ($strike['state'] === 'waiting_instant') {
            $phase = $strike['instant_phase'] ?? 'before';
            $type  = ($phase === 'combat') ? 'combat' : 'turn';

            // Показ кубиков — только в фазе combat (окно 2)
            $diceHtml = '';
            if ($phase === 'combat') {
                $ad = (int) ($strike['attack_dice'] ?? 0);
                $dd = (int) ($strike['defend_dice'] ?? 0);
                $am = (int) ($strike['attack_mod'] ?? 0);
                $dm = (int) ($strike['defend_mod'] ?? 0);
                $res = $strike['result'] ?? ['attack' => '', 'defend' => ''];

                $adText = (string) $ad . ($am > 0 ? ' +' . $am : ($am < 0 ? ' ' . $am : ''));
                $ddText = $dd > 0
                    ? (string) $dd . ($dm > 0 ? ' +' . $dm : ($dm < 0 ? ' ' . $dm : ''))
                    : '—';

                $diceHtml = '<div class="instant-dice">'
                    . '<div class="dice-col"><span class="dice-label">Атакующий</span>'
                    . '<span class="dice-value">' . htmlspecialchars($adText, ENT_QUOTES) . '</span>'
                    . '<span class="dice-level">' . htmlspecialchars(BattleHelper::strikeName($res['attack']), ENT_QUOTES) . '</span>'
                    . '</div>'
                    . '<div class="dice-col"><span class="dice-label">Защитник</span>'
                    . '<span class="dice-value">' . htmlspecialchars($ddText, ENT_QUOTES) . '</span>'
                    . '<span class="dice-level">' . htmlspecialchars(BattleHelper::strikeName($res['defend']), ENT_QUOTES) . '</span>'
                    . '</div>'
                    . '</div>';
            }

            $priority = $strike['instant_priority'] ?? null;
            $attackerCard = $state->getCard($strike['attacker_id']);
            $attackerKey = $attackerCard->owner;
            $isMine = ($priority === $playerKey);

            $whoLabel = ($attackerKey === $priority) ? 'Атакующий' : 'Защитник';

            if ($isMine) {
                $sr = new \Berserk\Core\StrikeResolver($state, new \Berserk\Core\Engine());
                $instants = $sr->getCombatInstants($playerKey, $phase, $type);

                $cards = '';
                foreach ($instants as $inst) {
                    $cardName = $cardsInfo[$inst['ukid']]['name'] ?? $inst['ukid'];
                    $label    = $inst['label'] ?? '';
                    $url = "{$baseUrl}&cmd=combat_instant_play&card_id={$inst['card_id']}&instant_key=" . urlencode($inst['payload']['key'] ?? '');
                    $cards .= '<a class="task-card task-card--instant" href="' . $url . '">'
                        . '<div class="task-card__name">' . htmlspecialchars($cardName, ENT_QUOTES) . '</div>'
                        . '<div class="task-card__hint">' . htmlspecialchars($label, ENT_QUOTES) . '</div>'
                        . '</a> ';
                }

                $contentHtml = $diceHtml . '<p>Твой ход: ' . $whoLabel . '. Сыграть инстант?</p>';
                if (!empty($cards)) {
                    $contentHtml .= '<div class="task-list">' . $cards . '</div>';
                }
                $contentHtml .= '<div class="death-choice-buttons">'
                    . '<a class="button skip" href="' . $baseUrl . '&cmd=combat_instant_pass">Продолжить</a>'
                    . '</div>';
            } else {
                $contentHtml = $diceHtml . '<p class="wait">Ожидание — оппонент решает, сыграть инстант...</p>';
            }
        } elseif ($strike['state'] === 'waiting_defender') {
            $chooserKey = $state->getOpponentKey($attackerCard->owner);
            if ($playerKey === $chooserKey) {
                $defButtons = '';
                foreach ($strike['defenders'] ?? [] as $defId) {
                    $dc = $state->getCard($defId);
                    if (!$dc) continue;
                    $di = $cardsInfo[$dc->ukid] ?? [];
                    $defButtons .= CardButton::battle(
                        $this->tpl,
                        $dc,
                        $di,
                        "{$baseUrl}&cmd=choose_defender&defender_id={$defId}",
                        $playerKey
                    );
                }
                $defButtons .= $this->tpl->parse('includes/battle/defender_button.tpl', [
                    'name'  => 'Без защитника',
                    'link'  => "{$baseUrl}&cmd=choose_defender&defender_id=0",
                    'class' => 'skip',
                ]);
                $contentHtml = $this->tpl->parse('includes/battle/strike_defender.tpl', [
                    'buttons_html' => $defButtons,
                ]);
            } else {
                $contentHtml = '<p class="wait">Ожидание выбора защитника...</p>';
            }

        } elseif ($strike['state'] === 'waiting_choice') {
            $attackerOwnerKey = $attackerCard->owner;
            $winnerKey = $strike['choice_winner'] === 'attack'
                ? $attackerOwnerKey
                : $state->getOpponentKey($attackerOwnerKey);

            $ad = $strike['attack_dice'];
            $dd = $strike['defend_dice'];
            $am = $strike['attack_mod'] ?? 0;
            $dm = $strike['defend_mod'] ?? 0;

            $adText = (string) $ad . ($am > 0 ? ' +' . $am : ($am < 0 ? ' ' . $am : ''));
            $ddText = $dd > 0
                ? (string) $dd . ($dm > 0 ? ' +' . $dm : ($dm < 0 ? ' ' . $dm : ''))
                : '—';
            $res = $strike['result'];

            $defendDiceHtml = $this->tpl->parse('includes/battle/dice_column.tpl', [
                'label' => 'Защитник',
                'value' => $ddText,
            ]);

            if ($playerKey === $winnerKey) {
                $afterHtml = $this->tpl->parse('includes/battle/strike_choice.tpl', [
                    'prompt'     => 'Ты выиграл кубики. Выбери вариант:',
                    'role_param' => $role === 'host' ? 'first' : 'second',
                    'game_id'    => $state->gameId,
                    'atk_normal' => BattleHelper::strikeName($res['attack']),
                    'def_normal' => BattleHelper::strikeName($res['defend']),
                    'atk_dec'    => BattleHelper::strikeName(BattleHelper::decreaseStrike($res['attack'])),
                    'def_dec'    => BattleHelper::strikeName(BattleHelper::decreaseStrike($res['defend'])),
                ]);
            } else {
                $afterHtml = '<p class="wait">Оппонент выбирает режим удара...</p>';
            }

            $contentHtml = $this->tpl->parse('includes/battle/strike_dice.tpl', [
                'attack_dice'      => $adText,
                'defend_dice_html' => $defendDiceHtml,
                'result_text'      => '',
                'confirm_html'     => $afterHtml,
            ]);

        } elseif ($strike['state'] === 'waiting_close_or_damage') {
            $pcod    = $strike['pending_close_or_damage'];
            $defCard = $state->getCard($pcod['defender_id']);
            $defInfo = $defCard ? ($cardsInfo[$defCard->ukid] ?? null) : null;

            if ($defCard && $defInfo && $playerKey === $defCard->owner) {
                $roleParam = $role === 'host' ? 'first' : 'second';

                $radio = '<label class="choice-radio">'
                    . '<input type="radio" name="choice" value="close" checked>'
                    . '<span>Закрыться</span>'
                    . '</label>'
                    . '<label class="choice-radio">'
                    . '<input type="radio" name="choice" value="damage">'
                    . '<span>Получить ' . $pcod['damage'] . ' урона</span>'
                    . '</label>';

                $contentHtml = '<div class="card-choice">'
                    . '<h3>Адское зловоние: ' . htmlspecialchars($defInfo['name'], ENT_QUOTES) . '</h3>'
                    . '<form method="get" class="card-choice-form">'
                    . '<input type="hidden" name="' . $roleParam . '" value="">'
                    . '<input type="hidden" name="game" value="' . $state->gameId . '">'
                    . '<input type="hidden" name="cmd" value="choose_close_or_damage">'
                    . $radio
                    . '<button type="submit" class="button">Подтвердить</button>'
                    . '</form>'
                    . '</div>';
            } else {
                $contentHtml = '<p class="wait">Ожидание выбора оппонента...</p>';
            }

            // Это в renderStrike, дальше общий parse('includes/battle/strike.tpl')
            $headerText = 'Адское зловоние';
        } elseif ($strike['state'] === 'waiting_ally_modifier') {
            $pam    = $strike['pending_ally_modifier'];
            $source = $state->getCard($pam['source_id']);
            $srcInfo = $source ? ($cardsInfo[$source->ukid] ?? null) : null;
            $srcName = $srcInfo ? htmlspecialchars($srcInfo['name'], ENT_QUOTES) : '?';

            if ($source && $playerKey === $source->owner) {
                $buttons = '';
                foreach ($pam['candidates'] as $tid) {
                    $tc = $state->getCard($tid);
                    if (!$tc) continue;
                    $tn2 = $cardsInfo[$tc->ukid]['name'] ?? '?';
                    $url = "{$baseUrl}&cmd=choose_ally_modifier&target_id={$tid}";
                    $buttons .= '<a class="button" href="' . $url . '">' . htmlspecialchars($tn2, ENT_QUOTES) . '</a> ';
                }
                $contentHtml = '<p><b>' . $srcName . '</b> — выбери союзника для защиты:</p>'
                    . '<div class="death-choice-buttons">' . $buttons . '</div>';
            } else {
                $contentHtml = '<p class="wait">Ожидание выбора оппонента...</p>';
            }
        } elseif ($strike['state'] === 'waiting_push_choice') {
            $pp      = $strike['pending_push'];
            $defCard = $state->getCard($pp['defender_id']);
            $defInfo = $defCard ? ($cardsInfo[$defCard->ukid] ?? null) : null;

            if ($defCard && $defInfo && $playerKey === $defCard->owner) {
                $roleParam = $role === 'host' ? 'first' : 'second';

                $radio = '<label class="choice-radio">'
                    . '<input type="radio" name="choice" value="move" checked>'
                    . '<span>Переместить на (' . $pp['new_row'] . ',' . $pp['new_col'] . ')</span>'
                    . '</label>'
                    . '<label class="choice-radio">'
                    . '<input type="radio" name="choice" value="damage">'
                    . '<span>Получить ' . $pp['damage'] . ' урона</span>'
                    . '</label>';

                $contentHtml = '<div class="card-choice">'
                    . '<h3>Молотобоец толкает ' . htmlspecialchars($defInfo['name'], ENT_QUOTES) . '</h3>'
                    . '<form method="get" class="card-choice-form">'
                    . '<input type="hidden" name="' . $roleParam . '" value="">'
                    . '<input type="hidden" name="game" value="' . $state->gameId . '">'
                    . '<input type="hidden" name="cmd" value="choose_push_choice">'
                    . $radio
                    . '<button type="submit" class="button">Подтвердить</button>'
                    . '</form>'
                    . '</div>';
            } else {
                $contentHtml = '<p class="wait">Ожидание выбора оппонента...</p>';
            }

        } elseif ($strike['state'] === 'waiting_auto_target') {
            $ad = $strike['attack_dice'];
            $dd = $strike['defend_dice'] ?? 0;
            $am = $strike['attack_mod'] ?? 0;
            $dm = $strike['defend_mod'] ?? 0;

            $adText = (string) $ad . ($am > 0 ? ' +' . $am : ($am < 0 ? ' ' . $am : ''));
            $ddText = $dd > 0
                ? (string) $dd . ($dm > 0 ? ' +' . $dm : ($dm < 0 ? ' ' . $dm : ''))
                : '—';

            $fin = $strike['final'] ?? $strike['result'];

            $defendDiceHtml = '';
            if (($strike['kind'] ?? 'strike') === 'strike') {
                $defendDiceHtml = $this->tpl->parse('includes/battle/dice_column.tpl', [
                    'label' => 'Защитник',
                    'value' => $ddText,
                ]);
            }

            $resultText = 'Атакующий: ' . BattleHelper::strikeName($fin['attack'])
                . ' | Защитник: ' . BattleHelper::strikeName($fin['defend']);

            $diceHtml = $this->tpl->parse('includes/battle/strike_dice.tpl', [
                'attack_dice'      => $adText,
                'defend_dice_html' => $defendDiceHtml,
                'result_text'      => $resultText,
                'confirm_html'     => '',
            ]);

            $pa     = $strike['pending_auto'];
            $source = $state->getCard($pa['source_id']);
            $sourceInfo = $source ? ($cardsInfo[$source->ukid] ?? null) : null;
            $sourceName = $sourceInfo ? htmlspecialchars($sourceInfo['name'], ENT_QUOTES) : '?';

            if ($source && $playerKey === $source->owner) {
                $buttons = '';
                foreach ($pa['candidates'] as $tid) {
                    $tc = $state->getCard($tid);
                    if (!$tc) continue;
                    $ti = $cardsInfo[$tc->ukid] ?? null;
                    $tn2 = $ti ? htmlspecialchars($ti['name'], ENT_QUOTES) : '?';
                    $url = "{$baseUrl}&cmd=choose_auto_target&target_id={$tid}";
                    $buttons .= '<a class="button" href="' . $url . '">' . $tn2 . '</a> ';
                }
                $skipUrl = "{$baseUrl}&cmd=choose_auto_target&target_id=0";
                $buttons .= '<a class="button skip" href="' . $skipUrl . '">Пропустить</a>';

                $effectName = $pa['effect']['name'] ?? 'Выстрел';

                $contentHtml = $diceHtml . '<div class="auto-choice">'
                    . '<p><b>' . $sourceName . '</b> — ' . htmlspecialchars($effectName, ENT_QUOTES) . ':</p>'
                    . '<div class="auto-choice-buttons">' . $buttons . '</div>'
                    . '</div>';
            } else {
                $contentHtml = $diceHtml . '<p class="wait">Ожидание выбора цели...</p>';
            }

        } elseif ($strike['state'] === 'waiting_push_ack') {
            $pr = $strike['push_result'] ?? null;
            $damageInfo = '';

            if ($pr && $pr['type'] === 'forced_damage') {
                $defCard = $state->getCard($strike['defender_id'] ?? $strike['target_id']);
                $defName = $defCard ? ($cardsInfo[$defCard->ukid]['name'] ?? '?') : '?';
                $damageInfo = '<p class="penalty">Нельзя переместить <b>' . htmlspecialchars($defName, ENT_QUOTES) . '</b>: +'
                    . $pr['damage'] . ' урона (удар молота)</p>';
            }

            $confirmed = $strike['confirmed'] ?? [];
            if (!in_array($playerKey, $confirmed, true)) {
                $confirmHtml = '<a class="button" href="' . $baseUrl . '&cmd=confirm_strike">Продолжить</a>';
            } else {
                $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
            }

            $contentHtml = $damageInfo . $confirmHtml;
        } elseif ($strike['state'] === 'results') {
            // Сводка инстантов (если были)
            $summaryHtml = '';
            if (!empty($strike['instant_summary'])) {
                // instant_summary уже в LIFO порядке (сверху — разрешается первым)

                $summaryHtml = '<div class="instant-summary">'
                    . '<div class="instant-summary__title">Стек (LIFO — сверху разрешается первым):</div>'
                    . '<ol class="instant-summary__list">';

                foreach ($strike['instant_summary'] as $s) {
                    $cardName = !empty($s['card_ukid'])
                        ? ($cardsInfo[$s['card_ukid']]['name'] ?? $s['card_ukid'])
                        : '?';

                    $isMine   = (($s['player'] ?? null) === $playerKey);
                    $whoLabel = $isMine ? 'Ты' : 'Оппонент';
                    $whoClass = $isMine ? 'instant-summary__who--mine' : 'instant-summary__who--opp';

                    $mark = $s['applied'] ? '✓' : '✗';
                    $cls  = $s['applied'] ? '' : ' instant-summary__item--skip';
                    $reason = !$s['applied'] && !empty($s['reason'])
                        ? ' (' . htmlspecialchars($s['reason'], ENT_QUOTES) . ')'
                        : '';

                    $summaryHtml .= '<li class="instant-summary__item' . $cls . '">'
                        . '<span class="instant-summary__who ' . $whoClass . '">' . $whoLabel . '</span> '
                        . '<b>' . htmlspecialchars($cardName, ENT_QUOTES) . '</b> — '
                        . htmlspecialchars($s['label'], ENT_QUOTES)
                        . ' ' . $mark . $reason
                        . '</li>';
                }

                $summaryHtml .= '</ol></div>';
            }
            $ad = $strike['attack_dice'];
            $dd = $strike['defend_dice'] ?? 0;
            $am = $strike['attack_mod'] ?? 0;
            $dm = $strike['defend_mod'] ?? 0;

            $adText = (string) $ad . ($am > 0 ? ' +' . $am : ($am < 0 ? ' ' . $am : ''));
            $ddText = $dd > 0
                ? (string) $dd . ($dm > 0 ? ' +' . $dm : ($dm < 0 ? ' ' . $dm : ''))
                : '—';

            $fin = $strike['final'] ?? $strike['result'];

            $defendDiceHtml = '';
            if ($kind === 'strike') {
                $defendDiceHtml = $this->tpl->parse('includes/battle/dice_column.tpl', [
                    'label' => 'Защитник',
                    'value' => $ddText ?: '—',
                ]);
            }

            // Текст результата по kind
            if ($kind === 'transfer_wounds') {
                $name = $strike['action_name'] ?? 'Перераспределение';
                $t = $strike['transfer'] ?? null;
                if ($t) {
                    $fromCard = $state->getCard($t['from_id']);
                    $fromInfo = $fromCard ? ($cardsInfo[$fromCard->ukid] ?? null) : null;
                    $fromName = $fromInfo ? htmlspecialchars($fromInfo['name'], ENT_QUOTES) : '?';
                    $resultText = $name . ': снято ' . $t['value'] . ' ран с ' . $fromName;
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'modifier') {
                $name = $strike['action_name'] ?? 'Способность';
                $mg   = $strike['modifier_granted'] ?? null;

                if ($mg) {
                    // Массив [{stat,value}, ...] или одиночный {stat,value}
                    $list = (is_array($mg) && isset($mg[0])) ? $mg : [$mg];

                    $parts = [];
                    foreach ($list as $m) {
                        if (!is_array($m)) continue;

                        $stat  = (string) ($m['stat'] ?? '');
                        $value = $m['value'] ?? 1;

                        $label = match ($stat) {
                            'direct'         => 'направка',
                            'zoal'           => 'zoal',
                            'ability_strike' => '+к удару',
                            'regeneration'   => 'регенерация',
                            'shot_bonus'     => '+к выстрелу',
                            'ova'            => 'ОВА',
                            'ovz'            => 'ОВЗ',
                            'move'            => 'Ход',
                            default          => $stat,
                        };

                        $parts[] = $label . (is_numeric($value) && (int) $value !== 1 ? ' ' . (int) $value : '');
                    }

                    $resultText = $name . (empty($parts) ? '' : ': получено — ' . implode(', ', $parts));
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'self_wound') {
                $name = $strike['action_name'] ?? 'Таран';
                $sw   = $strike['self_wound'] ?? null;
                if ($sw) {
                    $tCard = $state->getCard($strike['target_id']);
                    $tInfo = $tCard ? ($cardsInfo[$tCard->ukid] ?? null) : null;
                    $tName = $tInfo ? htmlspecialchars($tInfo['name'], ENT_QUOTES) : '?';

                    if ($sw['died_self']) {
                        $resultText = $name . ': Центурион погиб, урон не нанесён';
                    } else {
                        $resultText = $name . ': ' . $sw['amount'] . ' ран на себя, '
                            . $tName . ' получает ' . $sw['damage'] . ' урона';
                    }
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'shield_light') {
                $name = $strike['action_name'] ?? 'Щит света';
                $sh = $strike['shield_data'] ?? null;
                if ($sh) {
                    $tCard = $state->getCard($sh['target_id']);
                    $tInfo = $tCard ? ($cardsInfo[$tCard->ukid] ?? null) : null;
                    $tName = $tInfo ? htmlspecialchars($tInfo['name'], ENT_QUOTES) : '?';
                    $resultText = $name . ': ' . $tName . ' получает защиту от немагических атак на '
                        . $sh['turns'] . ' ход(а) противника';
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'multi_discharge') {
                $name    = $strike['action_name'] ?? 'Тройной разряд';
                $results = $strike['discharged'] ?? [];
                $parts   = [];
                foreach ($results as $r) {
                    $tc = $state->getCard($r['target_id']);
                    $ti = $tc ? ($cardsInfo[$tc->ukid] ?? null) : null;
                    $tn = $ti ? htmlspecialchars($ti['name'], ENT_QUOTES) : '?';
                    if (!empty($r['defended'])) {
                        $parts[] = $tn . ' — защита (0)';
                    } else {
                        $parts[] = $tn . ' −' . $r['damage'] . ' HP';
                    }
                }
                $resultText = $name . ': ' . implode(', ', $parts);
            } elseif ($kind === 'multi_heal') {
                $name = $strike['action_name'] ?? 'Излечение';
                $healed = $strike['healed'] ?? [];
                $parts = [];
                foreach ($healed as $h) {
                    $tc = $state->getCard($h['target_id']);
                    $ti = $tc ? ($cardsInfo[$tc->ukid] ?? null) : null;
                    $tn = $ti ? htmlspecialchars($ti['name'], ENT_QUOTES) : '?';
                    $parts[] = $tn . ' +' . $h['heal'] . ' HP';
                }
                $resultText = $name . ': ' . implode(', ', $parts);
            } elseif ($kind === 'blood_tap') {
                $name = $strike['action_name'] ?? 'Кровавый разряд';
                $bt   = $strike['blood_tap'] ?? null;
                if ($bt) {
                    $tCard = $state->getCard($bt['target_id']);
                    $tName = $tCard ? ($cardsInfo[$tCard->ukid]['name'] ?? '?') : '?';
                    $resultText = $name . ': −' . $bt['x'] . ' HP, ' . $tName . ' получает ' . $bt['x'] . ' урона';
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'poison_target') {
                $name = $strike['action_name'] ?? 'Отравление';
                $pa   = $strike['poison_applied'] ?? null;
                if ($pa) {
                    $tCard = $state->getCard($pa['target_id']);
                    $tName = $tCard ? ($cardsInfo[$tCard->ukid]['name'] ?? '?') : '?';
                    $resultText = $name . ': ' . $tName . ' получает отравление ' . $pa['value'];
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'damage_poisoned') {
                $name = $strike['action_name'] ?? 'Власть Ундины';
                $pd   = $strike['poisoned_damage'] ?? [];
                $parts = [];
                foreach ($pd as $r) {
                    $tc = $state->getCard($r['target_id']);
                    $tn = $tc ? ($cardsInfo[$tc->ukid]['name'] ?? '?') : '?';
                    $parts[] = $tn . ' −' . $r['damage'] . ' HP';
                }
                $resultText = $name . ': '
                    . (empty($parts) ? 'нет отравленных целей' : implode(', ', $parts));
            } elseif ($kind === 'dissonance') {
                $name = $strike['action_name'] ?? 'Диссонанс';
                $dn   = $strike['dissonance'] ?? null;
                if ($dn) {
                    $parts = [];
                    foreach ($dn['targets'] as $t) {
                        $tc = $state->getCard($t['target_id']);
                        $tn = $tc ? ($cardsInfo[$tc->ukid]['name'] ?? '?') : '?';
                        $parts[] = $tn . ' −' . $t['damage'];
                    }
                    $resultText = $name . ': ' . implode(', ', $parts);
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'heal') {
                $name = $strike['action_name'] ?? 'Излечение';
                if (!empty($strike['heal_instead_open'])) {
                    $t  = $state->getCard((int) $strike['heal_instead_open']['target_id']);
                    $tn = $t ? ($cardsInfo[$t->ukid]['name'] ?? '?') : '?';
                    $resultText = $name . ': ' . htmlspecialchars($tn, ENT_QUOTES)
                        . ' открывается вместо излечения';
                } else {
                    $resultText = $name . ': +' . ($strike['heal'] ?? 0) . ' HP';
                    if (!empty($strike['poison_removed'])) {
                        $resultText .= ' (яд снят)';
                    }
                }
            } elseif ($kind === 'sand_claws') {
                $name = $strike['action_name'] ?? 'Песчаные когти';
                $sc   = $strike['sand_claws'] ?? null;
                if ($sc) {
                    $tCard = $state->getCard($sc['target_id']);
                    $tInfo = $tCard ? ($cardsInfo[$tCard->ukid] ?? null) : null;
                    $tName = $tInfo ? htmlspecialchars($tInfo['name'], ENT_QUOTES) : '?';
                    $resultText = $name . ': ' . $tName . ' получает '
                        . $strike['damage'] . ' урона; ваши существа получают +'
                        . $sc['value'] . ' к кубику по этой цели';
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'give_coin') {
                $name = $strike['action_name'] ?? 'Передать монету';
                $gc   = $strike['give_coin'] ?? null;
                if ($gc) {
                    $tCard = $state->getCard($gc['target_id']);
                    $tName = $tCard ? ($cardsInfo[$tCard->ukid]['name'] ?? '?') : '?';
                    $resultText = $name . ': ' . htmlspecialchars($tName, ENT_QUOTES)
                        . ' получает ' . $gc['value'] . ' монету';
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'steal_coin') {
                $name = $strike['action_name'] ?? 'Уловка';
                $sc   = $strike['steal_coin'] ?? null;
                if ($sc) {
                    $tCard = $state->getCard($sc['target_id']);
                    $tName = $tCard ? ($cardsInfo[$tCard->ukid]['name'] ?? '?') : '?';
                    $resultText = $name . ': у ' . htmlspecialchars($tName, ENT_QUOTES)
                        . ' украдена ' . $sc['value'] . ' монета';
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'steal') {
                $name = $strike['action_name'] ?? 'Украсть оружие';
                $st = $strike['steal'] ?? null;
                if ($st) {
                    $tCard = $state->getCard($st['target_id']);
                    $tInfo = $tCard ? ($cardsInfo[$tCard->ukid] ?? null) : null;
                    $tName = $tInfo ? htmlspecialchars($tInfo['name'], ENT_QUOTES) : '?';
                    $resultText = $name . ': ' . $tName . ' теряет '
                        . abs($st['from']) . ' к strike, Лесной разбойник получает +'
                        . $st['to'] . ' к strike';
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'uchr') {
                $resultText = 'УЧР: ' . BattleHelper::strikeName($fin['attack'])
                    . ' (' . ($strike['damage'] ?? 0) . ' урона)';

                if (!empty($strike['uchr_mid_effects'])) {
                    foreach ($strike['uchr_mid_effects'] as $e) {
                        $midInfo = $cardsInfo[$e['target_ukid']] ?? null;
                        $midName = $midInfo ? htmlspecialchars($midInfo['name'], ENT_QUOTES) : '?';
                        $resultText .= '<br>→ ' . $midName . ' получает '
                            . htmlspecialchars($e['stat'], ENT_QUOTES)
                            . ' +' . $e['value'];
                    }
                }
            } elseif (in_array($kind, ['shot', 'throw', 'discharge', 'magic', 'cast', 'uchr', 'tap'], true)) {
                $labels = [
                    'strike'    => 'Удар',
                    'shot'      => 'Выстрел',
                    'throw'     => 'Метание',
                    'discharge' => 'Разряд',
                    'magic'     => 'Магический удар',
                    'cast'      => 'Заклинание',
                    'uchr'      => 'УЧР',
                    'tap'       => 'Особый удар',
                ];
                $label  = $labels[$kind] ?? 'Атака';
                $damage = (int) ($strike['damage_total'] ?? $strike['damage'] ?? 0);

                $resultText = $label . ': ' . BattleHelper::strikeName($fin['attack']);
                if (!empty($strike['defended'])) {
                    $resultText .= ' — защита сработала (0 урона)';
                } else {
                    $resultText .= ' (' . $damage . ' урона)';
                }
            } elseif ($kind === 'bomb_shot') {
                $name = $strike['action_name'] ?? 'Бомба';
                $bomb = $strike['bomb'] ?? null;
                if ($bomb) {
                    $tName = $state->getCard($strike['target_id']);
                    $tInfo = $tName ? ($cardsInfo[$tName->ukid] ?? null) : null;
                    $tn2 = $tInfo ? htmlspecialchars($tInfo['name'], ENT_QUOTES) : '?';
                    $resultText = $name . ': ' . $tn2 . ' получает '
                        . $strike['damage'] . ' урона. Бомба поставлена на клетку ('
                        . $bomb['row'] . ';' . $bomb['col'] . ').';
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'row_spell') {
                $rs = $strike['row_spell'] ?? null;
                $name = $strike['action_name'] ?? 'Цветущие руны';
                if ($rs) {
                    $parts = [];
                    foreach ($rs['targets'] as $t) {
                        $tc = $state->getCard($t['target_id']);
                        $tn = $tc ? ($cardsInfo[$tc->ukid]['name'] ?? '?') : '?';
                        $parts[] = $tn . ' −' . $t['damage'];
                    }
                    $resultText = $name
                        . ': кубик ' . $rs['dice']
                        . ' → ' . BattleHelper::strikeName($rs['level'])
                        . ' (' . $rs['damage_per_target'] . ' урона): '
                        . implode(', ', $parts);
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'dive') {
                $name = $strike['action_name'] ?? 'Пикирование';
                $resultText = $name . ': ' . BattleHelper::strikeName($fin['attack'])
                    . ' (' . ($strike['damage_total'] ?? 0) . ' урона)';
            } elseif ($kind === 'execute') {
                $name = $strike['action_name'] ?? 'Добивание';
                $resultText = $name . ': цель уничтожена';
            } elseif ($kind === 'destroy_self_and_target') {
                $name = $strike['action_name'] ?? 'Последний путь';
                $destroyed = $strike['destroyed'] ?? [];
                $sourceName = '?';
                $targetName = '?';
                if (!empty($destroyed['source_ukid'])) {
                    $sourceName = $cardsInfo[$destroyed['source_ukid']]['name'] ?? $destroyed['source_ukid'];
                }
                if (!empty($destroyed['target_ukid'])) {
                    $targetName = $cardsInfo[$destroyed['target_ukid']]['name'] ?? $destroyed['target_ukid'];
                }
                $sourceEsc = htmlspecialchars($sourceName, ENT_QUOTES);
                $targetEsc = htmlspecialchars($targetName, ENT_QUOTES);
                $nameEsc = htmlspecialchars($name, ENT_QUOTES);
                $resultText = $sourceEsc . ' использует «' . $nameEsc
                    . '»: ' . $sourceEsc . ' и ' . $targetEsc . ' уничтожены';
            } elseif ($kind === 'impact') {
                $name = $strike['action_name'] ?? 'Воздействие';
                $resultText = $name;
            } else {
                $resultText = 'Атакующий: <b>' . BattleHelper::strikeName($fin['attack']).'</b>'
                    . ' | Защитник: <b>' . BattleHelper::strikeName($fin['defend']).'</b>';

                if (!empty($fin['decreased'])) {
                    $resultText .= ' <span class="wait">(удары уменьшены)</span>';
                }

                $dmgTarget = (int) ($strike['damage_total'] ?? 0);
                $dmgBack   = (int) ($strike['defend_damage_total'] ?? 0);

                if (!empty($strike['blocked_by_weak'])) {
                    $resultText .= '<br><span class="wait">Удар заблокирован (защита от слабых атак)</span>';
                } elseif (!empty($strike['defense_applied'])) {
                    $resultText .= '<br><span class="wait">Защита сработала (0 урона)</span>';
                } elseif ($dmgTarget > 0) {
                    $resultText .= '<br>По цели: <b>' . $dmgTarget . '</b> урона';
                }

                if (!empty($strike['defend_blocked_by_weak'])) {
                    $resultText .= '<br><span class="wait">Ответка заблокирована (защита от слабых атак)</span>';
                } elseif ($dmgBack > 0) {
                    $resultText .= '<br>По атакующему (ответка): <b>' . $dmgBack . '</b> урона';
                }
            }

            // Доп. блоки
            $reductionHtml = '';
            if (!empty($strike['attack_reduction'])) {
                $reductionHtml .= '<p class="wait">Урон по цели снижен на ' . $strike['attack_reduction'] . '</p>';
            }
            if (!empty($strike['defend_reduction'])) {
                $reductionHtml .= '<p class="wait">Урон ответки снижен на ' . $strike['defend_reduction'] . '</p>';
            }
            if (!empty($strike['damage_reduction'])) {
                $reductionHtml .= '<p class="wait">Урон снижен на ' . $strike['damage_reduction'] . '</p>';
            }


            $answerHtml = '';
            if (!empty($strike['answer_damage'])) {
                foreach ($strike['answer_damage'] as $a) {
                    $defCard = $state->getCard($a['defender_id']);
                    $defInfo = $defCard ? ($cardsInfo[$defCard->ukid] ?? null) : null;
                    $defName = $defInfo ? htmlspecialchars($defInfo['name'], ENT_QUOTES) : '?';

                    $atkCard = $state->getCard($a['attacker_id']);
                    $atkInfo = $atkCard ? ($cardsInfo[$atkCard->ukid] ?? null) : null;
                    $atkName = $atkInfo ? htmlspecialchars($atkInfo['name'], ENT_QUOTES) : '?';

                    $answerHtml .= '<p class="answer">'
                        . '<b>' . $defName . '</b> отвечает: ' . $atkName . ' получает ' . $a['value'] . ' урона'
                        . '</p>';
                }
            }
            if (!empty($strike['answer_blocked'])) {
                foreach ($strike['answer_blocked'] as $a) {
                    $blockedCard = $state->getCard((int) ($a['target_id'] ?? 0));
                    $blockedInfo = $blockedCard ? ($cardsInfo[$blockedCard->ukid] ?? null) : null;
                    $blockedName = $blockedInfo ? htmlspecialchars($blockedInfo['name'], ENT_QUOTES) : '?';

                    $answerHtml .= '<p class="answer">'
                        . '<b>' . $blockedName . '</b>: ответный удар заблокирован'
                        . '</p>';
                }
            }

            $shotBonusHtml = '';
            if (!empty($strike['shot_bonus'])) {
                $shotBonusHtml = '<p class="bonus">+' . $strike['shot_bonus'] . ' к выстрелу</p>';
            }

            $nextActionBonusHtml = '';
            if (!empty($strike['next_action_bonus'])) {
                $nextActionLabel = match ($kind) {
                    'throw' => 'к метанию',
                    'shot' => 'к выстрелу',
                    default => 'к урону',
                };
                $nextActionBonusHtml = '<p class="bonus">+'
                    . $strike['next_action_bonus'] . ' ' . $nextActionLabel . '</p>';
            }

            $rowsBonusHtml = '';
            if (!empty($strike['rows_bonus'])) {
                $rowsBonusHtml = '<p class="bonus">+' . $strike['rows_bonus'] . ' к урону (все ряды)</p>';
            }

            $autoHtml = '';
            if (!empty($strike['auto_effects'])) {
                foreach ($strike['auto_effects'] as $a) {
                    $t = $state->getCard($a['target_id']);
                    $tInfo = $t ? ($cardsInfo[$t->ukid] ?? null) : null;
                    $tName = $tInfo ? htmlspecialchars($tInfo['name'], ENT_QUOTES) : '?';

                    $autoHtml .= '<p class="auto">'
                        . '<b>' . htmlspecialchars($a['name'], ENT_QUOTES) . '</b>: '
                        . $tName . ' получает ' . $a['damage'] . ' урона'
                        . '</p>';
                }
            }

            $impactHtml = '';
            if (!empty($strike['impact_targets'])) {
                foreach ($strike['impact_targets'] as $it) {
                    $tc = $state->getCard($it['target_id']);
                    $ti = $tc ? ($cardsInfo[$tc->ukid] ?? null) : null;
                    $tn2 = $ti ? htmlspecialchars($ti['name'], ENT_QUOTES) : '?';

                    $impactHtml .= '<p class="impact">'
                        . $tn2 . ': −' . $it['damage'] . ' HP';
                    if (!empty($it['poison'])) {
                        $impactHtml .= ' (отравление ' . $it['poison'] . ')';
                    }
                    $impactHtml .= '</p>';
                }
            }

            if (!empty($strike['self_destroyed'])) {
                $selfCard = $state->getCard($strike['attacker_id']);
                $selfInfo = $selfCard ? ($cardsInfo[$selfCard->ukid] ?? null) : null;
                $selfName = $selfInfo ? htmlspecialchars($selfInfo['name'], ENT_QUOTES) : '?';
                $impactHtml .= '<p class="impact">' . $selfName . ' уничтожен</p>';
            }

            $pushHtml = '';
            if (!empty($strike['push_result'])) {
                $pr = $strike['push_result'];
                if ($pr['type'] === 'moved') {
                    $pushHtml = '<p class="bonus">Карта перемещена на ('
                        . $pr['new_row'] . ',' . $pr['new_col'] . ')</p>';
                } elseif ($pr['type'] === 'damage') {
                    $pushHtml = '<p class="penalty">Отказ от перемещения: '
                        . $pr['damage'] . ' урона</p>';
                } elseif ($pr['type'] === 'forced_damage') {
                    $pushHtml = '<p class="penalty">Нельзя переместить: '
                        . $pr['damage'] . ' урона</p>';
                }
            }

            $huntHtml = '';
            if (!empty($strike['hunt_trigger'])) {
                foreach ($strike['hunt_trigger'] as $h) {
                    $tc = $state->getCard($h['target_id']);
                    $ti = $tc ? ($cardsInfo[$tc->ukid] ?? null) : null;
                    $tn2 = $ti ? htmlspecialchars($ti['name'], ENT_QUOTES) : '?';

                    $huntHtml .= '<p class="hunt">'
                        . 'Охота: <b>' . $tn2 . '</b> получает +' . $h['bonus'] . ' урона'
                        . '</p>';
                }
            }

            $behindWeakStrikeHtml = '';
            if (!empty($strike['behind_weak_strike_damage'])) {
                $bribe = $strike['behind_weak_strike_damage'];
                $source = $state->getCard((int) ($bribe['source_id'] ?? 0));
                $target = $state->getCard((int) ($bribe['target_id'] ?? 0));

                $sourceInfo = $source ? ($cardsInfo[$source->ukid] ?? null) : null;
                $targetInfo = $target ? ($cardsInfo[$target->ukid] ?? null) : null;
                $sourceName = $sourceInfo ? htmlspecialchars($sourceInfo['name'], ENT_QUOTES) : '?';
                $targetName = $targetInfo ? htmlspecialchars($targetInfo['name'], ENT_QUOTES) : '?';
                $damage = (int) ($bribe['damage'] ?? 0);

                $behindWeakStrikeHtml = '<p class="bonus">Коварный подкуп: слабый удар <b>'
                    . $sourceName . '</b> — +' . $damage . ' урона по <b>' . $targetName . '</b></p>';
            }

            $abilityBonusHtml = '';
            if (!empty($strike['ability_bonus'])) {
                $abilityBonusHtml = '<p class="bonus">+'
                    . $strike['ability_bonus'] . ' к урону (способность)</p>';
            }

            $nextStrikeBonusHtml = '';
            if (!empty($strike['next_strike_bonus'])) {
                $nextStrikeBonusHtml = '<p class="bonus">+'
                    . $strike['next_strike_bonus'] . ' к урону (следующий удар)</p>';
            }

            $clumsyHtml = '';
            $clumsy = $strike['attack_clumsy'] ?? $strike['clumsy'] ?? 0;
            if ($clumsy > 0) {
                $clumsyHtml = '<p class="penalty">Неповоротливость: −' . $clumsy . ' к кубику</p>';
            }

            $coinBonusHtml = '';
            if (!empty($strike['coin_bonus'])) {
                $coinBonusHtml = '<p class="bonus">Потрачено монет: '
                    . $strike['coins_spent'] . ' (+' . $strike['coin_bonus'] . ' к урону)</p>';
            }

            $deathHtml = '';
            if (!empty($strike['death_triggers'])) {
                foreach ($strike['death_triggers'] as $trigger) {
                    $diedInfo = $cardsInfo[$trigger['died_ukid']] ?? null;
                    $diedName = $diedInfo ? htmlspecialchars($diedInfo['name'], ENT_QUOTES) : '?';

                    $deathHtml .= '<div class="death-trigger">';
                    $deathHtml .= '<b>' . $diedName . '</b> погибает, срабатывает свойство:';

                    foreach ($trigger['targets'] as $t) {
                        $target = $state->getCard($t['target_id']);
                        $targetInfo = $target ? ($cardsInfo[$target->ukid] ?? null) : null;
                        $targetName = $targetInfo ? htmlspecialchars($targetInfo['name'], ENT_QUOTES) : '?';

                        if ($t['damage'] > 0) {
                            $deathHtml .= '<br>→ ' . $targetName . ' получает ' . $t['damage'] . ' урона';
                        } elseif ($t['heal'] > 0) {
                            $deathHtml .= '<br>→ ' . $targetName . ' излечивается на ' . $t['heal'];
                        }
                    }
                    $deathHtml .= '</div>';
                }
            }
            if (!empty($strike['line_death_next_strike_bonus'])) {
                foreach ($strike['line_death_next_strike_bonus'] as $trigger) {
                    $source = $state->getCard((int) ($trigger['source_id'] ?? 0));
                    $target = $state->getCard((int) ($trigger['target_id'] ?? 0));
                    $sourceInfo = $source ? ($cardsInfo[$source->ukid] ?? null) : null;
                    $targetInfo = $target ? ($cardsInfo[$target->ukid] ?? null) : null;
                    $sourceName = $sourceInfo ? htmlspecialchars($sourceInfo['name'], ENT_QUOTES) : '?';
                    $targetName = $targetInfo ? htmlspecialchars($targetInfo['name'], ENT_QUOTES) : '?';
                    $value = (int) ($trigger['value'] ?? 0);

                    $deathHtml .= '<div class="death-trigger">'
                        . '<b>' . $sourceName . '</b>: ' . $targetName
                        . ' получает +' . $value . ' к следующему удару'
                        . '</div>';
                }
            }

            $deadeatHtml = '';
            if (!empty($strike['deadeat'])) {
                foreach ($strike['deadeat'] as $t) {
                    $info = $cardsInfo[$t['ukid']] ?? null;
                    $name = $info ? htmlspecialchars($info['name'], ENT_QUOTES) : '?';
                    $deadeatHtml .= '<div class="deadeat">'
                        . '<b>' . $name . '</b> — трупоедство: +' . $t['heal'] . ' HP'
                        . '</div>';
                }
            }

            $vampireHtml = '';
            if (!empty($strike['vampire_heal'])) {
                foreach ($strike['vampire_heal'] as $v) {
                    $info = $cardsInfo[$v['ukid']] ?? null;
                    $name = $info ? htmlspecialchars($info['name'], ENT_QUOTES) : '?';
                    $vampireHtml .= '<div class="vampire">'
                        . '<b>' . $name . '</b> — вампиризм: +' . $v['heal'] . ' HP'
                        . '</div>';
                }
            }
            if (!empty($strike['kobold_heal'])) {
                foreach ($strike['kobold_heal'] as $h) {
                    $card = $state->getCard((int) ($h['card_id'] ?? 0));
                    $info = $card ? ($cardsInfo[$card->ukid] ?? null) : null;
                    $name = $info ? htmlspecialchars($info['name'], ENT_QUOTES) : '?';
                    $vampireHtml .= '<div class="vampire">'
                        . '<b>' . $name . '</b> излечивается на ' . (int) ($h['heal'] ?? 0)
                        . '</div>';
                }
            }
            if (!empty($strike['defender_heal'])) {
                foreach ($strike['defender_heal'] as $h) {
                    $card = $state->getCard((int) ($h['card_id'] ?? 0));
                    $info = $card ? ($cardsInfo[$card->ukid] ?? null) : null;
                    $name = $info ? htmlspecialchars($info['name'], ENT_QUOTES) : '?';
                    $vampireHtml .= '<div class="vampire">'
                        . '<b>' . $name . '</b> излечивается на ' . (int) ($h['heal'] ?? 0)
                        . ' (Бьерн)</div>';
                }
            }
            if (!empty($strike['talion_incarnation_token'])) {
                foreach ($strike['talion_incarnation_token'] as $t) {
                    $source = $state->getCard((int) ($t['source_id'] ?? 0));
                    $target = $state->getCard((int) ($t['target_id'] ?? 0));
                    $sourceInfo = $source ? ($cardsInfo[$source->ukid] ?? null) : null;
                    $targetInfo = $target ? ($cardsInfo[$target->ukid] ?? null) : null;
                    $sourceName = $sourceInfo ? htmlspecialchars($sourceInfo['name'], ENT_QUOTES) : '?';
                    $targetName = $targetInfo ? htmlspecialchars($targetInfo['name'], ENT_QUOTES) : '?';
                    $value = (int) ($t['value'] ?? 0);
                    $threshold = (int) ($t['threshold'] ?? 0);
                    $suffix = !empty($t['has_incarnation']) && $threshold > 0
                        ? ' (' . $value . '/' . $threshold . ')'
                        : ' (без Инкарнации: ' . $value . ')';

                    $vampireHtml .= '<div class="vampire">'
                        . '<b>' . $sourceName . '</b>: ' . $targetName
                        . ' получает жетон инкарнации' . $suffix
                        . '</div>';
                }
            }
            if (!empty($strike['holvert_open'])) {
                foreach ($strike['holvert_open'] as $h) {
                    $source = $state->getCard((int) ($h['source_id'] ?? 0));
                    $target = $state->getCard((int) ($h['target_id'] ?? 0));
                    $sourceInfo = $source ? ($cardsInfo[$source->ukid] ?? null) : null;
                    $targetInfo = $target ? ($cardsInfo[$target->ukid] ?? null) : null;
                    $sourceName = $sourceInfo ? htmlspecialchars($sourceInfo['name'], ENT_QUOTES) : '?';
                    $targetName = $targetInfo ? htmlspecialchars($targetInfo['name'], ENT_QUOTES) : '?';

                    $vampireHtml .= '<div class="vampire">'
                        . '<b>' . $sourceName . '</b>: ' . $targetName
                        . ' открыта и не может атаковать до конца хода'
                        . '</div>';
                }
            }

            // Кнопка подтверждения / отложенный выбор
            $confirmed = $strike['confirmed'] ?? [];
            if (!empty($strike['pending_choice'])) {
                $pc = $strike['pending_choice'];
                $diedInfo = $cardsInfo[$pc['died_ukid']] ?? null;
                $diedName = $diedInfo ? htmlspecialchars($diedInfo['name'], ENT_QUOTES) : '?';

                $died = $state->getCard($pc['died_id']);
                $chooserKey = $died ? $died->owner : null;

                if ($playerKey === $chooserKey) {
                    $buttons = '';
                    foreach ($pc['candidates'] as $tid) {
                        $tc = $state->getCard($tid);
                        if (!$tc) continue;
                        $ti = $cardsInfo[$tc->ukid] ?? null;
                        $tn2 = $ti ? htmlspecialchars($ti['name'], ENT_QUOTES) : '?';
                        $url = "{$baseUrl}&cmd=choose_death_target&target_id={$tid}";
                        $buttons .= '<a class="button" href="' . $url . '">' . $tn2 . '</a> ';
                    }

                    $confirmHtml = '<div class="death-choice">'
                        . '<p><b>' . $diedName . '</b> погибает. Выбери цель:</p>'
                        . '<div class="death-choice-buttons">' . $buttons . '</div>'
                        . '</div>';
                } else {
                    $confirmHtml = '<p class="wait">Ожидание выбора цели оппонентом...</p>';
                }
            } elseif (!in_array($playerKey, $confirmed, true)) {
                $confirmHtml = '<a class="button" href="' . $baseUrl . '&cmd=confirm_strike">Продолжить</a>';
            } else {
                $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
            }

            $extraResultHtml = $autoHtml . $pushHtml . $impactHtml . $huntHtml
                . $behindWeakStrikeHtml . $shotBonusHtml . $nextActionBonusHtml
                . $nextStrikeBonusHtml . $rowsBonusHtml . $clumsyHtml
                . $abilityBonusHtml . $coinBonusHtml . $reductionHtml
                . $answerHtml . $vampireHtml . $deadeatHtml . $deathHtml;

            $noDiceKinds = ['heal', 'modifier', 'execute', 'destroy_self_and_target', 'transfer_wounds', 'shield_light', 'self_wound', 'multi_heal', 'steal', 'sand_claws', 'multi_discharge', 'blood_tap', 'poison_target', 'damage_poisoned', 'place_cell_marker', 'dissonance', 'steal_coin', 'give_coin', 'magic', 'become_fly', 'bomb_shot'];
            if (!in_array($kind, $noDiceKinds, true)) {
                $contentHtml = $this->tpl->parse('includes/battle/strike_dice.tpl', [
                    'attack_dice'      => $adText,
                    'defend_dice_html' => $defendDiceHtml,
                    'result_text'      => $summaryHtml . $resultText . $extraResultHtml,
                    'confirm_html'     => $confirmHtml,
                ]);
            } else {
                $contentHtml = '<p>' . $summaryHtml . $resultText . $extraResultHtml . '</p>' . $confirmHtml;
            }
        }

        $headerText = 'Атака';
        if ($kind === 'heal' || $kind === 'multi_heal')     $headerText = 'Излечение';
        if ($kind === 'multi_discharge') $headerText = 'Разряд';
        if ($kind === 'modifier') $headerText = 'Способность';
        if ($kind === 'impact')   $headerText = 'Воздействие';
        if ($kind === 'shield_light')   $headerText = 'Заклинание';
        if ($kind === 'self_wound') $headerText = 'Таран';
        if ($kind === 'steal') $headerText = 'Кража';
        if ($kind === 'sand_claws') $headerText = 'Песчаные когти';
        if ($kind === 'blood_tap') $headerText = 'Кровавый разряд';
        if ($kind === 'poison_target')   $headerText = 'Отравление';
        if ($kind === 'damage_poisoned') $headerText = 'Власть Ундины';
        if ($kind === 'place_cell_marker') $headerText = 'Костер';
        if ($kind === 'dissonance') $headerText = 'Воздействие';
        if ($kind === 'steal_coin') $headerText = 'Уловка';
        if ($kind === 'give_coin') $headerText = 'Передача монеты';
        if ($kind === 'become_fly') $headerText = 'Полёт';
        if ($kind === 'dive') $headerText = 'Пикирование';
        if ($kind === 'bomb_shot') $headerText = 'Бомба';
        if ($kind === 'row_spell') $headerText = 'Цветущие руны';

        if (($strike['state'] ?? '') === 'waiting_instant') {
            $phase = $strike['instant_phase'] ?? 'before';
            $headerText = match ($phase) {
                'combat' => 'Результат броска',
                'after'  => 'Сражение завершено',
                default  => 'Объявление удара',
            };
        }

        return $this->tpl->parse('includes/battle/strike.tpl', [
            'attacker_name' => $an,
            'target_name'   => $tn,
            'header_text'   => $headerText,
            'content_html'  => $contentHtml,
        ]);
    }

    private function renderProphecy(): string
    {
        $state     = $this->state;
        $playerKey = $this->playerKey;
        $cardsInfo = $this->cardsInfo;
        $baseUrl   = $this->baseUrl;

        $pp = $state->battle['pending_prophecy'] ?? null;
        if (!$pp) return '';

        $isMine     = ($pp['owner'] === $playerKey);
        $sourceName = $cardsInfo[$pp['source_ukid']]['name'] ?? '?';
        $title      = $sourceName . ': ' . ($pp['title'] ?? 'Пророчество');

        // ── Режим вызова Отряда ─────────────────────────────
        if (!empty($pp['summon_mode'])) {
            if (!$isMine) {
                return '<div class="card-choice">'
                    . '<h3>' . htmlspecialchars($title, ENT_QUOTES) . '</h3>'
                    . '<p class="wait">Ожидание выбора оппонента...</p>'
                    . '</div>';
            }
            $step = $pp['summon_step'] ?? 'cell';

            $body = '';
            if ($step === 'cell') {
                $body .= '<p>Выбери клетку для выхода Отряда:</p>';
                $body .= '<div class="death-choice-buttons">';
                foreach ($pp['summon_cells'] as $key) {
                    [$r, $c] = explode('_', $key);
                    $url = "{$baseUrl}&cmd=summon_cell&row={$r}&col={$c}";
                    $body .= '<a class="button" href="' . $url . '">(' . $r . ',' . $c . ')</a> ';
                }
                $body .= '</div>';
            } elseif ($step === 'creature') {
                $body .= '<p>Выбери существо рядом с клеткой (оно получит яд 1):</p>';
                $body .= '<div class="death-choice-buttons">';
                foreach ($pp['summon_creatures'] as $tid) {
                    $tc = $state->getCard($tid);
                    if (!$tc) continue;
                    $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
                    $ownerLabel = ($tc->owner === $playerKey) ? 'свой' : 'враг';
                    $url = "{$baseUrl}&cmd=summon_creature&target_id={$tid}";
                    $body .= '<a class="button" href="' . $url . '">'
                        . htmlspecialchars($tn, ENT_QUOTES)
                        . ' (' . $ownerLabel . ')</a> ';
                }
                $body .= '</div>';
            }

            return '<div class="card-choice">'
                . '<h3>' . htmlspecialchars($title, ENT_QUOTES) . '</h3>'
                . $body
                . '</div>';
        }

        // ── Режим перестановки Махинатора ──────────────────
        if (!empty($pp['reorder_mode'])) {
            if (!$isMine) {
                return '<div class="card-choice">'
                    . '<h3>' . htmlspecialchars($title, ENT_QUOTES) . '</h3>'
                    . '<p class="wait">Ожидание выбора оппонента...</p>'
                    . '</div>';
            }
            $cardsHtml = '';
            foreach ($pp['reorder_remaining'] ?? [] as $cardId) {
                $c = $state->getCard($cardId);
                if (!$c) continue;
                $info = $cardsInfo[$c->ukid] ?? null;
                $name = $info ? htmlspecialchars($info['name'], ENT_QUOTES) : '?';

                $upUrl   = "{$baseUrl}&cmd=reorder_card_up&card_id={$cardId}";
                $downUrl = "{$baseUrl}&cmd=reorder_card_down&card_id={$cardId}";

                $cardsHtml .= '<div class="reorder-card">'
                    . '<div class="reorder-name">' . $name . '</div>'
                    . '<div class="reorder-actions">'
                    . '<a class="button small" href="' . $upUrl . '">↑ Наверх</a>'
                    . '<a class="button small skip" href="' . $downUrl . '">↓ Вниз</a>'
                    . '</div>'
                    . '</div>';
            }

            return '<div class="card-choice">'
                . '<h3>' . htmlspecialchars($title, ENT_QUOTES) . '</h3>'
                . '<p>Выбери, куда положить каждую карту:</p>'
                . '<div class="reorder-list">' . $cardsHtml . '</div>'
                . '</div>';
        }

        // ── Обычный рендер ─────────────────────────────────
        $cardsHtml = '';
        foreach ($pp['shown_ukids'] as $ukid) {
            $info = $cardsInfo[$ukid] ?? null;
            if (!$info) {
                $cardsHtml .= '<div class="prophecy-card"><div class="prophecy-card__name">?</div></div>';
                continue;
            }
            $cardsHtml .= $this->tpl->parse('includes/prophecy_card.tpl', [
                'name'   => htmlspecialchars($info['name'], ENT_QUOTES),
                'price'  => (int) $info['price'],
                'health' => (int) $info['health'],
                'move'   => (int) $info['move'],
                'weak'   => (int) $info['strike']['weak'],
                'medium' => (int) $info['strike']['medium'],
                'strong' => (int) $info['strike']['strong'],
                'elite'  => $info['elite'] ? 'elite' : '',
            ]);
        }

        if ($isMine) {
            $buttons = '';
            foreach ($pp['actions'] as $a) {
                $url = "{$baseUrl}&cmd=" . urlencode($a['cmd']);
                foreach ($a['params'] ?? [] as $k => $v) {
                    $url .= '&' . urlencode((string) $k) . '=' . urlencode((string) $v);
                }

                $cls = 'button' . (!empty($a['class']) ? ' ' . $a['class'] : '');

                // Отбиваем Махинатора и Отряд визуально от действий Искателя
                if (in_array($a['cmd'], ['reorder_start', 'summon_start'], true)) {
                    $cls .= ' prophecy-secondary';
                }

                $buttons .= '<a class="' . $cls . '" href="' . $url . '">'
                    . htmlspecialchars($a['label'], ENT_QUOTES) . '</a> ';
            }
            $actionsHtml = '<div class="prophecy-actions">' . $buttons . '</div>';
        } else {
            $actionsHtml = '<div class="prophecy-actions"><p class="wait">Ожидание выбора...</p></div>';
        }

        return '<div class="card-choice">'
            . '<h3>' . htmlspecialchars($title, ENT_QUOTES) . '</h3>'
            . '<div class="prophecy-view">'
            . '<div class="prophecy-cards">' . $cardsHtml . '</div>'
            . $actionsHtml
            . '</div>'
            . '</div>';
    }
    
    private function renderStartAck(): string
    {
        $state     = $this->state;
        $cardsInfo = $this->cardsInfo;
        $baseUrl   = $this->baseUrl;

        $sp = $state->battle['start_phase'] ?? null;
        if (!$sp || empty($sp['pending_ack'])) return '';

        $ack = $sp['pending_ack'];

        if (is_array($ack)) {
            $label = (string) ($ack['label'] ?? '');
            $items = $ack['items'] ?? [];

            $parts = [];
            foreach ($items as $it) {
                if (isset($it['standalone_text'])) {
                    $parts[] = $it['standalone_text'];
                    continue;
                }
                $card = $state->getCard($it['instance_id']);
                if (!$card) continue;
                $name = $cardsInfo[$card->ukid]['name'] ?? $card->ukid;

                // Яд/реген — есть delta
                if (isset($it['delta'])) {
                    $delta = (int) $it['delta'];
                    $sign  = $delta >= 0 ? '+' . $delta : '−' . abs($delta);
                    $parts[] = $name . ' ' . $sign;
                    continue;
                }

                if (isset($it['text'])) {
                    $parts[] = $name . ' — ' . $it['text'];
                    continue;
                }

                // Инкарнация — есть event
                $ev = (string) ($it['event'] ?? '');
                if ($ev === 'progress') {
                    $parts[] = $name . ' ' . (int) $it['value'] . '/' . (int) $it['threshold'];
                } elseif ($ev === 'ready') {
                    $parts[] = $name . ' — готов (ждёт места)';
                } elseif ($ev === 'returned') {
                    $parts[] = $name . ' — вернулся в бой';
                }
            }

            $text = $label . (empty($parts) ? '' : ': ' . implode(', ', $parts));
        } else {
            $text = (string) $ack;
            if ($text === 'incarnation') $text = 'Инкарнация';
        }

        return '<div class="card-choice">'
            . '<h3>' . htmlspecialchars($text, ENT_QUOTES) . '</h3>'
            . '<div class="death-choice-buttons">'
            . '<a class="button" href="' . $baseUrl . '&cmd=start_ack">Продолжить</a>'
            . '</div>'
            . '</div>';
    }

    private function renderStartOrder(string $side): string
    {
        $state     = $this->state;
        $role      = $this->role;
        $cardsInfo = $this->cardsInfo;

        $sp    = $state->battle['start_phase'];
        $queue = $sp[$side . '_queue'];
        $n     = count($queue);

        if ($n <= 1) return '';

        $rowsHtml = '';
        foreach ($queue as $i => $action) {
            $label = $action['label'] ?? $action['type'];

            if (!empty($action['card_id'])) {
                $c = $state->getCard($action['card_id']);
                if ($c) {
                    $name  = $cardsInfo[$c->ukid]['name'] ?? $c->ukid;
                    $label = $name . ' — ' . $label;
                }
            }

            $opts = '';
            for ($p = 1; $p <= $n; $p++) {
                $sel = ($p === $i + 1) ? ' selected' : '';
                $opts .= '<option value="' . $p . '"' . $sel . '>' . $p . '</option>';
            }

            $rowsHtml .= '<label class="start-order-row">'
                . '<span class="start-order-label">'
                . htmlspecialchars($label, ENT_QUOTES)
                . '</span>'
                . '<select name="order[' . $i . ']">' . $opts . '</select>'
                . '</label>';
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        $title = $side === 'active'
            ? 'Твой порядок действий в начале хода:'
            : 'Твой порядок действий (в начале хода оппонента):';

        return '<div class="card-choice">'
            . '<h3>' . htmlspecialchars($title, ENT_QUOTES) . '</h3>'
            . '<form method="get" class="card-choice-form">'
            . '<input type="hidden" name="' . $roleParam . '" value="">'
            . '<input type="hidden" name="game" value="' . $state->gameId . '">'
            . '<input type="hidden" name="cmd" value="choose_start_order">'
            . $rowsHtml
            . '<button type="submit" class="button">Подтвердить</button>'
            . '</form>'
            . '</div>';
    }

    private function renderDiveChoice(): string
    {
        $pd = $this->state->battle['pending_dive'];
        if ($pd['owner'] !== $this->playerKey) {
            return '<p class="wait">Ожидание выбора оппонента...</p>';
        }

        $target     = $this->state->getCard($pd['target_id']);
        $targetName = $target ? ($this->cardsInfo[$target->ukid]['name'] ?? '?') : '?';

        $buttons = '';
        foreach ($pd['cells'] as $c) {
            $url = "{$this->baseUrl}&cmd=choose_dive_cell&row={$c['row']}&col={$c['col']}";
            $buttons .= '<a class="button" href="' . $url . '">(' . $c['row'] . ',' . $c['col'] . ')</a> ';
        }

        return '<div class="card-choice">'
            . '<h3>Пикирование: куда переместить ' . htmlspecialchars($targetName, ENT_QUOTES) . '?</h3>'
            . '<div class="death-choice-buttons">' . $buttons . '</div>'
            . '<div class="death-choice-buttons" style="margin-top:10px">'
            . '<a class="button skip" href="' . $this->baseUrl . '&cmd=cancel_pending">Отмена</a>'
            . '</div>'
            . '</div>';
    }

}
