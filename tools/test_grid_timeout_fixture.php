<?php
declare(strict_types=1);
// Test-only namespace adapters. Never loaded by application; no sockets/config/writes.
namespace Berserk\Core\Prepare {
    function random_int(int $a,int $b): int { return $GLOBALS['grid_deterministic'] ? mt_rand($a,$b) : \random_int($a,$b); }
    function shuffle(array &$a): bool { return \shuffle($a); }
    function time(): int { return $GLOBALS['grid_now']; }
}
namespace Berserk\Core {
    final class Db {
        public function __construct(public array $cards) {}
        public function escape(string $s): string { return addslashes($s); }
        public function fetchAll(string $sql): array {
            if (str_contains($sql,'FROM card_synergy')) return [];
            if (str_contains($sql,'FROM elements')) return [['ind'=>1,'code'=>'neutral']];
            if (preg_match('/ukid IN \(([^)]+)\)/',$sql,$m)) {
                preg_match_all("/'([^']+)'/",$m[1],$ids);
                return array_values(array_filter($this->cards,fn($c)=>in_array($c['ukid'],$ids[1],true)));
            }
            throw new \RuntimeException('Unexpected fixture SELECT: '.$sql);
        }
    }
}
namespace {
    require __DIR__.'/../src/Core/Autoloader.php';
    use Berserk\Core\{Autoloader,Db,GameState};
    use Berserk\Core\Prepare\PrepareProcessor;
    Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
    $path=__DIR__.'/../docs/evidence/stage5c/grid-timeout-fixture.json';
    $raw=file_get_contents($path);$f=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if ($f['synthetic_only']!==true || $f['state']['status']!=='draft' || count($f['catalog'])!==100) throw new RuntimeException('Fixture mismatch');
    $known=array_column($f['catalog'],null,'ukid');
    foreach (array_merge($f['state']['draft']['pool'],$f['state']['draft']['grid'],$f['state']['draft']['recycle'],$f['state']['draft']['picked']['host'],$f['state']['draft']['picked']['player']) as $id) if ($id!==null && !isset($known[$id])) throw new RuntimeException('Missing catalog card');
    function summary(GameState $s): array { return ['status'=>$s->status,'version'=>$s->version,'draft'=>$s->draft,'cards_count'=>count($s->cards),'state_sha256'=>hash('sha256',json_encode($s->toArray(),JSON_THROW_ON_ERROR))]; }
    $rows=[];
    foreach (['exhausted','failing_seed','before_deadline','action_expired','one_total_expired','missing_timer','terminal'] as $scenario) {
        for ($i=0;$i<($scenario==='exhausted'?150:3);$i++) {
            $seed=$scenario==='failing_seed'?12:($i<5?1701:$i);$GLOBALS['grid_deterministic']=$i<100;mt_srand($seed);
            $data=$f['state'];$start=$data['draft']['timer']['action_started_at'];$now=$start+1000;
            if ($scenario==='before_deadline') { $data['draft']['timer']['elapsed']=['host'=>0,'player'=>0];$now=$start+1; }
            if ($scenario==='action_expired') { $data['draft']['timer']['elapsed']=['host'=>0,'player'=>0];$now=$start+32; }
            if ($scenario==='one_total_expired') $data['draft']['timer']['elapsed'][$data['draft']['turn']==='host'?'player':'host']=0;
            if ($scenario==='missing_timer') unset($data['draft']['timer']);
            if ($scenario==='terminal') { $data['status']='view';$data['draft']=null; }
            $GLOBALS['grid_now']=$now;$s=GameState::fromArray($data);$before=summary($s);
            $p=new PrepareProcessor($s,new Db($f['catalog']));$r=$p->resolveDraftTimeouts($now);$middle=summary($s);
            $r2=$p->resolveDraftTimeouts($now);$after=summary($s);
            $rows[]=['scenario'=>$scenario,'run'=>$i,'deterministic'=>$GLOBALS['grid_deterministic'],'seed'=>$seed,'now'=>$now,'success'=>$r->success,'error'=>$r->error,'events'=>$r->events,'second_success'=>$r2->success,'second_error'=>$r2->error,'second_events'=>$r2->events,'before'=>$before,'intermediate'=>$middle,'final'=>$after];
        }
    }
    $repeated=array_values(array_filter($rows,fn($r)=>$r['scenario']==='failing_seed'));
    if (count($repeated)!==3 || count(array_unique(array_column(array_column($repeated,'final'),'state_sha256')))!==1) throw new RuntimeException('Non-deterministic seed12');
    foreach ($repeated as $r) if ($r['success'] || $r['second_success'] || $r['final']['status']!=='draft') throw new RuntimeException('Expected seed12 defect not reproduced');
    foreach ($rows as $r) if (!in_array($r['scenario'],['exhausted','failing_seed'],true) && !$r['success']) throw new RuntimeException('Unexpected scenario failure');
    $normalized=GameState::fromArray($f['state'])->toArray();
    if ($normalized!==GameState::fromArray($normalized)->toArray()) throw new RuntimeException('Roundtrip mismatch');
    echo json_encode(['fixture_sha256'=>hash('sha256',$raw),'fixture_integrity'=>'pass','adapter'=>'in-memory read-only catalog, empty synergy; test-only RNG/time namespace wrappers','runs'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
