<?php
// src/Core/Choice/DiceChoiceChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class DiceChoiceChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_dice_choice';
    }

    public function commandTypes(): array
    {
        return ['choose_dice_choice'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $dc = $state->battle['pending_dice_choice'] ?? null;
        if (!$dc) return null;

        $card   = $state->getCard($dc['card_id']);
        $cardName = $card ? ($cardsInfo[$card->ukid]['name'] ?? '?') : '?';

        if ($dc['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $cardName . ' — Ловец',
                isMine: false,
            );
        }

        $url = $baseUrl . '&cmd=choose_dice_choice&choice=';

        $strike=$state->battle['strike']??[];
        $attacker=$state->getCard((int)($strike['attacker_id']??0));
        $ownAttack=$attacker?->owner===$playerKey;
        $own=\Berserk\View\Ui\DiceValue::render((int)($strike[$ownAttack?'attack_dice':'defend_dice']??0));
        $enemy=\Berserk\View\Ui\DiceValue::render((int)($strike[$ownAttack?'defend_dice':'attack_dice']??0));
        $buttons=[];
        foreach(['plus:own'=>'+1 к своему кубику','minus:own'=>'−1 от своего кубика','plus:enemy'=>'+1 к чужому кубику','minus:enemy'=>'−1 от чужого кубика']as$key=>$label){
            $mine=str_ends_with($key,':own');
            $buttons[]=['label'=>$label,'label_html'=>($mine?$own:$enemy).'<span>'.(str_starts_with($key,'plus:')?'+1':'−1').'</span>','url'=>$url.$key,'class'=>'catcher-dice '.($mine?'catcher-own':'catcher-enemy')];
        }
        $buttons[]=['label'=>'Переброс','url'=>$url.'reroll:any','class'=>'catcher-reroll'];
        $buttons[]=['label'=>'Отмена','url'=>$baseUrl.'&cmd=cancel_pending','class'=>'skip'];
        return new PanelSpec(title:$cardName.' — Удача: выбери действие',buttons:$buttons);
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        return $ar->chooseDiceChoice($playerKey, $cmd);
    }
}