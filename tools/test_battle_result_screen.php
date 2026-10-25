<?php
declare(strict_types=1);
$repo = getenv('BERSERK_RESULT_REPO') ?: dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($repo): void {
    if (!str_starts_with($class, 'Berserk\\')) return;
    $file = str_replace('\\', '/', substr($class, 8)) . '.php';
    foreach ([__DIR__ . '/../src/', $repo . '/src/'] as $root) {
        if (is_file($root . $file)) { require_once $root . $file; return; }
    }
});
use Berserk\Core\{GameState, Engine, Command, DamageResolver, TurnProcessor};
use Berserk\View\{Template};
use Berserk\View\Screen\GameOverScreen;
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$tpl = new Template($repo . '/templates');
$screen = new GameOverScreen($tpl);
$s = new GameState(42, 1, 2);
$s->status = 'game_over'; $s->winner = 'host';
$s->battle = ['turn'=>9, 'turn_timer'=>['period_id'=>17], 'started_at'=>100, 'finished_at'=>3865, 'result_reason'=>'resign'];
$data = $screen->prepare($s, 'host', true);
check($data['duration'] === '1:02:45' && $data['turns'] === '17', 'Duration and individual turns');
check($data['outcome'] === 'victory' && $data['reason'] === 'Соперник сдался.', 'Winner resign reason');
$loss = $screen->prepare($s, 'player', false);
check($loss['reason'] === 'Вы сдались.' && str_contains($loss['history_disabled'], 'disabled'), 'Loser and history access');
$s->battle['result_reason'] = 'elimination';
check($screen->prepare($s, 'player', true)['reason'] === 'Все ваши существа уничтожены.', 'Elimination reason');
$s->battle = [];
check($screen->prepare($s, 'host', true)['duration'] === '—', 'Legacy duration unavailable');
$s->status = 'battle'; $s->winner = null; $s->battle = ['active'=>'host','turn'=>1];
$method = new ReflectionMethod(Engine::class, 'resign');
$method->setAccessible(true);
$result = $method->invoke(new Engine(), $s, 'player', new Command('resign'));
check($result->success && $s->battle['result_reason'] === 'resign' && $s->battle['finished_at'] > 0, 'Resign persists terminal metadata');
$end = $s->battle['finished_at'];
(new DamageResolver($s))->checkGameOver();
check($s->battle['finished_at'] === $end && $s->battle['result_reason'] === 'resign', 'Terminal metadata remains stable');
$s->status = 'battle'; $s->winner = null; $s->battle = [];
(new DamageResolver($s))->checkGameOver();
check($s->battle['result_reason'] === 'elimination' && $s->battle['finished_at'] > 0, 'Elimination persists terminal metadata');
if (in_array('--fixture', $argv, true)) {
    $s->battle = ['turn'=>9, 'turn_timer'=>['period_id'=>17], 'started_at'=>100, 'finished_at'=>865, 'result_reason'=>'resign'];
    $s->winner = 'host';
    $data = $screen->prepare($s, 'host', true);
    $html = file_get_contents(__DIR__ . '/../templates/screen/game_over.tpl');
    foreach ($data as $key=>$value) $html = str_replace('{{'.$key.'}}', $value, $html);
    $menu = \Berserk\View\Ui\GameMenu::preparation('test', true, true, $s, 'host');
    $menu = str_replace(['<summary aria-label=', '>☰</summary>', 'class="game-menu-panel"'],
        ['<summary class="battle-menu" aria-label=', '><span class="battle-menu-icon"><i></i><i></i><i></i></span></summary>', 'class="game-menu-panel battle-menu-panel"'], $menu);
    $html .= $tpl->parse('includes/game_chat.tpl', ['chat_game_id'=>42,'chat_sender_id'=>1,'chat_csrf'=>'test']);
    $html .= '<link rel="stylesheet" href="/assets/css/battle-chat.css"><script src="/assets/js/battle-chat.js" defer></script>';
    echo '<!doctype html><html class="battle-page result-page"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/global.css"></head><body class="battle-page result-page"><div class="screen">'.$menu.$html.'</div></body></html>';
} else echo "PASS: result statistics, reasons, history permissions, legacy data, terminal persistence\n";
