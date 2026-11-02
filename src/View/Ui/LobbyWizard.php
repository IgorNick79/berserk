<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Core\{Db,DeckView,GameState,GameSettings};
use Berserk\Lobby\RoomSetup;
use Berserk\View\Screen\SettingsScreen;
use Berserk\View\Screen\DeckScreen;
use Berserk\View\Template;

/** Presentation only: all modes use the existing server settings renderer/parser. */
final class LobbyWizard
{
    public static function render(int $actor, string $csrf, ?Db $catalog = null): string
    {
        $e=static fn(string $s): string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<form id="wizard-fields-sealed"></form><form id="wizard-fields-draft"></form><form id="wizard-fields-solomon"></form><form id="wizard-fields-lucktest"></form><form id="wizard-fields-system"></form><form id="create-wizard" class="create-wizard" method="post" action="lobby.php" novalidate>'
            .'<input type="hidden" name="action" value="create"><input type="hidden" name="csrf" value="'.$e($csrf).'">'
            .'<input type="hidden" name="creation_key" value="'.bin2hex(random_bytes(16)).'">'
            .'<div class="wizard-scroll"><header class="wizard-heading"><h1>Создать игру</h1><p data-wizard-progress>Шаг 1 из 3</p><nav class="wizard-tabs" aria-label="Этапы создания">';
        foreach (['Режим','Общие настройки','Настройки режима'] as $i=>$title) $html.='<button type="button" data-step="'.$i.'" aria-current="'.($i===0?'step':'false').'">'.$title.'</button>';
        $html.='</nav></header><section data-page="0"><h2>Режим игры</h2><div class="wizard-modes">';
        foreach (['sealed','draft','solomon','lucktest','system'] as $mode) $html.='<label class="wizard-option"><input type="radio" name="setup_mode" value="'.$mode.'"'.($mode==='sealed'?' checked':'').'><span>'.RoomSetup::label($mode).'</span></label>';
        $html.='</div></section><section data-page="1" hidden><h2>Доступ к партии</h2><div class="wizard-options"><label class="wizard-option"><input type="radio" name="access" value="open" checked><span>Открытая партия</span></label><label class="wizard-option"><input type="radio" name="access" value="closed"><span>🔒 Закрытая партия</span></label></div>'
            .'<label class="wizard-invite" hidden>Никнейм приглашённого игрока<input name="invited_login" autocomplete="off" minlength="3" maxlength="32" pattern="[A-Za-z0-9_-]{3,32}"><small>Только этот игрок сможет запросить участие. Создатель подтверждает заявку.</small></label>'
            .'<h2>Таймер хода</h2><div class="wizard-options"><label class="wizard-option"><input type="radio" name="turn_seconds" value="0" checked><span>Без ограничения времени</span></label><label class="wizard-option"><input type="radio" name="turn_seconds" value="180"><span>180 секунд на каждый ход</span></label></div><p>Ограничение относится к отдельному ходу, а не ко всей партии.</p></section><section data-page="2" hidden><h2 data-mode-title>Настройки режима</h2>';
        foreach (['sealed','draft','solomon','lucktest','system'] as $mode) {
            $state=new GameState(0,$actor,0);$state->mode=$mode==='solomon'?'draft':$mode;
            $setup=RoomSetup::parse($mode,[]);$state->settings=GameSettings::fromArray($setup['settings']);
            $view=(new SettingsScreen())->prepare($state,'host','host',null)['data'];
            $body=$view['content_html'].$view['actions_html'];
            if ($mode === 'system') {
                if ($catalog === null) throw new \RuntimeException('Catalog connection required');
                $tpl = new Template(dirname(__DIR__,3).'/templates');
                $deck = (new DeckScreen(new DeckView($catalog),$tpl))->prepare($state,'host','host',null);
                $body = (string)$tpl->parse('screen/deck_host.tpl',$deck['data']);
            }
            if (in_array($mode,['draft','solomon','sealed'],true)) $body=self::boosterSections($body,$mode==='sealed');
            // The wizard already provides the mode heading; keep explanatory LuckTest text.
            if ($mode !== 'lucktest') $body=preg_replace('/<p class="settings-mode-title">.*?<\/p>/s','',$body);
            $body=preg_replace('/<script\b[^>]*>.*?<\/script>/s','',$body);
            $body=preg_replace('/<input[^>]*name="(?:first|second|game|cmd|draft_type)"[^>]*>/','',$body);
            $body=preg_replace('/<div class="settings-submit-row">.*?<\/div>/s','',$body);
            $body=preg_replace('/<form\b([^>]*)>/','<div$1>',$body);$body=str_replace('</form>','</div>',$body);
            // IDs are local to a mode; disambiguate labels and dependent sliders.
            $body=preg_replace_callback('/\b(id|for)="([^"]+)"/',static fn(array $m): string=>$m[1].'="wizard-'.$mode.'-'.$m[2].'"',$body);
            $body=preg_replace('/<(input|select|textarea)\b/','<$1 form="wizard-fields-'.$mode.'"',$body);
            $html.='<fieldset class="wizard-mode-settings" data-mode="'.$mode.'"'.($mode==='sealed'?'':' hidden disabled').'>'.$body.'</fieldset>';
        }
        return $html.'<p class="wizard-summary" data-wizard-summary></p></section><p class="wizard-error" data-wizard-error role="alert" tabindex="-1"></p></div><footer class="wizard-footer"><button type="button" data-back>Назад</button><button type="button" data-next>Далее: общие настройки</button><button type="submit" data-create hidden>Создать игру</button></footer></form><noscript>Для перехода между этапами включите JavaScript.</noscript>';
    }

    /** Regroup the existing fields only in the wizard, leaving SettingsScreen unchanged. */
    private static function boosterSections(string $body, bool $sealed): string
    {
        return preg_replace_callback('/<section class="settings-section"><h2>Основные настройки<\/h2>(.*?)<\/section>/s',static function(array $section) use ($sealed): string {
            $fields=[];
            $remaining=preg_replace_callback('/<fieldset class="settings-fieldset"><legend>(Количество бустеров|Состав бустера|Редкая \/ ультраредкая карта)<\/legend>.*?<\/fieldset>/s',static function(array $field) use (&$fields): string {$fields[]=$field[0];return '';},$section[1]);
            if (count($fields)!==3) throw new \LogicException('Unexpected booster settings markup');
            return ($sealed?'':'<section class="settings-section"><h2>Основные настройки</h2>'.$remaining.'</section>')
                .'<section class="settings-section wizard-boosters"><h2>Бустеры</h2>'.$fields[0]
                .'<details class="panel-card-description wizard-booster-composition"><summary><span>Состав бустера</span><span class="wizard-disclosure-closed">Раскрыть</span><span class="wizard-disclosure-open">Свернуть</span><i class="description-chevron" aria-hidden="true"></i></summary><div class="panel-card-description-body">'.$fields[1].$fields[2].'</div></details></section>';
        },$body,1);
    }
}
