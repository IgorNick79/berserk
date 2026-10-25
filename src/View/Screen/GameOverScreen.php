<?php
declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\View\Template;

final class GameOverScreen
{
    public function __construct(private Template $tpl) {}

    public function prepare(GameState $state, string $playerKey, bool $journalAllowed): array
    {
        $won = $state->winner === $playerKey;
        $outcome = $won ? 'victory' : 'defeat';
        $reason = match ($state->battle['result_reason'] ?? null) {
            'resign' => $won ? 'Соперник сдался.' : 'Вы сдались.',
            'elimination' => $won ? 'Все существа соперника уничтожены.' : 'Все ваши существа уничтожены.',
            default => 'Бой завершён.',
        };
        $start = (int) ($state->battle['started_at'] ?? 0);
        $finish = (int) ($state->battle['finished_at'] ?? 0);
        $seconds = $start > 0 && $finish >= $start ? $finish - $start : null;
        $duration = $seconds === null ? '—' : ($seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60));
        $history = $this->tpl->parse('includes/battle/control_icon.tpl', [
            'file' => 'game_log_160.png', 'label' => 'История боя',
        ]);
        return [
            'outcome' => $outcome,
            'text' => $won ? 'Победа' : 'Поражение',
            'reason' => $reason,
            'duration' => $duration,
            'turns' => isset($state->battle['turn_timer']['period_id']) ? (string) max(0, (int) $state->battle['turn_timer']['period_id']) : '—',
            'history_icon' => $history,
            'history_disabled' => $journalAllowed ? '' : 'disabled aria-disabled="true"',
        ];
    }
}
