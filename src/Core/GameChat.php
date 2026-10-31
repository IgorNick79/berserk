<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Independent storage: never invokes Engine, progress handlers or GameRepository::save. */
final class GameChat
{
    public function __construct(private Db $db) {}

    public static function clean(mixed $input): string
    {
        if (!is_string($input) || strlen($input) > 16000 || !preg_match('//u', $input)) {
            throw new \InvalidArgumentException('invalid_message');
        }
        $text = trim(strip_tags($input));
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        if ($text === '' || !preg_match('/[^\s\p{Z}\p{Cf}]/u', $text)) throw new \InvalidArgumentException('empty_message');
        if (preg_match_all('/./us', $text) > 1000) throw new \InvalidArgumentException('message_too_long');
        return $text;
    }

    private function game(int $gameId, int $sender, bool $lock = false): array
    {
        $row = $this->db->fetchOne('SELECT ind, host_id, player_id, status, json FROM games WHERE ind = '.$gameId.($lock ? ' FOR UPDATE' : ''));
        if (!$row || !in_array($sender, [(int)$row['host_id'], (int)$row['player_id']], true)) throw new \DomainException('forbidden');
        return $row;
    }

    public function send(int $gameId, int $sender, mixed $input, mixed $requestId): array
    {
        $text = self::clean($input);
        if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $requestId)) throw new \InvalidArgumentException('invalid_request_id');
        $escapedId = $this->db->escape($requestId);
        $this->db->beginTransaction();
        try {
            // Serializes chat inserts with each other and turn/terminal writes. No game update.
            $game = $this->game($gameId, $sender, true);
            $previous = $this->db->fetchOne("SELECT * FROM game_chat_messages WHERE game_id=$gameId AND sender_id=$sender AND request_id='$escapedId'");
            if ($previous) {
                if ($previous['message'] !== $text) throw new \InvalidArgumentException('request_id_conflict');
                $this->db->commit();
                return $this->message($previous);
            }
            if (!in_array($game['status'], ['battle','view'], true)) throw new \DomainException('chat_read_only');
            $recent = $this->db->fetchOne("SELECT id FROM game_chat_messages WHERE game_id=$gameId AND sender_id=$sender AND created_at > DATE_SUB(NOW(6), INTERVAL 1 SECOND) LIMIT 1");
            if ($recent) throw new \OverflowException('rate_limited');
            $data = json_decode($game['json'], true, 512, JSON_THROW_ON_ERROR);
            $turn = max(0, (int)($data['battle']['turn'] ?? 0));
            $escaped = $this->db->escape($text);
            $this->db->query("INSERT INTO game_chat_messages (game_id,sender_id,turn_number,message,request_id,created_at) VALUES ($gameId,$sender,$turn,'$escaped','$escapedId',NOW(6))");
            $id = $this->db->lastInsertId();
            $row = $this->db->fetchOne('SELECT * FROM game_chat_messages WHERE id='.$id);
            $this->db->commit();
            return $this->message($row);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollback();
            throw $e;
        }
    }

    private function message(array $row): array
    {
        // BIGINT cursors are decimal strings to avoid JavaScript precision loss.
        return ['id'=>(string)$row['id'], 'sender_id'=>(int)$row['sender_id'],
            'turn_number'=>(int)$row['turn_number'], 'message'=>$row['message'], 'created_at'=>$row['created_at']];
    }

    public static function cursor(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value)) throw new \InvalidArgumentException('invalid_cursor');
        return $value;
    }

    public function history(int $gameId, int $sender, ?string $after, ?string $before): array
    {
        $game = $this->game($gameId, $sender);
        if (!in_array($game['status'], ['battle','game_over','view'], true)) throw new \DomainException('chat_unavailable');
        if ($after !== null && $before !== null) throw new \InvalidArgumentException('invalid_cursor');
        // A consistent projection keeps the notification baseline and fetched page in agreement.
        $this->db->beginTransaction();
        try {
            $where = "game_id=$gameId";
            $descending = $after === null;
            if ($after !== null) $where .= ' AND id > '.self::cursor($after);
            if ($before !== null) $where .= ' AND id < '.self::cursor($before);
            $rows = $this->db->fetchAll('SELECT * FROM game_chat_messages WHERE '.$where.' ORDER BY id '.($descending?'DESC':'ASC').' LIMIT 51');
            $more = count($rows) > 50;
            if ($more) array_pop($rows);
            if ($descending) $rows = array_reverse($rows);
            $latest = $this->db->fetchOne("SELECT MAX(id) AS id FROM game_chat_messages WHERE game_id=$gameId");
            $read = $this->db->fetchOne("SELECT last_read_id FROM game_chat_reads WHERE game_id=$gameId AND player_id=$sender");
            $readId = (string)($read['last_read_id'] ?? '0');
            $unread = $this->db->fetchOne("SELECT id FROM game_chat_messages WHERE game_id=$gameId AND sender_id<>$sender AND id>$readId LIMIT 1");
            $this->db->commit();
            return ['messages'=>array_map($this->message(...),$rows), 'has_more'=>$more,
                'latest_id'=>(string)($latest['id'] ?? '0'), 'last_read_id'=>$readId,
                'unread'=>$unread !== null, 'can_send'=>in_array($game['status'], ['battle','view'], true)];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollback();
            throw $e;
        }
    }

    public function read(int $gameId, int $sender, mixed $id): void
    {
        $this->game($gameId, $sender);
        $id = self::cursor($id);
        if ($id === '0') return;
        if (!$this->db->fetchOne("SELECT id FROM game_chat_messages WHERE game_id=$gameId AND id=$id")) throw new \InvalidArgumentException('invalid_cursor');
        $this->db->query("INSERT INTO game_chat_reads (game_id,player_id,last_read_id) VALUES ($gameId,$sender,$id) ON DUPLICATE KEY UPDATE last_read_id=GREATEST(last_read_id,VALUES(last_read_id))");
    }
}
