<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\Autoloader;
use Berserk\Core\BattleHistory\HistoryRuntime;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
try {
    $runtime=HistoryRuntime::load();$invites=$runtime->invitations();
    if (!$invites) { http_response_code(404);exit; }
    $https=($_SERVER['HTTPS']??'')==='on';
    $local=$runtime->localAccessHttp() && in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)
        && in_array(strtolower(explode(':',$_SERVER['HTTP_HOST']??'')[0]),['127.0.0.1','localhost'],true);
    if (!$https && !$local) { http_response_code(403);exit('HTTPS required'); }
    if ($_GET || !in_array($_SERVER['REQUEST_METHOD']??'',['GET','POST'],true)) {http_response_code(400);exit('Invalid request');}
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.use_trans_sid','0');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Strict']);
    if (!session_start()) throw new RuntimeException('Session unavailable');
    $_SESSION['journal_access_csrf']??=bin2hex(random_bytes(32));
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (count($_POST)!==2 || !is_string($_POST['csrf']??null) || !hash_equals($_SESSION['journal_access_csrf'],$_POST['csrf'])
            || !is_string($_POST['invitation']??null) || isset($_SESSION['berserk_battle_journal_binding'])) throw new DomainException('Access denied');
        if (!session_regenerate_id(true)) throw new RuntimeException('Session unavailable');
        $grant=$invites->consume($_POST['invitation'],fn(int $id)=>$runtime->journals($id)&&$runtime->records($id));
        // This deliberately creates no authenticated_user_id and grants no replay principal.
        $_SESSION=['berserk_battle_journal_binding'=>$grant['binding']];
        if (!session_write_close()) throw new RuntimeException('Session unavailable');
        header('Location: /index.php?'.($grant['side']==='host'?'first':'second').'&game='.$grant['game_id'],true,303);exit;
    }
    $csrf=htmlspecialchars($_SESSION['journal_access_csrf'],ENT_QUOTES,'UTF-8');
    session_write_close();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Тестовый доступ к бою</title><h1>Тестовый доступ к бою</h1><form method="post" action="/journal-access.php"><input type="hidden" name="csrf" value="'.$csrf.'"><label>Одноразовое приглашение <input type="password" name="invitation" autocomplete="off" required maxlength="64"></label><button type="submit">Открыть бой</button></form></html>';
} catch (Throwable $e) {
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    http_response_code($e instanceof DomainException?403:503);
    echo 'Доступ не предоставлен. Обратитесь к организатору теста.';
}
