<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Card-specific choices using the native phase, prophecy and zone mechanisms. */
final class SwampQueenProcessor
{
    public const PENDING = 'pending_swamp_queen_summon';
    public function __construct(private GameState $state, private Engine $engine) {}

    public static function live(?CardInstance $card): bool
    {
        return $card !== null && EffectiveFeatures::onBattlefield($card) && !$card->dying && $card->hp > 0;
    }

    public static function summonConfig(CardInstance $source, int $index): ?array
    {
        $effect = $source->effectiveProp()['opponent_turn_end'][$index] ?? null;
        return is_array($effect) && ($effect['type'] ?? '') === 'deck_summon' ? $effect : null;
    }

    public function candidates(CardInstance $source, array $config): array
    {
        $ids = [];
        foreach ($this->state->getCardsInZone($source->owner, CardInstance::ZONE_DECK) as $card) {
            if (!in_array($card->type, ['creature','fly'], true) || $card->dying
                || !empty($card->flags['in_prophecy_pending']) || $card->hpMax <= 0
                || $card->element !== ($config['element'] ?? 'swamps') || $card->price > (int) ($config['max_price'] ?? 5)) continue;
            $ids[] = $card->instanceId;
        }
        return $ids;
    }

    public function cells(CardInstance $source, array $config): array
    {
        $cells = []; $zone = new ZoneManager($this->state);
        for ($row=1;$row<=6;$row++) for ($col=1;$col<=5;$col++) {
            if ($zone->isFieldOccupied($row,$col) || ZoneManager::hasBlockingMarker($this->state,"{$row}_{$col}")) continue;
            $count=0;
            foreach ($this->state->cards as $card) {
                if ($card->owner !== $source->owner || $card->zone !== CardInstance::ZONE_FIELD || !self::live($card)
                    || !in_array($card->type,['creature','fly'],true) || $card->row === null || $card->col === null) continue;
                $dr=abs($row-$card->row); $dc=abs($col-$card->col);
                if (max($dr,$dc)===1) ++$count;
            }
            // Count instances, not occupied cells: stacked/symbiotic neighbours are not capped at eight.
            if ($count >= (int) ($config['own_neighbors'] ?? 8)) $cells[] = "{$row}_{$col}";
        }
        return $cells;
    }

    public function startSummon(?CardInstance $source, int $index): array
    {
        if (!self::live($source)) return ['label'=>'Призыв','items'=>[]];
        $config=self::summonConfig($source,$index);
        if (!self::live($source) || $config===null || isset($this->state->battle[self::PENDING])) return ['label'=>'Призыв','items'=>[]];
        $cells=$this->cells($source,$config);
        $cards=$cells ? $this->candidates($source,$config) : [];
        if (!$cards || !$cells) return ['label'=>'Призыв','items'=>[]];
        $this->state->battle[self::PENDING]=['owner'=>$source->owner,'source_id'=>$source->instanceId,'effect_index'=>$index,
            'stage'=>'choose_card','candidate_ids'=>$cards,'cells'=>$cells,'card_id'=>null,'cell'=>null,'deck_viewed'=>true];
        $summoned=$this->advance(false);
        return ['label'=>'Призыв','items'=>$summoned===null?[]:[['instance_id'=>$summoned,'text'=>'Вызван из колоды']]];
    }

    /** null while choosing or after a safe no-op; ID when an automatic summon completes. */
    private function advance(bool $resume): ?int
    {
        $p=$this->state->battle[self::PENDING]??null;
        if (!$p) return null;
        $source=$this->state->getCard((int)$p['source_id']);
        $config=$source ? self::summonConfig($source,(int)$p['effect_index']) : null;
        if (!self::live($source) || $config===null) return $this->finish(null,$resume);
        $cards=$this->candidates($source,$config); $cells=$this->cells($source,$config);
        if (!$cards || !$cells) return $this->finish(null,$resume);
        $p['candidate_ids']=array_values(array_intersect($p['candidate_ids'],$cards));
        $p['cells']=array_values(array_intersect($p['cells'],$cells));
        if (!$p['candidate_ids'] || !$p['cells']) return $this->finish(null,$resume);
        if ($p['card_id']!==null && !in_array($p['card_id'],$p['candidate_ids'],true)) return $this->finish(null,$resume);
        if ($p['cell']!==null && !in_array($p['cell'],$p['cells'],true)) return $this->finish(null,$resume);
        if ($p['card_id']===null && count($p['candidate_ids'])===1) $p['card_id']=$p['candidate_ids'][0];
        if ($p['cell']===null && count($p['cells'])===1) $p['cell']=$p['cells'][0];
        $p['stage']=$p['card_id']===null?'choose_card':'choose_cell';
        $this->state->battle[self::PENDING]=$p;
        if ($p['card_id']===null || $p['cell']===null) return null;
        $card=$this->state->getCard($p['card_id']); [$row,$col]=array_map('intval',explode('_',$p['cell']));
        (new ZoneManager($this->state))->summonFromDeck($card,$row,$col);
        $this->engine->refreshArmor($this->state);
        $this->state->historyRecorder?->liveEvent($this->state,'ability.applied',[
            'source_id'=>$source->instanceId,'ability'=>'Призыв болотного существа'],[$source->instanceId,$card->instanceId]);
        return $this->finish($card->instanceId,$resume);
    }

    private function finish(?int $summoned, bool $resume): ?int
    {
        $p=$this->state->battle[self::PENDING]??null;
        unset($this->state->battle[self::PENDING]);
        if ($p && !empty($p['deck_viewed'])) (new ZoneManager($this->state))->shuffleDeck($p['owner']);
        if ($resume && !empty($this->state->battle['turn_phase'])) (new TurnPhaseProcessor($this->state,$this->engine))->resume();
        return $summoned;
    }

    public function choose(string $owner, Command $cmd): Result
    {
        $p=$this->state->battle[self::PENDING]??null;
        if (!$p || $p['owner']!==$owner) return Result::error('Нет вашего выбора призыва');
        $source=$this->state->getCard((int)$p['source_id']);
        $config=$source ? self::summonConfig($source,(int)$p['effect_index']) : null;
        if (!self::live($source) || $config===null || !$this->candidates($source,$config) || !$this->cells($source,$config)) {
            $this->finish(null,true); $this->state->bumpVersion(); return Result::ok(['summon_unavailable']);
        }
        $cards=array_values(array_intersect($p['candidate_ids'],$this->candidates($source,$config)));
        $cells=array_values(array_intersect($p['cells'],$this->cells($source,$config)));
        if ($cards!==$p['candidate_ids'] || $cells!==$p['cells']) {
            $this->advance(true); $this->state->bumpVersion(); return Result::ok(['summon_options_updated']);
        }
        if ($p['stage']==='choose_card' && $cmd->type==='choose_swamp_queen_card') {
            $id=(int)$cmd->get('card_id',0);
            if (!in_array($id,$p['candidate_ids'],true) || !in_array($id,$this->candidates($source,$config),true)) return Result::error('Карта недоступна');
            $this->state->battle[self::PENDING]['card_id']=$id;
        } elseif ($p['stage']==='choose_cell' && $cmd->type==='choose_swamp_queen_cell') {
            $cell=(string)$cmd->get('cell','');
            if (!in_array($cell,$p['cells'],true) || !in_array($cell,$this->cells($source,$config),true)) return Result::error('Клетка недоступна');
            $this->state->battle[self::PENDING]['cell']=$cell;
        } else return Result::error('Не тот шаг призыва');
        $this->advance(true); $this->state->bumpVersion(); return Result::ok(['summon_choice']);
    }

    public function startProphecy(CardInstance $source, CardInstance $target, array $action): Result
    {
        if (!self::live($source) || !self::live($target) || !in_array($target->type,['creature','fly'],true)) return Result::error('Нужны живые источник и цель');
        $pp=new ProphecyProcessor($this->state,$this->engine); $peek=$pp->peek($source->owner,1);
        if ($peek===null) return Result::error('Колода пуста');
        $field=array_filter($this->state->cards,static fn($c)=>EffectiveFeatures::onBattlefield($c));
        $names=$this->engine->cardNames(array_merge($peek['ukids'],array_map(static fn($c)=>$c->ukid,$field)));
        foreach (array_merge($peek['ukids'],array_map(static fn($c)=>$c->ukid,$field)) as $ukid) {
            if (!isset($names[$ukid]) || $names[$ukid]==='') return Result::error('Недоступны игровые имена карт');
        }
        $name=$names[$peek['ukids'][0]]; $match=false;
        foreach ($field as $card) if ($names[$card->ukid]===$name) { $match=true; break; }
        $source->closed=true;
        $pp->commit($source->owner,$source,$peek,'swamp_queen','Пророчество 1',[
            ['label'=>'Продолжить','cmd'=>'close_prophecy'],
        ]);
        $this->state->battle['pending_prophecy']['swamp_queen']=['source_id'=>$source->instanceId,'target_id'=>$target->instanceId,
            'action_key'=>(string)($action['key']??''),'revealed_name'=>$name,'name_match'=>$match,'value'=>$match?2:1,'applied'=>false];
        $this->state->bumpVersion(); return Result::ok(['swamp_queen_prophecy']);
    }

    public function finishProphecy(array $p): Result
    {
        $source=$this->state->getCard((int)$p['source_id']); $target=$this->state->getCard((int)$p['target_id']);
        $available=false;
        foreach ($source?->effectiveProp()['actions']??[] as $a) if (($a['key']??'')===$p['action_key'] && ($a['effect']??'')==='prophecy_wounds') $available=true;
        $apply=empty($p['applied']) && $available && self::live($source) && self::live($target);
        InformationalWaitManager::publish($this->state);
        $this->state->battle['strike']=['kind'=>'impact','effect'=>'prophecy_wounds','action_name'=>'Пророчество 1',
            'attacker_id'=>(int)$p['source_id'],'target_id'=>(int)$p['target_id'],'defender_id'=>null,'state'=>'results',
            'confirmed'=>[],'attack_dice'=>0,'defend_dice'=>0,'result'=>['attack'=>'','defend'=>'','winner'=>''],
            'final'=>['attack'=>'','defend'=>'','decreased'=>false],'damage'=>0,'impact_targets'=>[], 'prophecy_result'=>array_replace($p,['applied'=>$apply])];
        if ($apply) {
            $before=$target->hp;
            $this->engine->applyDamage($this->state,$target,(int)$p['value'],'prophecy_wounds',$source);
            $this->state->battle['strike']['impact_targets'][]=['target_id'=>$target->instanceId,'damage'=>max(0,$before-$target->hp),'poison'=>0];
            ActionResolution::effect($this->state,$source,'impact','ordinary');
            $this->state->historyRecorder?->liveEvent($this->state,'ability.applied',[
                'source_id'=>$source->instanceId,'ability'=>'Пророчество 1'],[$source->instanceId,$target->instanceId]);
            $this->engine->checkGameOver($this->state); $this->engine->flushDeadeatQueue($this->state);
        } else $this->state->battle['strike']['action_name']='Пророчество 1 — эффект недоступен';
        $this->state->bumpVersion(); return Result::ok(['swamp_queen_prophecy_finished']);
    }
}
