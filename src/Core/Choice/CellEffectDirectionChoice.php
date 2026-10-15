<?php
declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\{CardInstance, Command, Engine, GameState, Result, TurnPhaseProcessor, ZoneManager};
use Berserk\View\Ui\PanelSpec;

/** Direction choice for a turn-triggered line of cell markers. */
final class CellEffectDirectionChoice implements ChoiceHandlerInterface
{
    public const DESCRIPTION = 'В начале хода противника выберите направление: три последовательные клетки на одной прямой (в том числе по диагонали) от источника получают «Свет фонаря». До конца вашего следующего хода карты на этих клетках получают на 1 рану больше от дальних атак, отравления и удара источника света.';
    private const DIRECTIONS = [
        'up' => [-1, 0, 'ВВЕРХ'], 'up_right' => [-1, 1, 'ВВЕРХ-ВПРАВО'],
        'right' => [0, 1, 'ВПРАВО'], 'down_right' => [1, 1, 'ВНИЗ-ВПРАВО'],
        'down' => [1, 0, 'ВНИЗ'], 'down_left' => [1, -1, 'ВНИЗ-ВЛЕВО'],
        'left' => [0, -1, 'ВЛЕВО'], 'up_left' => [-1, -1, 'ВВЕРХ-ВЛЕВО'],
    ];

    public function pendingKey(): string { return 'pending_cell_effect_direction'; }
    public function commandTypes(): array { return ['choose_cell_effect_direction']; }

    /** Lantern is passive: closing alone does not disable it; other restrictions remain. */
    public static function canCreateLight(CardInstance $card): bool
    {
        return $card->zone === CardInstance::ZONE_FIELD && !$card->dying && $card->hp > 0
            && !isset($card->markers['spider_web']);
    }

    /** Physical board directions, consistent with the field rendered for both players. */
    public static function options(CardInstance $card, int $range): array
    {
        if ($card->zone !== CardInstance::ZONE_FIELD || $card->row === null || $card->col === null || $range < 1) return [];
        $options = [];
        foreach (self::DIRECTIONS as $key => [$dr, $dc, $label]) {
            $cells = [];
            for ($i = 1; $i <= $range; $i++) {
                $row = $card->row + $dr * $i;
                $col = $card->col + $dc * $i;
                if ($row < 1 || $row > 6 || $col < 1 || $col > 5) continue 2;
                $cells[] = $row . '_' . $col;
            }
            $options[$key] = ['label' => $label, 'cells' => $cells];
        }
        return $options;
    }

    public function spec(GameState $state, string $playerKey, array $cardsInfo, string $baseUrl, string $role): ?PanelSpec
    {
        $p = $state->battle[$this->pendingKey()] ?? null;
        if (!$p) return null;
        $card = $state->getCard((int) $p['source_id']);
        $name = $card ? ($cardsInfo[$card->ukid]['name'] ?? $card->ukid) : '?';
        if ($p['owner'] !== $playerKey) return new PanelSpec(title: $name . ' — Свет фонаря: выбор направления', isMine: false);
        $buttons = [];
        foreach ($p['options'] as $key => $option) {
            $buttons[] = ['label' => $option['label'], 'url' => $baseUrl . '&cmd=choose_cell_effect_direction&direction=' . $key];
        }
        return new PanelSpec(title: $name . ' — Свет фонаря: выберите направление (3 клетки)', text: [self::DESCRIPTION], buttons: $buttons);
    }

    public function apply(GameState $state, Engine $engine, string $playerKey, Command $cmd): Result
    {
        $p = $state->battle[$this->pendingKey()] ?? null;
        if (!$p || $p['owner'] !== $playerKey) return Result::error('Не ваш выбор');
        $direction = (string) $cmd->get('direction', '');
        if (!isset($p['options'][$direction])) return Result::error('Неверное направление');
        $card = $state->getCard((int) $p['source_id']);
        if ($card && $card->owner === $playerKey && self::canCreateLight($card)) {
            $options = self::options($card, (int) $p['range']);
            if (!isset($options[$direction]) || $options[$direction]['cells'] !== $p['options'][$direction]['cells']) {
                return Result::error('Положение источника изменилось');
            }
            foreach ($options[$direction]['cells'] as $cell) {
                ZoneManager::addMarker($state, $cell, [
                    'type' => 'lantern_light', 'source' => $playerKey, 'owner' => $playerKey,
                    'source_id' => $card->instanceId, 'created_turn' => (int) ($state->battle['turn'] ?? 0),
                    'created_active' => $state->battle['active'],
                    'value' => (int) $p['value'], 'timing' => 'end_of_turn', 'expire' => 1,
                ]);
            }
        }
        unset($state->battle[$this->pendingKey()]);
        (new TurnPhaseProcessor($state, $engine))->resume();
        $state->bumpVersion();
        return Result::ok(['cell_effect_direction_done']);
    }
}
