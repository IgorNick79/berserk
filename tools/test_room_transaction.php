<?php
declare(strict_types=1);
// Isolated lab only: supplied CLI config, never application/private config.
require dirname(__DIR__).'/src/Core/Autoloader.php';
Berserk\Core\Autoloader::register();
Berserk\Core\Autoloader::addNamespace('Berserk\\',dirname(__DIR__).'/src/');
use Berserk\Lobby\{RoomTransaction,MatchCreator,LobbyStore};
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$config = json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
$db = new mysqli($config['host'],$config['user'],$config['pass'],$config['name'],$config['port']);
$results = [];
function check(string $name, bool $ok): void { global $results; $results[]=['test'=>$name,'pass'=>$ok]; if (!$ok) throw new RuntimeException($name); }
function denied(callable $fn): bool { try { $fn(); return false; } catch (LogicException|TypeError $e) { return true; } }
check('bare mysqli cannot authorize MatchCreator',denied(fn()=>MatchCreator::create($db,1,1,2)));
$escaped=null;
RoomTransaction::run($db,function($t) use (&$escaped,$db) {
    $escaped=$t;
    check('managed nesting denied',denied(fn()=>RoomTransaction::run($db,fn()=>null)));
    check('context serialization denied',denied(fn()=>serialize($t)));
    check('unpending room denied',denied(fn()=>MatchCreator::create($t,2147483647,1,2)));
});
check('committed context cannot be reused',denied(fn()=>MatchCreator::create($escaped,1,1,2)));
try { RoomTransaction::run($db,function($t) use (&$escaped) { $escaped=$t; throw new RuntimeException('lab rollback'); }); } catch (RuntimeException) {}
check('rolled back context cannot be reused',denied(fn()=>MatchCreator::create($escaped,1,1,2)));
$store=new LobbyStore($config);
$id=$store->create(1);$store->act($id,2,1,null,'open');
$before=(int)$db->query('SELECT COUNT(*) AS n FROM games')->fetch_assoc()['n'];
check('unlinked match cannot commit',denied(function() use($db,$id,&$escaped) {
    RoomTransaction::run($db,function($t) use($id,&$escaped) {
        $escaped=$t;
        MatchCreator::create($t,$id,1,2);
        check('second match in same context denied',denied(fn()=>MatchCreator::create($t,$id,1,2)));
    });
}));
check('unlinked match fully rolled back',(int)$db->query('SELECT COUNT(*) AS n FROM games')->fetch_assoc()['n']===$before && $store->read($id,1)['state']==='awaiting_confirmation');
check('failed linkage invalidates context',denied(fn()=>MatchCreator::create($escaped,$id,1,2)));
check('wrong pending participants denied',denied(fn()=>RoomTransaction::run($db,fn($t)=>MatchCreator::create($t,$id,1,3))));
if (!str_contains($db->server_info,'MariaDB')) {
    $db->query("UPDATE lobby_rooms SET candidate_account_id=creator_account_id WHERE id=$id");
    $bad=$store->read($id,1);
    foreach (['accept','reject'] as $action) {
        check('ignored CHECK corrupt pending '.$action.' fails closed',denied(fn()=>$store->act($id,1,$bad['version'],$bad['candidate_request_id'],$action)));
    }
    $db->query("UPDATE lobby_rooms SET candidate_account_id=2 WHERE id=$id");
    $db->query("UPDATE lobby_rooms SET candidate_request_id='invalid' WHERE id=$id");
    check('ignored CHECK malformed request fails closed',denied(fn()=>$store->act($id,1,$bad['version'],'invalid','accept')));
    $stmt=$db->prepare('UPDATE lobby_rooms SET candidate_request_id=? WHERE id=?');$stmt->bind_param('si',$bad['candidate_request_id'],$id);$stmt->execute();$stmt->close();
}
// Fault injection at mysqli COMMIT boundary, not merely discarded HTTP response.
class LostCommit extends mysqli {
    public bool $armed=false;
    public bool $apply=true;
    public function commit(int $flags=0, ?string $name=null): bool {
        if (!$this->armed) return parent::commit($flags,$name);
        $this->armed=false;
        if ($this->apply) parent::commit($flags,$name);
        else parent::rollback();
        throw new RuntimeException('Synthetic lost COMMIT acknowledgement');
    }
}
$fault=new LostCommit($config['host'],$config['user'],$config['pass'],$config['name'],$config['port']);
$field=new ReflectionProperty(LobbyStore::class,'db');$field->setAccessible(true);$field->setValue($store,$fault);
foreach ([true,false] as $applied) {
    $room=$store->create(1);$store->act($room,2,1,null,'open');$waiting=$store->read($room,1);
    $n=(int)$db->query('SELECT COUNT(*) AS n FROM games')->fetch_assoc()['n'];
    $fault->apply=$applied;$fault->armed=true;$thrown=false;
    try { $store->act($room,1,$waiting['version'],$waiting['candidate_request_id'],'accept'); } catch (RuntimeException) { $thrown=true; }
    $after=$store->read($room,1);
    check('unknown commit '.($applied?'committed':'not committed').' read resolves outcome',$thrown && $after['state']===($applied?'started':'awaiting_confirmation') && (int)$db->query('SELECT COUNT(*) AS n FROM games')->fetch_assoc()['n']===$n+($applied?1:0));
    if ($applied) {
        $conflict=false;try{$store->act($room,1,$waiting['version'],$waiting['candidate_request_id'],'accept');}catch(DomainException $e){$conflict=$e->getCode()===409;}
        check('unknown committed retry never duplicates',$conflict);
    } else {
        $store->act($room,1,$after['version'],$after['candidate_request_id'],'accept');
        check('unknown uncommitted explicit retry once',$store->read($room,1)['state']==='started');
    }
}
check('no orphan games after faults',(int)$db->query('SELECT COUNT(*) AS n FROM games g LEFT JOIN lobby_rooms r ON r.game_id=g.ind WHERE g.ind<>1 AND g.host_id<>990001 AND r.id IS NULL')->fetch_assoc()['n']===0);
echo json_encode($results,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
