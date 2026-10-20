<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\Autoloader;
use Berserk\Core\BattleHistory\HistoryRuntime;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
try {
    $runtime=HistoryRuntime::load();$access=$runtime->invitations();
    if (!$access) throw new RuntimeException('Invitation access disabled');
    if (($argv[1]??'')==='revoke' && count($argv)===3) {
        $access->revoke($argv[2]);echo "Revoked\n";exit;
    }
    if (($argv[1]??'')!=='issue' || count($argv)!==6) throw new RuntimeException('Usage: issue GAME host|player TTL_SECONDS PRIVATE_NEW_FILE | revoke GRANT_ID');
    $game=filter_var($argv[2],FILTER_VALIDATE_INT);$ttl=filter_var($argv[4],FILTER_VALIDATE_INT);
    if (!$game || !$ttl || $ttl<1 || $ttl>86400 || !$runtime->records($game) || !$runtime->journals($game)) throw new RuntimeException('Invalid or disabled scope');
    // Output must stay in the same operator-protected directory as the ledger.
    $out=$argv[5];$parent=realpath(dirname($out));
    if (str_replace('\\','/',$parent?:'')!==$access->directory()) throw new RuntimeException('Output must be in private access directory');
    $handle=fopen($out,'x');if (!$handle) throw new RuntimeException('Output already exists or cannot be created');
    chmod($out,0600);
    try {
        $grant=$access->issue($game,$argv[3],time()+$ttl);
        $body=json_encode($grant,JSON_THROW_ON_ERROR);
        if (fwrite($handle,$body)!==strlen($body)) { $access->revoke($grant['id']);throw new RuntimeException('Output failed; grant revoked'); }
    } finally {fclose($handle);}
    echo "Invitation saved privately; no credential printed\n";
} catch (Throwable $e) {fwrite(STDERR,"Access operation failed: ".$e->getMessage()."\n");exit(1);}
