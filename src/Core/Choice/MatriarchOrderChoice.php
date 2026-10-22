<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{Command,Engine,GameState,Result,MatriarchOrderProcessor};
use Berserk\View\Ui\{PanelSpec,Coords,Form};

final class MatriarchOrderChoice implements ChoiceHandlerInterface
{
    public function pendingKey():string{return MatriarchOrderProcessor::PENDING;}
    public function commandTypes():array{return ['choose_matriarch_order','cancel_pending'];}
    public function spec(GameState $state,string $playerKey,array $cardsInfo,string $baseUrl,string $role):?PanelSpec
    {
        $p=$state->battle[$this->pendingKey()]??null;if(!$p)return null;
        if($p['owner']!==$playerKey)return new PanelSpec(title:'Приказ матриарха',isMine:false);
        $items=[];
        $buttons=[];
        foreach($p['ids'] as $id){
            $card=$state->getCard($id);if(!MatriarchOrderProcessor::live($card))continue;
            $name=(string)($cardsInfo[$card->ukid]['name']??$card->ukid);
            $labelHtml=htmlspecialchars($name,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8')
                .' '.Coords::renderResult($card->row,$card->col,$playerKey);
            $items[]=['value'=>$id,'label'=>$name,'label_html'=>$labelHtml];
            $buttons[]=['label'=>$name,'label_html'=>$labelHtml,
                'url'=>$baseUrl.'&cmd=choose_matriarch_order&target_id='.$id.'&choice_id='.rawurlencode($p['id'])];
        }
        if($p['kind']==='target') {
            return new PanelSpec(title:'Приказ матриарха — выберите противника',form:[
                'type'=>'radio','name'=>'target_id','items'=>$items,
                'hidden'=>Form::battleHidden($role,$state->gameId,'choose_matriarch_order',['choice_id'=>$p['id']]),
                'submit'=>'Применить приказ',
                'cancel'=>$baseUrl.'&cmd=cancel_pending&choice_id='.rawurlencode($p['id']),
            ]);
        }
        return new PanelSpec(title:'Приказ матриарха — следующий участник ('.count($buttons).')',buttons:$buttons);
    }
    public function apply(GameState $state,Engine $engine,string $playerKey,Command $cmd):Result
    {return (new MatriarchOrderProcessor($state,$engine))->choose($playerKey,$cmd);}
}
