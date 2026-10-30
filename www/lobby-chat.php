<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Auth\{AuthContext,AuthSession};
use Berserk\Core\{Autoloader,GameChat};
use Berserk\Lobby\RoomChat;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
try{
    $account=AuthContext::start();$method=$_SERVER['REQUEST_METHOD']??'';
    if(!in_array($method,['GET','POST'],true))throw new DomainException('method_not_allowed',405);
    if(array_diff(array_keys($_GET),['room','ajax','after_id','before_id','context']))throw new DomainException('invalid_parameter',400);
    $id=$_GET['room']??null;
    if(!is_string($id) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',$id) || (float)$id>2147483647)throw new DomainException('invalid_room',400);
    $action=$_GET['ajax']??null;
    if(!in_array($action,['chat_messages','chat_send','chat_read'],true))throw new DomainException('unknown_endpoint',400);
    if(($action==='chat_messages')!==($method==='GET'))throw new DomainException('method_not_allowed',405);
    if($method==='POST'){
        if((int)($_SERVER['CONTENT_LENGTH']??0)>20000 || array_diff(array_keys($_POST),['csrf','context','message','request_id','last_read_id']))throw new DomainException('invalid_parameter',400);
        AuthSession::requireCsrf($_POST['csrf']??null);
    }
    session_write_close();$config=require __DIR__.'/../config/auth.local.php';$chat=new RoomChat($config['lobby_db']??$config['db']);
    $context=($method==='POST'?$_POST:$_GET)['context']??null;
    $result=match($action){
        'chat_messages'=>$chat->history((int)$id,$account->id,$context,isset($_GET['after_id'])?GameChat::cursor($_GET['after_id']):null,isset($_GET['before_id'])?GameChat::cursor($_GET['before_id']):null),
        'chat_send'=>$chat->send((int)$id,$account->id,$context,$_POST['message']??null,$_POST['request_id']??null),
        'chat_read'=>$chat->read((int)$id,$account->id,$context,$_POST['last_read_id']??null),
    };
    echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    $status=$e instanceof DomainException?$e->getCode():($e instanceof InvalidArgumentException?400:($e instanceof OverflowException?429:503));
    http_response_code($status>=400&&$status<=599?$status:503);echo json_encode(['ok'=>false,'error'=>$status===503?'chat_unavailable':$e->getMessage()]);
}
