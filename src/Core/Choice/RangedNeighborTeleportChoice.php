<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState, Engine, Command, Result, RangedNeighborTeleport};
use Berserk\View\Ui\PanelSpec;

final class RangedNeighborTeleportChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return RangedNeighborTeleport::PENDING; }
    public function commandTypes(): array { return ['choose_ranged_neighbor_teleport']; }
    public function spec(GameState $state, string $playerKey, array $cardsInfo, string $baseUrl, string $role): ?PanelSpec
    {
        $p = $state->battle[$this->pendingKey()] ?? null;
        if (!$p) return null;
        $card = $state->getCard((int) $p['card_id']);
        $name = $cardsInfo[$card?->ukid]['name'] ?? 'Телепортация';
        if ($p['owner'] !== $playerKey) return new PanelSpec(title: $name . ': телепортация', isMine: false);
        $url = $baseUrl . '&cmd=choose_ranged_neighbor_teleport&choice_id=' . rawurlencode($p['id']);
        $buttons = [];
        if ($p['stage'] === 'offer') {
            $buttons[] = ['label' => 'Телепортироваться', 'url' => $url . '&operation=start'];
        } else {
            foreach ((new RangedNeighborTeleport($state, new Engine()))->cells($p) as $cell) {
                $buttons[] = ['label' => $cell, 'url' => $url . '&operation=select&cell=' . $cell];
            }
        }
        $buttons[] = ['label' => 'Отмена', 'url' => $url . '&operation=cancel', 'class' => 'skip'];
        return new PanelSpec(title: $name . ': ' . ($p['stage'] === 'offer' ? 'телепортация' : 'выберите клетку'), buttons: $buttons);
    }
    public function apply(GameState $state, Engine $engine, string $playerKey, Command $cmd): Result
    {
        return (new RangedNeighborTeleport($state, $engine))->choose($playerKey, $cmd);
    }
}
