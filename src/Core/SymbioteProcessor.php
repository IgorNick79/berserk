<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Reveal seating only; subtype data stays in prop, instance links in serialized flags. */
final class SymbioteProcessor
{
    public const PENDING='pending_symbiote_seating';
    public function __construct(private GameState $state, private Engine $engine) {}
    public static function subtype(CardInstance $card): ?string
    {
        $kind=$card->prop['symbiote']??null;
        return $card->type==='creature' && in_array($kind,['companion','parasite'],true)?$kind:null;
    }
    public static function corporeal(CardInstance $card): bool { return empty($card->effectiveProp()['incorporeal']); }
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
        foreach($state->cards as$sym){
            $hostId=(int)($sym->flags['symbiote_host_id']??0);
            if(!$hostId)continue;
            if($sym->zone!==CardInstance::ZONE_FIELD){unset($sym->flags['symbiote_host_id']);continue;}
            if($sym->dying)continue;
            $host=$state->getCard($hostId);
            if(!$host||$host->zone!==CardInstance::ZONE_FIELD||$host->dying||$host->hp<=0||$host->type!=='creature'||self::subtype($host)!==null||(self::corporeal($sym)&&!self::corporeal($host))){($engine??new Engine())->forceDeath($state,$sym,'destroy');continue;}
            $sym->row=$host->row;$sym->col=$host->col;
        }
    }
    public static function follow(GameState $state,Engine $engine,\Berserk\Core\Movement\MovementContext $context): void
    {
        foreach($state->cards as$sym)if($sym->zone===CardInstance::ZONE_FIELD&&!$sym->dying&&(int)($sym->flags['symbiote_host_id']??0)===$context->card->instanceId){
            $sym->row=$context->toRow;$sym->col=$context->toCol;
            (new \Berserk\Core\Movement\MovementEffectResolver($state,$engine))->applyForcedPositionChange(new \Berserk\Core\Movement\MovementContext($sym,$sym->owner,$context->fromRow,$context->fromCol,$context->toRow,$context->toCol,'forced_move','triggered',$context->card->instanceId));
        }
    }
}
