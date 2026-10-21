<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\Autoloader;
use Berserk\Auth\{AuthSession,AuthStore,AuthService};
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__.'/../src/');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
$escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page = $_GET['page'] ?? 'login';
$error = null; $account = null; $csrf = ''; $login = '';
try {
    if (!is_string($page) || !in_array($page,['login','register','account','logout'],true)) throw new DomainException('Страница не найдена.',404);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method,['GET','POST'],true) || ($page === 'logout' && $method !== 'POST') || ($page === 'account' && $method !== 'GET')) {
        header('Allow: '.($page === 'logout' ? 'POST' : ($page === 'account' ? 'GET' : 'GET, POST')));
        throw new DomainException('Метод не разрешён.',405);
    }
    $path = __DIR__.'/../config/auth.local.php';
    if (!is_file($path)) throw new RuntimeException('Configuration unavailable');
    $config = require $path;
    if (!is_array($config) || !is_string($config['limiter_secret'] ?? null) || strlen($config['limiter_secret']) < 32) throw new RuntimeException('Configuration unavailable');
    AuthSession::start(($config['allow_loopback_http'] ?? false) === true);
    if ($method === 'POST') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096 || count($_POST) > 4) throw new DomainException('Слишком большой запрос.',413);
        AuthSession::requireCsrf($_POST['csrf'] ?? null);
        if ($page === 'logout') {
            AuthSession::logout();
            header('Location: auth.php?page=login',true,303); exit;
        }
        $store = new AuthStore($config['db']);
        $login = $_POST['login'] ?? null; $password = $_POST['password'] ?? null;
        if (!is_string($login) || !is_string($password) || strlen($login)>128 || strlen($password)>1024) throw new DomainException('Некорректный запрос.',400);
        // Session stays locked until rotation; limiter uses DB locks across independent sessions.
        $service = new AuthService($store,$config['limiter_secret']);
        $address = $_SERVER['REMOTE_ADDR'] ?? '';
        if (filter_var($address,FILTER_VALIDATE_IP) === false) throw new RuntimeException('Client address unavailable');
        $address = bin2hex(inet_pton($address));
        if ($page === 'register') {
            $service->register($login,$password,$address);
            header('Location: auth.php?page=login&registered=1',true,303); exit;
        }
        $account = $service->login($login,$password,$address);
        AuthSession::login($account);
        header('Location: lobby.php',true,303); exit;
    }
    $store = new AuthStore($config['db']);
    if ($page === 'account') {
        $id = AuthSession::id();
        if ($id === null) { header('Location: auth.php?page=login',true,303); exit; }
        $account = $store->account($id);
        if ($account === null) { AuthSession::logout(); header('Location: auth.php?page=login',true,303); exit; }
    }
} catch (OverflowException $e) {
    http_response_code(429); header('Retry-After: 900'); $error = $e->getMessage();
} catch (InvalidArgumentException $e) {
    http_response_code(422); $error = $e->getMessage();
} catch (DomainException $e) {
    $code = $e->getCode(); http_response_code($code >= 400 && $code <= 599 ? $code : 422); $error = $e->getMessage();
} catch (Throwable) {
    http_response_code(503); $error = 'Авторизация временно недоступна. Попробуйте позже.';
}
if (session_status() === PHP_SESSION_ACTIVE) { $csrf = AuthSession::csrf(); session_write_close(); }
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Berserk — аккаунт</title><link rel="stylesheet" href="assets/css/global.css"><link rel="stylesheet" href="assets/css/auth.css"></head><body>
<main class="screen auth"><div class="toolbar">Berserk</div>
<?php if ($error !== null): ?><p role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($account !== null): ?>
<h1>Вы вошли как <?= $escape($account->login) ?>.</h1><p><a href="lobby.php">Открыть игровое лобби</a></p>
<form method="post" action="auth.php?page=logout"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><button class="button">Выйти</button></form>
<?php elseif ($csrf !== '' && in_array($page,['login','register'],true)): ?>
<h1><?= $page === 'register' ? 'Регистрация' : 'Вход' ?></h1>
<?php if ($page === 'login' && ($_GET['registered'] ?? null) === '1'): ?><p>Аккаунт создан. Войдите с вашим паролем.</p><?php endif; ?>
<form method="post" action="auth.php?page=<?= $escape($page) ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
<label>Логин<input name="login" autocomplete="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9_-]{3,32}" value="<?= $escape(is_string($login) ? $login : '') ?>" required></label>
<label>Пароль<input type="password" name="password" autocomplete="<?= $page === 'register' ? 'new-password' : 'current-password' ?>" required></label>
<?php if ($page === 'register'): ?><p>Логин: 3–32 латинских буквы, цифры, _ или -. Пароль: 10–72 байта.</p><?php endif; ?>
<button class="button"><?= $page === 'register' ? 'Создать аккаунт' : 'Войти' ?></button></form>
<p><a href="auth.php?page=<?= $page === 'register' ? 'login' : 'register' ?>"><?= $page === 'register' ? 'Войти' : 'Создать аккаунт' ?></a></p>
<?php endif; ?></main></body></html>
