<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Reveal seating only; subtype is cards.type, links are serialized instance flags. */
final class SymbioteProcessor
{
    public const PENDING='pending_symbiote_seating';
    public function __construct(private GameState $state, private Engine $engine) {}
    public static function subtype(CardInstance $card): ?string
    {
        return in_array($card->type,['companion','parasite'],true)?$card->type:null;
    }
    public static function corporeal(CardInstance $card): bool { return !CardStats::isIncorporeal($card); }
    public function hosts(CardInstance $sym): array
    {
        $kind=self::subtype($sym);if($kind===null)return [];
        $taken=[];foreach($this->state->cards as$c)if($c->zone===CardInstance::ZONE_FIELD&&!$c->dying&&isset($c->flags['symbiote_host_id']))$taken[(int)$c->flags['symbiote_host_id']]=true;
        $out=[];
        foreach($this->state->cards as$host){
            if($host->instanceId===$sym->instanceId||$host->zone!==CardInstance::ZONE_FIELD||$host->type!=='creature'||self::subtype($host)!==null||$host->dying||$host->hp<=0||isset($taken[$host->instanceId]))continue;
            if(self::corporeal($sym)&&!self::corporeal($host))continue;
            if($kind==='companion'&&$host->owner!==$sym->owner)continue;
            if($kind==='parasite'&&($host->owner===$sym->owner||!($sym->owner==='host'?$host->row<=4:$host->row>=3)))continue;
            $out[]=$host->instanceId;
        }
        return $out;
    }
    public function begin(): bool
    {
        $sources=[];foreach($this->state->cards as$c)if($c->zone===CardInstance::ZONE_FIELD&&self::subtype($c)!==null&&!$c->dying&&$c->hp>0){$c->revealed=true;if(empty($c->flags['symbiote_host_id']))$sources[]=$c->instanceId;}
        if(!$sources)return false;
        $first=$this->state->getFirstPlayerKey();
        $this->state->battle[self::PENDING]=['id'=>bin2hex(random_bytes(12)),'order'=>[$first,$this->state->getOpponentKey($first)],'sources'=>$sources,'owner'=>$first,'source_id'=>null];
        $this->advance();return !empty($this->state->battle[self::PENDING]);
    }
    private function advance(): void
    {
        $p=&$this->state->battle[self::PENDING];
        while($p['order']){
            $p['owner']=$p['order'][0];$available=[];
            foreach($p['sources']as$id){$c=$this->state->getCard($id);if($c&&$c->owner===$p['owner']&&$c->zone===CardInstance::ZONE_FIELD&&!$c->dying&&empty($c->flags['symbiote_host_id'])&&$this->hosts($c))$available[]=$id;}
            if($available){$p['source_id']=count($available)===1?$available[0]:null;return;}
            array_shift($p['order']);
        }
        $sources=$p['sources'];unset($this->state->battle[self::PENDING]);
        foreach($sources as$id){$c=$this->state->getCard($id);if($c&&$c->zone===CardInstance::ZONE_FIELD&&!$c->dying&&empty($c->flags['symbiote_host_id']))$this->engine->forceDeath($this->state,$c,'destroy');}
        $this->engine->finalizeDying($this->state);
    }
    public function choose(string $owner,Command $cmd): Result
    {
        $p=$this->state->battle[self::PENDING]??null;
        if(!$p||$p['owner']!==$owner||$cmd->get('choice_id')!==$p['id'])return Result::error('Устаревший или чужой выбор рассадки');
        $source=$this->state->getCard((int)($p['source_id']??$cmd->get('card_id',0)));
        if(!$source||$source->owner!==$owner||!in_array($source->instanceId,$p['sources'],true)||$source->zone!==CardInstance::ZONE_FIELD||$source->dying||!empty($source->flags['symbiote_host_id'])||!$this->hosts($source))return Result::error('Недоступный симбиот');
        if($p['source_id']===null){$this->state->battle[self::PENDING]['source_id']=$source->instanceId;$this->state->bumpVersion();return Result::ok(['symbiote_selected']);}
        $hostId=(int)$cmd->get('target_id',0);if(!in_array($hostId,$this->hosts($source),true))return Result::error('Недопустимый хозяин');
        $host=$this->state->getCard($hostId);$source->flags['symbiote_host_id']=$hostId;$source->row=$host->row;$source->col=$host->col;
        self::refresh($this->state, $this->engine);
        $this->state->battle[self::PENDING]['source_id']=null;$this->advance();$this->state->bumpVersion();
        if(empty($this->state->battle[self::PENDING])&&$this->state->status!=='game_over')(new TurnProcessor($this->state,$this->engine))->finishReveal();
        return Result::ok(['symbiote_seated:'.$source->instanceId.':'.$hostId]);
    }
    /** Rooted already forbids forced movement in the native movement resolver. */
    public static function hasRootedFollower(GameState $state,CardInstance $host): bool
    {
        foreach($state->cards as$c)if($c->zone===CardInstance::ZONE_FIELD&&!$c->dying&&(int)($c->flags['symbiote_host_id']??0)===$host->instanceId&&isset($c->markers['rooted']))return true;
        return false;
    }
    public static function refresh(GameState $state,?Engine $engine=null): void
    {
        $hostEffects = [];
        foreach($state->cards as$sym){
            $hostId=(int)($sym->flags['symbiote_host_id']??0);
            if(!$hostId)continue;
            if($sym->zone!==CardInstance::ZONE_FIELD){unset($sym->flags['symbiote_host_id']);continue;}
            if($sym->dying || $sym->hp <= 0)continue;
            $host=$state->getCard($hostId);
            if(!$host||$host->zone!==CardInstance::ZONE_FIELD||$host->dying||$host->hp<=0||$host->type!=='creature'||self::subtype($host)!==null||(self::corporeal($sym)&&!self::corporeal($host))){($engine??new Engine())->forceDeath($state,$sym,'destroy');continue;}
            $sym->row=$host->row;$sym->col=$host->col;
            $effects = $sym->effectiveProp()['host_effects'] ?? [];
            if ($effects) $hostEffects[$hostId] = $effects;
        }
        foreach ($state->cards as $card) {
            self::syncHostEffects($card, $hostEffects[$card->instanceId] ?? []);
        }
    }

    private static function syncHostEffects(CardInstance $host, array $effects): void
    {
        if (($host->flags['symbiote_host_effects'] ?? []) === $effects) return;

        $previousMove = 0;
        foreach ($host->modifiers as $index => $modifier) {
            if (empty($modifier['symbiote_host_effect'])) continue;
            if ($modifier['stat'] === 'move') $previousMove = (int) $modifier['value'];
            unset($host->modifiers[$index]);
        }
        $host->modifiers = array_values($host->modifiers);

        $bonus = $host->increaseMove([
            'value' => $effects['increase_move'] ?? 0,
            'expire' => 'end_of_battle',
        ], $host->owner);
        if ($bonus > 0) {
            $host->modifiers[array_key_last($host->modifiers)]['symbiote_host_effect'] = true;
        }
        $host->move = max(0, $host->move - $previousMove);

        foreach ($effects['damage_reduction'] ?? [] as $rule) {
            $host->modifiers[] = [
                'stat' => 'damage_reduction',
                'value' => (int) ($rule['value'] ?? 0),
                'types' => $rule['types'] ?? null,
                'expire' => 'end_of_battle',
                'source' => $host->owner,
                'symbiote_host_effect' => true,
            ];
        }

        if ($effects) $host->flags['symbiote_host_effects'] = $effects;
        else unset($host->flags['symbiote_host_effects']);
    }

    public static function follow(GameState $state,Engine $engine,\Berserk\Core\Movement\MovementContext $context): void
    {
        foreach($state->cards as$sym)if($sym->zone===CardInstance::ZONE_FIELD&&!$sym->dying&&(int)($sym->flags['symbiote_host_id']??0)===$context->card->instanceId){
            $sym->row=$context->toRow;$sym->col=$context->toCol;
            (new \Berserk\Core\Movement\MovementEffectResolver($state,$engine))->applyForcedPositionChange(new \Berserk\Core\Movement\MovementContext($sym,$sym->owner,$context->fromRow,$context->fromCol,$context->toRow,$context->toCol,'forced_move','triggered',$context->card->instanceId));
        }
    }
}
