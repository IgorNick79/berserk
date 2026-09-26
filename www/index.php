<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Db;
use Berserk\Core\GameRepository;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\CardInstance;
use Berserk\Core\View;
use Berserk\Core\DeckView;
use Berserk\Core\ResourceCalculator;
use Berserk\Core\BattleHelper;
use Berserk\Core\ZoneManager;

use Berserk\View\Template;
use Berserk\View\Screen\ModeScreen;
use Berserk\View\Screen\ViewScreen;
use Berserk\View\Screen\DeckScreen;
use Berserk\View\Screen\TurnScreen;
use Berserk\View\Screen\SideScreen;
use Berserk\View\Screen\DealScreen;
use Berserk\View\Screen\PlaceScreen;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Screen\DraftScreen;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

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

file_put_contents(__DIR__ . '/../debug.log', 
    date('[Y-m-d H:i:s] ') . 'URI=' . ($_SERVER['REQUEST_URI'] ?? '') . "\n", 
    FILE_APPEND
);

$db      = new Db($config);
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
    echo '<h1>Berserk</h1>';
    echo '<p><a href="?first">Создать партию (я первый)</a></p>';
    echo '<p><a href="?second">Присоединиться (я второй)</a></p>';
    exit;
}

$userId = $role === 'host' ? 1 : 2;

// ─── Находим или создаём партию ──────────────────────────────
$gameId = isset($_GET['game']) ? (int) $_GET['game'] : 0;

if ($gameId > 0) {
    $state = $repo->findById($gameId);
    if (!$state) {
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
    http_response_code(403);
    echo "Вы не участник партии #$gameId";
    exit;
}

$_SESSION['user_id'] = $userId;
$_SESSION['game_id'] = $state->gameId;
$_SESSION['role']    = $role;

$playerKey = $role === 'host' ? 'host' : 'player';
$oppKey    = $state->getOpponentKey($playerKey);

// ─── Обработка команды ────────────────────────────────────────
$message = null;
$commandType = $_GET['cmd'] ?? '';

if ($commandType !== '') {
    $payload = $_GET;

    // Для select_deck — резолвим список дек и вторую деку
    if ($commandType === 'select_deck') {
        $starters = $deckView->listStarters();
        $deckIds  = array_column($starters, 'ind');

        $selected = (int) ($_GET['deck_id'] ?? 0);
        $other    = null;
        foreach ($deckIds as $id) {
            if ((int) $id !== $selected) { $other = (int) $id; break; }
        }

        $payload['valid_deck_ids'] = array_map('intval', $deckIds);
        $payload['other_deck_id']  = $other;

        // Состав дек в формате [{ukid, count}]
        $selectedDeck = $deckView->forDeck($selected);
        $otherDeck    = $deckView->forDeck($other);

       $payload['deck_cards'] = array_map(
            fn ($c) => [
                'ukid'    => $c['ukid'],
                'count'   => $c['count'],
                'price'   => $c['price'],
                'elite'   => $c['elite'],
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
            $selectedDeck['cards']
        );
        $payload['other_cards'] = array_map(
            fn ($c) => [
                'ukid'          => $c['ukid'],
                'count'         => $c['count'],
                'price'         => $c['price'],
                'elite'         => $c['elite'],
                'element'       => $c['element'],
                'health'        => $c['health'],
                'move'          => $c['move'],
                'strike_weak'   => $c['strike']['weak'],
                'strike_medium' => $c['strike']['medium'],
                'strike_strong' => $c['strike']['strong'],
                'prop'          => $c['prop'],
                'type'          => $c['type'],
                'class'         => $c['class'],
            ],
            $otherDeck['cards']
        );
    }

    $cmd = new Command($commandType, $payload);
    $result = $engine->apply($state, $playerKey, $cmd);

    file_put_contents(
        __DIR__ . '/../debug.log',
        date('[Y-m-d H:i:s] ') . 'RESULT: success=' . ($result->success ? 'Y' : 'N') 
            . ' err=' . ($result->error ?? '-') 
            . ' events=' . json_encode($result->events) 
            . ' has_pending=' . (!empty($state->battle['pending_cell_marker_pick']) ? 'Y' : 'N')
            . "\n",
        FILE_APPEND
    );

    if ($result->success) {
        $repo->save($state);

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

// Загружаем все карты, которые есть в партии — для рендера
$cardsInfo = [];

$ukids = array_map(fn ($c) => $c->ukid, $state->cards);

if (!empty($state->draft)) {
    foreach ($state->draft['grid'] ?? [] as $u) if ($u !== null) $ukids[] = $u;
    foreach ($state->draft['pool'] ?? [] as $u) if ($u !== null) $ukids[] = $u;
    foreach ($state->draft['picked']['host']   ?? [] as $u) $ukids[] = $u;
    foreach ($state->draft['picked']['player'] ?? [] as $u) $ukids[] = $u;
}

// DeckCards игроков (после драфта, для view/deal)
foreach (['host', 'player'] as $key) {
    $player = $state->getPlayer($key);
    foreach ($player->deckCards ?? [] as $dc) {
        if (!empty($dc['ukid'])) $ukids[] = $dc['ukid'];
    }
}

$ukids = array_unique($ukids);

// Стихии — один раз
$elements = [];
foreach ($db->fetchAll("SELECT ind, name FROM elements") as $e) {
    $elements[(int) $e['ind']] = (string) $e['name'];
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

switch ($state->status) {
    case 'mode':
        $result = (new ModeScreen($tpl))
            ->prepare($state, $playerKey, $role, $message);
        $screenName = $result['screen'];
        $screenData = $result['data'];
        break;

    case 'settings':
        $roleParam = ($role === 'host') ? 'first' : 'second';
        if ($playerKey === 'host') {
            $screenName = 'mode';
            $screenData = [
                'content_html' => '<p>Настройки по умолчанию готовы.</p>'
                    . '<div class="mode-actions">'
                    . '<a class="button wide" href="?' . $roleParam . '&game=' . $state->gameId . '&cmd=confirm_settings">Продолжить</a>'
                    . '</div>',
                'message' => $message ?? '',
            ];
        } else {
            $screenName = 'mode';
            $screenData = [
                'content_html' => '<p class="wait">Ожидание, пока хост подтвердит настройки...</p>',
                'message' => $message ?? '',
            ];
        }
        break;

    case 'draft':
        $result = (new DraftScreen($tpl))
            ->prepare($state, $playerKey, $role, $message, $cardsInfo);
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
            ->prepare($state, $playerKey, $role, $message, $cardsInfo);
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
            ->prepare($state, $playerKey, $role, $message, $cardsInfo);
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
]);

echo $page;
