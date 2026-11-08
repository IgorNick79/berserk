<?php
declare(strict_types=1);
namespace Berserk\Lobby;

use Berserk\Core\{BoosterSettings,Command,Db,GameSettings,GameState};
use Berserk\Core\Prepare\PrepareProcessor;

/** Room configuration only: pool generation is deferred until candidate acceptance. */
final class RoomSetup
{
    public static function delegated(array $setup): bool { return ($setup['selection']??null)==='opponent'; }
    public static function delegation(): array { return ['selection'=>'opponent','configurator_id'=>null]; }
    public static function parse(string $mode, array $input, ?int $debugAccount = null, ?Db $catalog = null): array
    {
        if (!in_array($mode,['system','draft','solomon','glimpse','sealed','lucktest'],true)) throw new \DomainException('Выберите вид игры.',400);
        $settings=GameSettings::defaults()->toArray();
        foreach (['enabled','gold','silver'] as $key) {
            $field='handicap_'.$key;
            if (!array_key_exists($field,$input)) continue;
            $value=$input[$field];
            if (!is_string($value) || !preg_match($key==='enabled'?'/\A[01]\z/D':'/\A[0-5]\z/D',$value)) throw new \DomainException('Некорректная фора сопернику.',400);
            $settings['battle'][$field]=$key==='enabled'?$value==='1':(int)$value;
        }
        if (array_key_exists('turn_time_mode',$input) && !in_array($input['turn_time_mode'],['none','fixed','adaptive'],true)) throw new \DomainException('Выберите режим таймера.',400);
        if (array_key_exists('turn_seconds',$input) && !array_key_exists('turn_time_mode',$input)) {
            if (array_key_exists('turn_fixed_seconds',$input) || array_key_exists('turn_creature_seconds',$input)) throw new \DomainException('Укажите режим таймера.',400);
            if (!in_array($input['turn_seconds'],['0','180'],true)) throw new \DomainException('Некорректный таймер хода.',400);
            $settings['battle']['turn_seconds']=(int)$input['turn_seconds'];
        } else {
            $settings['battle']['turn_time_mode']=$input['turn_time_mode']??'fixed';
            foreach (['turn_fixed_seconds'=>180,'turn_creature_seconds'=>30] as $field=>$default) {
                $v=$input[$field]??(string)$default;
                if (!is_string($v) || !preg_match('/\A[0-9]{1,3}\z/D',$v)) throw new \DomainException('Некорректное время хода.',400);
                $settings['battle'][$field]=(int)$v;
            }
            $settings['battle']['turn_seconds']=$settings['battle']['turn_time_mode']==='none'?0:($settings['battle']['turn_time_mode']==='fixed'?$settings['battle']['turn_fixed_seconds']:180);
            if (array_key_exists('turn_seconds',$input) && $input['turn_seconds']!==(string)$settings['battle']['turn_seconds']) throw new \DomainException('Несогласованные настройки времени.',400);
        }
        $settings['draft']['type']=in_array($mode,['solomon','glimpse'],true)?$mode:'grid';
        $fields=[
            'draft'=>['draft_burn_mode'=>'burn_mode','draft_burn_count'=>'burn_count','draft_pick_mode'=>'pick_mode','draft_grid_mode'=>'grid_mode','draft_auto_side'=>'auto_side','boosters'=>'boosters','stack_size'=>'stack_size','draft_timer_mode'=>'timer_mode','draft_timer_total'=>'timer_total','draft_timer_action'=>'timer_action'],
            'sealed'=>['sealed_boosters'=>'boosters'],
            'booster'=>['booster_common'=>'common','booster_uncommon'=>'uncommon','booster_rare_slots'=>'rare_slots','booster_ultra_rare_chance'=>'ultra_rare_chance'],
        ];
        foreach ($fields as $group=>$map) foreach ($map as $field=>$key) {
            if (!array_key_exists($field,$input)) continue;
            $value=$input[$field];
            if (!is_string($value) || strlen($value)>32) throw new \DomainException('Некорректные настройки.',400);
            if (in_array($key,['burn_count','boosters','stack_size','timer_total','timer_action','common','uncommon','rare_slots','ultra_rare_chance'],true)) {
                if (!preg_match('/\A[0-9]{1,4}\z/D',$value)) throw new \DomainException('Некорректное число.',400);
                $value=(int)$value;
                if ($field==='draft_timer_total') $value*=60;
            }
            $settings[$group][$key]=$value;
        }
        if (isset($input['booster_rare_chance'])) {
            if (!is_string($input['booster_rare_chance']) || !preg_match('/\A[0-9]{1,3}\z/D',$input['booster_rare_chance'])) throw new \DomainException('Некорректная вероятность.',400);
            $settings['booster']['ultra_rare_chance']=BoosterSettings::rareChanceToUltraChance($input['booster_rare_chance']) ?? -1;
        }
        if ($mode === GameSettings::MODE_LUCKTEST) {
            if (!array_key_exists('lucktest_average_cost',$input)) {
                $settings['lucktest']['card_count']=6;
                $settings['lucktest']['total_cost']=45;
            }
            $settings['lucktest'] = GameSettings::luckTestInput($settings['lucktest'], $input);
        }
        $setup = ['mode'=>$mode,'settings'=>$settings];
        if ($mode === 'system' && array_intersect(array_keys($input), ['deck_id','other_deck_id','deck_mode','other_deck_mode'])) {
            $setup['system_decks'] = [];
            foreach (['deck','other_deck'] as $prefix) {
                $id = $input[$prefix.'_id'] ?? '0';
                if (!is_string($id) || !preg_match('/\A[0-9]{1,10}\z/D', $id) || (float)$id > 4294967295) throw new \DomainException('Некорректная колода.',400);
                $setup['system_decks'][$prefix.'_id'] = (int)$id;
                $setup['system_decks'][$prefix.'_mode'] = $input[$prefix.'_mode'] ?? 'manual';
            }
        }
        if (array_key_exists('lucktest_scenario',$input) || array_key_exists('debug_roll',$input) || array_key_exists('lucktest_url_roll',$input)) {
            if ($mode !== 'lucktest' || $debugAccount === null || !\Berserk\Auth\DebugAccess::allowsId($debugAccount)) throw new \DomainException('Debug недоступен для этого аккаунта или режима',403);
            $private = ['account_id'=>$debugAccount];
            if (array_key_exists('lucktest_url_roll',$input)) $private['url_roll_enabled'] = \Berserk\Core\LuckTestDebug::urlInput($input['lucktest_url_roll']);
            if (array_key_exists('lucktest_scenario',$input)) $private['scenario'] = \Berserk\Core\Prepare\LuckTestScenario::parse($input['lucktest_scenario'], is_int($settings['lucktest']['card_count']) ? $settings['lucktest']['card_count'] : 0);
            if (($private['scenario'] ?? []) !== [] && $settings['lucktest']['quick_game'] !== true) throw new \DomainException('Сценарий LuckTest доступен только в быстрой игре',400);
            if (array_key_exists('debug_roll',$input)) $private['roll'] = \Berserk\Core\LuckTestDebug::rollInput($input['debug_roll']);
            if (($private['scenario'] ?? []) !== [] || ($private['roll'] ?? 'off') !== 'off' || array_key_exists('url_roll_enabled',$private)) $setup['debug'] = $private;
        }
        $setup = self::validate($setup);
        if (isset($setup['system_decks']) && $catalog !== null) {
            // Reuse the existing processor checks, without persisting this validation state.
            $probe = new GameState(0, 1, 2);
            $probe->status = 'deck';
            $result = (new PrepareProcessor($probe,$catalog))->selectDeck('host',new Command('select_deck',(new \Berserk\Core\DeckView($catalog))->selectionPayload($setup['system_decks'])));
            if (!$result->success) throw new \DomainException($result->error ?? 'Некорректные колоды.',400);
        }
        return $setup;
    }

    public static function validate(array $setup): array
    {
        if (self::delegated($setup)) {
            $id=$setup['configurator_id']??null;
            if ($id!==null && (!is_int($id) || $id<=0)) throw new \DomainException('Некорректное право настройки.',400);
            if (isset($setup['mode']) && $id===null) throw new \DomainException('Соперник ещё не принят.',400);
            if (!isset($setup['mode'])) {
                if (array_diff(array_keys($setup),['selection','configurator_id'])) throw new \DomainException('Режим ещё не выбран.',400);
                return ['selection'=>'opponent','configurator_id'=>$id];
            }
        }
        $mode=$setup['mode']??null;
        if (!in_array($mode,['system','draft','solomon','glimpse','sealed','lucktest'],true) || !is_array($setup['settings']??null)) throw new \DomainException('Некорректные настройки комнаты.',400);
        $s=GameSettings::fromArray($setup['settings']);
        if (!is_bool($s->battle['handicap_enabled'])) throw new \DomainException('Некорректная фора сопернику.',400);
        foreach (['gold','silver'] as $currency) if (!is_int($s->battle['handicap_'.$currency]) || $s->battle['handicap_'.$currency]<0 || $s->battle['handicap_'.$currency]>5) throw new \DomainException('Некорректная фора сопернику.',400);
        if (($timerError=$s->validateTurnTime())!==null) throw new \DomainException($timerError,400);
        if ($s->draftType()!==(in_array($mode,['solomon','glimpse'],true)?$mode:'grid')) throw new \DomainException('Несовместимый вид драфта.',400);
        if (!in_array($s->draft['pick_mode'],['manual','random'],true) || !in_array($s->draft['auto_side'],['both','host','player'],true)) throw new \DomainException('Некорректный автовыбор.',400);
        $error = match ($mode) {
            GameSettings::MODE_LUCKTEST => $s->validateLuckTestSettings(),
            'sealed' => $s->validateSealedSettings(),
            'system' => null,
            default => $s->validateDraftSettings(),
        };
        if ($error!==null) throw new \DomainException($error,400);
        $result = ['mode'=>$mode,'settings'=>$s->toArray()];
        if (self::delegated($setup)) $result+=['selection'=>'opponent','configurator_id'=>$setup['configurator_id']??null];
        if (isset($setup['system_decks'])) {
            $decks = $setup['system_decks'];
            if ($mode !== 'system' || !is_array($decks) || array_diff(array_keys($decks),['deck_id','other_deck_id','deck_mode','other_deck_mode'])) throw new \DomainException('Некорректные колоды.',400);
            foreach (['deck','other_deck'] as $prefix) {
                $id = $decks[$prefix.'_id'] ?? null;
                $selection = $decks[$prefix.'_mode'] ?? null;
                if (!is_int($id) || $id < 0 || $id > 4294967295 || !in_array($selection,['manual','random'],true) || ($selection === 'manual' && $id === 0)) throw new \DomainException('Выберите системные колоды.',400);
            }
            $result['system_decks'] = $decks;
        }
        if (isset($setup['debug'])) {
            $debug = $setup['debug'];
            if ($mode !== 'lucktest' || !is_array($debug) || array_diff(array_keys($debug),['account_id','scenario','roll','url_roll_enabled'])
                || !is_int($debug['account_id'] ?? null) || !\Berserk\Auth\DebugAccess::allowsId($debug['account_id'])) throw new \DomainException('Разрешение Debug отсутствует или отозвано',403);
            if (array_key_exists('url_roll_enabled',$debug)) $debug['url_roll_enabled'] = \Berserk\Core\LuckTestDebug::urlInput($debug['url_roll_enabled']);
            $entries = $debug['scenario'] ?? [];
            if (!is_array($entries)) throw new \DomainException('Некорректный сценарий LuckTest',400);
            if ($entries !== []) {
                $debug['scenario'] = \Berserk\Core\Prepare\LuckTestScenario::parse(json_encode(\Berserk\Core\Prepare\LuckTestScenario::root($entries),JSON_THROW_ON_ERROR),$s->lucktest['card_count']);
                if ($s->luckTestStartStage() === 'battle' && $s->lucktest['placement'] === 'smart') {
                    throw new \DomainException('LuckTest Smart: несовместим со сценарием Debug',400);
                }
                if ($s->lucktest['quick_game'] !== true) throw new \DomainException('Сценарий LuckTest доступен только в быстрой игре',400);
            }
            if (isset($debug['roll'])) $debug['roll'] = \Berserk\Core\LuckTestDebug::rollInput($debug['roll']);
            $result['debug'] = $debug;
        }
        return $result;
    }

    public static function attachDebug(GameState $state, array $setup): void
    {
        if (!isset($setup['debug'])) return;
        if (($setup['debug']['account_id'] ?? null) !== $state->hostId || !\Berserk\Auth\DebugAccess::allowsId($state->hostId)) throw new \DomainException('Разрешение Debug хоста отсутствует или отозвано',403);
        $payload = [];
        if (array_key_exists('url_roll_enabled',$setup['debug'])) $payload['lucktest_url_roll'] = $setup['debug']['url_roll_enabled'];
        if (($setup['debug']['scenario'] ?? []) !== []) $payload['lucktest_scenario'] = json_encode(\Berserk\Core\Prepare\LuckTestScenario::root($setup['debug']['scenario']),JSON_THROW_ON_ERROR);
        if (isset($setup['debug']['roll'])) $payload['debug_roll'] = $setup['debug']['roll'];
        \Berserk\Core\LuckTestDebug::configure($state,'host',new Command('confirm_settings',$payload));
    }

    public static function apply(GameState $state, array $setup, ?Db $catalog): void
    {
        $setup=self::validate($setup);
        $processor=new PrepareProcessor($state,$catalog);
        $result=$processor->chooseMode('host',new Command('choose_mode',['mode'=>$setup['mode']]));
        if (!$result->success) throw new \DomainException($result->error??'Не удалось выбрать режим.',400);
        $state->settings=GameSettings::fromArray($setup['settings']);
        self::attachDebug($state,$setup);
        if ($setup['mode']!=='system') {
            if ($catalog===null) throw new \RuntimeException('Catalog connection required');
            $result = $setup['mode'] === GameSettings::MODE_LUCKTEST
                ? (new \Berserk\Core\Engine($catalog))->apply($state, 'host', new Command('confirm_settings'))
                : $processor->confirmSettings('host',new Command('confirm_settings'));
            if (!$result->success) throw new \DomainException($result->error??'Не удалось начать игру.',400);
        }
        if (isset($setup['system_decks'])) {
            if ($catalog===null) throw new \RuntimeException('Catalog connection required');
            $result = (new \Berserk\Core\Engine($catalog))->apply($state,'host',new Command('select_deck',(new \Berserk\Core\DeckView($catalog))->selectionPayload($setup['system_decks'])));
            if (!$result->success) throw new \DomainException($result->error ?? 'Не удалось выбрать колоды.',400);
        }
    }

    public static function label(?string $mode): string
    {
        return ['system'=>'Системные колоды','draft'=>'Grid Draft','glimpse'=>'Glimpse Draft','solomon'=>'Solomon Draft','sealed'=>'Sealed','lucktest'=>'LuckTest'][$mode??'']??'Не выбран';
    }
}
