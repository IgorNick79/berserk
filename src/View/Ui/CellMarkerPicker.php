<?php

declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Core\GameState;

/** View adapter for the existing cell-marker choice; commands retain system coordinates. */
final class CellMarkerPicker
{
    public static function targets(GameState $state, string $viewerKey, string $baseUrl): array
    {
        $pending = $state->battle['pending_cell_marker_pick'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $viewerKey) return [];
        if (!(\Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\CellMarkerChoice)) return [];
        $targets = [];
        foreach ($pending['cells'] as $key) {
            if (!is_string($key) || !preg_match('/^([1-6])_([1-5])$/D', $key, $m)) continue;
            $targets[$key] = [
                'row' => (int) $m[1], 'col' => (int) $m[2],
                'url' => $baseUrl . '&cmd=choose_cell_marker&row=' . $m[1] . '&col=' . $m[2],
            ];
        }
        return $targets;
    }

    public static function decorate(PanelSpec $spec, GameState $state, string $viewerKey, string $baseUrl): void
    {
        if (!$spec->isMine) return;
        $targetsByUrl = [];
        foreach (self::targets($state, $viewerKey, $baseUrl) as $target) {
            $targetsByUrl[$target['url']] = $target;
        }
        // Decorate existing cell actions in place. Preserve URLs, order and other actions
        // (especially cancellation) exactly as supplied by the choice handler.
        foreach ($spec->buttons as &$button) {
            $target = $targetsByUrl[$button['url']] ?? null;
            if ($target === null) continue;
            $pos = BattlefieldPosition::display($target['row'], $target['col'], $viewerKey);
            $button['label'] = (($state->battle['pending_cell_marker_pick']['marker'] ?? '') === 'bonfire' ? '🔥 ' : '')
                . ($pos['side'] === 'own' ? 'Свой' : 'Вражеский') . ' ' . $pos['row'] . '-й ряд · столбец ' . $pos['col'];
            $classes = preg_split('/\s+/', trim(($button['class'] ?? '') . ' cell-marker-choice coords--' . $pos['side']));
            $button['class'] = implode(' ', array_unique($classes));
        }
        unset($button);
    }
}