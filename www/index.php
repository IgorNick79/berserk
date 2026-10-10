<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Db;
use Berserk\Core\GameRepository;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\CardInstance;
use Berserk\Core\GameState;
use Berserk\Core\View;
use Berserk\Core\DeckView;
use Berserk\Core\ResourceCalculator;
use Berserk\Core\BattleHelper;
use Berserk\Core\ZoneManager;
use Berserk\Core\StaleStateException;
use Berserk\Core\Prepare\DraftTimer;
use Berserk\Core\Prepare\PrepareProcessor;

use Berserk\View\Template;
use Berserk\View\Screen\ModeScreen;
use Berserk\View\Screen\ViewScreen;
use Berserk\View\Screen\DeckScreen;
use Berserk\View\Screen\TurnScreen;
use Berserk\View\Screen\SideScreen;
use Berserk\View\Screen\DealScreen;
use Berserk\View\Screen\PlaceScreen;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Screen\BattleSnapshotRenderer;
use Berserk\View\Screen\DraftScreen;
use Berserk\View\Screen\SettingsScreen;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function ajaxParamString(string $key, int $maxLength = 128): ?string
{
    return requestParamString($_GET, $key, $maxLength);
}

function ajaxPostParamString(string $key, int $maxLength = 128): ?string
{
    return requestParamString($_POST, $key, $maxLength);
}

function requestParamString(array $source, string $key, int $maxLength = 128): ?string
{
    if (!array_key_exists($key, $source) || is_array($source[$key])) {
        return null;
    }

    $value = (string) $source[$key];
    if (strlen($value) > $maxLength) {
        return null;
    }

    return $value;
}

function ajaxParamInt(string $key): ?int
{
    return requestParamInt($_GET, $key);
}

function ajaxPostParamInt(string $key): ?int
{
    return requestParamInt($_POST, $key);
}

function requestParamInt(array $source, string $key): ?int
{
    $value = requestParamString($source, $key, 32);
    if ($value === null || !preg_match('/^-?\d+$/', $value)) {
        return null;
    }

    return (int) $value;
}

function ajaxFlag(string $key): bool
{
    $value = ajaxParamString($key, 16);
    if ($value === null) {
        return false;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function ajaxCommandPayload(): ?array
{
    $payload = [];
    $excluded = [
        'cmd' => true,
        'expected_version' => true,
        'sel' => true,
        'mode' => true,
        'pile' => true,
    ];

    foreach ($_POST as $key => $value) {
        if (!is_string($key) || strlen($key) > 64) {
            return null;
        }
        if (isset($excluded[$key])) {
            continue;
        }
        if (is_array($value)) {
            return null;
        }

        $stringValue = (string) $value;
        if (strlen($stringValue) > 1024) {
            return null;
        }

        $payload[$key] = $stringValue;
    }

    return $payload;
}

function sendAjaxJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sendBattleAjaxState(
    Db $db,
    Template $tpl,
    GameState $state,
    string $playerKey,
    string $role,
    array $uiState,
    bool $ok = true,
    string $type = 'snapshot',
    ?string $error = null,
    int $statusCode = 200
): never {
    $syncVersion = $state->persistenceVersion ?? 0;

    if ($state->status !== 'battle') {
        $payload = [
            'ok' => $ok,
            'type' => $type === 'conflict' ? 'conflict' : 'status',
            'sync_version' => $syncVersion,
            'status' => $state->status,
        ];
        if ($error !== null) {
            $payload['error'] = $error;
        }
        sendAjaxJson($payload, $statusCode);
    }

    [$cardsInfo] = buildRenderCardInfo($db, $state);
    $snapshot = (new BattleSnapshotRenderer($tpl))->render(
        $state,
        $playerKey,
        $role,
        null,
        $cardsInfo,
        $uiState
    );

    $payload = [
        'ok' => $ok,
        'type' => $type,
        'sync_version' => $syncVersion,
        'status' => $state->status,
        'ui' => $snapshot['ui'],
        'fragments' => $snapshot['fragments'],
    ];
    if ($error !== null) {
        $payload['error'] = $error;
    }

    sendAjaxJson($payload, $statusCode);
}

function reloadPersistedBattleAjaxState(GameRepository $repo, int $gameId): GameState
{
    $fresh = $repo->findById($gameId);
    if ($fresh === null) {
        sendAjaxJson([
            'ok' => false,
            'error' => 'server_error',
        ], 500);
    }

    return $fresh;
}

function buildRenderCardInfo(Db $db, GameState $state): array
{
    $cardsInfo = [];

    $ukids = array_map(fn ($c) => $c->ukid, $state->cards);

    if (!empty($state->draft)) {
        foreach ($state->draft['grid'] ?? [] as $u) if ($u !== null) $ukids[] = $u;
        foreach ($state->draft['pool'] ?? [] as $u) if ($u !== null) $ukids[] = $u;
        foreach ($state->draft['picked']['host']   ?? [] as $u) $ukids[] = $u;
        foreach ($state->draft['picked']['player'] ?? [] as $u) $ukids[] = $u;
    }

    foreach (['host', 'player'] as $key) {
        $player = $state->getPlayer($key);
        foreach ($player->deckCards ?? [] as $dc) {
            if (!empty($dc['ukid'])) $ukids[] = $dc['ukid'];
        }
    }

    $ukids = array_unique($ukids);

    $elements = [];
    $elementLabels = [];
    foreach ($db->fetchAll("SELECT ind, code, name FROM elements") as $e) {
        $elements[(int) $e['ind']] = (string) $e['name'];
        $elementLabels[(string) $e['code']] = (string) $e['name'];
    }

    if (!empty($ukids)) {
        $in = "'" . implode("','", array_map(fn ($u) => $db->escape($u), $ukids)) . "'";
        $rows = $db->fetchAll(
            "SELECT ukid, name, price, health, move, elite,
                    strike_weak, strike_medium, strike_strong,
                    element_id
             FROM cards WHERE ukid IN ($in)"
        );
        foreach ($rows as $r) {
            $cardsInfo[$r['ukid']] = [
                'name'    => $r['name'],
                'price'   => (int) $r['price'],
                'health'  => (int) $r['health'],
                'move'    => (int) $r['move'],
                'elite'   => (bool) $r['elite'],
                'element' => $elements[(int) $r['element_id']] ?? '—',
                'strike'  => [
                    'weak'   => (int) $r['strike_weak'],
                    'medium' => (int) $r['strike_medium'],
                    'strong' => (int) $r['strike_strong'],
                ],
            ];
        }
    }

    return [$cardsInfo, $elementLabels];
}

$ajax = ajaxParamString('ajax', 32);
$isBattleSync = $ajax === 'battle_sync';
$isBattleCommand = $ajax === 'battle_command';

$config = require __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// в начале index.php, после session_start()
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    $_SESSION['debug'] = 1;
}
if (isset($_GET['debug']) && $_GET['debug'] === '0') {
    unset($_SESSION['debug']);
}

// Debug roll: ?debug_roll=6,1,4 или ?debug_roll=6* или ?debug_roll=off
if (isset($_GET['debug_roll'])) {
    $v = (string) $_GET['debug_roll'];
    unset($_SESSION['debug_roll_state']);
    if ($v === '' || $v === 'off') {
        unset($_SESSION['debug_roll']);
    } else {
        $_SESSION['debug_roll'] = $v;
    }
}
\Berserk\Core\Dice::init();

try {
    $db = new Db($config);
} catch (Throwable $e) {
    if ($isBattleSync || $isBattleCommand) {
        sendAjaxJson([
            'ok' => false,
            'error' => 'server_error',
        ], 500);
    }

    throw $e;
}
$repo    = new GameRepository($db);
$engine   = new Engine($db);
$deckView = new DeckView($db);
$tpl     = new Template(__DIR__ . '/../templates', '/');

// ─── Сброс сессии ─────────────────────────────────────────────
if (($_SERVER['REQUEST_URI'] ?? '') === '/reset') {
    session_destroy();
    header('Location: /');
    exit;
}

// ─── Определяем роль ──────────────────────────────────────────
$role = null;
if (isset($_GET['first'])) {
    $role = 'host';
} elseif (isset($_GET['second'])) {
    $role = 'player';
}

if ($role === null) {
    if ($isBattleSync || $isBattleCommand) {
        sendAjaxJson([
            'ok' => false,
            'error' => 'missing_player_role',
        ], 400);
    }

    echo '<h1>Berserk</h1>';
    echo '<p><a href="?first">Создать партию (я первый)</a></p>';
    echo '<p><a href="?second">Присоединиться (я второй)</a></p>';
    exit;
}

$userId = $role === 'host' ? 1 : 2;

// ─── Находим или создаём партию ──────────────────────────────
$isBattleAjax = $isBattleSync || $isBattleCommand;
$gameId = $isBattleAjax
    ? (ajaxParamInt('game') ?? 0)
    : (isset($_GET['game']) && !is_array($_GET['game']) ? (int) $_GET['game'] : 0);

if ($isBattleAjax && $gameId <= 0) {
    sendAjaxJson([
        'ok' => false,
        'error' => 'invalid_game',
    ], 400);
}

if ($gameId > 0) {
    $state = $repo->findById($gameId);
    if (!$state) {
        if ($isBattleAjax) {
            sendAjaxJson([
                'ok' => false,
                'error' => 'game_not_found',
            ], 404);
        }

        http_response_code(404);
        echo "Партия #$gameId не найдена";
        exit;
    }
} else {
    $state = $repo->create(1, 2);
    $url = $role === 'host' ? '?first' : '?second';
    header("Location: $url&game={$state->gameId}");
    exit;
}

if ($userId !== $state->hostId && $userId !== $state->playerId) {
    if ($isBattleAjax) {
        sendAjaxJson([
            'ok' => false,
            'error' => 'forbidden',
        ], 403);
    }

    http_response_code(403);
    echo "Вы не участник партии #$gameId";
    exit;
}

$_SESSION['user_id'] = $userId;
$_SESSION['game_id'] = $state->gameId;
$_SESSION['role']    = $role;

$playerKey = $role === 'host' ? 'host' : 'player';
$oppKey    = $state->getOpponentKey($playerKey);

if ($isBattleSync) {
    try {
        $syncVersion = $state->persistenceVersion ?? 0;
        $sinceVersion = ajaxParamInt('since_version');
        $forceSnapshot = ajaxFlag('force_snapshot');

        if ($state->status !== 'battle') {
            sendAjaxJson([
                'ok' => true,
                'type' => 'status',
                'sync_version' => $syncVersion,
                'status' => $state->status,
            ]);
        }

        if (!$forceSnapshot && $sinceVersion !== null && $sinceVersion === $syncVersion) {
            sendAjaxJson([
                'ok' => true,
                'type' => 'no_change',
                'sync_version' => $syncVersion,
            ]);
        }

        sendBattleAjaxState(
            $db,
            $tpl,
            $state,
            $playerKey,
            $role,
            [
                'sel' => ajaxParamInt('sel') ?? 0,
                'mode' => ajaxParamString('mode', 64) ?? '',
                'pile' => ajaxParamString('pile', 32) ?? '',
            ]
        );
    } catch (Throwable) {
        sendAjaxJson([
            'ok' => false,
            'error' => 'server_error',
        ], 500);
    }
}

if ($isBattleCommand) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        sendAjaxJson([
            'ok' => false,
            'error' => 'method_not_allowed',
        ], 405);
    }

    try {
        $expectedVersion = ajaxPostParamInt('expected_version');
        if ($expectedVersion === null) {
            sendAjaxJson([
                'ok' => false,
                'error' => 'missing_expected_version',
            ], 400);
        }

        $uiState = [
            'sel' => ajaxPostParamInt('sel') ?? 0,
            'mode' => ajaxPostParamString('mode', 64) ?? '',
            'pile' => ajaxPostParamString('pile', 32) ?? '',
        ];

        $currentVersion = $state->persistenceVersion ?? 0;
        if ($expectedVersion !== $currentVersion) {
            $fresh = reloadPersistedBattleAjaxState($repo, $state->gameId);
            sendBattleAjaxState(
                $db,
                $tpl,
                $fresh,
                $playerKey,
                $role,
                $uiState,
                false,
                'conflict',
                'State changed, refresh required',
                409
            );
        }

        if ($state->status !== 'battle') {
            sendBattleAjaxState(
                $db,
                $tpl,
                $state,
                $playerKey,
                $role,
                $uiState,
                false,
                'status',
                'Battle is not active'
            );
        }

        $commandType = ajaxPostParamString('cmd', 64);
        $payload = ajaxCommandPayload();
        if ($commandType === null || $commandType === '' || $payload === null) {
            $fresh = reloadPersistedBattleAjaxState($repo, $state->gameId);
            sendBattleAjaxState(
                $db,
                $tpl,
                $fresh,
                $playerKey,
                $role,
                $uiState,
                false,
                'snapshot',
                'Invalid command request'
            );
        }

        $result = $engine->apply($state, $playerKey, new Command($commandType, $payload));
        if (!$result->success) {
            $fresh = reloadPersistedBattleAjaxState($repo, $state->gameId);
            sendBattleAjaxState(
                $db,
                $tpl,
                $fresh,
                $playerKey,
                $role,
                $uiState,
                false,
                'snapshot',
                $result->error !== '' ? $result->error : 'Command cannot be performed'
            );
        }

        try {
            $repo->save($state);
        } catch (StaleStateException) {
            $fresh = reloadPersistedBattleAjaxState($repo, $state->gameId);
            sendBattleAjaxState(
                $db,
                $tpl,
                $fresh,
                $playerKey,
                $role,
                $uiState,
                false,
                'conflict',
                'State changed, refresh required',
                409
            );
        }

        $committed = reloadPersistedBattleAjaxState($repo, $state->gameId);
        sendBattleAjaxState($db, $tpl, $committed, $playerKey, $role, $uiState);
    } catch (Throwable) {
        sendAjaxJson([
            'ok' => false,
            'error' => 'server_error',
        ], 500);
    }
}

// ─── Обработка команды ────────────────────────────────────────
$message = null;
$commandType = $_GET['cmd'] ?? '';

if ($commandType === 'draft_sync') {
    $changed = false;
    $events = [];
    $result = (new PrepareProcessor($state, $db))->resolveDraftTimeouts();
    if ($result->success && !empty($result->events)) {
        $changed = true;
        $events = $result->events;
        try {
            $repo->save($state);
        } catch (StaleStateException) {
            $state = $repo->findById($state->gameId) ?? $state;
            $changed = true;
            $events = ['stale_reloaded'];
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $result->success,
        'changed' => $changed,
        'version' => $state->version,
        'status' => $state->status,
        'events' => $events,
        'timer' => $state->draft !== null ? DraftTimer::snapshot($state, $playerKey, time()) : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($commandType !== '') {
    $payload = $_GET;

    // Для select_deck — резолвим список дек и составы выбранных колод
    $canResolveDeckSelection = $commandType === 'select_deck'
        && $playerKey === 'host'
        && $state->status === 'deck'
        && $state->getPlayer('host')->deckId === null;

    if ($canResolveDeckSelection) {
        $starters = $deckView->listStarters();
        $deckIds  = array_map('intval', array_column($starters, 'ind'));

        $deckMode = (string) ($_GET['deck_mode'] ?? 'manual');
        $otherMode = (string) ($_GET['other_deck_mode'] ?? 'manual');
        $selected = (int) ($_GET['deck_id'] ?? 0);
        $other = (int) ($_GET['other_deck_id'] ?? 0);

        if ($deckMode === 'random' && $deckIds !== []) {
            $selected = $deckIds[random_int(0, count($deckIds) - 1)];
        }
        if ($otherMode === 'random' && $deckIds !== []) {
            $other = $deckIds[random_int(0, count($deckIds) - 1)];
        }

        $payload['deck_mode'] = $deckMode;
        $payload['other_deck_mode'] = $otherMode;
        $payload['deck_id'] = $selected;
        $payload['valid_deck_ids'] = $deckIds;
        $payload['other_deck_id']  = $other;

        $mapDeckCards = static fn(array $deck): array => array_map(
            static fn ($c) => [
                'ukid'    => $c['ukid'],
                'count'   => $c['count'],
                'price'   => $c['price'],
                'elite'   => $c['elite'],
                'single'  => $c['single'] ?? false,
                'element' => $c['element'],
                'health'  => $c['health'],
                'move'    => $c['move'],
                'strike_weak'   => $c['strike']['weak'],
                'strike_medium' => $c['strike']['medium'],
                'strike_strong' => $c['strike']['strong'],
                'prop'          => $c['prop'],
                'type'          => $c['type'],
                'class'         => $c['class'],
            ],
            $deck['cards'] ?? []
        );

        // Состав дек в формате [{ukid, count}]
        $payload['deck_cards'] = in_array($selected, $deckIds, true)
            ? $mapDeckCards($deckView->forDeck($selected))
            : [];
        $payload['other_cards'] = in_array($other, $deckIds, true)
            ? $mapDeckCards($deckView->forDeck($other))
            : [];
    }

    $cmd = new Command($commandType, $payload);
    $result = $engine->apply($state, $playerKey, $cmd);

    if ($result->success) {
        try {
            $repo->save($state);
        } catch (StaleStateException) {
            $_SESSION['flash_message'] = 'Ошибка: состояние партии уже изменилось, обновляю страницу';
            $roleParam = ($role === 'host') ? 'first' : 'second';
            header('Location: ?' . $roleParam . '&game=' . $state->gameId);
            exit;
        }

        // PRG: редирект без cmd, чтобы F5 не выполнил команду повторно
        $roleParam = ($role === 'host') ? 'first' : 'second';
        $redirectUrl = '?' . $roleParam . '&game=' . $state->gameId;

        // Если был выбран режим — сохраним его
        if (isset($_GET['mode']) && $_GET['mode'] !== '') {
            $redirectUrl .= '&mode=' . urlencode((string) $_GET['mode']);
        }
        if (isset($_GET['sel']) && $_GET['sel'] !== '') {
            $redirectUrl .= '&sel=' . (int) $_GET['sel'];
        }
        if (isset($_GET['card']) && $_GET['card'] !== '') {
            $redirectUrl .= '&card=' . urlencode((string) $_GET['card']);
        }

        if (isset($_GET['pile']) && $_GET['pile'] !== '') {
            $redirectUrl .= '&pile=' . urlencode((string) $_GET['pile']);
        }

        // Одноразовое сообщение через сессию
        $_SESSION['flash_message'] = 'OK: ' . implode(', ', $result->events);

        header("Location: $redirectUrl");
        exit;
    }

    $message = 'Ошибка: ' . $result->error;
}

if ($commandType === '' && $state->status === 'draft') {
    $timeoutResult = (new PrepareProcessor($state, $db))->resolveDraftTimeouts();
    if ($timeoutResult->success && !empty($timeoutResult->events)) {
        try {
            $repo->save($state);
        } catch (StaleStateException) {
            $state = $repo->findById($state->gameId) ?? $state;
        }
    }
}

// Читаем flash-сообщение
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// ─── Готовим данные для рендера ──────────────────────────────
$me  = $state->getPlayer($playerKey);
$opp = $state->getPlayer($oppKey);

// Определяем, что рендерить
$screenName = null;
$screenData = [];
$scriptsHtml = '';

// Загружаем все карты, которые есть в партии — для рендера
[$cardsInfo, $elementLabels] = buildRenderCardInfo($db, $state);

switch ($state->status) {
    case 'mode':
        $result = (new ModeScreen($tpl))
            ->prepare($state, $playerKey, $role, $message);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        break;

    case 'settings':
        $result = (new SettingsScreen())
            ->prepare($state, $playerKey, $role, $message);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        break;

    case 'draft':
        $result = (new DraftScreen($tpl))
            ->prepare($state, $playerKey, $role, $message, $cardsInfo, $elementLabels);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        break;

    case 'deck':
        $result = (new DeckScreen($deckView, $tpl))
            ->prepare($state, $playerKey, $role, $message);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        break;

    case 'view':
        $result = (new ViewScreen($deckView, $tpl))
            ->prepare($state, $playerKey, $role, $message, $cardsInfo, $elementLabels);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        break;

    case 'turn':
        $result = (new TurnScreen())
            ->prepare($state, $playerKey, $role, $message);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        break;

    case 'side':
        $result = (new SideScreen())
            ->prepare($state, $playerKey, $role, $message);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        break;

    case 'deal':
        $result = (new DealScreen($tpl))
            ->prepare($state, $playerKey, $role, $message, $cardsInfo, $elementLabels);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        break;

    case 'place':
        $result = (new PlaceScreen($tpl))
            ->prepare($state, $playerKey, $role, $message, $cardsInfo);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        break;

    case 'battle':
        $result = (new BattleScreen($tpl))
            ->prepare($state, $playerKey, $role, $message, $cardsInfo);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        $roleParam = $role === 'host' ? 'first' : 'second';
        $syncUrl = '?' . $roleParam . '&game=' . $state->gameId . '&ajax=battle_sync';
        $ui = $screenData['ui'] ?? ['sel' => 0, 'mode' => 'strike', 'pile' => ''];
        $scriptsHtml = '<script src="/assets/js/battle-sync.js?v={{rkey}}"'
            . ' data-sync-url="' . htmlspecialchars($syncUrl, ENT_QUOTES) . '"'
            . ' data-game-id="' . (int) $state->gameId . '"'
            . ' data-role="' . htmlspecialchars($role, ENT_QUOTES) . '"'
            . ' data-version="' . (int) ($state->persistenceVersion ?? 0) . '"'
            . ' data-sel="' . (int) ($ui['sel'] ?? 0) . '"'
            . ' data-mode="' . htmlspecialchars((string) ($ui['mode'] ?? 'strike'), ENT_QUOTES) . '"'
            . ' data-pile="' . htmlspecialchars((string) ($ui['pile'] ?? ''), ENT_QUOTES) . '"'
            . ' defer></script>';
        break;

    case 'game_over':
        $screenName = 'game_over';

        $youWon = ($state->winner === $playerKey);

        $screenData = [
            'text'    => $youWon ? 'Победа!' : 'Поражение',
            'message' => $message ?? '',
        ];
        break;

    default:
        $screenName = 'debug';
        $screenData = [
            'status'  => $state->status,
            'game_id' => $state->gameId,
            'role'    => $role,
            'deck_id' => $me->deckId ?? '—',
            'message' => $message ?? '',
        ];
        break;
}

// ─── Рендерим ────────────────────────────────────────────────
$screenHtml = $tpl->parse("screen/{$screenName}.tpl", $screenData);

$page = $tpl->parse('page.tpl', [
    'game_id' => $state->gameId,
    'role'    => $role,
    'screen'  => $screenHtml,
    'scripts' => $scriptsHtml,
]);

echo $page;
