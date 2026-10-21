<?php
declare(strict_types=1);
namespace Berserk\Lobby;

final class LobbyStore
{
    private \mysqli $db;

    public function __construct(array $config)
    {
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
        $this->db = new \mysqli($config['host'],$config['user'],$config['pass'],$config['name'],(int)($config['port'] ?? 3306));
        $this->db->set_charset('utf8mb4');
        foreach (['accounts','games','lobby_rooms'] as $table) {
            $row = $this->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetch_assoc();
            if (($row['ENGINE'] ?? '') !== 'InnoDB') throw new \RuntimeException('Lobby schema unavailable');
        }
        $this->query('SELECT id,creator_account_id,candidate_account_id,accepted_account_id,candidate_request_id,game_id,state,version,created_at,updated_at FROM lobby_rooms LIMIT 0');
        $this->query('SELECT ind,host_id,player_id,status,json,version,date,active FROM games LIMIT 0');
        $indexes = $this->query("SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lobby_rooms' AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetch_all(MYSQLI_ASSOC);
        $unique = [];
        foreach ($indexes as $index) $unique[$index['INDEX_NAME']][] = $index['COLUMN_NAME'];
        if (($unique['PRIMARY'] ?? []) !== ['id'] || !in_array(['game_id'],$unique,true)) throw new \RuntimeException('Lobby schema unavailable');
    }

    private function query(string $sql, array $params = []): \mysqli_result|bool
    {
        $stmt = $this->db->prepare($sql);
        try {
            if ($params) $stmt->bind_param(str_repeat('s',count($params)),...$params);
            $stmt->execute(); return $stmt->get_result();
        } finally { $stmt->close(); }
    }

    private function row(int $id, bool $lock = false): array
    {
        $row = $this->query('SELECT * FROM lobby_rooms WHERE id=?'.($lock ? ' FOR UPDATE' : ''),[$id])->fetch_assoc();
        if (!$row) throw new \DomainException('Комната не найдена.',404);
        // MySQL 5.7 ignores CHECK: validate persisted invariants before any transition.
        $emptyCandidate = $row['candidate_account_id'] === null && $row['candidate_request_id'] === null;
        $valid = (int)$row['creator_account_id'] > 0 && (int)$row['version'] > 0 && match ($row['state']) {
            'open' => $emptyCandidate && $row['accepted_account_id'] === null && $row['game_id'] === null,
            'awaiting_confirmation' => (int)$row['candidate_account_id'] > 0
                && (int)$row['candidate_account_id'] !== (int)$row['creator_account_id']
                && is_string($row['candidate_request_id']) && preg_match('/\A[0-9a-f]{64}\z/D', $row['candidate_request_id'])
                && $row['accepted_account_id'] === null && $row['game_id'] === null,
            'started' => $emptyCandidate && (int)$row['accepted_account_id'] > 0
                && (int)$row['accepted_account_id'] !== (int)$row['creator_account_id'] && (int)$row['game_id'] > 0,
            default => false,
        };
        if (!$valid) throw new \LogicException('Invalid room state');
        return $row;
    }

    private function member(array $room, int $actor): bool
    {
        return $actor === (int)$room['creator_account_id']
            || ($room['state'] === 'awaiting_confirmation' && $actor === (int)$room['candidate_account_id'])
            || ($room['state'] === 'started' && $actor === (int)$room['accepted_account_id']);
    }

    private function projection(array $row, int $actor, bool $private): array
    {
        $member = $this->member($row,$actor);
        $room = [
            'id'=>(int)$row['id'],'version'=>(int)$row['version'],'state'=>$row['state'],
            'creator'=>$row['creator_login'],'second'=>$row['second_login'],
            'status'=>$row['game_status'] === 'game_over' ? 'Завершена' : match($row['state']) {
                'open'=>'Открытая','awaiting_confirmation'=>'Ожидает подтверждения','started'=>'Игра идёт',
            },
            'can_open'=>$member || $row['state'] === 'open',
            'can_decide'=>$actor === (int)$row['creator_account_id'] && $row['state'] === 'awaiting_confirmation',
        ];
        if ($private) {
            $room['game_id'] = $row['game_id'] === null ? null : (int)$row['game_id'];
            $room['role'] = $actor === (int)$row['creator_account_id'] ? 'creator' : ($row['state'] === 'started' ? 'accepted' : 'candidate');
            $room['candidate_request_id'] = $room['can_decide'] ? $row['candidate_request_id'] : null;
        }
        return $room;
    }

    private const SELECT_VIEW = 'SELECT r.*,c.login AS creator_login,s.login AS second_login,g.status AS game_status FROM lobby_rooms r JOIN accounts c ON c.id=r.creator_account_id LEFT JOIN accounts s ON s.id=COALESCE(r.candidate_account_id,r.accepted_account_id) LEFT JOIN games g ON g.ind=r.game_id';

    public function listing(int $actor): array
    {
        $rows = $this->query(self::SELECT_VIEW." WHERE r.state<>'started' OR g.status<>'game_over' ORDER BY r.id DESC")->fetch_all(MYSQLI_ASSOC);
        return array_map(fn(array $r): array=>$this->projection($r,$actor,false),$rows);
    }

    public function read(int $id, int $actor): array
    {
        // One SELECT gives permissions and projection from the SAME database snapshot.
        $row = $this->query(self::SELECT_VIEW.' WHERE r.id=?',[$id])->fetch_assoc();
        if (!$row || !$this->member($row,$actor)) throw new \DomainException('Доступ к комнате закрыт.',403);
        return $this->projection($row,$actor,true);
    }

    public function create(int $actor): int
    {
        RoomTransaction::run($this->db, function (RoomTransaction $transaction) use ($actor, &$id): void {
            $this->query("INSERT INTO lobby_rooms(creator_account_id,state,version,created_at,updated_at) VALUES(?,'open',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$actor]);
            $id = (int)$this->db->insert_id;
            $this->row($id,true);
        });
        return $id;
    }

    public function act(int $id, int $actor, int $version, ?string $request, string $action): void
    {
        RoomTransaction::run($this->db, function (RoomTransaction $transaction) use ($id, $actor, $version, $request, $action): void {
            $room = $this->row($id,true);
            // Check permission under lock before revealing version/request state.
            if ($action !== 'open' && $actor !== (int)$room['creator_account_id']) throw new \DomainException('Только создатель принимает решение.',403);
            if ($action === 'open' && $room['state'] !== 'open' && !$this->member($room,$actor)) throw new \DomainException('Место уже занято.',403);
            if ((int)$room['version'] !== $version || $version >= 4294967295) throw new \DomainException('Состояние изменилось. Обновите комнату.',409);
            if ($action === 'open') {
                if ($room['state'] === 'open' && $actor !== (int)$room['creator_account_id']) {
                    $this->query("UPDATE lobby_rooms SET candidate_account_id=?,candidate_request_id=?,state='awaiting_confirmation',version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?",[$actor,bin2hex(random_bytes(32)),$id]);
                }
            } else {
                if ($room['state'] !== 'awaiting_confirmation' || !is_string($request) || !hash_equals($room['candidate_request_id'] ?? '',$request)) throw new \DomainException('Заявка устарела.',409);
                if ($action === 'reject') {
                    $this->query("UPDATE lobby_rooms SET candidate_account_id=NULL,candidate_request_id=NULL,state='open',version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?",[$id]);
                } elseif ($action === 'accept') {
                    $game = MatchCreator::create($transaction,$id,(int)$room['creator_account_id'],(int)$room['candidate_account_id']);
                    $this->query("UPDATE lobby_rooms SET accepted_account_id=candidate_account_id,candidate_account_id=NULL,candidate_request_id=NULL,game_id=?,state='started',version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?",[$game,$id]);
                } else { throw new \DomainException('Неизвестное действие.',400); }
            }
        });
    }

}
