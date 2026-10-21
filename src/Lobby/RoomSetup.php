<?php
declare(strict_types=1);
namespace Berserk\Lobby;

use Berserk\Core\{BoosterSettings,Command,Db,GameSettings,GameState};
use Berserk\Core\Prepare\PrepareProcessor;

/** Room configuration only: pool generation is deferred until candidate acceptance. */
final class RoomSetup
{
    public static function parse(string $mode, array $input): array
    {
        if (!in_array($mode,['system','draft','solomon','sealed'],true)) throw new \DomainException('Выберите вид игры.',400);
        $settings=GameSettings::defaults()->toArray();
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
        return self::validate(['mode'=>$mode,'settings'=>$settings]);
    }

    public static function validate(array $setup): array
    {
        $mode=$setup['mode']??null;
        if (!in_array($mode,['system','draft','solomon','sealed'],true) || !is_array($setup['settings']??null)) throw new \DomainException('Некорректные настройки комнаты.',400);
        $s=GameSettings::fromArray($setup['settings']);
        if ($s->draftType()!==($mode==='solomon'?'solomon':'grid')) throw new \DomainException('Несовместимый вид драфта.',400);
        if (!in_array($s->draft['pick_mode'],['manual','random'],true) || !in_array($s->draft['auto_side'],['both','host','player'],true)) throw new \DomainException('Некорректный автовыбор.',400);
        $error=$mode==='sealed'?$s->validateSealedSettings():($mode==='system'?null:$s->validateDraftSettings());
        if ($error!==null) throw new \DomainException($error,400);
        return ['mode'=>$mode,'settings'=>$s->toArray()];
    }

    public static function apply(GameState $state, array $setup, ?Db $catalog): void
    {
        $setup=self::validate($setup);
        $processor=new PrepareProcessor($state,$catalog);
        $result=$processor->chooseMode('host',new Command('choose_mode',['mode'=>$setup['mode']]));
        if (!$result->success) throw new \DomainException($result->error??'Не удалось выбрать режим.',400);
        $state->settings=GameSettings::fromArray($setup['settings']);
        if ($setup['mode']!=='system') {
            if ($catalog===null) throw new \RuntimeException('Catalog connection required');
            $result=$processor->confirmSettings('host',new Command('confirm_settings'));
            if (!$result->success) throw new \DomainException($result->error??'Не удалось начать игру.',400);
        }
    }

    public static function label(?string $mode): string
    {
        return ['system'=>'Системные колоды','draft'=>'Grid Draft','solomon'=>'Solomon Draft','sealed'=>'Sealed'][$mode??'']??'Не выбран';
    }
}
