<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState,Engine,Command,Result,SymbioteProcessor,CardInstance};
use Berserk\View\Ui\{PanelSpec,CreatureChoice,CardSelectionSpec,Form};
final class SymbioteSeatingChoice implements ChoiceHandlerInterface
{
    public function pendingKey():string{return SymbioteProcessor::PENDING;}
    public function commandTypes():array{return ['choose_symbiote_host'];}
    public function spec(GameState $state,string $playerKey,array $cardsInfo,string $baseUrl,string $role):?PanelSpec
    {
        $p=$state->battle[$this->pendingKey()]??null;if(!$p)return null;
        $title=$p['source_id']===null?'Рассадка: выберите симбиота':'Рассадка: выберите хозяина';
        if($p['owner']!==$playerKey)return new PanelSpec(title:$title,isMine:false);
        if($p['source_id']!==null){$source=$state->getCard($p['source_id']);$title='Рассадка — '.($cardsInfo[$source->ukid]['name']??$source->ukid).': выберите хозяина';}
        $processor=new SymbioteProcessor($state,new Engine());$ids=[];
        if($p['source_id']!==null)$ids=$processor->hosts($state->getCard($p['source_id']));
        else foreach($p['sources']as$id){$c=$state->getCard($id);if($c&&$c->owner===$playerKey&&$c->zone===CardInstance::ZONE_FIELD&&empty($c->flags['symbiote_host_id'])&&$processor->hosts($c))$ids[]=$id;}
        $items=array_map(static fn($id)=>['value'=>$id],$ids);
        return new PanelSpec(title:$title,form:['name'=>$p['source_id']===null?'card_id':'target_id','hidden'=>Form::battleHidden($role,$state->gameId,'choose_symbiote_host',['choice_id'=>$p['id']]),'submit'=>'Выбрать','side_toggle'=>false],cardSelection:CreatureChoice::confirmed($state,$playerKey,$cardsInfo,$items,'symbiote:'.$p['id'].':'.($p['source_id']??'source'),mode:CardSelectionSpec::SIMPLE));
    }
    public function apply(GameState $state,Engine $engine,string $playerKey,Command $cmd):Result{return (new SymbioteProcessor($state,$engine))->choose($playerKey,$cmd);}
}
