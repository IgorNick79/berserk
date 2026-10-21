<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db};
use Berserk\Core\BattleHistory\{HistoryRuntime,ReplayPreview};
use Berserk\View\{Template};
use Berserk\View\Screen\ReplayScreen;
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__.'/../src/');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
$api = isset($_GET['api']);
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw new DomainException('method_not_allowed',405);
    $int = static function(string $key, ?int $default=null): int {
        $value = $_GET[$key] ?? $default;
        if (is_array($value) || filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) === false) throw new DomainException('invalid_'.$key,400);
        return (int)$value;
    };
    $game = $int('game');
    // Read and close: viewing never changes even the session principal or legacy role.
    $account = \Berserk\Auth\AuthContext::start();
    $principal = $account->id;
    $runtime = HistoryRuntime::load();
    if ($principal === 0) throw new DomainException('authentication_required',401);
    if (!$runtime->previews($game)) throw new DomainException('replay_not_available',404);
    $db = \Berserk\Auth\AuthContext::gameDb();
    \Berserk\Auth\GameAccess::resolve($db,$game,$principal);
    session_write_close();
    $preview = (new ReplayPreview($db,$runtime))->read($game,$principal);
    if ($api) {
        $kind = $_GET['api'];
        if (!is_string($kind) || !in_array($kind,['metadata','steps','step'],true)) throw new DomainException('invalid_api',400);
        if ($kind === 'metadata') $response = $preview['metadata']+['gaps'=>$preview['gaps']];
        elseif ($kind === 'steps') $response = ['steps'=>array_map(static function(array $step):array { unset($step['state']); return $step; },$preview['steps']), 'gaps'=>$preview['gaps']];
        else {
            $seq = $int('step'); $response = null;
            foreach ($preview['steps'] as $step) if ($step['step_seq'] === $seq) $response = $step;
            if ($response === null) throw new DomainException('step_not_found',404);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo (new ReplayScreen(new Template(__DIR__.'/../templates')))->render($preview,$int('step',1));
    }
} catch (Throwable $error) {
    $code = $error instanceof DomainException ? $error->getCode() : ($error instanceof UnexpectedValueException ? 409 : 503);
    http_response_code($code >= 400 && $code <= 599 ? $code : 503);
    $message = $error instanceof DomainException ? $error->getMessage() : ($error instanceof UnexpectedValueException ? 'snapshot_integrity_failure' : 'replay_unavailable');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>$message]);
}
