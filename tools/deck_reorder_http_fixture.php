<?php
declare(strict_types=1);
// Loopback-only, explicit temporary JSON storage; never loads DB configuration.
if(!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)||!getenv('SEER_TEST_STATE')){http_response_code(403);exit;}
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command};
use Berserk\View\{Template};
use Berserk\View\Screen\BattleSnapshotRenderer;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
session_start();if(!isset($_SESSION['side']))$_SESSION['side']=($_GET['side']??'host')==='player'?'player':'host';$side=$_SESSION['side'];
$f=fopen(getenv('SEER_TEST_STATE'),'c+');flock($f,LOCK_EX);$raw=stream_get_contents($f);
if(!$raw){$s=new GameState(-960,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>2,'strike'=>null,'hidden_row_revealed'=>true];
 foreach([[1,'host',3],[2,'player',4]] as [$id,$owner,$row])$s->addCard(new CardInstance(instanceId:$id,ukid:'s1_1',owner:$owner,zone:'field',row:$row,col:2,hp:20,hpMax:20,prop:$id===2?['opponent_turn_end'=>[['type'=>'deck_reorder','count'=>3]]]:[]));
 foreach(range(10,14) as $id){$c=new CardInstance(instanceId:$id,ukid:'s1_'.$id,owner:'player',zone:'deck');$c->order=$id;$s->addCard($c);}
}else $s=GameState::fromArray(json_decode($raw,true));
$ok=true;$error=null;if($_SERVER['REQUEST_METHOD']==='POST'){$data=json_decode(file_get_contents('php://input'),true);$r=(new Engine())->apply($s,$side,new Command($data['cmd'],$data['payload']??[]));$ok=$r->success;$error=$r->error;}
$info=[];foreach($s->cards as $c)$info[$c->ukid]=['name'=>$c->zone==='deck'?'Secret '.$c->ukid:'Creature','price'=>1,'health'=>5,'move'=>1,'strike'=>['weak'=>1,'medium'=>2,'strong'=>3],'elite'=>false,'prop'=>$c->prop];
$snapshot=(new BattleSnapshotRenderer(new Template(__DIR__.'/../templates')))->render($s,$side,$side,null,$info);
ftruncate($f,0);rewind($f);fwrite($f,json_encode($s->toArray()));flock($f,LOCK_UN);fclose($f);
header('Content-Type: application/json');echo json_encode(['ok'=>$ok,'error'=>$error,'snapshot'=>$snapshot],JSON_THROW_ON_ERROR);
