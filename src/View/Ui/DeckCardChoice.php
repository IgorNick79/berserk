<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Core\GameState;
/** Native Degasca radio items, also used by mandatory deck searches. */
final class DeckCardChoice
{
    public static function items(GameState $state, array $ids, array $catalog, string $suffix = '', bool $instances = false): array
    {
        $items=[];
        foreach ($ids as $id) {
            $card=$state->getCard((int)$id); if (!$card) continue;
            $name=$catalog[$card->ukid]['name']??$card->ukid;
            $items[]=['value'=>$card->instanceId,'label'=>$name . ($instances?' #'.$card->instanceId:'') . $suffix];
        }
        return $items;
    }
}
