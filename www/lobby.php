<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\Autoloader;
use Berserk\Auth\{AuthSession,AuthStore};
use Berserk\Lobby\{LobbyStore,RoomSetup};
Autoloader::register(); Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$wizard = ''; $wizardMode = null;
$api = isset($_GET['api']); $error = null; $account = null; $data = null; $csrf = '';
$escape = static fn(string $s): string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$integer = static function(mixed $value): int {
    if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',$value) || (float)$value > 4294967295) throw new DomainException('Некорректный номер.',400);
    return (int)$value;
};
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST'); throw new DomainException('Метод не разрешён.',405); }
    $configPath = __DIR__.'/../config/auth.local.php';
    if (!is_file($configPath)) throw new RuntimeException('Configuration unavailable');
    $config = require $configPath;
    if (!is_array($config)) throw new RuntimeException('Configuration unavailable');
    AuthSession::start(($config['allow_loopback_http'] ?? false) === true);
    $actor = AuthSession::id();
    if ($actor === null && ($method === 'POST' || isset($_GET['room']) || isset($_GET['new']))) {
        if ($api || $method === 'POST') throw new DomainException('Требуется вход.',401);
        header('Location: auth.php?page=login',true,303); exit;
    }
    if ($actor !== null) {
        $account = (new AuthStore($config['db']))->account($actor);
        if ($account === null) throw new DomainException('Требуется вход.',401);
        \Berserk\Auth\DebugAccess::bind($account);
        \Berserk\Auth\AccountUiPreferences::bindPresentation(new \Berserk\Auth\AccountUiPreferences($config['db']));
    }
    $csrf = AuthSession::csrf();
    if ($method === 'POST') AuthSession::requireCsrf($_POST['csrf'] ?? null);
    session_write_close(); // DB locks, not session serialization, protect room transitions.
    $store = new LobbyStore($config['lobby_db'] ?? $config['db'],static fn()=>\Berserk\Auth\AuthContext::gameDb());
    $id = isset($_GET['room']) ? $integer($_GET['room']) : null;
    if ($api && $method==='GET' && ($_GET['check_invite']??null)==='1') {
        if ($actor===null) throw new DomainException('Требуется вход.',401);
        $store->invitee($actor,'closed',$_GET['login']??null);
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]);exit;
    }
    if ($method === 'POST') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>8192 || count($_POST)>32) throw new DomainException('Слишком большой запрос.',413);
        $action = $_POST['action'] ?? null;
        if ($action === 'create') {
            if ($id !== null) throw new DomainException('Некорректный запрос.',400);
            $setupMode=$_POST['setup_mode']??null;
            if (!is_string($setupMode)) throw new DomainException('Некорректный режим.',400);
            $creationKey=$_POST['creation_key']??null;
            if (!is_string($creationKey) || !preg_match('/\A[a-f0-9]{32}\z/D',$creationKey)) throw new DomainException('Откройте мастер создания игры заново.',400);
            $invited=$store->invitee($actor,$_POST['access']??'open',$_POST['invited_login']??'');
            $id = $store->create($actor,RoomSetup::parse($setupMode,$_POST,$actor),$invited,$creationKey);
        } else {
            if ($id === null || !is_string($action) || !in_array($action,['open','accept','reject'],true)) throw new DomainException('Некорректный запрос.',400);
            $version = $integer($_POST['version'] ?? null);
            $request = $_POST['candidate_request_id'] ?? null;
            if ($request !== null && (!is_string($request) || !preg_match('/\A[a-f0-9]{64}\z/D',$request))) throw new DomainException('Некорректная заявка.',400);
            $store->act($id,$actor,$version,$request,$action);
        }
        $data = ['room'=>$store->read($id,$actor),'url'=>'lobby.php?room='.$id];
        if (!$api) { header('Location: '.$data['url'],true,303); exit; }
    } else {
        $data = $id === null ? ['rooms'=>$store->listing($actor ?? 0)] : ['room'=>$store->read($id,$actor)];
    }
    if (!$api && isset($_GET['new'])) $wizard=\Berserk\View\Ui\LobbyWizard::render($actor,$csrf);

} catch (DomainException $e) {
    $code = $e->getCode(); http_response_code($code>=400 && $code<=599 ? $code : 400); $error = $e->getMessage();
} catch (Throwable) { http_response_code(503); $error = 'Лобби временно недоступно. Обновите состояние перед повторной попыткой.'; }
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if ($api) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($error === null ? $data : ['error'=>$error],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Berserk — лобби</title><link rel="stylesheet" href="assets/css/global.css"><link rel="stylesheet" href="assets/css/auth.css"><link rel="stylesheet" href="assets/css/lobby.css?v=6"><link rel="stylesheet" href="assets/css/lobby-wizard.css?v=1"><link rel="stylesheet" href="assets/css/pregame.css?v=2"><script src="assets/js/settings.js?v=2" defer></script><script src="assets/js/lobby.js?v=6" defer></script><script src="assets/js/lobby-wizard.js?v=1" defer></script></head><body class="lobby-page">
<?= \Berserk\View\Ui\GameMenu::render(isset($_GET['new'])?'preparation':'lobby',$account,$csrf) ?>
<main id="lobby" class="screen lobby<?= isset($_GET['new']) ? ' lobby--wizard' : '' ?>" data-state="<?= $escape(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) ?>" data-csrf="<?= $escape($csrf) ?>" data-authenticated="<?= $account ? '1' : '0' ?>" data-wizard="<?= $wizard !== '' ? '1' : '0' ?>">
<p id="notice" role="status"><?= $escape($error ?? '') ?></p>
<?php if ($data !== null): ?>
<?php if ($wizard !== ''): ?><section class="lobby-wizard"><?= $wizard ?></section><?php else: ?><section id="content"></section><?php endif; ?><noscript>Для автоматического обновления лобби включите JavaScript.</noscript>
<?php else: ?><p><a href="lobby.php">Вернуться в лобби</a></p><?php endif; ?></main><script src="/assets/js/lucktest-debug.js?v=2" defer></script></body></html>
