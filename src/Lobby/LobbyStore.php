<?php
declare(strict_types=1);
namespace Berserk\Lobby;

final class LobbyStore
{
    private \mysqli $db;

    public function __construct(array $config, private ?\Closure $catalogFactory = null)
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

    private function visible(array $room, int $actor): bool
    {
        return ($room['invited_account_id']??null)===null || $actor===(int)$room['creator_account_id'] || $actor===(int)$room['invited_account_id'];
    }

    public function invitee(int $actor, mixed $access, mixed $login): ?int
    {
        if (!in_array($access,['open','closed'],true)) throw new \DomainException('Выберите доступ к партии.',400);
        if ($access==='open') return null;
        try {$key=\Berserk\Auth\Account::loginKey(is_string($login)?$login:'');}
        catch (\InvalidArgumentException) {throw new \DomainException('Укажите корректный никнейм приглашённого игрока.',400);}
        $row=$this->query('SELECT id FROM accounts WHERE login_key=?',[$key])->fetch_assoc();
        if (!$row) throw new \DomainException('Игрок с таким никнеймом не найден.',400);
        if ((int)$row['id']===$actor) throw new \DomainException('Нельзя пригласить самого себя.',400);
        return (int)$row['id'];
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
            'game_type'=>$row['game_type']??null,
            'kind'=>RoomSetup::label($row['game_type']??null),
            'created_at'=>str_replace(' ','T',$row['created_at']).'Z',
            'id'=>(int)$row['id'],'version'=>(int)$row['version'],'state'=>$row['state'],
            'creator'=>$row['creator_login'],'second'=>$row['second_login'],
            'status'=>$row['game_status'] === 'game_over' ? 'Завершена' : match($row['state']) {
                'open'=>'Открытая','awaiting_confirmation'=>'Ожидает подтверждения','started'=>'Игра идёт',
            },
            'is_mine'=>$member,
            'can_open'=>$member || $row['state'] === 'open',
            'can_decide'=>$actor === (int)$row['creator_account_id'] && $row['state'] === 'awaiting_confirmation',
        ];
        if ($private) {
            // Room presentation reads saved setup only; no transition or default form values.
            $setup=json_decode($row['setup_json']??'null',true,512,JSON_THROW_ON_ERROR);
            $room['access']=($row['invited_account_id']??null)===null?'open':'closed';
            $room['turn_seconds']=$setup['settings']['battle']['turn_seconds']??180;
            $room['game_id'] = $row['game_id'] === null ? null : (int)$row['game_id'];
            $room['role'] = $actor === (int)$row['creator_account_id'] ? 'creator' : ($row['state'] === 'started' ? 'accepted' : ($actor===(int)($row['invited_account_id']??0) && $row['state']==='open'?'invited':'candidate'));
            $room['candidate_request_id'] = $room['can_decide'] ? $row['candidate_request_id'] : null;
        }
        return $room;
    }

    private const SELECT_VIEW = 'SELECT r.*,c.login AS creator_login,s.login AS second_login,g.status AS game_status FROM lobby_rooms r JOIN accounts c ON c.id=r.creator_account_id LEFT JOIN accounts s ON s.id=COALESCE(r.candidate_account_id,r.accepted_account_id) LEFT JOIN games g ON g.ind=r.game_id';

    public function listing(int $actor): array
    {
        $rows = $this->query(self::SELECT_VIEW." WHERE (r.state<>'started' OR g.status<>'game_over') AND (r.invited_account_id IS NULL OR r.creator_account_id=? OR r.invited_account_id=?) ORDER BY r.id DESC",[$actor,$actor])->fetch_all(MYSQLI_ASSOC);
        return array_map(fn(array $r): array=>$this->projection($r,$actor,false),$rows);
    }

    public function read(int $id, int $actor): array
    {
        // One SELECT gives permissions and projection from the SAME database snapshot.
        $row = $this->query(self::SELECT_VIEW.' WHERE r.id=?',[$id])->fetch_assoc();
        if (!$row || !$this->visible($row,$actor) || (!$this->member($row,$actor) && $actor !== (int)($row['invited_account_id']??0))) throw new \DomainException('Доступ к комнате закрыт.',403);
        return $this->projection($row,$actor,true);
    }

    public function create(int $actor, ?array $setup = null, ?int $invited = null, ?string $creationKey = null): int
    {
        $setup=$setup===null?null:RoomSetup::validate($setup);
        if (isset($setup['debug']) && (($setup['debug']['account_id'] ?? null) !== $actor || !\Berserk\Auth\DebugAccess::allowsId($actor))) throw new \DomainException('Debug недоступен для создателя',403);
        if ($creationKey!==null && !preg_match('/\A[a-f0-9]{32}\z/D',$creationKey)) throw new \DomainException('Некорректный ключ создания.',400);
        if ($invited!==null && ($invited===$actor || !$this->query('SELECT id FROM accounts WHERE id=?',[$invited])->fetch_assoc())) throw new \DomainException('Некорректное приглашение.',400);
        $existing=function() use($actor,$creationKey,$setup,$invited): ?int {
            if ($creationKey===null) return null;
            $row=$this->query('SELECT id,setup_json,invited_account_id FROM lobby_rooms WHERE creator_account_id=? AND creation_key=?',[$actor,$creationKey])->fetch_assoc();
            if (!$row) return null;
            if ($row['setup_json']!==($setup===null?null:json_encode($setup,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) || ($row['invited_account_id']===null?null:(int)$row['invited_account_id'])!==$invited) throw new \DomainException('Ключ создания уже использован с другими настройками.',409);
            return (int)$row['id'];
        };
        if (($prior=$existing())!==null) return $prior;
        try { RoomTransaction::run($this->db, function (RoomTransaction $transaction) use ($actor, $setup, $invited, $creationKey, &$id): void {
            $this->query("INSERT INTO lobby_rooms(creator_account_id,invited_account_id,creation_key,state,version,created_at,updated_at) VALUES(?,?,?,'open',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$actor,$invited,$creationKey]);
            $id = (int)$this->db->insert_id;
            if ($setup !== null) {
                $json=json_encode(RoomSetup::validate($setup),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $this->query('UPDATE lobby_rooms SET game_type=?,setup_json=? WHERE id=?',[$setup['mode'],$json,$id]);
            }
            $this->row($id,true);
        });
        } catch (\mysqli_sql_exception $e) { if ($e->getCode()!==1062 || ($prior=$existing())===null) throw $e;return $prior; }
        return $id;
    }

    public function act(int $id, int $actor, int $version, ?string $request, string $action): void
    {
        RoomTransaction::run($this->db, function (RoomTransaction $transaction) use ($id, $actor, $version, $request, $action): void {
            $room = $this->row($id,true);
            if (!$this->visible($room,$actor)) throw new \DomainException('Доступ к комнате закрыт.',403);
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
                    $game = MatchCreator::create($transaction,$id,(int)$room['creator_account_id'],(int)$room['candidate_account_id'],$this->catalogFactory);
                    $this->query("UPDATE lobby_rooms SET accepted_account_id=candidate_account_id,candidate_account_id=NULL,candidate_request_id=NULL,game_id=?,state='started',version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?",[$game,$id]);
                } else { throw new \DomainException('Неизвестное действие.',400); }
            }
        });
    }

}
