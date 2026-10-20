<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;

/** Private filesystem capability ledger. No SQL, plaintext credentials or HTTP-selected scopes. */
final class JournalInvitations
{
    private string $directory;
    public function __construct(string $directory)
    {
        $real=realpath($directory);
        if ($real===false || !is_dir($real) || is_link($directory)) throw new \RuntimeException('Private access directory unavailable');
        $real=str_replace('\\','/',$real);
        $root=str_replace('\\','/',realpath(dirname(__DIR__,3)));
        // Deliberately outside the entire checkout, not merely outside its document root.
        if (str_starts_with(strtolower($real).'/',strtolower($root).'/')) throw new \RuntimeException('Access directory must be outside application');
        $this->directory=$real;
    }
    public function directory(): string { return $this->directory; }
    private function ledger(bool $write, callable $operation): mixed
    {
        $lock=fopen($this->directory.'/journal-access.lock','c+');
        if (!$lock || !flock($lock,$write?LOCK_EX:LOCK_SH)) throw new \RuntimeException('Access lock unavailable');
        try {
            $path=$this->directory.'/journal-access.json';
            $data=is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];
            if (!is_array($data)) throw new \RuntimeException('Invalid access ledger');
            $result=$operation($data);
            if ($write) {
                $tmp=tempnam($this->directory,'access-');
                if ($tmp===false) throw new \RuntimeException('Access storage unavailable');
                try {
                    chmod($tmp,0600);
                    $json=json_encode($data,JSON_THROW_ON_ERROR);
                    if (file_put_contents($tmp,$json)!==strlen($json) || !rename($tmp,$path)) throw new \RuntimeException('Access storage failed');
                } finally { if (is_file($tmp)) unlink($tmp); }
            }
            return $result;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    public function issue(int $game,string $side,int $expires): array
    {
        if ($game<1 || !in_array($side,['host','player'],true) || $expires<=time() || $expires>time()+86400) throw new \InvalidArgumentException('Invalid scope or expiry (maximum 24 hours)');
        $id=bin2hex(random_bytes(16));$secret=bin2hex(random_bytes(32));
        $this->ledger(true,function(array &$data)use($id,$secret,$game,$side,$expires){
            $data[$id]=['invite_hash'=>hash('sha256',$secret),'game_id'=>$game,'side'=>$side,'expires_at'=>$expires,'used'=>false,'revoked'=>false];
        });
        return ['id'=>$id,'secret'=>$secret];
    }
    /** Called after session ID rotation. A failed session save burns the invite, never reopens it. */
    public function consume(string $secret,callable $allowed): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$secret)) throw new \DomainException('Access denied');
        return $this->ledger(true,function(array &$data)use($secret,$allowed){
            foreach ($data as &$row) {
                if (!hash_equals($row['invite_hash'],hash('sha256',$secret))) continue;
                if ($row['used'] || $row['revoked'] || $row['expires_at']<=time() || !$allowed($row['game_id'])) break;
                $binding=bin2hex(random_bytes(32));
                $row['used']=true;$row['binding_hash']=hash('sha256',$binding);
                return ['binding'=>$binding,'game_id'=>$row['game_id'],'side'=>$row['side']];
            }
            throw new \DomainException('Access denied');
        });
    }
    public function side(string $binding,int $game): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$binding)) return null;
        return $this->ledger(false,function(array &$data)use($binding,$game){
            foreach ($data as $row) if ($row['used'] && !$row['revoked'] && $row['expires_at']>time()
                && $row['game_id']===$game && isset($row['binding_hash']) && hash_equals($row['binding_hash'],hash('sha256',$binding))) return $row['side'];
            return null;
        });
    }
    public function revoke(string $id): void
    {
        $this->ledger(true,function(array &$data)use($id){
            if (!isset($data[$id])) throw new \InvalidArgumentException('Unknown grant');
            $data[$id]['revoked']=true;
        });
    }
}
