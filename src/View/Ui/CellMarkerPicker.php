<?php

declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Core\GameState;

/** View adapter for the existing cell-marker choice; commands retain system coordinates. */
final class CellMarkerPicker
{
    public static function targets(GameState $state, string $viewerKey, string $baseUrl): array
    {
        $pi = $state->battle['pending_incarnation'] ?? null;
        if ($pi && !\Berserk\Core\WormIncarnation::operation($state) && $pi['owner'] === $viewerKey
            && \Berserk\Core\Tremor::incarnationEnabled($state->getCard($pi['current']))
            && \Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\IncarnationChoice) {
            $targets = [];
            foreach (\Berserk\Core\Tremor::incarnationCells($state) as $cell=>$id) {
                [$row,$col] = array_map('intval',explode('_',$cell));
                $targets[$cell] = ['row'=>$row,'col'=>$col,'url'=>$baseUrl . '&cmd=choose_incarnation_cell&incarnation_id=' . $pi['current'] . "&row=$row&col=$col"];
            }
            return $targets;
        }

        $tremor = $state->battle[\Berserk\Core\Tremor::PENDING] ?? null;
        if ($tremor && $tremor['owner'] === $viewerKey
            && \Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\TremorChoice) {
            $moving = ($tremor['mode'] ?? '') === 'move';
            [$r, $c] = $moving ? array_map('intval', explode('_', $tremor['from'])) : [0,0];
            $targets = [];
            for ($row=1; $row<=6; $row++) for ($col=1; $col<=5; $col++) {
                if ($moving && max(abs($row-$r),abs($col-$c))>1) continue;
                $targets[$row . '_' . $col] = ['row'=>$row, 'col'=>$col, 'url'=>$baseUrl
                    . '&cmd=choose_tremor_cell&marker_id=' . rawurlencode($tremor['id'])
                    . ($moving ? '&choice_id=' . rawurlencode($tremor['choice_id']) : '') . "&row=$row&col=$col"];
            }
            return $targets;
        }

        $gates = $state->battle['pending_gate_pick'] ?? null;
        if ($gates && $gates['owner'] === $viewerKey
            && \Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\GatePickChoice) {
            $targets = [];
            foreach ($gates['candidates'] as $key) {
                [$row, $col] = array_map('intval', explode('_', $key));
                $targets[$key] = ['row'=>$row, 'col'=>$col,
                    'url'=>$baseUrl . '&cmd=choose_gate&row=' . $row . '&col=' . $col];
            }
            return $targets;
        }
        $door = $state->battle['pending_teleport_target'] ?? null;
        if ($door && ($door['owner'] ?? null) === $viewerKey
            && \Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\TeleportTargetChoice) {
            $targets = [];
            foreach ($door['cells'] as $cell) {
                $row = (int) $cell['row']; $col = (int) $cell['col']; $key = $row . '_' . $col;
                $targets[$key] = ['row' => $row, 'col' => $col,
                    'url' => $baseUrl . '&cmd=choose_teleport_cell&cell=' . $key];
            }
            return $targets;
        }
        $teleport = $state->battle[\Berserk\Core\RangedNeighborTeleport::PENDING] ?? null;
        if ($teleport && $teleport['owner'] === $viewerKey && $teleport['stage'] === 'cell'
            && \Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\RangedNeighborTeleportChoice) {
            $targets = [];
            foreach ((new \Berserk\Core\RangedNeighborTeleport($state, new \Berserk\Core\Engine()))->cells($teleport) as $key) {
                [$row, $col] = array_map('intval', explode('_', $key));
                $targets[$key] = ['row' => $row, 'col' => $col, 'url' => $baseUrl
                    . '&cmd=choose_ranged_neighbor_teleport&choice_id=' . rawurlencode($teleport['id']) . '&operation=select&cell=' . $key];
            }
            return $targets;
        }
        $pending = $state->battle['pending_cell_marker_pick'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $viewerKey) return [];
        if (!(\Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\CellMarkerChoice)) return [];
        $targets = [];
        foreach ((array) ($pending['cells'] ?? []) as $key) {
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
        if(($state->battle[\Berserk\Core\Tremor::PENDING]['mode']??null)==='move'&&\Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\TremorChoice)return;
        $pickerKey = self::identity($state, $viewerKey);
        $pickerTargets = [];
        $targetsByUrl = [];
        foreach (self::targets($state, $viewerKey, $baseUrl) as $target) {
            $targetsByUrl[$target['url']] = $target;
        }
        // Decorate existing cell actions in place. Preserve URLs, order and other actions
        // (especially cancellation) exactly as supplied by the choice handler.
        foreach ($spec->buttons as &$button) {
            if (($button['class'] ?? '') === 'tremor-stay') continue;
            $target = $targetsByUrl[$button['url']] ?? null;
            if ($target === null) continue;
            $pos = BattlefieldPosition::display($target['row'], $target['col'], $viewerKey);
            if ($pickerKey !== '') {
                $pickerTargets[] = $target + ['side' => $pos['side'], 'game_row' => $pos['row'], 'game_col' => $pos['col']];
                $button['cell_marker_target'] = true;
            }
            $button['label'] = (($state->battle['pending_cell_marker_pick']['marker'] ?? '') === 'bonfire' ? '🔥 ' : '')
                . ($pos['side'] === 'own' ? 'Свой' : 'Вражеский') . ' ' . $pos['row'] . '-й ряд · столбец ' . $pos['col'];
            $classes = preg_split('/\s+/', trim(($button['class'] ?? '') . ' cell-marker-choice coords--' . $pos['side']));
            $button['class'] = implode(' ', array_unique($classes));
        }
        unset($button);
        $spec->cellPicker = $pickerKey !== '' && $pickerTargets !== []
            ? ['id' => $pickerKey, 'targets' => $pickerTargets, 'title' => isset($state->battle['pending_gate_pick']) ? 'Выберите клетку для врат' : (isset($state->battle[\Berserk\Core\RangedNeighborTeleport::PENDING]) || isset($state->battle['pending_teleport_target']) ? 'Выберите клетку для телепортации' : '🔥 Выберите клетку для костра')] : null;
    }

    /** Snapshot identity only; no new pending state is written. */
    public static function identity(GameState $state, string $viewerKey): string
    {
        $pi = $state->battle['pending_incarnation'] ?? null;
        if ($pi && !\Berserk\Core\WormIncarnation::operation($state) && $pi['owner'] === $viewerKey
            && \Berserk\Core\Tremor::incarnationEnabled($state->getCard($pi['current']))
            && \Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\IncarnationChoice) {
            return hash('sha256', json_encode([$state->gameId,$state->version,$viewerKey,$pi], JSON_THROW_ON_ERROR));
        }

        $tremor = $state->battle[\Berserk\Core\Tremor::PENDING] ?? null;
        if ($tremor && $tremor['owner'] === $viewerKey
            && \Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\TremorChoice) {
            return hash('sha256', json_encode([$state->gameId, $state->version, $viewerKey, $tremor], JSON_THROW_ON_ERROR));
        }

        $gates = $state->battle['pending_gate_pick'] ?? null;
        if ($gates && $gates['owner'] === $viewerKey
            && \Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\GatePickChoice) {
            return hash('sha256', json_encode([$state->gameId, $state->version, $viewerKey, $gates], JSON_THROW_ON_ERROR));
        }
        $door = $state->battle['pending_teleport_target'] ?? null;
        if ($door && ($door['owner'] ?? null) === $viewerKey
            && \Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\TeleportTargetChoice) {
            return hash('sha256', json_encode([$state->gameId, $state->version, $viewerKey, $door], JSON_THROW_ON_ERROR));
        }
        $teleport = $state->battle[\Berserk\Core\RangedNeighborTeleport::PENDING] ?? null;
        if ($teleport && $teleport['owner'] === $viewerKey && $teleport['stage'] === 'cell'
            && \Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\RangedNeighborTeleportChoice) {
            return hash('sha256', json_encode([$state->gameId, $state->version, $viewerKey, $teleport], JSON_THROW_ON_ERROR));
        }
        $pending = $state->battle['pending_cell_marker_pick'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $viewerKey || ($pending['marker'] ?? '') !== 'bonfire'
            || !(\Berserk\Core\Choice\ChoiceRegistry::current($state) instanceof \Berserk\Core\Choice\CellMarkerChoice)) return '';
        $snapshot = json_encode([$state->gameId, $state->version, $viewerKey, $pending], JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($snapshot) ? hash('sha256', $snapshot) : '';
    }

    public static function render(array $picker): string
    {
        $groups = [];
        foreach ($picker['targets'] as $target) {
            $groups[$target['side']][$target['game_row']][$target['game_col']] = $target;
        }
        $html = '<div class="cell-picker-compact" data-cell-marker-compact hidden>'
            . '<p class="cell-picker-title">' . htmlspecialchars($picker['title'] ?? '🔥 Выберите клетку для костра', ENT_QUOTES, 'UTF-8') . '</p>'
            . '<div class="cell-picker-controls" role="group" aria-label="Сторона">';
        foreach (['own' => 'Свои', 'enemy' => 'Вражеские'] as $side => $label) {
            $disabled = empty($groups[$side]) ? ' disabled' : '';
            $html .= '<button type="button" class="coords--' . $side . '" data-picker-side="' . $side
                . '" aria-pressed="false"' . $disabled . '>' . $label . '</button>';
        }
        $html .= '</div><div class="cell-picker-controls" role="group" aria-label="Ряд">';
        foreach ([1, 2, 3] as $row) {
            $html .= '<button type="button" data-picker-row="' . $row . '" aria-pressed="false">'
                . Coords::rowLabel($row) . '</button>';
        }
        $html .= '</div><div role="group" aria-label="Доступные столбцы">';
        foreach (['own', 'enemy'] as $side) {
            foreach ([1, 2, 3] as $row) {
                if (empty($groups[$side][$row])) continue;
                ksort($groups[$side][$row]);
                $html .= '<div class="cell-picker-columns" data-picker-group="' . $side . ':' . $row . '" hidden>';
                foreach ($groups[$side][$row] as $col => $target) {
                    $url = htmlspecialchars($target['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $key = $target['row'] . '_' . $target['col'];
                    $label = ($side === 'own' ? 'Свой' : 'Вражеский') . ' ряд ' . Coords::rowLabel($row) . ', столбец ' . $col;
                    $html .= '<a class="button" data-picker-column="' . $key . '" href="' . $url
                        . '" aria-label="' . $label . '">' . $col . '</a>';
                }
                $html .= '</div>';
            }
        }
        return $html . '</div></div>';
    }
}
