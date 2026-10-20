<?php

declare(strict_types=1);
namespace Berserk\Core\Choice;

use Berserk\Core\{Command, Engine, GameState, Result, Strangulation};
use Berserk\View\Ui\{BattlefieldPosition, PanelSpec};

final class StrangulationChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return Strangulation::PENDING; }
    public function commandTypes(): array { return ['choose_strangulation', 'cancel_pending']; }
    public function spec(GameState $state, string $playerKey, array $cardsInfo, string $baseUrl, string $role): ?PanelSpec
    {
        $p = $state->battle[$this->pendingKey()] ?? null;
        if (!$p) return null;
        if ($p['owner'] !== $playerKey) return new PanelSpec(title: 'Удушить', isMine: false);
        $source = $state->getCard((int) $p['source_id']);
        $items = [];
        foreach ($p['target_ids'] as $id) {
            $target = $state->getCard((int) $id);
            if (!$source || !Strangulation::validTarget($source, $target)) continue;
            $items[] = ['value' => $id, 'checked' => (int) ($_GET['sel'] ?? 0) === $id, 'label' => ($cardsInfo[$target->ukid]['name'] ?? $target->ukid)
                . ' ' . BattlefieldPosition::label($target->row, $target->col, $playerKey)];
        }
        return new PanelSpec(title: 'Удушить — выберите соседнее рядовое существо противника', form: [
            'type' => 'radio', 'name' => 'target_id', 'items' => $items,
            'hidden' => [$role === 'host' ? 'first' : 'second' => '', 'game' => $state->gameId,
                'cmd' => 'choose_strangulation', 'choice_id' => $p['id']],
            'submit' => 'Удушить', 'cancel' => $baseUrl . '&cmd=cancel_pending&choice_id=' . rawurlencode($p['id']),
        ]);
    }
    public function apply(GameState $state, Engine $engine, string $playerKey, Command $cmd): Result
    {
        return Strangulation::choose($state, $engine, $playerKey, $cmd);
    }
}
