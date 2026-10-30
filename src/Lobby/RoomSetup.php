<?php
declare(strict_types=1);
namespace Berserk\Lobby;

use Berserk\Core\{BoosterSettings,Command,Db,GameSettings,GameState};
use Berserk\Core\Prepare\PrepareProcessor;

/** Room configuration only: pool generation is deferred until candidate acceptance. */
final class RoomSetup
{
    public static function parse(string $mode, array $input, ?int $debugAccount = null): array
    {
        if (!in_array($mode,['system','draft','solomon','sealed','lucktest'],true)) throw new \DomainException('Выберите вид игры.',400);
        $settings=GameSettings::defaults()->toArray();
        $timer=$input['turn_seconds']??'0';
        if (!in_array($timer,['0','180'],true)) throw new \DomainException('Допустимо: без таймера или 180 секунд на ход.',400);
        $settings['battle']['turn_seconds']=(int)$timer;
        $settings['draft']['type']=$mode==='solomon'?'solomon':'grid';
        $fields=[
            'draft'=>['draft_pick_mode'=>'pick_mode','draft_grid_mode'=>'grid_mode','draft_auto_side'=>'auto_side','boosters'=>'boosters','stack_size'=>'stack_size','draft_timer_mode'=>'timer_mode','draft_timer_total'=>'timer_total','draft_timer_action'=>'timer_action'],
            'sealed'=>['sealed_boosters'=>'boosters'],
            'booster'=>['booster_common'=>'common','booster_uncommon'=>'uncommon','booster_rare_slots'=>'rare_slots','booster_ultra_rare_chance'=>'ultra_rare_chance'],
        ];
        foreach ($fields as $group=>$map) foreach ($map as $field=>$key) {
            if (!array_key_exists($field,$input)) continue;
            $value=$input[$field];
            if (!is_string($value) || strlen($value)>32) throw new \DomainException('Некорректные настройки.',400);
            if (in_array($key,['boosters','stack_size','timer_total','timer_action','common','uncommon','rare_slots','ultra_rare_chance'],true)) {
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
        if ($mode === GameSettings::MODE_LUCKTEST) $settings['lucktest'] = GameSettings::luckTestInput($settings['lucktest'], $input);
        $setup = ['mode'=>$mode,'settings'=>$settings];
        if (array_key_exists('lucktest_scenario',$input) || array_key_exists('debug_roll',$input) || array_key_exists('lucktest_url_roll',$input)) {
            if ($mode !== 'lucktest' || $debugAccount === null || !\Berserk\Auth\DebugAccess::allowsId($debugAccount)) throw new \DomainException('Debug недоступен для этого аккаунта или режима',403);
            $private = ['account_id'=>$debugAccount];
            if (array_key_exists('lucktest_url_roll',$input)) $private['url_roll_enabled'] = \Berserk\Core\LuckTestDebug::urlInput($input['lucktest_url_roll']);
            if (array_key_exists('lucktest_scenario',$input)) $private['scenario'] = \Berserk\Core\Prepare\LuckTestScenario::parse($input['lucktest_scenario'], is_int($settings['lucktest']['card_count']) ? $settings['lucktest']['card_count'] : 0);
            if (($private['scenario'] ?? []) !== [] && $settings['lucktest']['quick_game'] !== true) throw new \DomainException('Сценарий LuckTest доступен только в быстрой игре',400);
            if (array_key_exists('debug_roll',$input)) $private['roll'] = \Berserk\Core\LuckTestDebug::rollInput($input['debug_roll']);
            if (($private['scenario'] ?? []) !== [] || ($private['roll'] ?? 'off') !== 'off' || array_key_exists('url_roll_enabled',$private)) $setup['debug'] = $private;
        }
        return self::validate($setup);
    }

    public static function validate(array $setup): array
    {
        $mode=$setup['mode']??null;
        if (!in_array($mode,['system','draft','solomon','sealed','lucktest'],true) || !is_array($setup['settings']??null)) throw new \DomainException('Некорректные настройки комнаты.',400);
        $s=GameSettings::fromArray($setup['settings']);
        if (!in_array($s->battle['turn_seconds'],[0,180],true)) throw new \DomainException('Некорректный таймер хода.',400);
        if ($s->draftType()!==($mode==='solomon'?'solomon':'grid')) throw new \DomainException('Несовместимый вид драфта.',400);
        if (!in_array($s->draft['pick_mode'],['manual','random'],true) || !in_array($s->draft['auto_side'],['both','host','player'],true)) throw new \DomainException('Некорректный автовыбор.',400);
        $error = match ($mode) {
            GameSettings::MODE_LUCKTEST => $s->validateLuckTestSettings(),
            'sealed' => $s->validateSealedSettings(),
            'system' => null,
            default => $s->validateDraftSettings(),
        };
        if ($error!==null) throw new \DomainException($error,400);
        $result = ['mode'=>$mode,'settings'=>$s->toArray()];
        if (isset($setup['debug'])) {
            $debug = $setup['debug'];
            if ($mode !== 'lucktest' || !is_array($debug) || array_diff(array_keys($debug),['account_id','scenario','roll','url_roll_enabled'])
                || !is_int($debug['account_id'] ?? null) || !\Berserk\Auth\DebugAccess::allowsId($debug['account_id'])) throw new \DomainException('Разрешение Debug отсутствует или отозвано',403);
            if (array_key_exists('url_roll_enabled',$debug)) $debug['url_roll_enabled'] = \Berserk\Core\LuckTestDebug::urlInput($debug['url_roll_enabled']);
            $entries = $debug['scenario'] ?? [];
            if (!is_array($entries)) throw new \DomainException('Некорректный сценарий LuckTest',400);
            if ($entries !== []) {
                $debug['scenario'] = \Berserk\Core\Prepare\LuckTestScenario::parse(json_encode(\Berserk\Core\Prepare\LuckTestScenario::root($entries),JSON_THROW_ON_ERROR),$s->lucktest['card_count']);
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
    }

    public static function label(?string $mode): string
    {
        return ['system'=>'Системные колоды','draft'=>'Grid Draft','solomon'=>'Solomon Draft','sealed'=>'Sealed','lucktest'=>'LuckTest'][$mode??'']??'Не выбран';
    }
}
