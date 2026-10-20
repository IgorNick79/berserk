<?php
declare(strict_types=1);
if (getenv('BERSERK_STAGE4D1_ISOLATED')!=='1' || file_exists(__DIR__.'/../.git')) throw new RuntimeException('Disposable copy only');
require __DIR__.'/../src/Core/Autoloader.php';
Berserk\Core\Autoloader::register();Berserk\Core\Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
use Berserk\Core\BattleHistory\{JournalInvitations,HistoryRuntime};
$directory=getenv('BERSERK_STAGE4D1_ACCESS');if (!$directory) throw new RuntimeException('Private fixture directory required');
$access=new JournalInvitations($directory);
function check(bool $b):void {if (!$b) throw new RuntimeException('Access contract failed');}
$grant=$access->issue(7,'host',time()+60);$bound=$access->consume($grant['secret'],fn($g)=>$g===7);
check($access->side($bound['binding'],7)==='host');check($access->side($bound['binding'],8)===null);
try {$access->consume($grant['secret'],fn()=>true);throw new RuntimeException('Replay accepted');}catch(DomainException){}
$access->revoke($grant['id']);check($access->side($bound['binding'],7)===null);
$grant=$access->issue(7,'player',time()+60);
try {$access->consume($grant['secret'],fn()=>false);throw new RuntimeException('Disabled accepted');}catch(DomainException){}
$bound=$access->consume($grant['secret'],fn()=>true);check($access->side($bound['binding'],7)==='player');
check(!str_contains(file_get_contents($directory.'/journal-access.json'),$grant['secret']));
check(!str_contains(file_get_contents($directory.'/journal-access.json'),$bound['binding']));
check((new HistoryRuntime([]))->journalSide(7,1,2,['user_id'=>1,'role'=>'host'])===null);
check((new HistoryRuntime([]))->principal(['berserk_battle_journal_binding'=>$bound['binding']])===0);
echo "PASS one-time consumption, exact scope, revocation, disabled scope, hashed credentials, default OFF, no replay principal\n";
