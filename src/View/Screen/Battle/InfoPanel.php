<?php
// src/View/Screen/Battle/InfoPanel.php

declare(strict_types=1);

namespace Berserk\View\Screen\Battle;

use Berserk\Core\GameState;
use Berserk\Core\TurnTimer;
use Berserk\Core\MatchPause;
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

    public static function hasRequiredInteraction(GameState $state): bool
    {
        $priority = !empty($state->battle['strike']) || !empty($state->battle['turn_instant_stack']) || !empty($state->battle['turn_instant_result'])
            || \Berserk\Core\BattleWaitResolver::current($state) !== null
            || ChoiceRegistry::current($state) !== null
            || !empty($state->battle['instant_result']) || !empty($state->battle['any_death_messages'])
            || !empty($state->battle['degasca_messages'])
            || !empty($state->battle['informational_wait']) || !empty($state->battle['informational_wait_pending']);
        foreach ($state->battle as $key => $value) {
            if (str_starts_with((string) $key, 'pending_') && !empty($value)) $priority = true;
        }
        return $priority;
    }

    public function render(
        GameState $state,
        string $playerKey,
        string $role,
        array $cardsInfo,
        string $baseUrl,
        string $selectedCardHtml = '',
        string $pileHtml = '',
        string $actionChoiceHtml = '',
        int $inspectedCardId = 0
    ): string {
        $this->state     = $state;
        $this->playerKey = $playerKey;
        $this->role      = $role;
        $this->cardsInfo = $cardsInfo;
        $this->baseUrl   = $baseUrl;

        if (MatchPause::blocking($state)) {
            $pausePhase = $state->battle['match_pause']['mode'] === 'active' ? 'Пауза' : 'Запрос паузы';
            return '<header class="info-panel-header"><div class="info-heading"><h1 class="info-status">Матч приостановлен</h1>'
                . '<span class="info-phase">' . $pausePhase . '</span></div>' . $this->renderTimer() . '</header>'
                . '<div class="info-panel-content"><div class="battle-selected-card" data-battle-fragment="panel_html" hidden></div>'
                . $this->renderPauseBody() . '</div><footer class="info-panel-footer">' . $this->renderFooter() . '</footer>';
        }
        $inCombat = !empty($state->battle['strike']);
        $inInstantStack = !$inCombat && (!empty($state->battle['turn_instant_stack']) || !empty($state->battle['turn_instant_result']));
        $priority = self::hasRequiredInteraction($state) || $actionChoiceHtml !== '';
        $exclusive = $priority;
        $combatKey = '';
        if ($inCombat) {
            $strike = $state->battle['strike'];
            $attacker = $state->getCard((int) ($strike['attacker_id'] ?? 0));
            $combatKey = implode(':', [
                $state->battle['turn'] ?? 0, $strike['attacker_id'] ?? 0,
                $strike['original_target_id'] ?? $strike['target_id'] ?? 0,
                $strike['kind'] ?? 'strike', $attacker?->flags['attacks_used_this_turn'] ?? 0,
            ]);
        }
        if ($inInstantStack) $combatKey = 'instant:' . ($state->battle['turn'] ?? 0);
        if ($priority && $combatKey === '') $combatKey = 'information:' . ($state->battle['turn'] ?? 0);
        $body = $actionChoiceHtml !== '' ? $actionChoiceHtml : $this->renderOchiKrongaNotice() . $this->renderBody();
        $instantWindow = $inInstantStack || ($state->battle['strike']['state'] ?? '') === 'waiting_instant'
            || !empty($state->battle['pending_instant_pick']) || !empty($state->battle['pending_combat_pick']);
        $contextBody = $instantWindow
            ? '<section class="info-panel-instants" aria-label="Инстанты">' . $body . '</section>'
            : $body;
        return '<header class="info-panel-header">' . $this->renderStatusHeader() . $this->renderTimer() . '</header>'
            . '<div class="info-panel-content" data-panel-priority="' . ($priority ? '1' : '0') . '" data-combat-key="' . htmlspecialchars($combatKey, ENT_QUOTES) . '" data-inspected-card="' . $inspectedCardId . '" tabindex="0" aria-label="Контекст боя и выбранная карта">'
            . ($priority ? '' : $pileHtml)
            . '<div class="battle-selected-card" data-battle-fragment="panel_html"' . ($exclusive ? ' hidden' : '') . '>' . $selectedCardHtml . '</div>'
            . '<div class="info-panel-context' . ($exclusive ? ' info-panel-context--combat' : '') . '">' . ($exclusive ? '<div class="combat-panel-heading panel-card-heading"><img class="panel-information-icon" src="/assets/images/battle/combat.png" width="24" height="24" alt=""><h3 class="panel-card-title" tabindex="-1">Сражение</h3></div><div class="combat-panel-body">' : '') . $contextBody . ($exclusive ? '</div>' : '') . '</div>'
            . '</div>'
            . '<footer class="info-panel-footer">' . $this->renderFooter() . '</footer>';
    }

    /** The per-card counter is persisted with gameplay; rendering never consumes it. */
    private function renderOchiKrongaNotice(): string
    {
        $html = '';
        foreach ($this->state->cards as $card) {
            $uses = (int) ($card->flags[\Berserk\Core\OchiKronga::STATE]['uses'] ?? 0);
            if ($uses < 1 || !in_array($card->zone, [CardInstance::ZONE_FIELD, CardInstance::ZONE_FLYING], true)) continue;
            $side = $card->owner === $this->playerKey ? 'Ваши' : 'У соперника';
            $coords = \Berserk\View\Ui\Coords::renderResult($card->row, $card->col, $this->playerKey, $card->owner);
            $period = (string) ($card->flags[\Berserk\Core\OchiKronga::STATE]['period'] ?? 'legacy');
            $id = htmlspecialchars($card->instanceId . ':' . $period . ':' . $uses . ':'
                . (int) ($card->flags[\Berserk\Core\OchiKronga::STATE]['last_open_sequence'] ?? 0), ENT_QUOTES, 'UTF-8');
            $html .= '<p class="bonus" role="status" data-ochi-opening="' . $id . '">' . $side . ' Очи Кронга открылись ' . $coords
                . ' — срабатываний за ход: ' . $uses . '/2.</p>';
        }
        return $html === '' ? '' : '<details open class="info-box ochi-kronga-notice" data-ochi-notice="'
            . $this->state->gameId . ':' . $this->playerKey . '"><summary><h3>Сражение</h3></summary>'
            . $html . '<button type="button" class="button" data-dismiss-ochi>Продолжить</button></details>';
    }

    private function renderFooter(): string
    {
        $state = $this->state;
        $inBattle = $state->status === 'battle' && $state->winner === null;
        $canEnd = $inBattle && !MatchPause::blocking($state) && ($state->battle['active'] ?? null) === $this->playerKey
            && !\Berserk\Core\InstantReservation::blocks($state,'end_turn')
            && ChoiceRegistry::current($state) === null && empty($state->battle['strike'])
            && empty($state->battle['pending_prophecy']) && empty($state->battle['pending_dive']);
        if ($canEnd) {
            foreach ($state->cards as $card) {
                if ($card->owner === $this->playerKey && $card->zone === CardInstance::ZONE_FIELD
                    && CardStats::getForcedStrikeTarget($state, $card) !== null) {
                    $canEnd = false;
                    break;
                }
            }
        }
        $end = $canEnd
            ? '<a class="button wide green-btn" href="' . $this->baseUrl . '&cmd=end_turn"><span>Завершить ход</span></a>'
            : '<button class="button wide green-btn" type="button" disabled><span>Завершить ход</span></button>';
        $pause = MatchPause::snapshot($state, $this->playerKey, time());
        if ($pause['mode'] === 'active') {
            $end = '<button class="button wide green-btn" type="button" data-pause-command="pause_ready"'
                . ($pause['ready_self'] ? ' disabled' : '') . '><span>'
                . ($pause['ready_self'] ? 'Вы готовы' : 'Вернулся / Готов') . '</span></button>';
        } elseif ($pause['mode'] === 'requested') {
            $end = '<button class="button wide green-btn" type="button" disabled><span>Ожидаем ответа</span></button>';
        }
        $pauseIcon = '<button class="battle-icon-control" type="button" data-open-pause aria-label="Пауза игры" title="Пауза игры"'
            . (in_array('request_pause', $pause['allowed_commands'], true) ? '' : ' disabled') . '>'
            . $this->tpl->parse('includes/battle/control_icon.tpl', [
                'file' => 'pause_160.png', 'label' => 'Пауза игры',
            ]) . '</button>';
        $resignIcon = $this->tpl->parse('includes/battle/control_icon.tpl', [
            'file' => 'surrender_160.png', 'label' => 'Сдаться',
        ]);
        $resign = $inBattle
            ? '<a class="battle-icon-control resign" aria-label="Сдаться" href="' . $this->baseUrl . '&cmd=resign" onclick="return confirm(\'Сдаться?\')">' . $resignIcon . '</a>'
            : '<button class="battle-icon-control resign" aria-label="Сдаться" type="button" disabled>' . $resignIcon . '</button>';
        $journalRuntime=\Berserk\Core\BattleHistory\HistoryRuntime::load();
        $journalAllowed=$journalRuntime->journals($state->gameId) && $journalRuntime->journalSide($state->gameId,$state->hostId,$state->playerId,$_SESSION??[])===$this->playerKey;
        $log = '<button class="battle-icon-control" type="button" data-action="battle-log" aria-label="Лог игры" aria-controls="battle-journal"'
            . ($journalAllowed ? '' : ' aria-disabled="true" disabled') . '>'
            . $this->tpl->parse('includes/battle/control_icon.tpl', [
                'file' => 'game_log_160.png', 'label' => 'Лог игры',
            ]) . '</button>';
        $httpResign = $inBattle
            ? '<form method="post" action="' . $this->baseUrl . '&amp;ajax=battle_command">'
                . '<input type="hidden" name="cmd" value="resign">'
                . '<input type="hidden" name="expected_version" value="' . (int) ($state->persistenceVersion ?? 0) . '">'
                . '<input type="hidden" name="command_id" value="' . bin2hex(random_bytes(16)) . '">'
                . '<input type="hidden" name="html_resign" value="1">'
                . '<button type="submit" class="button" data-http-resign>Сдаться</button></form>' : '';
        return '<noscript><style>[data-battle-sync-root] [data-open-pause], [data-battle-sync-root] [data-pause-command], [data-battle-sync-root] a.resign { display: none !important; }</style>'
            . '<p class="pause-noscript-notice">Управление паузой требует JavaScript. Сдаться можно без JavaScript.</p>' . $httpResign . '</noscript>'
            . $this->renderInstantControl()
            . '<div class="info-actions">' . $end . '<div class="battle-icon-actions">' . $pauseIcon . '<span class="battle-chat-anchor" data-chat-anchor></span>' . $log . $resign . '</div></div>';
    }
    private function renderPauseBody(): string
    {
        $p = MatchPause::snapshot($this->state, $this->playerKey, time());
        $deadline = $p['mode'] === 'requested' ? $p['request_deadline_at'] : $p['pause_deadline_at'];
        $seconds = max(0, $deadline - $p['server_now']);
        $heading = $p['mode'] === 'active' ? 'Пауза' : 'Запрос паузы';
        $message = $p['mode'] === 'active'
            ? ($p['ready_self'] ? 'Вы готовы, ожидаем соперника' : 'Когда вернётесь, нажмите «Вернулся / Готов».')
            : ($p['initiator'] === $this->playerKey ? 'Ожидаем ответа соперника.' : 'Соперник просит паузу на ' . intdiv($p['duration_seconds'], 60) . ' мин.');
        $html = '<section class="match-pause-content" data-match-pause-content><div class="combat-panel-heading panel-card-heading"><img class="panel-information-icon" src="/assets/images/battle/pause_160.png" width="24" height="24" alt=""><h2 class="panel-card-title">' . $heading . '</h2></div>'
            . '<div class="match-pause-countdown" data-pause-countdown role="timer">' . sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60) . '</div>'
            . '<p data-pause-description>' . $message . '</p><div class="match-pause-decisions">';
        foreach (['accept_pause'=>'Принять', 'reject_pause'=>'Отклонить', 'cancel_pause_request'=>'Отменить запрос'] as $cmd=>$label) {
            if (in_array($cmd, $p['allowed_commands'], true)) $html .= '<button class="button" type="button" data-pause-command="' . $cmd . '">' . $label . '</button>';
        }
        return $html . '</div></section>';
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
            'start' => 'Начало',
            'end'   => 'Завершение',
            default => 'Основная фаза',
        };

        if (!empty($state->battle['strike'])) $phaseLabel = 'Фаза сражения';

        if ($isMyTurn) {
            $text = '#' . $turn . ' · Ваш ход';
        } else {
            $text = '#' . $turn . ' · Ход соперника';
        }

        return '<div class="info-heading"><h1 class="info-status">' . htmlspecialchars($text, ENT_QUOTES) . '</h1>'
            . '<span class="info-phase">' . $phaseLabel . '</span></div>';
    }

    private function renderTimer(): string
    {
        $timer = TurnTimer::snapshot($this->state, time());
        $seconds = $timer['remaining_seconds'] ?? null;
        $text = $seconds === null ? '—:—' : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
        $mine = ($timer['owner'] ?? null) === $this->playerKey;
        $status = $timer['status'] ?? 'unavailable';
        $label = $timer === null ? 'Нет таймера' : ($mine ? 'Ваше время' : 'Соперник');
        if ($status === 'paused') $label .= ' · Пауза';
        if ($status === 'expired') $label .= ' · Истекло';
        return '<div class="turn-timer" data-turn-timer data-turn-timer-state="'
            . htmlspecialchars(json_encode($timer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ENT_QUOTES) . '"'
            . ' data-status="' . $status . '" data-mine="' . ($mine ? '1' : '0') . '"'
            . ' role="timer" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '">'
            . '<span class="turn-timer-value" data-turn-timer-value>' . $text . '</span>'
            . '<span class="turn-timer-label" data-turn-timer-label>' . $label . '</span></div>';
    }

    private function renderBody(): string
    {
        $state     = $this->state;
        $playerKey = $this->playerKey;
        $role      = $this->role;
        $cardsInfo = $this->cardsInfo;
        $baseUrl   = $this->baseUrl;

        // Реестр ChoiceHandler — новая ветка
        $handler = \Berserk\Core\Choice\ChoiceRegistry::current($state);
        if ($handler !== null) {
            $spec = $handler->spec($state, $playerKey, $cardsInfo, $baseUrl, $role);
            if ($spec !== null) {
                if ($handler instanceof \Berserk\Core\Choice\TremorChoice
                    || $handler instanceof \Berserk\Core\Choice\CellMarkerChoice
                    || $handler instanceof \Berserk\Core\Choice\RangedNeighborTeleportChoice
                    || $handler instanceof \Berserk\Core\Choice\TeleportTargetChoice
                    || $handler instanceof \Berserk\Core\Choice\GatePickChoice) {
                    \Berserk\View\Ui\CellMarkerPicker::decorate($spec, $state, $playerKey, $baseUrl);
                }
                \Berserk\View\Ui\CreatureChoice::decorate($spec, $state, $playerKey, $cardsInfo);
                return \Berserk\View\Ui\Panel::render($spec);
            }
        }

        $anyDeathMessageHtml = '';
        if (!empty($state->battle['any_death_messages'])) {
            $anyDeathMessageHtml = '<div class="card-choice">';
            foreach ((array) $state->battle['any_death_messages'] as $message) {
                $type = (string) ($message['type'] ?? '');

                if ($type === 'incarnation_token_wound' && !empty($message['targets'])) {
                    $sourceUkid = (string) ($message['source_ukid'] ?? '');
                    $sourceName = $sourceUkid !== '' && isset($cardsInfo[$sourceUkid])
                        ? $cardsInfo[$sourceUkid]['name']
                        : '?';

                    $parts = [];
                    foreach ($message['targets'] as $t) {
                        $tUkid = (string) ($t['target_ukid'] ?? '');
                        $tName = $tUkid !== '' && isset($cardsInfo[$tUkid])
                            ? $cardsInfo[$tUkid]['name']
                            : '?';
                        $dmg = (int) ($t['damage'] ?? 0);
                        $parts[] = $tName . ' -' . $dmg . ' ❤️ HP';
                    }

                    $text = $sourceName . ': ' . implode(', ', $parts);
                } else {
                    $text = (string) ($message['message'] ?? '');
                }

                if ($text === '') continue;
                $anyDeathMessageHtml .= '<p class="bonus">'
                    . htmlspecialchars($text, ENT_QUOTES)
                    . '</p>';
            }
            $anyDeathMessageHtml .= '</div>';
        }

        if (!empty($state->battle['pending_prophecy'])) {
            return $this->renderProphecy();
        }

        if (!empty($state->battle['strike'])) {
            return $anyDeathMessageHtml . $this->renderStrike();
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

        return $anyDeathMessageHtml . $instantResultHtml;

    }

    private function renderInstantControl(): string
    {
        $p=InstantControl::project($this->state,$this->playerKey,time());
        if(!$p['visible'])return '';
        $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
        $url=fn($cmd,$payload=[])=>$esc($this->baseUrl.'&'.http_build_query(['cmd'=>$cmd]+$payload));
        $label=$esc($p['label']);
        $color=$p['cost']===null?'':($p['cost']==='Бесплатно'?' reserve-free':' reserve-paid');
        if($p['command']!==null)$action='<a class="button instant-control-main'.$color.'" aria-label="'.$esc($p['label']).'" href="'.$url($p['command'],$p['payload']).'">'.$label.'</a>';
        elseif($p['enabled']){
            $cards='';foreach($p['options']as$option){
                $card=$this->state->getCard((int)$option['card_id']);if(!$card)continue;
                $hint=$option['label'];$target=$this->state->getCard((int)($option['target_id']??0));
                if($target)$hint.=' → '.($this->cardsInfo[$target->ukid]['name']??$target->ukid);
                $cards.=TaskCard::render(['label'=>$hint,'hint'=>$hint,'card_ukid'=>$card->ukid,'row'=>$card->row,'col'=>$card->col,'is_instant'=>true,'owner'=>$card->owner],
                    html_entity_decode($url($option['command'],$option['payload']),ENT_QUOTES,'UTF-8'),$this->cardsInfo,true,$this->playerKey);
            }
            $action='<details class="instant-control-picker"><summary class="button instant-control-main">'.$label.'</summary><div class="task-list instant-control-options">'.$cards.'</div></details>';
        }else $action='<button type="button" class="button instant-control-main" disabled>'.$label.'</button>';
        $meta=$p['quota']===null?'':'<span class="instant-control-quota" aria-label="Остаток платных резервирований">'.$esc($p['quota']).'</span>';
        $pass=$p['pass']===null?'':($p['pass']==='local'?'<button type="button" class="button skip" data-instant-local-pass>Пас</button>':'<a class="button skip" data-instant-pass href="'.$url($p['pass']).'">Пас</a>');
        return '<section class="instant-control" data-instant-control data-instant-phase="'.$esc($p['phase']).'" aria-label="Мгновенное действие"><h3>Мгновенное действие '.$meta.'</h3><div class="instant-control-actions">'.$action.$pass.'</div></section>';
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

        if ($kind === 'impact' && ($strike['effect'] ?? '') === 'memory_loss' && $strike['state'] === 'results') {
            $confirmed = $strike['confirmed'] ?? [];
            $continue = !in_array($playerKey, $confirmed, true)
                ? $this->confirmStrikeLink($state, $baseUrl)
                : '<p class="wait">Ожидание оппонента...</p>';
            return '<div class="strike-box"><div class="memory-loss-notice" role="status">'
                . '<h3>Потеря памяти</h3><p><b>' . $tn . '</b>: уникальные особенности отключены.</p>'
                . '<p>Воздействие действует, пока <b>' . $an . '</b> остаётся на поле боя.</p>'
                . '</div>' . $continue . '</div>';
        }

        if ($strike['state'] === 'results' && !empty($strike['cell_marker'])) {
            $cm = $strike['cell_marker'];
            $label = $strike['action_name'] ?? 'Маркер';
            $contentHtml = '<p>' . htmlspecialchars($label, ENT_QUOTES)
                . ': ' . \Berserk\View\Ui\Coords::renderResult($cm['row'] ?? null, $cm['col'] ?? null, $playerKey) . '</p>';

            $confirmed = $strike['confirmed'] ?? [];
            if (!in_array($playerKey, $confirmed, true)) {
                $contentHtml .= $this->confirmStrikeLink($state, $baseUrl);
            } else {
                $contentHtml .= '<p class="wait">Ожидание оппонента...</p>';
            }

            // Сразу возвращаем — пропускаем всё остальное
            return '<div class="strike-box"><p><b>' . $an . '</b></p>'
                . $contentHtml . '</div>';
        }

        // Статьи без сражения — упрощённая панель
        if (($strike['kind'] ?? '') === 'become_fly') {
            $attackerCard = $state->getCard($strike['attacker_id']);
            $name = $attackerCard
                ? ($cardsInfo[$attackerCard->ukid]['name'] ?? '?')
                : '?';

            $confirmed = $strike['confirmed'] ?? [];
            if (!in_array($playerKey, $confirmed, true)) {
                $confirmHtml = $this->confirmStrikeLink($state, $baseUrl);
            } else {
                $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
            }

            return '<div class="card-choice">'
                . '<h3>' . htmlspecialchars($name, ENT_QUOTES) . ' — получен полёт</h3>'
                . '<div class="death-choice-buttons">' . $confirmHtml . '</div>'
                . '</div>';
        }

        if ($strike['state'] === 'waiting_redirect') {
            $stackHtml = $this->renderCombatInstantStack($strike, false);

            $chooserKey = $state->getOpponentKey($attackerCard->owner);

            if ($playerKey === $chooserKey) {
                $origTarget = $state->getCard($strike['target_id']);
                $origInfo   = $origTarget ? ($cardsInfo[$origTarget->ukid] ?? null) : null;
                $origName   = $origInfo ? (string) $origInfo['name'] : '?';

                $options = [];
                foreach ($strike['redirect_candidates'] ?? [] as $tid) {
                    $tc = $state->getCard($tid);
                    if (!$tc) continue;
                    $ti = $cardsInfo[$tc->ukid] ?? [];
                    $options[] = [
                        'value' => 'redirect:' . (int) $tid,
                        'label' => $this->battleCardLabel($tc, $ti, $playerKey),
                        'label_html' => htmlspecialchars((string) ($ti['name'] ?? $tc->ukid), ENT_QUOTES) . ' ' . \Berserk\View\Ui\Coords::render($tc->row, $tc->col, $playerKey, $tc->owner),
                        'class' => 'redirect-target',
                    ];
                }

                $options[] = [
                    'value' => 'redirect:0',
                    'label' => 'Не перенаправлять (' . $origName . ')',
                    'class' => 'skip',
                ];

                $contentHtml = '<p>' . $an . ' атакует. Перенаправить удар на другое существо рядом?</p>'
                    . $this->renderDefenderRadioForm($options);
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

                $adText = \Berserk\View\Ui\DiceValue::render((int) $ad, (int) $am);
                $ddText = \Berserk\View\Ui\DiceValue::render((int) $dd, (int) $dm);

                $diceHtml = '<div class="instant-dice">'
                    . '<div class="dice-col"><span class="dice-label">Атакующий</span>'
                    . '<span class="dice-value">' . $adText . '</span>'
                    . '<span class="dice-level">' . htmlspecialchars(BattleHelper::strikeName($res['attack']), ENT_QUOTES) . '</span>'
                    . '</div>'
                    . '<div class="dice-col"><span class="dice-label">Защитник</span>'
                    . '<span class="dice-value">' . $ddText . '</span>'
                    . '<span class="dice-level">' . htmlspecialchars(BattleHelper::strikeName($res['defend']), ENT_QUOTES) . '</span>'
                    . '</div>'
                    . '</div>';
            }

            $priority = $strike['instant_priority'] ?? null;
            $attackerCard = $state->getCard($strike['attacker_id']);
            $attackerKey = $attackerCard->owner;
            $isMine = ($priority === $playerKey);

            $whoLabel = ($attackerKey === $priority) ? 'Атакующий' : 'Защитник';
            $stackHtml = $this->renderCombatInstantStack($strike, true);
            $window = (string) ($strike['combat_instant_window'] ?? 'main');
            $windowLabel = match ($window) {
                'dice' => 'Работа с кубиками',
                'wounds' => 'После нанесения ран',
                default => 'Боевые инстанты',
            };
            $windowHtml = '<p class="instant-window-label">' . htmlspecialchars($windowLabel, ENT_QUOTES) . '</p>';

            if ($isMine) {
                $sr = new \Berserk\Core\StrikeResolver($state, new \Berserk\Core\Engine());
                $instants = $sr->getCombatInstants($playerKey, $phase, $type);

                $contentHtml = $diceHtml . $windowHtml . $stackHtml . '<p>Ваш приоритет. Выберите инстант или пас в нижнем блоке.</p>';
            } else {
                $contentHtml = $diceHtml . $windowHtml . $stackHtml . '<p class="wait">Ожидание — оппонент решает, сыграть инстант...</p>';
            }
        } elseif ($strike['state'] === 'waiting_defender') {
            $chooserKey = $state->getOpponentKey($attackerCard->owner);
            if ($playerKey === $chooserKey) {
                $options = [];
                $sr = new \Berserk\Core\StrikeResolver($state, new \Berserk\Core\Engine());

                foreach ($strike['defenders'] ?? [] as $defId) {
                    $dc = $state->getCard($defId);
                    if (!$dc) continue;
                    $di = $cardsInfo[$dc->ukid] ?? [];
                    $options[] = [
                        'value' => 'defender:' . (int) $defId,
                        'label' => (string) ($di['name'] ?? $dc->ukid),
                        'card_id' => (int) $defId,
                    ];
                }
                $contentHtml = $this->tpl->parse('includes/battle/strike_defender.tpl', [
                    'buttons_html' => $this->renderDefenderRadioForm($options, true),
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

            $adText = \Berserk\View\Ui\DiceValue::render((int) $ad, (int) $am);
            $ddText = \Berserk\View\Ui\DiceValue::render((int) $dd, (int) $dm);
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
                    $ti = $cardsInfo[$tc->ukid] ?? [];
                    $url = "{$baseUrl}&cmd=choose_ally_modifier&target_id={$tid}";
                    $buttons .= CardButton::battle(
                        $this->tpl,
                        $tc,
                        $ti,
                        $url,
                        $playerKey,
                        ['class' => 'ally-modifier-target']
                    );
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

            $adText = \Berserk\View\Ui\DiceValue::render((int) $ad, (int) $am);
            $ddText = \Berserk\View\Ui\DiceValue::render((int) $dd, (int) $dm);

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
                    $ti = $cardsInfo[$tc->ukid] ?? [];
                    $url = "{$baseUrl}&cmd=choose_auto_target&target_id={$tid}";
                    $buttons .= CardButton::battle(
                        $this->tpl,
                        $tc,
                        $ti,
                        $url,
                        $playerKey,
                        ['class' => 'auto-target']
                    );
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
                $confirmHtml = $this->confirmStrikeLink($state, $baseUrl);
            } else {
                $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
            }

            $contentHtml = $damageInfo . $confirmHtml;
        } elseif ($strike['state'] === 'results') {
            $summaryHtml = $this->renderInstantResolutionSummary($strike);
            $ad = $strike['attack_dice'];
            $dd = $strike['defend_dice'] ?? 0;
            $am = $strike['attack_mod'] ?? 0;
            $dm = $strike['defend_mod'] ?? 0;

            $adText = \Berserk\View\Ui\DiceValue::render((int) $ad, (int) $am);
            $ddText = \Berserk\View\Ui\DiceValue::render((int) $dd, (int) $dm);
            if (!empty($strike['temple_defense'])) $ddText = \Berserk\View\Ui\DiceValue::render((int)$strike['temple_defense']['die']);

            $fin = $strike['final'] ?? $strike['result'];

            $defendDiceHtml = '';
            if ($kind === 'strike' || !empty($strike['temple_defense'])) {
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
            } elseif ($kind === 'damage_ranged') {
                $name = $strike['action_name'] ?? 'Пламя бездны';
                $rd   = $strike['ranged_damage'] ?? [];
                $parts = [];
                foreach ($rd as $r) {
                    $tc = $state->getCard($r['target_id']);
                    $tn = $tc ? ($cardsInfo[$tc->ukid]['name'] ?? '?') : '?';
                    $parts[] = $tn . ' −' . $r['damage'];
                }
                $resultText = $name . ': '
                    . (empty($parts) ? 'нет целей с выстрелом/метанием/разрядом' : implode(', ', $parts));
            } elseif ($kind === 'teleport_target') {
                $name = htmlspecialchars((string) ($strike['action_name'] ?? 'Дверь измерений'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $tp   = $strike['teleport'] ?? null;
                if ($tp) {
                    $tCard = $state->getCard($tp['target_id']);
                    $tName = $tCard ? ($cardsInfo[$tCard->ukid]['name'] ?? '?') : '?';
                    $resultText = $name . ': ' . htmlspecialchars($tName, ENT_QUOTES)
                        . ' перемещён на ' . \Berserk\View\Ui\Coords::renderResult($tp['new_row'] ?? null, $tp['new_col'] ?? null, $playerKey);
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'blood_storm') {
                $name = $strike['action_name'] ?? 'Буря крови';
                $bs = $strike['blood_storm'] ?? null;
                if ($bs && !empty($bs['target_id'])) {
                    $tCard = $state->getCard((int) $bs['target_id']);
                    $tName = $tCard ? ($cardsInfo[$tCard->ukid]['name'] ?? '?') : '?';
                    if (!empty($bs['defended'])) {
                        $resultText = $name . ': ' . htmlspecialchars($tName, ENT_QUOTES)
                            . ' — защита (0 урона)';
                    } else {
                        $resultText = $name . ': ' . htmlspecialchars($tName, ENT_QUOTES)
                            . ' получает ' . (int) $bs['damage'] . ' урона (X = ' . (int) $bs['x'] . ')';
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
                        $parts[] = $tn . ' −' . $r['damage'] . ' ❤️ HP';
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
                    $parts[] = $tn . ' +' . $h['heal'] . ' ❤️ HP';
                }
                if (!empty($strike['heal_instead_wound'])) {
                    foreach ($strike['heal_instead_wound'] as $hw) {
                        $tc = $state->getCard((int) $hw['target_id']);
                        $tn = $tc ? ($cardsInfo[$tc->ukid]['name'] ?? '?') : '?';
                        $parts[] = $tn . ' −' . (int) $hw['value'] . ' ❤️ HP (рана)';
                    }
                }
                $resultText = $name . ': ' . implode(', ', $parts);
            } elseif ($kind === 'blood_tap') {
                $name = $strike['action_name'] ?? 'Кровавый разряд';
                $bt   = $strike['blood_tap'] ?? null;
                if ($bt) {
                    $tCard = $state->getCard($bt['target_id']);
                    $tName = $tCard ? ($cardsInfo[$tCard->ukid]['name'] ?? '?') : '?';
                    $resultText = $name . ': −' . $bt['x'] . ' ❤️ HP, ' . $tName . ' получает ' . $bt['x'] . ' урона';
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
            } elseif ($kind === 'poison_boost') {
                $pb = $strike['poison_boost'] ?? null;
                if ($pb) {
                    $t = $state->getCard((int) $pb['target_id']);
                    $tn = $t ? ($cardsInfo[$t->ukid]['name'] ?? '?') : '?';
                    $resultText = 'Вскипающий яд: ' . htmlspecialchars($tn, ENT_QUOTES)
                        . ' — отравление ' . $pb['from'] . ' → ' . $pb['to'];
                } else {
                    $resultText = 'Вскипающий яд';
                }
            } elseif ($kind === 'damage_poisoned') {
                $name = $strike['action_name'] ?? 'Власть Ундины';
                $pd   = $strike['poisoned_damage'] ?? [];
                $parts = [];
                foreach ($pd as $r) {
                    $tc = $state->getCard($r['target_id']);
                    $tn = $tc ? ($cardsInfo[$tc->ukid]['name'] ?? '?') : '?';
                    $parts[] = $tn . ' −' . $r['damage'] . ' ❤️ HP';
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
                        $parts[] = '<div class="row-spell-wound">' . htmlspecialchars($tn, ENT_QUOTES) . ' <strong>−' . (int)$t['damage'] . ' ❤ HP</strong></div>';
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
                    $resultText = $name . ': +' . ($strike['heal'] ?? 0) . ' ❤️ HP';
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
            } elseif ($kind === 'apply_delayed_marker') {
                $name = $strike['action_name'] ?? 'Способность';
                $target = $state->getCard((int) ($strike['target_id'] ?? 0));
                $targetName = $target ? ($cardsInfo[$target->ukid]['name'] ?? '?') : '?';
                $resultText = htmlspecialchars($name, ENT_QUOTES)
                    . ': ' . htmlspecialchars($targetName, ENT_QUOTES)
                    . ' получит сеть в конце хода';
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

                if (!empty($strike['ranged_redirect']) && is_array($strike['ranged_redirect'])) {
                    $redirect = $strike['ranged_redirect'];
                    $source = $state->getCard((int) ($redirect['source_id'] ?? 0));
                    $original = $state->getCard((int) ($redirect['original_target_id'] ?? 0));
                    $redirectTarget = $state->getCard((int) ($redirect['target_id'] ?? 0));
                    $sourceName = $source ? ($cardsInfo[$source->ukid]['name'] ?? $source->ukid) : '?';
                    $originalName = $original ? ($cardsInfo[$original->ukid]['name'] ?? $original->ukid) : '?';
                    $redirectName = $redirectTarget ? ($cardsInfo[$redirectTarget->ukid]['name'] ?? $redirectTarget->ukid) : '?';
                    $resultText .= '<br>' . htmlspecialchars($sourceName, ENT_QUOTES)
                        . ': атака перенаправлена с '
                        . htmlspecialchars($originalName, ENT_QUOTES)
                        . ' на '
                        . htmlspecialchars($redirectName, ENT_QUOTES);
                }
            } elseif ($kind === 'bomb_shot') {
                $name = htmlspecialchars((string) ($strike['action_name'] ?? 'Бомба'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $bomb = $strike['bomb'] ?? null;
                if ($bomb) {
                    $tName = $state->getCard($strike['target_id']);
                    $tInfo = $tName ? ($cardsInfo[$tName->ukid] ?? null) : null;
                    $tn2 = $tInfo ? htmlspecialchars($tInfo['name'], ENT_QUOTES) : '?';
                    $resultText = $name . ': ' . $tn2 . ' получает '
                        . $strike['damage'] . ' урона. Бомба поставлена на '
                        . \Berserk\View\Ui\Coords::renderResult($bomb['row'] ?? null, $bomb['col'] ?? null, $playerKey) . '.';
                } else {
                    $resultText = $name;
                }
            } elseif ($kind === 'particle') {
                $pt = $strike['particle'] ?? null;
                if ($pt) {
                    $parts = [];
                    foreach ($pt['targets'] as $t) {
                        $tc = $state->getCard((int) $t['target_id']);
                        $tn = $tc ? ($cardsInfo[$tc->ukid]['name'] ?? '?') : '?';
                        $parts[] = '<div class="row-spell-wound">' . htmlspecialchars($tn, ENT_QUOTES) . ' <strong>−' . (int)$t['damage'] . ' ❤ HP</strong></div>';
                    }
                    $resultText = 'Частица души: ' . implode(', ', $parts);
                } else {
                    $resultText = 'Частица души';
                }
            } elseif ($kind === 'life_gift') {
                $lg = $strike['life_gift'] ?? null;
                if ($lg) {
                    $e = $state->getCard((int) $lg['enemy_id']);
                    $a = $state->getCard((int) $lg['ally_id']);
                    $en = $e ? ($cardsInfo[$e->ukid]['name'] ?? '?') : '?';
                    $an = $a ? ($cardsInfo[$a->ukid]['name'] ?? '?') : '?';
                    $resultText = 'Предсмертный дар (X = ' . $lg['x'] . '): ' . $en
                        . ' получает ' . $lg['damage'] . ($lg['defended'] ? ' (защита)' : '')
                        . ', ' . $an . ' излечивается на ' . $lg['heal'];
                } else {
                    $resultText = 'Предсмертный дар';
                }
            } elseif ($kind === 'freeze_moves') {
                $f = $strike['freeze'] ?? null;
                if ($f) {
                    $ownerLabel = ($f['owner'] ?? '') === 'player' ? 'игрок' : 'хост';
                    $resultText = 'Ледяной дождь: кубик ' . (int) $f['dice']
                        . ' → ' . (int) $f['limit']
                        . ' существ ' . $ownerLabel . ' смогут двигаться в свой следующий ход';
                } else {
                    $resultText = 'Ледяной дождь';
                }
            } elseif ($kind === 'row_spell') {
                $rs = $strike['row_spell'] ?? null;
                $name = $strike['action_name'] ?? 'Цветущие руны';
                if ($rs) {
                    $parts = [];
                    foreach ($rs['targets'] as $t) {
                        $tc = $state->getCard($t['target_id']);
                        $tn = $tc ? ($cardsInfo[$tc->ukid]['name'] ?? '?') : '?';
                        $parts[] = '<div class="row-spell-wound">' . htmlspecialchars($tn, ENT_QUOTES) . ' <strong>−' . (int)$t['damage'] . ' ❤ HP</strong></div>';
                    }
                    $resultText = htmlspecialchars($name, ENT_QUOTES) . ':<br>' . implode('', $parts);
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
            } elseif ($kind === 'strangle') {
                $resultText = 'Удушить: маркеров удушения — ' . (int) ($strike['strangulation_count'] ?? 0)
                    . (!empty($strike['destruction']) ? '. Существо уничтожено.' : '');
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
                $resultText = $name === 'Воздействие' ? '' : htmlspecialchars((string) $name, ENT_QUOTES);
            } else {
                $resultText = 'Атакующий: <b>' . BattleHelper::strikeName($fin['attack']).'</b>'
                    . ' | Защитник: <b>' . BattleHelper::strikeName($fin['defend']).'</b>';

                if (!empty($fin['decreased'])) {
                    $resultText .= ' <span class="wait">(удары уменьшены)</span>';
                }

                $damageSummary = (array) ($strike['combat_damage_summary'] ?? []);
                $primaryDamage = (int) ($damageSummary['primary_damage'] ?? ($strike['damage_total'] ?? 0));
                $targetTotal = (int) ($damageSummary['target_total_this_strike'] ?? $primaryDamage);
                $targetExtra = (int) ($damageSummary['target_extra_this_strike'] ?? max(0, $targetTotal - $primaryDamage));
                $answerDamage = (int) ($damageSummary['answer_damage'] ?? ($strike['defend_damage_total'] ?? 0));
                $attackerTotal = (int) ($damageSummary['attacker_total_this_strike'] ?? $answerDamage);
                $attackerExtra = (int) ($damageSummary['attacker_extra_this_strike'] ?? max(0, $attackerTotal - $answerDamage));

                if (!empty($strike['blocked_by_weak'])) {
                    $resultText .= '<br><span class="wait">Удар заблокирован (защита от слабых атак)</span>';
                } elseif (!empty($strike['defense_applied'])) {
                    $resultText .= '<br><span class="wait">Защита сработала (0 урона)</span>';
                } elseif ($targetTotal > 0) {
                    if ($targetExtra > 0) {
                        $resultText .= '<br>Основной урон по цели: <b>' . $primaryDamage . '</b>';
                        $resultText .= '<br>Доп. урон по цели: <b>+' . $targetExtra . '</b>';
                        $resultText .= '<br>Итого по цели: <b>' . $targetTotal . '</b> урона';
                    } else {
                        $resultText .= '<br>По цели: <b>' . $targetTotal . '</b> урона';
                    }
                }

                if (!empty($strike['defend_blocked_by_weak'])) {
                    $resultText .= '<br><span class="wait">Ответка заблокирована (защита от слабых атак)</span>';
                } elseif ($attackerTotal > 0) {
                    if ($attackerExtra > 0) {
                        $resultText .= '<br>Ответка по атакующему: <b>' . $answerDamage . '</b>';
                        $resultText .= '<br>Доп. урон по атакующему: <b>+' . $attackerExtra . '</b>';
                        $resultText .= '<br>Итого по атакующему: <b>' . $attackerTotal . '</b> урона';
                    } else {
                        $resultText .= '<br>По атакующему (ответка): <b>' . $attackerTotal . '</b> урона';
                    }
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
            foreach (['attack' => 'Урон по цели', 'defend' => 'Урон ответки'] as $side => $label) {
                if (isset($strike['strike_value_set'][$side])) {
                    $reductionHtml .= '<p class="wait">' . $label . ' сведён к ' . (int) $strike['strike_value_set'][$side] . '</p>';
                }
                if (!empty($strike['strike_aura'][$side])) {
                    $reductionHtml .= '<p class="wait">' . ($side === 'attack' ? 'Удар по цели' : 'Ответный удар')
                        . ' сведён к слабому защитной аурой</p>';
                }
            }
            if (!empty($strike['attack_value_reduction'])) {
                foreach ((array) $strike['attack_value_reduction'] as $event) {
                    $label = htmlspecialchars((string) ($event['label'] ?? 'Способность'), ENT_QUOTES);
                    $from = (int) ($event['from'] ?? 0);
                    $to = (int) ($event['to'] ?? 0);
                    if ($from > $to) {
                        $reductionHtml .= '<p class="wait">' . $label
                            . ': атака снижена с ' . $from . ' до ' . $to . '</p>';
                    }
                }
            }

            $spiderWebHtml = '';
            if (!empty($strike['spider_web_block'])) {
                $web = $strike['spider_web_block'];
                $target = $state->getCard((int) ($web['target_id'] ?? 0));
                $targetInfo = $target ? ($cardsInfo[$target->ukid] ?? null) : null;
                $targetName = $targetInfo ? htmlspecialchars($targetInfo['name'], ENT_QUOTES) : '?';
                $spiderWebHtml = '<p class="wait">Сеть блокирует атаку по '
                    . $targetName . ' и исчезает</p>';
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
                $impactHtml = '<div class="impact-targets" aria-label="Результаты воздействия"><ul>';
                foreach ($strike['impact_targets'] as $it) {
                    $tc = $state->getCard($it['target_id']);
                    $ti = $tc ? ($cardsInfo[$tc->ukid] ?? null) : null;
                    $tn2 = $ti ? htmlspecialchars($ti['name'], ENT_QUOTES) : '?';

                    $impactHtml .= '<li class="impact"><span class="impact-target-name">' . $tn2
                        . '</span><span class="impact-target-result">−' . (int) $it['damage'] . ' ❤️ HP';
                    if (!empty($it['poison'])) {
                        $impactHtml .= ' (отравление ' . $it['poison'] . ')';
                    }
                    $impactHtml .= '</span></li>';
                }
                $impactHtml .= '</ul></div>';
            }

            if (!empty($strike['self_destroyed'])) {
                $impactHtml .= '<p class="impact">Источник воздействия уничтожен.</p>';
            }

            $pushHtml = '';
            if (!empty($strike['push_result'])) {
                $pr = $strike['push_result'];
                if ($pr['type'] === 'moved') {
                    $pushHtml = '<p class="bonus">Карта перемещена на '
                        . \Berserk\View\Ui\Coords::renderResult($pr['new_row'] ?? null, $pr['new_col'] ?? null, $playerKey) . '</p>';
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

                $behindWeakStrikeHtml = '<p class="bonus">Слабый удар карты позади: <b>'
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
                        . '<b>' . $name . '</b> — трупоедство: +' . $t['heal'] . ' ❤️ HP'
                        . '</div>';
                }
            }

            $vampireHtml = '';
            if (!empty($strike['vampire_heal'])) {
                foreach ($strike['vampire_heal'] as $v) {
                    $info = $cardsInfo[$v['ukid']] ?? null;
                    $name = $info ? htmlspecialchars($info['name'], ENT_QUOTES) : '?';
                    $vampireHtml .= '<div class="vampire">'
                        . '<b>' . $name . '</b> — вампиризм: +' . $v['heal'] . ' ❤️ HP'
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
            if (!empty($strike['chain_damage'])) {
                foreach ((array) $strike['chain_damage'] as $chain) {
                    foreach ((array) ($chain['items'] ?? []) as $item) {
                        $target = $state->getCard((int) ($item['target_id'] ?? 0));
                        $targetInfo = $target ? ($cardsInfo[$target->ukid] ?? null) : null;
                        $targetName = $targetInfo ? htmlspecialchars($targetInfo['name'], ENT_QUOTES) : '?';
                        $damage = (int) ($item['damage'] ?? 0);
                        if ($damage <= 0) continue;

                        $vampireHtml .= '<div class="vampire">'
                            . 'Цепи Анубисара: <b>' . $targetName . '</b> -' . $damage . ' ❤️ HP'
                            . '</div>';
                    }
                }
            }
            if (!empty($state->battle['degasca_messages'])) {
                foreach ((array) $state->battle['degasca_messages'] as $message) {
                    $type = (string) ($message['type'] ?? '');
                    if ($type === 'chain_damage') {
                        foreach ((array) ($message['items'] ?? []) as $item) {
                            $target = $state->getCard((int) ($item['target_id'] ?? 0));
                            $targetInfo = $target ? ($cardsInfo[$target->ukid] ?? null) : null;
                            $targetName = $targetInfo ? htmlspecialchars($targetInfo['name'], ENT_QUOTES) : '?';
                            $damage = (int) ($item['damage'] ?? 0);
                            if ($damage <= 0) continue;

                            $vampireHtml .= '<div class="vampire">'
                                . 'Цепи Анубисара: <b>' . $targetName . '</b> -' . $damage . ' ❤️ HP'
                                . '</div>';
                        }
                        continue;
                    }

                    if ($type === 'behind_weak_strike_damage') {
                        $source = $state->getCard((int) ($message['source_id'] ?? 0));
                        $target = $state->getCard((int) ($message['target_id'] ?? 0));
                        $sourceInfo = $source ? ($cardsInfo[$source->ukid] ?? null) : null;
                        $targetInfo = $target ? ($cardsInfo[$target->ukid] ?? null) : null;
                        $sourceName = $sourceInfo ? htmlspecialchars($sourceInfo['name'], ENT_QUOTES) : '?';
                        $targetName = $targetInfo ? htmlspecialchars($targetInfo['name'], ENT_QUOTES) : '?';
                        $damage = (int) ($message['damage'] ?? 0);
                        if ($damage > 0) {
                            $vampireHtml .= '<div class="vampire">'
                                . 'Слабый удар карты позади: <b>' . $sourceName . '</b> -'
                                . $damage . ' ❤️ HP по <b>' . $targetName . '</b>'
                                . '</div>';
                        }
                        continue;
                    }

                    $text = (string) ($message['message'] ?? '');
                    if ($text === '') continue;
                    $vampireHtml .= '<div class="vampire">'
                        . htmlspecialchars($text, ENT_QUOTES)
                        . '</div>';
                }
            }

            if (!empty($strike['segger_shield_result'])) {
                $sr = $strike['segger_shield_result'];
                $names = [];
                foreach ($sr['targets'] as $tid) {
                    $tc = $state->getCard((int) $tid);
                    if ($tc) $names[] = $cardsInfo[$tc->ukid]['name'] ?? '?';
                }
                $vampireHtml .= '<div class="vampire">Ревнитель Сеггера: защита '
                    . (int) $sr['value'] . ' на ' . htmlspecialchars(implode(', ', $names), ENT_QUOTES)
                    . '</div>';
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
                    $buttons = [];
                    foreach ($pc['candidates'] as $tid) {
                        $tc = $state->getCard($tid);
                        if (!$tc) continue;
                        $buttons[] = ['label' => $cardsInfo[$tc->ukid]['name'] ?? '?',
                            'url' => "{$baseUrl}&cmd=choose_death_target&target_id={$tid}"];
                    }
                    $spec = new \Berserk\View\Ui\PanelSpec(title: (string) ($diedInfo['name'] ?? '?') . ': выберите существо', buttons: $buttons);
                    \Berserk\View\Ui\CreatureChoice::decorate($spec, $state, $playerKey, $cardsInfo);
                    $confirmHtml = Panel::render($spec);
                } else {
                    $confirmHtml = '<p class="wait">Ожидание выбора цели оппонентом...</p>';
                }
            } elseif (!in_array($playerKey, $confirmed, true)) {
                $confirmHtml = $this->confirmStrikeLink($state, $baseUrl);
            } else {
                $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
            }

            if (!empty($strike['coin_blocked'])) $resultText .= '<br>Удар заблокирован за монетку.';
            if (!empty($strike['first_strike_blocked'])) $resultText .= '<br>Первый удар заблокирован.';
            if (!empty($strike['first_answer_blocked'])) $resultText .= '<br>Ответный удар заблокирован защитой первого удара.';
            foreach ($strike['triggered_discharges'] ?? [] as $discharge) {
                $victim = $state->getCard((int) ($discharge['target_id'] ?? 0));
                $victimName = $victim ? ($cardsInfo[$victim->ukid]['name'] ?? 'Цель') : 'Цель';
                $resultText .= '<br>Разряд при ударе: ' . htmlspecialchars($victimName, ENT_QUOTES)
                    . ', кубик ' . \Berserk\View\Ui\DiceValue::render((int) ($discharge['attack_dice'] ?? 0))
                    . ', урон ' . (int) ($discharge['damage_total'] ?? 0) . '.';
            }
            $extraResultHtml = $autoHtml . $pushHtml . $impactHtml . $huntHtml
                . $behindWeakStrikeHtml . $shotBonusHtml . $nextActionBonusHtml
                . $nextStrikeBonusHtml . $rowsBonusHtml . $clumsyHtml
                . $abilityBonusHtml . $coinBonusHtml . $reductionHtml
                . $spiderWebHtml . $answerHtml . $vampireHtml . $deadeatHtml . $deathHtml;

            if (!empty($strike['temple_defense']['blocked'])) $resultText = 'Атака заблокирована';

            $noDiceKinds = ['impact', 'strangle', 'heal', 'modifier', 'execute', 'destroy_self_and_target', 'transfer_wounds', 'shield_light', 'self_wound', 'multi_heal', 'steal', 'sand_claws', 'multi_discharge', 'blood_tap', 'poison_target', 'damage_poisoned', 'place_cell_marker', 'dissonance', 'steal_coin', 'give_coin', 'become_fly', 'bomb_shot', 'apply_delayed_marker', 'particle', 'life_gift', 'poison_boost', 'damage_ranged', 'teleport_target', 'blood_storm', 'row_spell'];
            if (!in_array($kind, $noDiceKinds, true)) {
                $contentHtml = $this->tpl->parse('includes/battle/strike_dice.tpl', [
                    'attack_dice'      => $adText,
                    'defend_dice_html' => $defendDiceHtml,
                    'result_text'      => $summaryHtml . $resultText . $extraResultHtml,
                    'confirm_html'     => $confirmHtml,
                ]);
            } else {
                $contentHtml = $summaryHtml . ($resultText !== '' ? '<p>' . $resultText . '</p>' : '') . $extraResultHtml . $confirmHtml;
            }
        }

        $headerText = 'Атака';
        if ($kind === 'heal' || $kind === 'multi_heal') $headerText = 'Излечение';
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
        if ($kind === 'apply_delayed_marker') $headerText = 'Воздействие';
        if ($kind === 'dive') $headerText = 'Пикирование';
        if ($kind === 'bomb_shot') $headerText = 'Бомба';
        if ($kind === 'row_spell') $headerText = 'Цветущие руны';
        if ($kind === 'freeze_moves') $headerText = 'Ледяной дождь';
        if ($kind === 'particle')  $headerText = 'Частица души';
        if ($kind === 'life_gift') $headerText = 'Предсмертный дар';
        if ($kind === 'poison_boost') $headerText = 'Вскипающий яд';
        if ($kind === 'damage_ranged')   $headerText = 'Пламя бездны';
        if ($kind === 'teleport_target') $headerText = 'Дверь измерений';
        if ($kind === 'blood_storm') $headerText = 'Буря крови';

        if (($strike['state'] ?? '') === 'waiting_instant') {
            $phase = $strike['instant_phase'] ?? 'before';
            $headerText = match ($phase) {
                'combat' => 'Результат броска',
                'after'  => 'Сражение завершено',
                default  => 'Объявление удара',
            };
        }

        if ($kind === 'impact' && (int) $strike['attacker_id'] === (int) $strike['target_id']) {
            return '<div class="strike-box"><p>' . htmlspecialchars($headerText, ENT_QUOTES)
                . ': <b>' . $an . '</b></p>' . $contentHtml . '</div>';
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

    private function renderInstantResolutionSummary(array $strike): string
    {
        if (empty($strike['instant_summary'])) {
            return '';
        }

        $phaseLabels = [
            'redirect' => 'ПЕРЕНАПРАВЛЕНИЕ',
            'dice' => 'КУБИК',
            'power' => 'СИЛА УДАРА',
            'value' => 'ИЗМЕНЕНИЕ УДАРА',
            'setter' => 'ФИНАЛЬНЫЙ ЭФФЕКТ',
            'wounds' => 'РАНЫ',
        ];
        $groups = [];
        foreach ((array) $strike['instant_summary'] as $summary) {
            $phase = (string) ($summary['phase'] ?? 'other');
            $groups[$phase][] = (array) $summary;
        }

        $html = '<div class="instant-summary">'
            . '<div class="instant-summary__title">Разрешение инстантов по фазам:</div>';

        foreach ($phaseLabels as $phase => $label) {
            if (empty($groups[$phase])) continue;
            $html .= '<div class="instant-summary__phase">'
                . '<div class="instant-summary__phase-title">' . htmlspecialchars($label, ENT_QUOTES) . '</div>'
                . '<ol class="instant-summary__list">';
            foreach ($groups[$phase] as $summary) {
                $html .= $this->renderInstantSummaryItem($summary);
            }
            $html .= '</ol></div>';
            unset($groups[$phase]);
        }

        foreach ($groups as $phase => $items) {
            $html .= '<div class="instant-summary__phase">'
                . '<div class="instant-summary__phase-title">' . htmlspecialchars(strtoupper($phase), ENT_QUOTES) . '</div>'
                . '<ol class="instant-summary__list">';
            foreach ($items as $summary) {
                $html .= $this->renderInstantSummaryItem($summary);
            }
            $html .= '</ol></div>';
        }

        return $html . '</div>';
    }

    private function renderInstantSummaryItem(array $summary): string
    {
        $cardName = $this->instantCardName($summary);
        $isMine = (($summary['player'] ?? null) === $this->playerKey);
        $whoLabel = $isMine ? 'Ты' : 'Оппонент';
        $whoClass = $isMine ? 'instant-summary__who--mine' : 'instant-summary__who--opp';
        $cls = !empty($summary['applied']) ? '' : ' instant-summary__item--skip';

        return '<li class="instant-summary__item' . $cls . '">'
            . '<span class="instant-summary__who ' . $whoClass . '">' . $whoLabel . '</span> '
            . '<b>' . htmlspecialchars($cardName, ENT_QUOTES) . '</b> — '
            . htmlspecialchars($this->instantResultText($summary), ENT_QUOTES)
            . '</li>';
    }

    private function instantResultText(array $summary): string
    {
        $name = (string) (($summary['instant_name'] ?? '') ?: ($summary['label'] ?? 'Инстант'));
        if (empty($summary['applied'])) {
            $reason = (string) ($summary['reason'] ?? '');
            return $name . ': без эффекта' . ($reason !== '' ? ' — ' . $reason : '');
        }

        $result = (array) ($summary['result'] ?? []);
        $type = (string) ($summary['effect_type'] ?? '');

        if ($type === 'strike_value' && isset($result['after'])) {
            $reduction = (int) ($result['before'] ?? $result['after']) - (int) $result['after'];
            if ($reduction > 0) return $name . ': урон уменьшен на ' . $reduction;
            return $name . ': величина урона — ' . (int) $result['after'];
        }

        if ($type === 'damage_on_dice') {
            $dice = (int) ($result['dice_value'] ?? 0);
            $damage = (int) ($result['damage_delta'] ?? 0);
            return $name . ': кубик ' . $dice . ' -> +' . $damage . ' урона';
        }

        if ($type === 'strike_level') {
            $before = (string) ($result['before'] ?? '');
            $after = (string) ($result['after'] ?? '');
            $beforeText = $before !== '' ? BattleHelper::strikeName($before) : '?';
            $afterText = $after !== '' ? BattleHelper::strikeName($after) : '?';
            return $name . ': сила удара ' . $beforeText . ' -> ' . $afterText;
        }

        if ($type === 'dice_choice') {
            $attackBefore = (int) ($result['attack_before'] ?? 0);
            $attackAfter = (int) ($result['attack_after'] ?? 0);
            $defendBefore = (int) ($result['defend_before'] ?? 0);
            $defendAfter = (int) ($result['defend_after'] ?? 0);
            $parts = [];
            if ($attackBefore !== $attackAfter) {
                $parts[] = 'атакующий ' . $attackBefore . ' -> ' . $attackAfter;
            }
            if ($defendBefore !== $defendAfter) {
                $parts[] = 'защитник ' . $defendBefore . ' -> ' . $defendAfter;
            }
            if (empty($parts)) {
                $parts[] = 'кубики без изменения';
            }
            return $name . ': ' . implode(', ', $parts);
        }

        if ($type === 'redirect_strike') {
            $before = $this->cardNameById((int) ($result['before_target_id'] ?? 0));
            $after = $this->cardNameById((int) ($result['after_target_id'] ?? 0));
            return $name . ': цель ' . $before . ' -> ' . $after;
        }

        if ($type === 'damage_cap') {
            $cap = (int) ($result['after_cap'] ?? ($result['cap'] ?? 0));
            return $name . ': лимит урона -> ' . $cap;
        }

        if ($type === 'redistribute_wounds') {
            $transferred = (int) ($result['transferred'] ?? 0);
            return $name . ': перенесено ран ' . $transferred;
        }

        return $name . ': применен';
    }

    private function instantCardName(array $summary): string
    {
        $ukid = (string) ($summary['card_ukid'] ?? '');
        if ($ukid !== '') {
            return $this->cardsInfo[$ukid]['name'] ?? $ukid;
        }
        return $this->cardNameById((int) ($summary['card_id'] ?? 0));
    }

    private function cardNameById(int $cardId): string
    {
        if ($cardId <= 0) return '?';
        $card = $this->state->getCard($cardId);
        if (!$card) return '?';
        return $this->cardsInfo[$card->ukid]['name'] ?? $card->ukid;
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

    private function renderCombatInstantStack(array $strike, bool $showEmpty): string
    {
        $stack = array_values((array) ($strike['instant_stack'] ?? []));
        if (empty($stack)) {
            if (!$showEmpty) {
                return '';
            }
            return '<div class="instant-summary instant-stack--empty">'
                . '<div class="instant-summary__title">Объявленные боевые инстанты:</div>'
                . '<p class="instant-stack__empty">Пока нет объявленных инстантов.</p>'
                . '</div>';
        }

        foreach ($stack as $index => &$item) {
            $item['_display_index'] = $index;
        }
        unset($item);

        usort($stack, static function (array $a, array $b): int {
            $aSeq = array_key_exists('sequence', $a) ? (int) $a['sequence'] : (int) $a['_display_index'];
            $bSeq = array_key_exists('sequence', $b) ? (int) $b['sequence'] : (int) $b['_display_index'];
            return [$aSeq, (int) $a['_display_index']] <=> [$bSeq, (int) $b['_display_index']];
        });

        $html = '<div class="instant-summary">'
            . '<div class="instant-summary__title">Объявленные боевые инстанты:</div>'
            . '<ul class="instant-summary__list instant-summary__list--plain">';

        foreach ($stack as $item) {
            $srcCard = $this->state->getCard((int) ($item['card_id'] ?? 0));
            $srcName = $srcCard ? ($this->cardsInfo[$srcCard->ukid]['name'] ?? $srcCard->ukid) : '?';
            $target = $this->state->getCard((int) ($item['target_id'] ?? 0));
            $targetName = $target ? ($this->cardsInfo[$target->ukid]['name'] ?? $target->ukid) : '';
            $isMine = (($item['player'] ?? '') === $this->playerKey);
            $whoLabel = $isMine ? 'Ты' : 'Соперник';
            $whoClass = $isMine ? 'instant-summary__who--mine' : 'instant-summary__who--opp';

            $html .= '<li class="instant-summary__item">'
                . '<span class="instant-summary__who ' . $whoClass . '">' . htmlspecialchars($whoLabel, ENT_QUOTES) . '</span> '
                . '<b>' . htmlspecialchars((string) $srcName, ENT_QUOTES) . '</b> — '
                . htmlspecialchars((string) ($item['label'] ?? 'Инстант'), ENT_QUOTES);
            if ($targetName !== '') {
                $html .= ' <span class="instant-stack__target">→ '
                    . htmlspecialchars((string) $targetName, ENT_QUOTES)
                    . '</span>';
            }
            $html .= '</li>';
        }

        return $html . '</ul></div>';
    }

    /**
     * @param array<int, array{value:string,label:string,class?:string,card_id?:int}> $options
     */
    private function renderDefenderRadioForm(array $options, bool $skipDefender = false): string
    {
        if (empty($options) && !$skipDefender) {
            return '';
        }

        $body = '<div class="defender-radio-list">';
        foreach ($options as $index => $option) {
            $labelHtml = $option['label_html'] ?? \Berserk\View\Ui\Coords::text((string) $option['label']);
            $card = $this->state->getCard((int) ($option['card_id'] ?? 0));
            $coordsHtml = $card !== null
                ? \Berserk\View\Ui\Coords::renderResult($card->row, $card->col, $this->playerKey)
                : '';

            $body .= '<label class="' . htmlspecialchars('choice-radio ' . ($option['class'] ?? ''), ENT_QUOTES) . '">'
                . '<input type="radio" name="choice" value="' . htmlspecialchars($option['value'], ENT_QUOTES) . '"'
                . ($index === 0 ? ' checked="checked"' : '') . '>'
                . '<span class="defender-option-text">' . $labelHtml . '</span>' . $coordsHtml . '</label>';
        }
        $body .= '</div>'
            . '<div class="death-choice-buttons' . ($skipDefender ? ' defender-choice-actions' : '') . '">';
        if ($options !== []) $body .= Form::submit('Выбрать');
        if ($skipDefender) {
            $body .= Form::button('Пропустить', $this->baseUrl . '&cmd=choose_defender&defender_id=0', ['class' => 'skip']);
        }
        $body .= '</div>';

        return Form::form(
            Form::battleHidden($this->role, $this->state->gameId, 'choose_defender_option'),
            $body
        );
    }

    /**
     * @param array{name?:string} $cardInfo
     */
    private function battleCardLabel(CardInstance $card, array $cardInfo, string $viewerKey): string
    {
        $label = (string) ($cardInfo['name'] ?? $card->ukid);
        if ($card->zone === CardInstance::ZONE_FIELD || $card->zone === CardInstance::ZONE_FLYING) {
            $positionLabel = \Berserk\View\Ui\BattlefieldPosition::label($card->row, $card->col, $viewerKey);
            if ($positionLabel !== '') {
                $label .= ' ' . $positionLabel;
            }
        }
        return $label;
    }

    private function confirmStrikeLink(GameState $state, string $baseUrl): string
    {
        $token = \Berserk\Core\InformationalWaitManager::token($state);
        return '<a class="button" href="' . $baseUrl . '&cmd=confirm_strike&wait_id='
            . rawurlencode($token ?? 'unavailable') . '">Продолжить</a>';
    }
}
