<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Core\GameState;
/** Presentation of the already resolved preparation roll, using battle dice. */
final class RollResults
{
    public static function render(GameState $state, string $viewer): string
    {
        $html='<div class="dice-block">';
        foreach ([$viewer=>'Ты', $state->getOpponentKey($viewer)=>'Оппонент'] as $key=>$label) {
            $html.='<div class="dice-column"><div class="label">'.$label.'</div>'.DiceValue::render((int)($state->getPlayer($key)->dice ?? 0)).'</div>';
        }
        return $html.'</div>';
    }
}
