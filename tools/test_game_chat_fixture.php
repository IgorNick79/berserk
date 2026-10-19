<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,GameChat,GameState,CardInstance,TurnTimer};
Autoloader::register(); Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$config=require __DIR__.'/../config/db.php';
$expected=realpath(getenv('CHAT_TEST_DATADIR') ?: '/nonexistent');
if (!$expected || $config['name']!=='berserk_chat_test' || file_exists(__DIR__.'/../.git')) throw new RuntimeException('Disposable source and DB required');
$db=new Db($config);$meta=$db->fetchOne('SELECT @@datadir AS dir,DATABASE() AS db');
if(realpath($meta['dir'])!==$expected || $meta['db']!==$config['name']) throw new RuntimeException('Wrong DB instance');
$repo=new GameRepository($db);$chat=new GameChat($db);
$mode=$argv[1] ?? 'verify';
if($mode==='verify'){echo json_encode($meta);exit;}
if($mode==='create' || $mode==='foreign') {
    $s=$repo->create($mode==='foreign'?101:1,$mode==='foreign'?202:2); $s->status='battle';
    $s->players['host']->side=1;$s->players['player']->side=2;
    $s->battle=['active'=>'host','turn'=>7,'strike'=>null,'hidden_row_revealed'=>true];
    foreach([1=>'host',2=>'player'] as $id=>$owner)$s->addCard(new CardInstance(instanceId:$id,ukid:'chat_fixture_'.$id,owner:$owner,zone:CardInstance::ZONE_FIELD,row:$id===1?3:4,col:3,hp:20,hpMax:20));
    TurnTimer::prepare($s,'host');$repo->save($s);
    echo json_encode(['game_id'=>$s->gameId]);exit;
}
$id=(int)($argv[2]??0);
if($mode==='age'){$db->query('UPDATE game_chat_messages SET created_at=DATE_SUB(NOW(6),INTERVAL 5 SECOND) WHERE game_id='.$id);echo '{}';exit;}
if($mode==='worker'){
    echo "READY\n";fflush(STDOUT);fgets(STDIN);
    echo json_encode($chat->send($id,(int)$argv[3],$argv[4],$argv[5]),JSON_UNESCAPED_UNICODE)."\n";exit;
}
$s=$repo->findById($id);
if($mode==='finish'){$s->status='game_over';$s->winner='host';$repo->save($s);}
if($mode==='turn'){$s->battle['turn']++;$s->battle['active']='player';$repo->save($s);}
if($mode==='pending'){$s->battle['pending']=['type'=>'test_chat_independence','owner'=>'player'];$s->battle['turn_timer']['anchor_at']=1;$repo->save($s);}
if($mode==='seed'){
    for($i=0;$i<121;$i++)$db->query("INSERT INTO game_chat_messages(game_id,sender_id,turn_number,message,request_id,created_at) VALUES ($id,2,7,'История $i 😀','seed-$i',DATE_SUB(NOW(6),INTERVAL 5 SECOND))");
}
echo json_encode(['game_id'=>$id,'state'=>$s->toArray(),'version'=>$s->persistenceVersion,
    'messages'=>$db->fetchAll('SELECT * FROM game_chat_messages WHERE game_id='.$id.' ORDER BY id'),
    'reads'=>$db->fetchAll('SELECT * FROM game_chat_reads WHERE game_id='.$id)],JSON_UNESCAPED_UNICODE);
