<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\Autoloader;
use Berserk\Auth\{AuthContext,AuthSession,AccountUiPreferences,GameAccess};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
try {
    $method=$_SERVER['REQUEST_METHOD']??'';
    if (!in_array($method,['GET','POST'],true)) throw new DomainException('method_not_allowed',405);
    $account=AuthContext::start();
    if (array_diff(array_keys($_GET),['game'])) throw new DomainException('invalid_parameter',400);
    $peer=null;
    if (isset($_GET['game'])) {
        if (!is_string($_GET['game']) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',$_GET['game'])) throw new DomainException('invalid_game',400);
        $game=(int)$_GET['game'];$db=AuthContext::gameDb();GameAccess::resolve($db,$game,$account->id);
        $row=$db->fetchOne('SELECT host_id,player_id FROM games WHERE ind='.$game);
        $peer=(int)$row[(int)$row['host_id']===$account->id?'player_id':'host_id'];
    }
    if ($method==='POST') {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>1024 || array_diff(array_keys($_POST),['key','value','csrf'])) throw new DomainException('invalid_parameter',400);
        AuthSession::requireCsrf($_POST['csrf']??null);
        if (!in_array($_POST['key']??null,['sound_enabled','playmat_id'],true) || !is_string($_POST['value']??null)) throw new DomainException('invalid_preference',400);
    }
    $csrf=AuthSession::csrf();session_write_close();
    $config=require __DIR__.'/../config/auth.local.php';$store=new AccountUiPreferences($config['db']);
    $own=$method==='POST'?$store->save($account->id,$_POST['key'],$_POST['value']):$store->snapshot($account->id);
    $other=$peer===null?null:$store->snapshot($peer);
    // Only peer mat is public to a participant; never expose their sound/theme/account token.
    echo json_encode(['ok'=>true,'preferences'=>$own,'opponent'=>$other===null?null:['playmat_id'=>$other['playmat_id'],'revision'=>$other['revision']],'csrf'=>$csrf],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $status=$e instanceof DomainException?$e->getCode():($e instanceof InvalidArgumentException?400:503);
    http_response_code($status>=400&&$status<=599?$status:503);
    echo json_encode(['ok'=>false,'error'=>$status===503?'preferences_storage_unavailable':$e->getMessage()]);
}
