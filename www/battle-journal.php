<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db};
use Berserk\Core\BattleHistory\{HistoryRuntime,LiveJournal};
Autoloader::register(); Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='GET') throw new DomainException('method_not_allowed',405);
    foreach (array_keys($_GET) as $key) if (!in_array($key,['game','after','limit'],true)) throw new DomainException('invalid_parameter',400);
    $integer=static function(string $key,int $min,int $max,?int $default=null): int {
        $v=$_GET[$key]??$default;
        if (is_array($v)||filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]])===false) throw new DomainException('invalid_'.$key,400);
        return (int)$v;
    };
    $game=$integer('game',1,PHP_INT_MAX);$after=$integer('after',0,PHP_INT_MAX,0);$limit=$integer('limit',1,200,100);
    $runtime=HistoryRuntime::load();
    $account=\Berserk\Auth\AuthContext::start();
    $db=\Berserk\Auth\AuthContext::gameDb();
    \Berserk\Auth\GameAccess::resolve($db,$game,$account->id);
    $viewer=[\Berserk\Auth\AuthSession::PRINCIPAL=>$account->id];
    session_write_close();
    $runtime=HistoryRuntime::load($db);
    if (!$runtime->journals($game)) throw new DomainException('journal_unavailable',404);
    echo json_encode((new LiveJournal($db,$runtime))->read($game,$viewer,$after,$limit),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $code=$e instanceof DomainException?$e->getCode():503;
    http_response_code($code>=400&&$code<=599?$code:503);
    echo json_encode(['error'=>$e instanceof DomainException?$e->getMessage():'journal_unavailable']);
}
