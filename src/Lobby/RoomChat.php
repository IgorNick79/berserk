<?php
declare(strict_types=1);
namespace Berserk\Lobby;
use Berserk\Core\GameChat;

/** Room-only history. The peer partitions candidates' conversations; no game is loaded or saved. */
final class RoomChat
{
    private \mysqli $db;
    public function __construct(array $config)
    {
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
        $this->db=new \mysqli($config['host'],$config['user'],$config['pass'],$config['name'],(int)($config['port']??3306));
        $this->db->set_charset('utf8mb4');
        foreach(['lobby_rooms','lobby_chat_messages','lobby_chat_reads'] as $table){
            $row=$this->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetch_assoc();
            if(($row['ENGINE']??null)!=='InnoDB')throw new \RuntimeException('Room chat schema unavailable');
        }
    }
    private function query(string $sql, array $params=[]): \mysqli_result|bool
    {
        $stmt=$this->db->prepare($sql);
        try {if($params)$stmt->bind_param(str_repeat('s',count($params)),...$params);$stmt->execute();return $stmt->get_result();}
        finally {$stmt->close();}
    }
    private function transaction(\Closure $operation): mixed
    {
        $this->db->begin_transaction();
        try {$result=$operation();$this->db->commit();return $result;}
        catch (\Throwable $e) {$this->db->rollback();throw $e;}
    }
    /** Lock serializes access validation with acceptance/rejection, without a room UPDATE. */
    private function room(int $room, int $actor, mixed $context, bool $required): array
    {
        $row=$this->query('SELECT creator_account_id,candidate_account_id,accepted_account_id,invited_account_id,state FROM lobby_rooms WHERE id=? FOR UPDATE',[$room])->fetch_assoc();
        if(!$row)throw new \DomainException('forbidden',403);
        $creator=(int)$row['creator_account_id'];
        $peer=match($row['state']){
            'open'=>(int)($row['invited_account_id']??0),
            'awaiting_confirmation'=>(int)$row['candidate_account_id'],
            'started'=>(int)$row['accepted_account_id'],
            default=>throw new \DomainException('chat_unavailable',403),
        };
        if($actor!==$creator && ($peer<=0 || $actor!==$peer))throw new \DomainException('forbidden',403);
        if($peer===$creator)throw new \DomainException('chat_unavailable',403);
        $key=hash('sha256',$room.':'.$peer);
        if(($required || $context!==null) && (!is_string($context) || !hash_equals($key,$context)))throw new \DomainException('chat_context_changed',409);
        return ['peer'=>$peer,'context'=>$key,'can_send'=>$peer>0 && $row['state']!=='started'];
    }
    private function message(array $row): array
    {
        return ['id'=>(string)$row['id'],'sender_id'=>(int)$row['sender_id'],'message'=>$row['message'],'created_at'=>$row['created_at']];
    }
    public function history(int $room, int $actor, mixed $context, ?string $after, ?string $before): array
    {
        if($after!==null && $before!==null)throw new \InvalidArgumentException('invalid_cursor');
        if($after!==null)$after=GameChat::cursor($after);
        if($before!==null)$before=GameChat::cursor($before);
        return $this->transaction(function()use($room,$actor,$context,$after,$before):array{
            $scope=$this->room($room,$actor,$context,false);$peer=$scope['peer'];
            $where='room_id=? AND peer_account_id=?';$params=[$room,$peer];
            if($after!==null){$where.=' AND id>?';$params[]=$after;}
            if($before!==null){$where.=' AND id<?';$params[]=$before;}
            $rows=$this->query('SELECT * FROM lobby_chat_messages WHERE '.$where.' ORDER BY id '.($after===null?'DESC':'ASC').' LIMIT 51',$params)->fetch_all(MYSQLI_ASSOC);
            $more=count($rows)>50;if($more)array_pop($rows);if($after===null)$rows=array_reverse($rows);
            $latest=$this->query('SELECT MAX(id) AS id FROM lobby_chat_messages WHERE room_id=? AND peer_account_id=?',[$room,$peer])->fetch_assoc();
            $read=$this->query('SELECT last_read_id FROM lobby_chat_reads WHERE room_id=? AND peer_account_id=? AND account_id=?',[$room,$peer,$actor])->fetch_assoc();$readId=(string)($read['last_read_id']??'0');
            $unread=$this->query('SELECT id FROM lobby_chat_messages WHERE room_id=? AND peer_account_id=? AND sender_id<>? AND id>? LIMIT 1',[$room,$peer,$actor,$readId])->fetch_assoc();
            return ['context'=>$scope['context'],'messages'=>array_map($this->message(...),$rows),'has_more'=>$more,'latest_id'=>(string)($latest['id']??'0'),'last_read_id'=>$readId,'unread'=>$unread!==null,'can_send'=>$scope['can_send'],'read_only_reason'=>$peer===0?'awaiting_participant':($scope['can_send']?null:'room_started')];
        });
    }
    public function send(int $room, int $actor, mixed $context, mixed $input, mixed $request): array
    {
        $text=GameChat::clean($input);
        if(!is_string($request) || !preg_match('/\A[A-Za-z0-9_-]{16,64}\z/D',$request))throw new \InvalidArgumentException('invalid_request_id');
        return $this->transaction(function()use($room,$actor,$context,$text,$request):array{
            $scope=$this->room($room,$actor,$context,true);$peer=$scope['peer'];
            $previous=$this->query('SELECT * FROM lobby_chat_messages WHERE room_id=? AND peer_account_id=? AND sender_id=? AND request_id=?',[$room,$peer,$actor,$request])->fetch_assoc();
            if($previous){if($previous['message']!==$text)throw new \InvalidArgumentException('request_id_conflict');return ['context'=>$scope['context'],'message'=>$this->message($previous)];}
            if(!$scope['can_send'])throw new \DomainException('chat_read_only',403);
            if($this->query('SELECT id FROM lobby_chat_messages WHERE room_id=? AND sender_id=? AND created_at>DATE_SUB(NOW(6),INTERVAL 1 SECOND) LIMIT 1',[$room,$actor])->fetch_assoc())throw new \OverflowException('rate_limited');
            $this->query('INSERT INTO lobby_chat_messages(room_id,peer_account_id,sender_id,message,request_id,created_at) VALUES(?,?,?,?,?,NOW(6))',[$room,$peer,$actor,$text,$request]);
            $row=$this->query('SELECT * FROM lobby_chat_messages WHERE id=?',[(string)$this->db->insert_id])->fetch_assoc();
            return ['context'=>$scope['context'],'message'=>$this->message($row)];
        });
    }
    public function read(int $room, int $actor, mixed $context, mixed $cursor): array
    {
        $cursor=GameChat::cursor($cursor);
        return $this->transaction(function()use($room,$actor,$context,$cursor):array{
            $scope=$this->room($room,$actor,$context,true);$peer=$scope['peer'];
            if($cursor!=='0'){
                if(!$this->query('SELECT id FROM lobby_chat_messages WHERE room_id=? AND peer_account_id=? AND id=?',[$room,$peer,$cursor])->fetch_assoc())throw new \InvalidArgumentException('invalid_cursor');
                $this->query('INSERT INTO lobby_chat_reads(room_id,peer_account_id,account_id,last_read_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_read_id=GREATEST(last_read_id,VALUES(last_read_id))',[$room,$peer,$actor,$cursor]);
            }
            return ['context'=>$scope['context']];
        });
    }
}
