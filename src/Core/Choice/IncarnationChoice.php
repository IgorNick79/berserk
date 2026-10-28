<?php
// src/Core/Choice/IncarnationChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\Core\CardInstance;
use Berserk\View\Ui\PanelSpec;

final class IncarnationChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_incarnation';
    }

    public function commandTypes(): array
    {
        return ['choose_incarnation_cell', 'choose_worm_death'];
    }

    public function isActive(GameState $state): bool {
        if (empty($state->battle['pending_incarnation'])) return false;
        return !\Berserk\Core\WormIncarnation::operation($state) || !empty($state->battle['strike']['pending_choice']);
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pi = $state->battle['pending_incarnation'] ?? null;
        if (!$pi) return null;
        if (\Berserk\Core\WormIncarnation::operation($state)) {
            $pc=$state->battle['strike']['pending_choice']??null;if(!$pc)return null;
            $source=$state->getCard($pc['died_id']);
            if($source->owner!==$playerKey)return new PanelSpec(title:'Предсмертная способность',isMine:false);
            $buttons=[];foreach($pc['candidates'] as $id)$buttons[]=['label'=>($cardsInfo[$state->getCard($id)->ukid]['name']??'Карта').' #'.$id,
                'url'=>$baseUrl.'&cmd=choose_worm_death&choice_id='.rawurlencode($pc['choice_id']).'&target_id='.$id];
            return new PanelSpec(title:'Предсмертная способность — выберите цель',buttons:$buttons);
        }


        $card = $state->getCard($pi['current']);
        $name = $card ? ($cardsInfo[$card->ukid]['name'] ?? '?') : '?';

        if ($pi['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $name . ' инкарнирует',
                isMine: false,
                waitText: 'Ожидание выбора клетки оппонентом...',
            );
        }

        if (\Berserk\Core\Tremor::incarnationEnabled($card)) {
            $buttons = [];
            foreach (\Berserk\Core\Tremor::incarnationCells($state) as $cell=>$marker) {
                [$r,$c] = array_map('intval',explode('_',$cell));
                $buttons[] = ['label'=>\Berserk\View\Ui\BattlefieldPosition::label($r,$c,$playerKey),
                    'url'=>$baseUrl . "&cmd=choose_incarnation_cell&incarnation_id={$card->instanceId}&row=$r&col=$c"];
            }
            return new PanelSpec(title:$name . ' — инкарнация на клетку с Дрожью', buttons:$buttons);
        }

        $backRow = $playerKey === 'host' ? 1 : 6;
        $buttons = [];
        for ($col = 1; $col <= 5; $col++) {
            $occupied = false;
            foreach ($state->cards as $c) {
                if ($c->zone === CardInstance::ZONE_FIELD
                    && $c->row === $backRow && $c->col === $col) {
                    $occupied = true;
                    break;
                }
            }
            if ($occupied) continue;

            $buttons[] = [
                'label' => \Berserk\View\Ui\BattlefieldPosition::label($backRow, $col, $playerKey),
                'label_html' => \Berserk\View\Ui\Coords::render($backRow, $col, $playerKey),
                'url'   => "{$baseUrl}&cmd=choose_incarnation_cell&row={$backRow}&col={$col}",
            ];
        }

        if (empty($buttons)) {
            return new PanelSpec(
                title: 'Нет места для инкарнации ' . $name,
                isMine: true,
            );
        }

        return new PanelSpec(
            title: $name . ' инкарнирует — выбери клетку в заднем ряду',
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        if ($cmd->type === 'choose_worm_death') {
            if (!\Berserk\Core\WormIncarnation::operation($state)) return Result::error('Нет активной инкарнации Червя');
            return (new \Berserk\Core\DamageResolver($state))->chooseDeathTarget($playerKey,$cmd);
        }
        $turn = new \Berserk\Core\TurnProcessor($state, $engine);
        return $turn->chooseIncarnationCell($playerKey, $cmd);
    }
}