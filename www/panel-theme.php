<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\Autoloader;
use Berserk\Auth\{AuthContext,AuthSession,AccountUiPreferences};
use Berserk\View\Ui\PanelTheme;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
try {
    $method=$_SERVER['REQUEST_METHOD']??'';
    if (!in_array($method,['GET','POST'],true)) throw new DomainException('method_not_allowed',405);
    $account=AuthContext::start();
    if ($_GET!==[]) throw new DomainException('invalid_parameter',400);
    if ($method==='POST') {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>1024 || array_diff(array_keys($_POST),['theme','csrf'])) throw new DomainException('invalid_parameter',400);
        AuthSession::requireCsrf($_POST['csrf']??null);
        if (!PanelTheme::valid($_POST['theme']??null)) throw new DomainException('invalid_theme',400);
    }
    $csrf=AuthSession::csrf();session_write_close();
    $config=require __DIR__.'/../config/auth.local.php';
    $store=new AccountUiPreferences($config['db']);
    $theme=$method==='POST'?$store->saveTheme($account->id,$_POST['theme']):$store->theme($account->id);
    echo json_encode(['ok'=>true,'theme'=>$theme,'catalog'=>PanelTheme::ITEMS,'csrf'=>$csrf],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $status=$e instanceof DomainException && $e->getCode()>=400 && $e->getCode()<=599?$e->getCode():503;
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$status===503?'theme_storage_unavailable':$e->getMessage()]);
}
