<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;

use Berserk\Core\{Command, Engine, FirstStrikeJump, GameState, Result};
use Berserk\View\Ui\PanelSpec;

final class FirstStrikeJumpChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return FirstStrikeJump::PENDING; }
    public function commandTypes(): array { return ['choose_first_strike_movement']; }

    public function spec(GameState $state, string $playerKey, array $cardsInfo, string $baseUrl, string $role): ?PanelSpec
    {
        $p = $state->battle[$this->pendingKey()] ?? null;
        if (!$p) return null;
        $jump = $p['stage'] === 'jump';
        $title = $jump
            ? 'Выберите клетку для обязательного прыжка Детей топи (дальность ' . (int) $p['range'] . ')'
            : 'Выберите своё существо рядом с освободившейся клеткой, которое переместится на неё';
        if ($p['owner'] !== $playerKey) return new PanelSpec(title: $title, isMine: false);
        $buttons = [];
        $url = $baseUrl . '&cmd=choose_first_strike_movement&choice_id=' . rawurlencode($p['id']);
        if ($jump) {
            $card = $state->getCard((int) $p['card_id']);
            $valid = $card ? (new \Berserk\Core\Movement\MovementResolver($state, new Engine()))->triggeredJumpCells($card, (int) $p['range']) : [];
            foreach ($p['cells'] as $cell) {
                if (!isset($valid[$cell])) continue;
                [$row, $col] = array_map('intval', explode('_', $cell));
                $buttons[] = ['label' => "({$row}, {$col})", 'url' => $url . "&row={$row}&col={$col}"];
            }
        } else {
            $valid = (new FirstStrikeJump($state, new Engine()))->candidates($p);
            foreach ($p['candidates'] as $id) {
                if (!in_array($id, $valid, true)) continue;
                $card = $state->getCard((int) $id);
                $name = $cardsInfo[$card->ukid]['name'] ?? $card->ukid;
                $buttons[] = ['label' => $name . " ({$card->row}, {$card->col})", 'url' => $url . '&target_id=' . $id];
            }
        }
        return new PanelSpec(title: $title, buttons: $buttons);
    }

    public function apply(GameState $state, Engine $engine, string $playerKey, Command $cmd): Result
    {
        return (new FirstStrikeJump($state, $engine))->choose($playerKey, $cmd);
    }
}
