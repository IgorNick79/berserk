<?php
declare(strict_types=1);
// Loopback-only, explicit temporary JSON storage; never loads DB configuration.
if(!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)||!getenv('LILITH_TEST_STATE')){http_response_code(403);exit;}
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command};
use Berserk\View\{Template};
use Berserk\View\Screen\BattleSnapshotRenderer;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
session_start();if(!isset($_SESSION['side']))$_SESSION['side']=($_GET['side']??'host')==='player'?'player':'host';$side=$_SESSION['side'];
$f=fopen(getenv('LILITH_TEST_STATE'),'c+');flock($f,LOCK_EX);$raw=stream_get_contents($f);
if(!$raw){$s=new GameState(-971,1,2);$s->status='battle';$s->battle=['active'=>'player','turn'=>2,'strike'=>null,'hidden_row_revealed'=>true];
 $prop=json_decode(file_get_contents(__DIR__.'/../docs/lilith-eideric.prop.json'),true);
 foreach([[1,'host',3,3],[2,'player',6,3],[3,'host',1,2]] as [$id,$owner,$row,$col])$s->addCard(new CardInstance(instanceId:$id,ukid:'s1_'.$id,owner:$owner,zone:'field',row:$row,col:$col,hp:20,hpMax:20,prop:$id===1?$prop:[],classes:$id===3?['Демон']:[]));
 $s->addCard(new CardInstance(instanceId:10,ukid:'s1_10',owner:'host',zone:'deck',classes:['Демон','Нежить'],prop:['tremor_on_graveyard'=>true]));
 $s->addCard(new CardInstance(instanceId:11,ukid:'s1_11',owner:'host',zone:'graveyard',classes:['Нежить']));
}else $s=GameState::fromArray(json_decode($raw,true));
$ok=true;$error=null;if($_SERVER['REQUEST_METHOD']==='POST'){$data=json_decode(file_get_contents('php://input'),true);$r=(new Engine())->apply($s,$side,new Command($data['cmd'],$data['payload']??[]));$ok=$r->success;$error=$r->error;}
$info=[];foreach($s->cards as $c)$info[$c->ukid]=['name'=>$c->zone==='deck'?'Secret '.$c->ukid:'Creature','price'=>1,'health'=>5,'move'=>1,'strike'=>['weak'=>1,'medium'=>2,'strong'=>3],'elite'=>false,'prop'=>$c->prop];
$snapshot=(new BattleSnapshotRenderer(new Template(__DIR__.'/../templates')))->render($s,$side,$side,null,$info);
ftruncate($f,0);rewind($f);fwrite($f,json_encode($s->toArray()));flock($f,LOCK_UN);fclose($f);
header('Content-Type: application/json');echo json_encode(['ok'=>$ok,'error'=>$error,'snapshot'=>$snapshot],JSON_THROW_ON_ERROR);
