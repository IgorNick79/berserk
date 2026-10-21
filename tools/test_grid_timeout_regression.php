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
        public bool $failBuilder = false;
        public function __construct(public array $cards) {}
        public function escape(string $s): string { return addslashes($s); }
        public function fetchAll(string $sql): array {
            if ($this->failBuilder && str_contains($sql,'SELECT ukid, price')) throw new \RuntimeException('fixture builder failure');
            if (preg_match("/rarity = '([^']+)'/",$sql,$m)) return array_values(array_filter($this->cards,fn($c)=>($c['rarity']??'')===$m[1]));
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
    use Berserk\Core\{Autoloader,Db,GameState,GameSettings};
    use Berserk\Core\Prepare\{PrepareProcessor,DraftProcessor,DraftCopyRules,DraftAutoPicker,DraftSelectionEvaluator,BoosterGenerator};
    Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
    $f=json_decode(file_get_contents(__DIR__.'/../docs/evidence/stage5c/grid-timeout-fixture.json'),true,512,JSON_THROW_ON_ERROR);
    $db=new Db($f['catalog']);$catalog=array_column($f['catalog'],null,'ukid');$checks=0;$runs=[];
    $GLOBALS['grid_deterministic']=true;$GLOBALS['grid_now']=$f['state']['draft']['timer']['action_started_at']+1000;
    function check(bool $ok,string $why): void { global $checks;$checks++;if(!$ok)throw new RuntimeException($why); }
    function counts(array $a): array { $r=array_count_values($a);ksort($r);return $r; }
    function remaining(GameState $s): array { return array_values(array_filter(array_merge($s->draft['pool'],$s->draft['recycle']??[],$s->draft['grid']),fn($v)=>$v!==null)); }
    function inventory(GameState $s): array {
        if ($s->draft!==null) return counts(array_merge(remaining($s),$s->draft['picked']['host'],$s->draft['picked']['player']));
        $all=[];foreach($s->players as $p)foreach($p->deckCards as $c)for($i=0;$i<$c['count'];$i++)$all[]=$c['ukid'];return counts($all);
    }
    function invariants(GameState $s,array $before,array $catalog): void {
        check($s->status==='view'&&$s->draft===null,'Expected view');check(inventory($s)===$before,'No loss or duplication in decks');
        $instances=[];
        foreach($s->players as $key=>$p){
            $total=0;foreach($p->deckCards as $c){$total+=$c['count'];check($c['count']<=DraftCopyRules::deckLimit($catalog[$c['ukid']]),'3/5 cap');}
            check($total>=30,'Draft minimum30; view trimming to50 remains existing rule');
            $own=[];foreach($s->cards as $id=>$c)if($c->owner===$key){$own[]=$c->ukid;check(!isset($instances[$id]),'Unique instance ID');$instances[$id]=true;}
            $deck=[];foreach($p->deckCards as $c)for($i=0;$i<$c['count'];$i++)$deck[]=$c['ukid'];check(counts($own)===counts($deck),'Materialized copies equal deck');
        }
    }
    // The initial pool was valid. Locate the first unsafe historic decision, not only last card.
    $saved=json_decode(file_get_contents(__DIR__.'/../docs/evidence/stage5d/grid-timeout-runs.json'),true,512,JSON_THROW_ON_ERROR);
    $old=array_values(array_filter($saved['runs'],fn($r)=>$r['scenario']==='failing_seed'))[0];
    $original=GameState::fromArray($f['state']);$stock=remaining($original);$picked=$original->draft['picked'];
    check(DraftCopyRules::canCompleteDistribution($picked,$stock,$catalog,30),'Frozen input is feasible');
    check(DraftCopyRules::canCompleteDistribution(['host'=>[],'player'=>[]],array_merge($stock,...array_values($picked)),$catalog,30),'Generated full multiset feasible');
    $firstUnsafe=null;$history=$old['intermediate']['draft']['history'];
    foreach(array_slice($history,count($original->draft['history'])) as $i=>$step){
        foreach($step['cards'] as $id){$k=array_search($id,$stock,true);check($k!==false,'Historic conservation');unset($stock[$k]);$stock=array_values($stock);$picked[$step['player']][]=$id;}
        if(!DraftCopyRules::canCompleteDistribution($picked,$stock,$catalog,30)){$firstUnsafe=['decision_after_frozen'=>$i+1,'side'=>$step['player'],'taken'=>$step['cards'],'picked'=>array_map('count',$picked),'remaining'=>counts($stock)];break;}
    }
    check($firstUnsafe!==null,'Historic first infeasible prefix identified');
    // 1000 controlled RNG paths from the exact frozen input. Observe EVERY selected prefix.
    for($seed=0;$seed<1000;$seed++){
        mt_srand($seed);$s=GameState::fromArray($f['state']);$before=inventory($s);$prefixes=0;
        $real=new DraftAutoPicker(new DraftSelectionEvaluator($catalog,[]));
        $picker=new class($s,$db,$real,$prefixes){
            public function __construct(private GameState $s,private Db $db,private DraftAutoPicker $picker,public int &$prefixes){}
            public function pick(array $valid,array $current): ?array {
                $p=new DraftProcessor($this->s,$this->db);$legal=$p->validSelections();
                foreach($valid as $v){check(in_array($v,$legal,true),'Auto uses existing legal row/col');check($p->canCompleteGrid($v['positions']),'Every candidate preserves exact completion');}
                $this->prefixes++;return $this->picker->pick($valid,$current);
            }
        };
        $r=(new PrepareProcessor($s,$db,$picker))->resolveDraftTimeouts($GLOBALS['grid_now']);check($r->success,'Frozen seed'.$seed.':'.($r->error??''));invariants($s,$before,$catalog);
        $snap=$s->toArray();$r2=(new PrepareProcessor($s,$db))->resolveDraftTimeouts($GLOBALS['grid_now']);check($r2->success&&$snap===$s->toArray(),'Completed timeout idempotent');
        $runs[]=['seed'=>$seed,'prefixes'=>$prefixes,'status'=>$s->status,'hash'=>hash('sha256',json_encode($snap))];
    }
    // Every saved failing prefix is already impossible, and now errors without any mutation.
    $bad=0;foreach($saved['runs'] as $row)if(!$row['success']){
        $data=$f['state'];$data['draft']=$row['intermediate']['draft'];$s=GameState::fromArray($data);$snapshot=serialize($s);$r=(new PrepareProcessor($s,$db))->resolveDraftTimeouts($row['now']);
        check(!$r->success&&str_contains($r->error,'полного распределения'),'Explicit impossible completion');check(serialize($s)===$snapshot,'Impossible prefix atomic');$bad++;
    }
    function boundary(array $f,int $host,int $player,array $grid,array $pool=[],array $recycle=[]): GameState {
        $d=$f['state'];$d['draft']['picked']=['host'=>array_map(fn($i)=>'card_'.($i%20+1),range(0,$host-1)),'player'=>array_map(fn($i)=>'card_'.($i%20+41),range(0,$player-1))];
        $d['draft']['grid']=array_pad($grid,9,null);$d['draft']['pool']=$pool;$d['draft']['recycle']=$recycle;$d['draft']['history']=[];$d['draft']['turn']='host';return GameState::fromArray($d);
    }
    // Unique card only one side needs: active side must pass; reverse case also finishes.
    foreach([[29,30],[30,29]] as [$h,$p]){
        $s=boundary($f,$h,$p,['card_99']);$before=inventory($s);$r=(new PrepareProcessor($s,$db))->resolveDraftTimeouts($GLOBALS['grid_now']);check($r->success,'29/30 boundary');invariants($s,$before,$catalog);
    }
    // Fourth normal / sixth Horde are never forced. Mixed recipients and fully blocked cases.
    foreach([false,true] as $horde){
        $cat=$catalog;$cat['card_99']['prop']=$horde?' {"horde":true}':'{}';$local=new Db(array_values($cat));$cap=$horde?5:3;
        $s=boundary($f,30,29,['card_99']);$s->draft['picked']['host']=array_merge(array_fill(0,$cap,'card_99'),array_slice($s->draft['picked']['host'],$cap));
        $b=inventory($s);$r=(new PrepareProcessor($s,$local))->resolveDraftTimeouts($GLOBALS['grid_now']);check($r->success,'Cap card goes only to allowed player');invariants($s,$b,$cat);
        $s=boundary($f,30,29,['card_99']);foreach(['host','player'] as $key)$s->draft['picked'][$key]=array_merge(array_fill(0,$cap,'card_99'),array_slice($s->draft['picked'][$key],$cap));
        $snapshot=serialize($s);$r=(new PrepareProcessor($s,$local))->resolveDraftTimeouts($GLOBALS['grid_now']);check(!$r->success&&serialize($s)===$snapshot,'Both capped: explicit atomic error');
    }
    // A feasible stock can have no safe row/column on this layout. Recycle must still progress.
    $s=boundary($f,27,24,['card_99','card_98','card_97','card_96','card_99','card_95','card_94','card_93','card_99']);
    $s->draft['picked']['player']=array_merge(['card_99','card_99','card_99'],array_slice($s->draft['picked']['player'],3));
    $b=inventory($s);mt_srand(12);check((new DraftProcessor($s,$db))->canCompleteGrid(),'Geometry fixture allocation feasible');
    check((new DraftProcessor($s,$db))->automaticSelections()===[],'No safe current geometry');$r=(new PrepareProcessor($s,$db))->resolveDraftTimeouts($GLOBALS['grid_now']);check($r->success,'Existing pass/recycle reshuffle recovers geometry');invariants($s,$b,$catalog);
    // Failure AFTER earlier picks/finalization must restore all state, including persistence metadata.
    $s=GameState::fromArray($f['state']);$s->persistenceJson='fixture-persistence';$s->persistenceVersion=81;$snapshot=serialize($s);$db->failBuilder=true;
    try{(new PrepareProcessor($s,$db))->resolveDraftTimeouts($GLOBALS['grid_now']);throw new RuntimeException('Expected builder failure');}catch(RuntimeException $e){check($e->getMessage()==='fixture builder failure','Expected injected error');}
    check(serialize($s)===$snapshot,'Late exception fully atomic');$db->failBuilder=false;
    // Manual choices retain exact existing contract, including choices the AUTO policy avoids.
    $s=GameState::fromArray($f['state']);$p=new DraftProcessor($s,$db);$legal=$p->validSelections();
    foreach($legal as $selection){$copy=clone $s;$copy->players=array_map(fn($x)=>clone $x,$s->players);check((new DraftProcessor($copy,$db))->pickSelection($s->draft['turn'],$selection)->success,'Manual legal selection preserved');}
    // An old legal-but-unsafe manual row is still legal; only auto policy excludes it.
    $s=GameState::fromArray($f['state']);$picked=$s->draft['picked'];
    foreach(array_slice($history,count($s->draft['history']),16) as $step)$picked[$step['player']]=array_merge($picked[$step['player']],$step['cards']);
    $s->draft['picked']=$picked;$s->draft['pool']=[];$s->draft['recycle']=[];
    $s->draft['grid']=['card_22','card_37','card_23','card_15','card_27','card_6',null,null,null];$s->draft['turn']='host';
    $p=new DraftProcessor($s,$db);check($p->canCompleteGrid(),'Pre-decision17 allocation still feasible');
    check(in_array([0,1,2],array_column($p->validSelections(),'positions'),true),'Unsafe old manual row legal');
    check(!in_array([0,1,2],array_column($p->automaticSelections(),'positions'),true),'Auto excludes first historic unsafe row');
    check($p->pickRow('host',1)->success,'Manual legality unchanged even for old unsafe decision');
    check(!$p->canCompleteGrid(),'That manual choice really destroys completion');
    $s=boundary($f,29,29,['card_99']);$snapshot=serialize($s);
    $r=(new PrepareProcessor($s,$db))->resolveDraftTimeouts($GLOBALS['grid_now']);
    check(!$r->success&&serialize($s)===$snapshot,'Insufficient total stock explicit and atomic');
    // New legitimate generator pools, varied rarities and booster counts; never reject frozen pool.
    $generated=0;$genCatalog=$catalog;foreach($genCatalog as $id=>&$c){$c['rarity']=['common','uncommon','rare','ultrarare'][intdiv($c['ind']-1,25)];if($c['ind']%8===0)$c['prop']='{"horde":true}';}unset($c);
    $generatorDb=new Db(array_values($genCatalog));
    for($i=0;$i<100;$i++){
        mt_srand($i);$pool=(new BoosterGenerator($generatorDb,GameSettings::defaults()->boosterConfig()))->generatePool(5+($i%4));
        check(DraftCopyRules::canCompleteDistribution(['host'=>[],'player'=>[]],$pool,$genCatalog,30),'Generated pool feasible');
        $s=GameState::fromArray($f['state']);$s->draft['picked']=['host'=>[],'player'=>[]];$s->draft['grid']=array_splice($pool,0,9);$s->draft['pool']=$pool;$s->draft['recycle']=[];$s->draft['history']=[];
        $b=inventory($s);$r=(new PrepareProcessor($s,$generatorDb))->resolveDraftTimeouts($GLOBALS['grid_now']);check($r->success,'Generated pool timeout');invariants($s,$b,$genCatalog);$generated++;
    }
    echo json_encode(['checks'=>$checks,'frozen_paths'=>count($runs),'first_unsafe_historic_decision'=>$firstUnsafe,'saved_impossible_prefixes'=>$bad,'generated_pools'=>$generated,'runs'=>$runs],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
