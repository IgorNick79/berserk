<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState, Engine, Command, Result, SwampQueenProcessor};
use Berserk\View\Ui\{PanelSpec, Form, DeckCardChoice, Coords};
final class SwampQueenSummonChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return SwampQueenProcessor::PENDING; }
    public function commandTypes(): array { return ['choose_swamp_queen_card','choose_swamp_queen_cell']; }
    public function spec(GameState $state,string $playerKey,array $cardsInfo,string $baseUrl,string $role): ?PanelSpec
    {
        $p=$state->battle[$this->pendingKey()]??null; if (!$p) return null;
        $source=$state->getCard((int)$p['source_id']); $name=$cardsInfo[$source?->ukid]['name']??'Призыв';
        if ($p['owner']!==$playerKey) return new PanelSpec(title:$name.': призыв',isMine:false);
        $cardStage=$p['stage']==='choose_card';
        $items=$cardStage?DeckCardChoice::items($state,$p['candidate_ids'],$cardsInfo,'',true):[];
        if (!$cardStage) foreach ($p['cells'] as $cell) {
            [$row,$col]=array_map('intval',explode('_',$cell));
            $items[]=['value'=>$cell,'label'=>$cell,'label_html'=>Coords::render($row,$col,$playerKey)];
        }
        return new PanelSpec(title:$name.($cardStage?': выберите существо в колоде':': выберите клетку'),form:[
            'type'=>'radio','name'=>$cardStage?'card_id':'cell','items'=>$items,
            'hidden'=>Form::battleHidden($role,$state->gameId,$cardStage?'choose_swamp_queen_card':'choose_swamp_queen_cell'),
            'submit'=>'Вызвать',
        ]);
    }
    public function apply(GameState $state,Engine $engine,string $playerKey,Command $cmd): Result
    { return (new SwampQueenProcessor($state,$engine))->choose($playerKey,$cmd); }
}
