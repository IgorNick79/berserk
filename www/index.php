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
use Berserk\Core\TurnTimer;
use Berserk\Core\AutomaticBattleProgress;
use Berserk\Core\MatchPause;
use Berserk\Core\MatchPlayerSettings;
use Berserk\Core\AutomaticMatchPause;
use Berserk\Core\View;
use Berserk\Core\DeckView;
use Berserk\Core\ResourceCalculator;
use Berserk\Core\BattleHelper;
use Berserk\Core\ZoneManager;
use Berserk\Core\StaleStateException;
use Berserk\Core\CommandJournalException;
use Berserk\Core\DbQueryException;
use Berserk\Core\CommandJournalRepository;
use Berserk\Core\TransactionCommitUnknownException;
use Berserk\Core\TransactionRollbackUnknownException;
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
    if (count($_POST) > 64 || strlen(http_build_query($_POST, '', '&')) > 16384) return null;
    $payload = [];
    $commandType = requestParamString($_POST, 'cmd', 64) ?? '';
    $idListCommands = [
        'choose_multi_discharge', 'choose_multi_heal', 'choose_row_spell_targets',
        'choose_anubisar_chain', 'choose_incarnation_wound', 'choose_intrigue_start',
    ];
    $excluded = [
        'cmd' => true,
        'expected_version' => true,
        'command_id' => true,
        'sel' => true,
        'pile' => true,
    ];
    if ($commandType !== 'choose_strike_mode') {
        $excluded['mode'] = true;
    }

    foreach ($_POST as $key => $value) {
        if (!is_string($key) || strlen($key) > 64) {
            return null;
        }
        if (isset($excluded[$key])) {
            continue;
        }
        if (is_array($value)) {
            if ($key !== 'target_ids' || !in_array($commandType, $idListCommands, true)
                || !array_is_list($value) || count($value) > 64) return null;
            foreach ($value as $id) {
                if ((!is_string($id) && !is_int($id))
                    || !preg_match('/^[1-9][0-9]*$/D', (string) $id)
                    || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) return null;
            }
            $payload[$key] = array_map('strval', $value);
            continue;
        }

        if (!is_string($value) && !is_int($value)) return null;

        $stringValue = (string) $value;
        if (strlen($stringValue) > 1024) {
            return null;
        }

        $payload[$key] = $stringValue;
    }

    return $payload;
}

function requestCommandId(array $source): array
{
    if (!array_key_exists('command_id', $source)) {
        return ['present' => false, 'command_id' => null, 'error' => 'missing_command_id'];
    }

    $commandId = requestParamString($source, 'command_id', 64);
    if ($commandId === null || $commandId === '' || !isValidAjaxCommandId($commandId)
        || str_starts_with(strtolower($commandId), 'p2a:') || str_starts_with(strtolower($commandId), 't3e:')
        || str_starts_with(strtolower($commandId), 'mpx:')) {
        return ['present' => true, 'command_id' => null, 'error' => 'invalid_command_id'];
    }

    return ['present' => true, 'command_id' => $commandId, 'error' => null];
}

function isValidAjaxCommandId(string $commandId): bool
{
    return preg_match('/^[A-Za-z0-9_.:-]+$/', $commandId) === 1;
}

function sendAjaxJson(array $payload, int $statusCode = 200): never
{
    // No-JS surrender uses the SAME journalled endpoint; only its presentation differs.
    $htmlResign = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && ($_GET['ajax'] ?? null) === 'battle_command' && ($_POST['cmd'] ?? null) === 'resign'
            && ($_POST['html_resign'] ?? null) === '1')
        || (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
            && ($_GET['ajax'] ?? null) === 'battle_command_status' && ($_GET['html_resign'] ?? null) === '1');
    global $state, $playerKey;
    if ($htmlResign && isset($state, $playerKey) && $state instanceof GameState) {
        $url = '?' . ($playerKey === 'host' ? 'first' : 'second') . '&game=' . (int) $state->gameId;
        header('Cache-Control: no-store');
        // The HTML flag selects presentation, never the command's identity or result.
        $resignRecord = null;
        $resignResultState = null;
        $resignLookupFailed = false;
        try {
            global $db;
            $id = $payload['command_id'] ?? null;
            if (is_string($id) && strlen($id) <= 64 && isValidAjaxCommandId($id)) {
                $resignRecord = (new CommandJournalRepository($db))->find($state->gameId, $playerKey, $id);
                if ($resignRecord !== null && $resignRecord['command_type'] === 'resign'
                    && $resignRecord['status'] === CommandJournalRepository::STATUS_COMMITTED) {
                    $resignResultState = (\Berserk\Core\BattleHistory\HistoryRuntime::load()->repository($db))->findById($state->gameId);
                }
            }
        } catch (Throwable) {
            $resignLookupFailed = true; // Read failure cannot confirm an uncertain result.
        }
        $wrongCommand = $resignRecord !== null && $resignRecord['command_type'] !== 'resign';
        $confirmedResign = !$resignLookupFailed && $resignRecord !== null && !$wrongCommand
            && (int) $resignRecord['game_id'] === $state->gameId && $resignRecord['player_key'] === $playerKey
            && $resignRecord['status'] === CommandJournalRepository::STATUS_COMMITTED
            && $resignRecord['before_version'] !== null && $resignRecord['after_version'] !== null
            && (int) $resignRecord['before_version'] === (int) $resignRecord['expected_version']
            && (int) $resignRecord['after_version'] === (int) $resignRecord['before_version'] + 1
            && $resignRecord['error_code'] === null && $resignRecord['error_message'] === null
            && !empty($resignRecord['finished_at'])
            // Journal has no Result blob. Its exact saved version must prove terminal surrender.
            && $resignResultState !== null && $resignResultState->gameId === $state->gameId
            && $resignResultState->persistenceVersion === (int) $resignRecord['after_version']
            && $resignResultState->status === 'game_over'
            && $resignResultState->winner === $resignResultState->getOpponentKey($playerKey);
        if ($confirmedResign && ($payload['ok'] ?? false) === true
            && ($payload['command_status'] ?? null) === 'committed' && ($payload['terminal'] ?? false) === true) {
            header('Location: ' . $url, true, 303);
            exit;
        }
        http_response_code($wrongCommand ? 409 : $statusCode);
        header('Content-Type: text/html; charset=utf-8');
        $terminal = $wrongCommand || (!$resignLookupFailed && (
            ($resignRecord !== null && in_array($resignRecord['status'], ['rejected', 'conflict', 'failed'], true))
            || ($payload['request_status'] ?? null) === 'rejected_before_registration'));
        echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Сдача матча</title><body><p>'
            . ($terminal ? 'Сдача не выполнена. Обновите матч перед новой попыткой.'
                : 'Результат сдачи не подтверждён. Не отправляйте команду повторно.') . '</p>';
        if (!$terminal && isset($payload['command_id']) && is_string($payload['command_id'])) {
            $statusUrl = $url . '&ajax=battle_command_status&html_resign=1&command_id=' . rawurlencode($payload['command_id']);
            echo '<p><a href="' . htmlspecialchars($statusUrl, ENT_QUOTES) . '">Проверить результат</a></p>';
        }
        echo '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Вернуться к матчу</a></p></body></html>';
        exit;
    }
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
    int $statusCode = 200,
    array $extra = []
): never {
    $state = applyAutomaticBattleProgress($db, $state, $playerKey);
    $syncVersion = $state->persistenceVersion ?? 0;

    if ($state->status !== 'battle') {
        $payload = [
            'ok' => $ok,
            'type' => $type === 'conflict' ? 'conflict' : 'status',
            'sync_version' => $syncVersion,
            'render_token' => \Berserk\Core\PlaymatCatalog::renderToken($state),
            'playmats' => \Berserk\Core\PlaymatCatalog::snapshot($state,$playerKey),
            'status' => $state->status,
            'turn_timer' => TurnTimer::snapshot($state, time()),
            'player_settings' => MatchPlayerSettings::snapshot($state, $playerKey),
            'match_pause' => MatchPause::snapshot($state, $playerKey, time()),
        ];
        if ($error !== null) {
            $payload['error'] = $error;
        }
        $payload = array_merge($payload, $extra);
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
        'render_token' => \Berserk\Core\PlaymatCatalog::renderToken($state),
        'playmats' => \Berserk\Core\PlaymatCatalog::snapshot($state,$playerKey),
        'status' => $state->status,
        'ui' => $snapshot['ui'],
        'fragments' => $snapshot['fragments'],
        'turn_timer' => $snapshot['turn_timer'],
        'player_settings' => $snapshot['player_settings'],
        'match_pause' => $snapshot['match_pause'],
    ];
    if ($error !== null) {
        $payload['error'] = $error;
    }
    $payload = array_merge($payload, $extra);

    sendAjaxJson($payload, $statusCode);
}

function commandJournalMeta(array $record): array
{
    return [
        'command_id' => (string) $record['command_id'],
        'command_status' => (string) $record['status'],
        'terminal' => (string) $record['status'] !== CommandJournalRepository::STATUS_PROCESSING,
        'before_version' => $record['before_version'] === null ? null : (int) $record['before_version'],
        'after_version' => $record['after_version'] === null ? null : (int) $record['after_version'],
    ];
}

function sendCommandStatus(
    GameState $state,
    string $commandId,
    ?array $record,
    bool $ok = true,
    ?string $error = null,
    int $statusCode = 200
): never {
    global $db, $playerKey, $settingsOnlyRequest;
    if (($record['command_type'] ?? null) === MatchPlayerSettings::COMMAND) $settingsOnlyRequest = true;
    $viewer = (string) ($record['player_key'] ?? $playerKey);
    $allowCombat = $record !== null && ($record['status'] ?? 'processing') !== CommandJournalRepository::STATUS_PROCESSING;
    $state = applyAutomaticBattleProgress($db, $state, $viewer, $allowCombat);
    sendCommandStatusPayload($commandId, $record, $ok, $error, $statusCode, $state->persistenceVersion ?? 0,
        ['turn_timer' => TurnTimer::snapshot($state, time()),
         'player_settings' => MatchPlayerSettings::snapshot($state, $viewer),
         'match_pause' => MatchPause::snapshot($state, $viewer, time())]);
}

function sendCommandStatusPayload(
    string $commandId,
    ?array $record,
    bool $ok = true,
    ?string $error = null,
    int $statusCode = 200,
    ?int $syncVersion = null,
    array $extra = []
): never {
    $payload = [
        'ok' => $ok,
        'type' => 'command_status',
        'command_id' => $commandId,
        'command_status' => $record === null ? 'unknown' : (string) $record['status'],
        'terminal' => $record !== null && (string) $record['status'] !== CommandJournalRepository::STATUS_PROCESSING,
        'sync_version' => $syncVersion,
    ];

    if ($record !== null) {
        $payload['before_version'] = $record['before_version'] === null ? null : (int) $record['before_version'];
        $payload['after_version'] = $record['after_version'] === null ? null : (int) $record['after_version'];
        if (!empty($record['error_code'])) {
            $payload['error_code'] = (string) $record['error_code'];
        }
        if (!empty($record['error_message'])) {
            $payload['error'] = (string) $record['error_message'];
        }
    }

    if ($error !== null) {
        $payload['error'] = $error;
    }

    sendAjaxJson(array_merge($payload, $extra), $statusCode);
}

function sendUnknownCommandOutcome(string $commandId): never
{
    sendCommandStatusPayload(
        $commandId,
        null,
        false,
        'Command outcome is unknown',
        500
    );
}

/** Definitive refusal of this request, never a guess about an unknown commit. */
function sendPreRegistrationRejection(Db $db, GameState $state, string $playerKey, string $error, int $statusCode = 400): never
{
    $id = requestCommandId($_POST);
    if ($id['error'] !== null) {
        sendAjaxJson(['ok' => false, 'type' => 'command_status', 'error' => $error], $statusCode);
    }
    $commandId = (string) $id['command_id'];
    // A reused ID may already have a real outcome; do not hide it with an early refusal.
    $record = (new CommandJournalRepository($db))->find($state->gameId, $playerKey, $commandId);
    if ($record !== null) sendCommandStatus($state, $commandId, $record, false, $error, $statusCode);
    sendAjaxJson([
        'ok' => false, 'type' => 'command_status', 'command_id' => $commandId,
        'request_status' => 'rejected_before_registration', 'error' => $error,
    ], $statusCode);
}

function redirectToGamePage(string $role, GameState $state, array $source = []): never
{
    $roleParam = $role === 'host' ? 'first' : 'second';
    $redirectUrl = '?' . $roleParam . '&game=' . $state->gameId;

    $mode = requestParamString($source, 'mode', 64);
    if ($mode !== null && $mode !== '') {
        $redirectUrl .= '&mode=' . urlencode($mode);
    }

    $sel = requestParamInt($source, 'sel');
    if ($sel !== null) {
        $redirectUrl .= '&sel=' . $sel;
    }

    $card = requestParamString($source, 'card', 128);
    if ($card !== null && $card !== '') {
        $redirectUrl .= '&card=' . urlencode($card);
    }

    $pile = requestParamString($source, 'pile', 32);
    if ($pile !== null && $pile !== '') {
        $redirectUrl .= '&pile=' . urlencode($pile);
    }

    http_response_code(302);
    header('Location: ' . $redirectUrl);
    exit;
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

/** Optional due processing never changes the outcome/identity of a user's command. */
function applyAutomaticBattleProgress(Db $db, GameState $state, string $requester, bool $allowCombat = true): GameState
{
    global $settingsOnlyRequest;
    if ($settingsOnlyRequest ?? false) return $state; // A preference response is projection-only, including deadlines.

    // One guard instance per HTTP request, shared by snapshots and command recovery.
    static $progress = null;
    try {
        $progress ??= new \Berserk\Core\HttpBattleProgress($db, \Berserk\Core\BattleHistory\HistoryRuntime::load()->repository($db), new Engine($db));
        return $progress->check($state, $requester, $allowCombat)->state;
    } catch (Throwable) {
        // HttpBattleProgress latches the stop before propagating any exception.
        error_log('Automatic battle progress unavailable for game ' . $state->gameId);
        try { return (\Berserk\Core\BattleHistory\HistoryRuntime::load()->repository($db))->findById($state->gameId) ?? $state; }
        catch (Throwable) { return $state; }
    }
}

function buildRenderCardInfo(Db $db, GameState $state): array
{
    $cardsInfo = [];

    $ukids = array_map(fn ($c) => $c->ukid, $state->cards);

    if (!empty($state->draft)) {
        foreach ($state->draft['grid'] ?? [] as $u) if ($u !== null) $ukids[] = $u;
        foreach ($state->draft['pool'] ?? [] as $u) if ($u !== null) $ukids[] = $u;
        foreach ($state->draft['recycle'] ?? [] as $u) if ($u !== null) $ukids[] = $u;
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
    $elementCodes = [];
    $elementLabels = [];
    foreach ($db->fetchAll("SELECT ind, code, name FROM elements") as $e) {
        $elements[(int) $e['ind']] = (string) $e['name'];
        $elementCodes[(int) $e['ind']] = (string) $e['code'];
        $elementLabels[(string) $e['code']] = (string) $e['name'];
    }

    if (!empty($ukids)) {
        $in = "'" . implode("','", array_map(fn ($u) => $db->escape($u), $ukids)) . "'";
        $rows = $db->fetchAll(
            "SELECT ukid, name, price, health, move, elite, single,
                    strike_weak, strike_medium, strike_strong,
                    element_id, descr, rarity, class, type
             FROM cards WHERE ukid IN ($in)"
        );
        foreach ($rows as $r) {
            $cardsInfo[$r['ukid']] = [
                'name'    => $r['name'],
                'descr'   => (string) ($r['descr'] ?? ''),
                'rarity' => (string) ($r['rarity'] ?? ''),
                'class' => (string) ($r['class'] ?? ''),
                'type' => (string) ($r['type'] ?? ''),
                'element_code' => $elementCodes[(int) $r['element_id']] ?? '',
                'element_label' => $elements[(int) $r['element_id']] ?? '—',
                'price'   => (int) $r['price'],
                'health'  => (int) $r['health'],
                'move'    => (int) $r['move'],
                'elite'   => (bool) $r['elite'],
                'single'  => (bool) ($r['single'] ?? false),
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
$isChat = in_array($ajax, ['chat_messages', 'chat_send', 'chat_read'], true);
$isPlaymat = $ajax === 'playmat';
$isBattleSync = $ajax === 'battle_sync';
$isBattleCommand = $ajax === 'battle_command';
$isBattleCommandStatus = $ajax === 'battle_command_status';
$settingsOnlyRequest = $isBattleCommand && ajaxPostParamString('cmd', 64) === MatchPlayerSettings::COMMAND;

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
if (is_string($_GET['debug_roll'] ?? null)) {
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
    if ($isChat || $isPlaymat || $isBattleSync || $isBattleCommand || $isBattleCommandStatus) {
        sendAjaxJson([
            'ok' => false,
            'error' => 'server_error',
        ], 500);
    }

    throw $e;
}
// Chat exits before game construction, role-session writes and automatic battle progress.
if ($isChat) {
    try {
        $game = ajaxParamInt('game') ?? 0;
        if ($game <= 0 || isset($_GET['first']) === isset($_GET['second'])) throw new InvalidArgumentException('invalid_game_or_role');
        // Explicit prototype identity agreed with Project Lead; URL roles are not real authentication.
        $sender = isset($_GET['first']) ? 1 : 2;
        $chat = new \Berserk\Core\GameChat($db);
        if ($ajax === 'chat_messages') {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') sendAjaxJson(['ok'=>false,'error'=>'method_not_allowed'],405);
            session_write_close();
            sendAjaxJson(['ok'=>true] + $chat->history($game,$sender,
                isset($_GET['after_id']) ? \Berserk\Core\GameChat::cursor($_GET['after_id']) : null,
                isset($_GET['before_id']) ? \Berserk\Core\GameChat::cursor($_GET['before_id']) : null));
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sendAjaxJson(['ok'=>false,'error'=>'method_not_allowed'],405);
        $token = ajaxPostParamString('csrf', 64);
        if (!$token || !isset($_SESSION['chat_csrf']) || !hash_equals($_SESSION['chat_csrf'],$token)) sendAjaxJson(['ok'=>false,'error'=>'csrf'],403);
        session_write_close();
        if ($ajax === 'chat_send') {
            $message = $chat->send($game,$sender,$_POST['message'] ?? null,$_POST['request_id'] ?? null);
            sendAjaxJson(['ok'=>true,'message'=>$message]);
        }
        $chat->read($game,$sender,$_POST['last_read_id'] ?? null);
        sendAjaxJson(['ok'=>true]);
    } catch (InvalidArgumentException $e) { sendAjaxJson(['ok'=>false,'error'=>$e->getMessage()],400);
    } catch (DomainException $e) { sendAjaxJson(['ok'=>false,'error'=>$e->getMessage()],403);
    } catch (OverflowException $e) { sendAjaxJson(['ok'=>false,'error'=>'rate_limited'],429);
    } catch (Throwable $e) { sendAjaxJson(['ok'=>false,'error'=>'chat_unavailable'],503); }
}
$repo    = \Berserk\Core\BattleHistory\HistoryRuntime::load()->repository($db);
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
    if ($isChat || $isPlaymat || $isBattleSync || $isBattleCommand || $isBattleCommandStatus) {
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
$isBattleAjax = $isPlaymat || $isBattleSync || $isBattleCommand || $isBattleCommandStatus;
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

$historyRuntime = \Berserk\Core\BattleHistory\HistoryRuntime::load();
if ($historyRuntime->records($state->gameId)) {
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: same-origin');
    $trustedUser = $historyRuntime->principal($_SESSION);
    $expectedUser = $role === 'host' ? $state->hostId : $state->playerId;
    if ($trustedUser !== $expectedUser) {
        http_response_code(403);
        echo 'Trusted authentication required for experimental test match';
        exit;
    }
    $_SESSION['history_prepare_csrf'] ??= bin2hex(random_bytes(32));
}

$_SESSION['user_id'] = $userId;
$_SESSION['game_id'] = $state->gameId;
$_SESSION['role']    = $role;

$playerKey = $role === 'host' ? 'host' : 'player';
$oppKey    = $state->getOpponentKey($playerKey);

if ($isPlaymat) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sendAjaxJson(['ok'=>false,'error'=>'method'],405);
    $token = ajaxPostParamString('csrf',64);
    if (!$token || !hash_equals($_SESSION['playmat_csrf'] ?? '', $token)) sendAjaxJson(['ok'=>false,'error'=>'csrf'],403);
    try {
        $fresh = $repo->setPlaymat($state->gameId, $userId, ajaxPostParamString('playmat_id',64) ?? '');
        sendAjaxJson(['ok'=>true,'render_token'=>\Berserk\Core\PlaymatCatalog::renderToken($fresh),'playmats'=>\Berserk\Core\PlaymatCatalog::snapshot($fresh,$playerKey)]);
    } catch (\InvalidArgumentException $error) {
        sendAjaxJson(['ok'=>false,'error'=>$error->getMessage()],400);
    } catch (Throwable) { sendAjaxJson(['ok'=>false,'error'=>'save_failed'],500); }
}

if ($isBattleSync) {
    try {
        $state = applyAutomaticBattleProgress($db, $state, $playerKey);
        $syncVersion = $state->persistenceVersion ?? 0;
        $sinceVersion = ajaxParamInt('since_version');
        $forceSnapshot = ajaxFlag('force_snapshot');

        if ($state->status !== 'battle') {
            sendAjaxJson([
                'ok' => true,
                'type' => 'status',
                'sync_version' => $syncVersion,
                'status' => $state->status,
                'turn_timer' => TurnTimer::snapshot($state, time()),
                'player_settings' => MatchPlayerSettings::snapshot($state, $playerKey),
                'match_pause' => MatchPause::snapshot($state, $playerKey, time()),
            ]);
        }

        if (!$forceSnapshot && $sinceVersion !== null && ($sinceVersion === $syncVersion || hash_equals(\Berserk\Core\PlaymatCatalog::renderToken($state), ajaxParamString('render_token',64) ?? ''))) {
            sendAjaxJson([
                'ok' => true,
                'type' => 'no_change',
                'sync_version' => $syncVersion,
                'playmats' => \Berserk\Core\PlaymatCatalog::snapshot($state,$playerKey),
                'turn_timer' => TurnTimer::snapshot($state, time()),
                'player_settings' => MatchPlayerSettings::snapshot($state, $playerKey),
                'match_pause' => MatchPause::snapshot($state, $playerKey, time()),
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

if ($isBattleCommandStatus) {
    try {
        $commandIdResult = requestCommandId($_GET);
        if ($commandIdResult['error'] === 'missing_command_id') {
            sendAjaxJson([
                'ok' => false,
                'type' => 'command_status',
                'error' => 'missing_command_id',
            ], 400);
        }

        if ($commandIdResult['error'] !== null) {
            sendAjaxJson([
                'ok' => false,
                'type' => 'command_status',
                'error' => $commandIdResult['error'],
            ], 400);
        }

        $commandId = (string) $commandIdResult['command_id'];
        $journal = new CommandJournalRepository($db);
        $record = $journal->find($state->gameId, $playerKey, $commandId);
        sendCommandStatus($state, $commandId, $record);
    } catch (CommandJournalException) {
        sendAjaxJson([
            'ok' => false,
            'type' => 'command_status',
            'error' => 'server_error',
        ], 500);
    } catch (Throwable) {
        sendAjaxJson([
            'ok' => false,
            'type' => 'command_status',
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
            sendPreRegistrationRejection($db, $state, $playerKey, 'missing_expected_version');
        }

        $uiState = [
            'sel' => ajaxPostParamInt('sel') ?? 0,
            'mode' => ajaxPostParamString('mode', 64) ?? '',
            'pile' => ajaxPostParamString('pile', 32) ?? '',
        ];

        $commandIdResult = requestCommandId($_POST);
        if ($commandIdResult['error'] !== null) {
            sendPreRegistrationRejection($db, $state, $playerKey, $commandIdResult['error']);
        }

        $commandId = (string) $commandIdResult['command_id'];
        $commandType = ajaxPostParamString('cmd', 64);
        $payload = ajaxCommandPayload();
        if ($commandType === null || $commandType === '' || $payload === null) {
            sendPreRegistrationRejection($db, $state, $playerKey, 'invalid_command_request');
        }

        $journal = new CommandJournalRepository($db);
        try {
            $journalRecord = $journal->registerProcessing(
                $state->gameId,
                $playerKey,
                $commandId,
                $commandType,
                $payload,
                $expectedVersion
            );
        } catch (CommandJournalException) {
            sendAjaxJson([
                'ok' => false,
                'type' => 'command_status',
                'command_id' => $commandId,
                'error' => 'command_id_conflict',
            ], 409);
        }

        $journalId = (int) $journalRecord['id'];
        $journalMeta = commandJournalMeta($journalRecord);

        if (($journalRecord['is_new'] ?? false) !== true) {
            $status = (string) $journalRecord['status'];
            $fresh = reloadPersistedBattleAjaxState($repo, $state->gameId);

            if ($status === CommandJournalRepository::STATUS_PROCESSING) {
                sendCommandStatus($fresh, $commandId, $journalRecord);
            }

            if ($status === CommandJournalRepository::STATUS_COMMITTED) {
                sendBattleAjaxState($db, $tpl, $fresh, $playerKey, $role, $uiState, true, 'snapshot', null, 200, $journalMeta);
            }

            if ($status === CommandJournalRepository::STATUS_CONFLICT) {
                sendBattleAjaxState(
                    $db,
                    $tpl,
                    $fresh,
                    $playerKey,
                    $role,
                    $uiState,
                    false,
                    'conflict',
                    $journalRecord['error_message'] !== null ? (string) $journalRecord['error_message'] : 'State changed, refresh required',
                    409,
                    $journalMeta
                );
            }

            if ($status === CommandJournalRepository::STATUS_REJECTED) {
                sendBattleAjaxState(
                    $db,
                    $tpl,
                    $fresh,
                    $playerKey,
                    $role,
                    $uiState,
                    false,
                    'snapshot',
                    $journalRecord['error_message'] !== null ? (string) $journalRecord['error_message'] : 'Command cannot be performed',
                    200,
                    $journalMeta
                );
            }

            sendCommandStatus(
                $fresh,
                $commandId,
                $journalRecord,
                false,
                $journalRecord['error_message'] !== null ? (string) $journalRecord['error_message'] : 'Command failed'
            );
        }

        $currentVersion = $state->persistenceVersion ?? 0;
        if ($expectedVersion !== $currentVersion) {
            $journalRecord = $journal->markConflict($journalId, $currentVersion, 'stale_version', 'State changed, refresh required');
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
                409,
                commandJournalMeta($journalRecord)
            );
        }

        if ($state->status !== 'battle') {
            $journalRecord = $journal->markRejected($journalId, $currentVersion, 'battle_not_active', 'Battle is not active');
            sendBattleAjaxState(
                $db,
                $tpl,
                $state,
                $playerKey,
                $role,
                $uiState,
                false,
                'status',
                'Battle is not active',
                200,
                commandJournalMeta($journalRecord)
            );
        }

        try {
            $repo->executeLockedCommand($state, $journal, $journalId,
                function (GameState $locked) use ($engine, $playerKey, $commandType, $payload): \Berserk\Core\Result {
                    return $engine->apply($locked, $playerKey, new Command($commandType, $payload));
                }, 'command_rejected');
        } catch (StaleStateException) {
            $fresh = reloadPersistedBattleAjaxState($repo, $state->gameId);
            $journalRecord = $journal->markConflict(
                $journalId,
                $fresh->persistenceVersion ?? $currentVersion,
                'stale_state',
                'State changed, refresh required'
            );
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
                409,
                commandJournalMeta($journalRecord)
            );
        } catch (TransactionCommitUnknownException|TransactionRollbackUnknownException) {
            try {
                $fresh = $repo->findById($state->gameId);
                $latestJournalRecord = $journal->findById($journalId);
            } catch (Throwable) {
                sendUnknownCommandOutcome($commandId);
            }

            if (
                $fresh !== null
                && $latestJournalRecord !== null
                && (string) $latestJournalRecord['status'] === CommandJournalRepository::STATUS_COMMITTED
            ) {
                sendBattleAjaxState($db, $tpl, $fresh, $playerKey, $role, $uiState, true, 'snapshot', null, 200, commandJournalMeta($latestJournalRecord));
            }

            if (
                $fresh !== null
                && $latestJournalRecord !== null
                && (string) $latestJournalRecord['status'] === CommandJournalRepository::STATUS_CONFLICT
            ) {
                sendBattleAjaxState(
                    $db,
                    $tpl,
                    $fresh,
                    $playerKey,
                    $role,
                    $uiState,
                    false,
                    'conflict',
                    $latestJournalRecord['error_message'] !== null ? (string) $latestJournalRecord['error_message'] : 'State changed, refresh required',
                    409,
                    commandJournalMeta($latestJournalRecord)
                );
            }

            if (
                $fresh !== null
                && $latestJournalRecord !== null
                && (string) $latestJournalRecord['status'] === CommandJournalRepository::STATUS_REJECTED
            ) {
                sendBattleAjaxState(
                    $db,
                    $tpl,
                    $fresh,
                    $playerKey,
                    $role,
                    $uiState,
                    false,
                    'snapshot',
                    $latestJournalRecord['error_message'] !== null ? (string) $latestJournalRecord['error_message'] : 'Command cannot be performed',
                    200,
                    commandJournalMeta($latestJournalRecord)
                );
            }

            if (
                $latestJournalRecord !== null
                && (string) $latestJournalRecord['status'] === CommandJournalRepository::STATUS_FAILED
            ) {
                sendCommandStatusPayload(
                    $commandId,
                    $latestJournalRecord,
                    false,
                    $latestJournalRecord['error_message'] !== null ? (string) $latestJournalRecord['error_message'] : 'Command failed',
                    500,
                    $fresh?->persistenceVersion
                );
            }

            if (
                $fresh === null
                || $latestJournalRecord === null
                || (string) $latestJournalRecord['status'] === CommandJournalRepository::STATUS_PROCESSING
            ) {
                sendUnknownCommandOutcome($commandId);
            }

            sendUnknownCommandOutcome($commandId);
        } catch (DbQueryException) {
            // saveCommittedCommand rethrows SQL errors only after acknowledged rollback.
            // Failed COMMIT/ROLLBACK acknowledgements have distinct exception types above.
            try {
                $latestJournalRecord = $journal->markFailed($journalId, $currentVersion, 'persistence_failed');
            } catch (Throwable) {
                // The terminal UPDATE may have succeeded even if its reply/read failed.
                try {
                    $latestJournalRecord = $journal->findById($journalId);
                } catch (Throwable) {
                    sendUnknownCommandOutcome($commandId);
                }
            }

            if ($latestJournalRecord === null || !$journal->isTerminalStatus((string) $latestJournalRecord['status'])) {
                sendUnknownCommandOutcome($commandId);
            }

            $outcome = (string) $latestJournalRecord['status'];
            sendCommandStatusPayload(
                $commandId,
                $latestJournalRecord,
                $outcome === CommandJournalRepository::STATUS_COMMITTED,
                $outcome === CommandJournalRepository::STATUS_FAILED ? 'Command could not be saved' : null,
                match ($outcome) {
                    CommandJournalRepository::STATUS_FAILED => 500,
                    CommandJournalRepository::STATUS_CONFLICT => 409,
                    default => 200,
                }
            );
        }

        try {
            $committed = reloadPersistedBattleAjaxState($repo, $state->gameId);
            $journalRecord = $journal->findById($journalId) ?? $journalRecord;
            sendBattleAjaxState($db, $tpl, $committed, $playerKey, $role, $uiState,
                $journalRecord['status'] === CommandJournalRepository::STATUS_COMMITTED, 'snapshot',
                $journalRecord['error_message'], 200, commandJournalMeta($journalRecord));
        } catch (Throwable) {
            sendUnknownCommandOutcome($commandId);
        }
    } catch (Throwable) {
        sendAjaxJson([
            'ok' => false,
            'error' => 'server_error',
        ], 500);
    }
}

// ─── Обработка команды ────────────────────────────────────────
$state = applyAutomaticBattleProgress($db, $state, $playerKey);
$message = null;
$commandType = is_string($_GET['cmd'] ?? null) ? $_GET['cmd'] : '';
$hasLegacyCommand = array_key_exists('cmd', $_GET)
    && (is_array($_GET['cmd']) || (string) $_GET['cmd'] !== '');

if ($hasLegacyCommand && $state->status === 'battle') {
    redirectToGamePage($role, $state, $_GET);
}

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
        'error' => $result->success ? null : $result->error,
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
    $historyPrepared = false;
    if ($historyRuntime->records($state->gameId) && $state->status === 'place') {
        $token = requestParamString($_GET, 'prepare_token', 64);
        $version = requestParamString($_GET, 'prepare_version', 20);
        if ($token === null || !hash_equals($_SESSION['history_prepare_csrf'] ?? '', $token)
            || $version === null || !ctype_digit($version) || (string)(int)$version !== $version) {
            http_response_code(403); echo 'Invalid preparation token/version'; exit;
        }
        $journalPayload = $payload;
        unset($journalPayload['prepare_token']);
        ksort($journalPayload);
        $commandId = 'prep:'.substr(hash('sha256', $playerKey.':'.$version.':'.json_encode($journalPayload,JSON_THROW_ON_ERROR)),0,48);
        $prepareJournal = new CommandJournalRepository($db);
        $record = $prepareJournal->registerProcessing($state->gameId,$playerKey,$commandId,$commandType,$journalPayload,(int)$version);
        if ($record['status'] === 'processing' && $record['is_new']) {
            if ((int)$version !== $state->persistenceVersion) {
                $prepareJournal->markConflict((int)$record['id'],$state->persistenceVersion,'stale_state','Refresh preparation');
            } else {
                try {
                    $repo->executeLockedCommand($state,$prepareJournal,(int)$record['id'],
                        fn(GameState $locked): \Berserk\Core\Result => $engine->apply($locked,$playerKey,$cmd),'prepare_rejected');
                } catch (StaleStateException) {
                    $fresh = $repo->findById($state->gameId);
                    $prepareJournal->markConflict((int)$record['id'],$fresh->persistenceVersion,'stale_state','Refresh preparation');
                }
            }
        }
        $record = $prepareJournal->findById((int)$record['id']);
        if ($record['status'] !== 'committed') {
            http_response_code(409); echo 'Preparation not committed; refresh required'; exit;
        }
        $state = $repo->findById($state->gameId);
        $result = \Berserk\Core\Result::ok();
        $historyPrepared = true;
    } else {
        $result = $engine->apply($state, $playerKey, $cmd);
    }

    if ($result->success) {
        try {
            if (!$historyPrepared) $repo->save($state);
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
            $redirectUrl .= '&card=' . ($commandType === 'place_card' ? '0' : urlencode((string) $_GET['card']));
        }

        if (isset($_GET['pile']) && $_GET['pile'] !== '') {
            $redirectUrl .= '&pile=' . urlencode((string) $_GET['pile']);
        }

        // Successful commands redirect silently; keep service events out of the UI.
        unset($_SESSION['flash_message']);

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
    $flashMessage = (string) $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
    // Discard service messages left in sessions created before this UI cleanup.
    if (!str_starts_with($flashMessage, 'OK: ')) {
        $message = $flashMessage;
    }
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
        $deckData = (new ViewScreen($deckView, $tpl))->readOnlyDeck($state, $playerKey, $role, $message, $cardsInfo, $elementLabels);
        $result = (new TurnScreen())
            ->prepare($state, $playerKey, $role, $message, $deckData);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        break;

    case 'side':
        $deckData = (new ViewScreen($deckView, $tpl))->readOnlyDeck($state, $playerKey, $role, $message, $cardsInfo, $elementLabels);
        $result = (new SideScreen())
            ->prepare($state, $playerKey, $role, $message, $deckData);
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
            ->prepare($state, $playerKey, $role, $message, $cardsInfo,
                $historyRuntime->records($state->gameId) ? ['prepare_version'=>$state->persistenceVersion,
                    'prepare_token'=>$_SESSION['history_prepare_csrf']] : []);
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
        $turnSoundUrl = '';
        foreach (['mp3', 'ogg'] as $extension) {
            if (is_file(__DIR__ . '/assets/sound/turn.' . $extension)) {
                $turnSoundUrl = '/assets/sound/turn.' . $extension;
                break;
            }
        }
        $_SESSION['playmat_csrf'] ??= bin2hex(random_bytes(32));
        $scriptsHtml = '<script type="application/json" id="battle-playmats">' . json_encode(['catalog'=>\Berserk\Core\PlaymatCatalog::ITEMS, 'csrf'=>$_SESSION['playmat_csrf'], 'state'=>\Berserk\Core\PlaymatCatalog::snapshot($state,$playerKey)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'
            . '<script src="/assets/js/battle-playmats.js?v={{rkey}}"></script>'
            . '<script src="/assets/js/battle-turn-timer.js?v={{rkey}}"></script>'
            . '<script src="/assets/js/battle-menu.js?v={{rkey}}"></script>'
            . '<script src="/assets/js/battle-match-pause.js?v={{rkey}}"></script>'
            . '<script src="/assets/js/battle-sync.js?v={{rkey}}"'
            . ' data-viewer-key="' . htmlspecialchars($playerKey, ENT_QUOTES) . '"'
            . ' data-turn-sound-url="' . htmlspecialchars($turnSoundUrl, ENT_QUOTES) . '"'
            . ' data-sync-url="' . htmlspecialchars($syncUrl, ENT_QUOTES) . '"'
            . ' data-game-id="' . (int) $state->gameId . '"'
            . ' data-role="' . htmlspecialchars($role, ENT_QUOTES) . '"'
            . ' data-render-token="' . \Berserk\Core\PlaymatCatalog::renderToken($state) . '"'
            . ' data-version="' . (int) ($state->persistenceVersion ?? 0) . '"'
            . ' data-sel="' . (int) ($ui['sel'] ?? 0) . '"'
            . ' data-mode="' . htmlspecialchars((string) ($ui['mode'] ?? 'strike'), ENT_QUOTES) . '"'
            . ' data-mode-explicit="' . (!empty($ui['mode_explicit']) ? '1' : '0') . '"'
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
// Data only: Stage 1 supplies the same contract on full pages, including game_over.
if ($state->status === 'battle' || isset($state->battle['match_pause'])) {
    $scriptsHtml = '<script type="application/json" id="battle-player-settings">'
        . json_encode(MatchPlayerSettings::snapshot($state, $playerKey), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>' . '<script type="application/json" id="match-pause-state">'
        . json_encode(MatchPause::snapshot($state, $playerKey, time()), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>' . $scriptsHtml;
}
$isPreGame = \Berserk\View\Ui\PreGameLayout::supports($screenName);
$screenHtml = $isPreGame
    ? (new \Berserk\View\Ui\PreGameLayout($tpl))->renderScreen($screenName, $screenData)
    : $tpl->parse("screen/{$screenName}.tpl", $screenData);

if (in_array($screenName, ['battle','game_over'], true)) {
    $_SESSION['chat_csrf'] ??= bin2hex(random_bytes(32));
    $screenHtml .= $tpl->parse('includes/game_chat.tpl', [
        'chat_game_id'=>$state->gameId,
        'chat_sender_id'=>$userId,
        'chat_role'=>$role === 'host' ? 'first' : 'second',
        'chat_csrf'=>htmlspecialchars($_SESSION['chat_csrf'],ENT_QUOTES),
    ]);
    $scriptsHtml .= '<script src="/assets/js/battle-chat.js?v=4" defer></script>';
}
// Resolve the URL before passing the HTML slot to the single-pass template parser.
$preGameResourceKey = $isPreGame
    ? hash('sha256', hash_file('sha256', __DIR__ . '/assets/css/global.css')
        . hash_file('sha256', __DIR__ . '/assets/css/pregame.css'))
    : null;

$page = $tpl->parse('page.tpl', [
    'game_id' => $state->gameId,
    'role'    => $role,
    'screen'  => $screenHtml,
    'scripts' => $scriptsHtml,
    'page_class' => $screenName === 'battle' ? 'battle-page' : ($isPreGame ? 'pregame-page' : ''),
    'page_styles' => in_array($screenName, ['battle','game_over'], true) ? '<link rel="stylesheet" href="/assets/css/battle-chat.css?v=4">' : ($isPreGame ? '<link rel="stylesheet" href="/assets/css/pregame.css?v=' . $preGameResourceKey . '">' : ''),
    // PreGame opts into a stable CSS version; battle keeps its existing rkey policy.
    'rkey' => $preGameResourceKey,
]);

echo $page;
