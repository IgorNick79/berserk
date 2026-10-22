<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{Command,Engine,GameState,Result,MatriarchOrderProcessor};
use Berserk\View\Ui\{PanelSpec,BattlefieldPosition};

final class MatriarchOrderChoice implements ChoiceHandlerInterface
{
    public function pendingKey():string{return MatriarchOrderProcessor::PENDING;}
    public function commandTypes():array{return ['choose_matriarch_order','cancel_pending'];}
    public function spec(GameState $state,string $playerKey,array $cardsInfo,string $baseUrl,string $role):?PanelSpec
    {
        $p=$state->battle[$this->pendingKey()]??null;if(!$p)return null;
        if($p['owner']!==$playerKey)return new PanelSpec(title:'Приказ матриарха',isMine:false);
        $buttons=[];
        foreach($p['ids'] as $id){
            $card=$state->getCard($id);if(!MatriarchOrderProcessor::live($card))continue;
            $buttons[]=['label'=>($cardsInfo[$card->ukid]['name']??$card->ukid).' '.BattlefieldPosition::label($card->row,$card->col,$playerKey),
                'url'=>$baseUrl.'&cmd=choose_matriarch_order&target_id='.$id.'&choice_id='.rawurlencode($p['id'])];
        }
        if($p['kind']==='target')$buttons[]=['label'=>'Отмена','url'=>$baseUrl.'&cmd=cancel_pending&choice_id='.rawurlencode($p['id']),'class'=>'skip'];
        return new PanelSpec(title:$p['kind']==='target'?'Приказ матриарха — выберите противника':'Приказ матриарха — следующий участник ('.count($p['ids']).')',buttons:$buttons);
    }
    public function apply(GameState $state,Engine $engine,string $playerKey,Command $cmd):Result
    {return (new MatriarchOrderProcessor($state,$engine))->choose($playerKey,$cmd);}
}
