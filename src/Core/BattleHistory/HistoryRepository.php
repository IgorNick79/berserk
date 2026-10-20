<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;

use Berserk\Core\Db;

/** Opt-in storage only. Never starts/commits transactions, creates schema or calls Engine. */
final class HistoryRepository
{
    public function __construct(private Db $db) {}

    /** Explicit provisioning in a caller-owned transaction, before the first recorded command. */
    public function initialize(int $gameId, string $status = 'recording', string $completeness = 'complete'): void
    {
        $this->transaction();
        if ($gameId <= 0 || !in_array($status, ['recording','disabled'], true)
            || !in_array($completeness, ['complete','incomplete','none'], true)
            || ($status === 'disabled') !== ($completeness === 'none')) throw new \InvalidArgumentException('Invalid history provisioning');
        if ($this->db->fetchOne("SELECT ind FROM games WHERE ind=$gameId FOR UPDATE") === null) throw new \LogicException('Missing game');
        $this->insert('battle_history_matches', ['game_id'=>$gameId,'recording_status'=>$status,'completeness'=>$completeness,
            'event_version'=>1,'snapshot_version'=>1,'created_at'=>$this->now(),'updated_at'=>$this->now()]);
    }

    /**
     * Caller: begin -> journal.requireProcessing -> game lock/CAS save -> append -> journal.markCommitted -> COMMIT.
     * Any exception MUST roll back the entire caller transaction. No partial-error recovery/commit is supported.
     * Returned rows are provisional until that COMMIT is acknowledged.
     */
    public function append(int $gameId, int $journalId, int $afterVersion, HistoryBatch $batch): array
    {
        $this->transaction();
        if ($gameId <= 0 || $journalId <= 0 || $afterVersion < 1) throw new \InvalidArgumentException('Invalid identity/version');
        // Same lock order as commitJournalCommand: journal -> games -> history metadata.
        $command = $this->db->fetchOne("SELECT * FROM battle_command_journal WHERE id=$journalId FOR UPDATE");
        if ($command === null || $command['status'] !== 'processing' || (int)$command['game_id'] !== $gameId
            || (int)$command['expected_version'] + 1 !== $afterVersion) throw new \LogicException('Not the processing command/version');
        $game = $this->db->fetchOne("SELECT version,status FROM games WHERE ind=$gameId FOR UPDATE");
        if ($game === null || (int)$game['version'] !== $afterVersion) throw new \LogicException('Game must be CAS-saved in this transaction first');
        $meta = $this->db->fetchOne("SELECT * FROM battle_history_matches WHERE game_id=$gameId FOR UPDATE");
        if ($meta === null || $meta['recording_status'] !== 'recording' || (bool)$meta['has_final'] || (int)$meta['event_version'] !== 1
            || (int)$meta['snapshot_version'] !== 1) throw new \LogicException('History not enabled or unsupported version');
        $data = $batch->data();
        if ($data['complete_match'] && $game['status'] !== 'game_over') throw new \LogicException('Game not confirmed finished');
        $eventBase = (int)$meta['last_event_seq']; $stepBase = (int)$meta['last_step_seq'];
        $events = $data['events']; $captures = $data['captures'];
        if ($eventBase < 0 || $stepBase < 0 || count($events) > PHP_INT_MAX-$eventBase || count($captures) > PHP_INT_MAX-$stepBase) throw new \OverflowException('History sequence exceeds runtime range');
        $this->insert('battle_history_batches', ['game_id'=>$gameId,'journal_id'=>$journalId,
            'player_key'=>$command['player_key'],'command_id'=>$command['command_id'],'persistence_version'=>$afterVersion,
            'batch_hash'=>$batch->hash(),'first_event_seq'=>$events ? $eventBase+1 : null,
            'last_event_seq'=>$events ? $eventBase+count($events) : null,
            'first_step_seq'=>$captures ? $stepBase+1 : null,'last_step_seq'=>$captures ? $stepBase+count($captures) : null,'created_at'=>$this->now()]);
        $eventCount = count($events);
        foreach ($data['actions'] as $action) {
            $id = $this->id($action['id'] ?? null);
            $status = $action['status'] ?? null;
            if (!in_array($status, ['open','settled','cancelled','interrupted'], true)) throw new \InvalidArgumentException('Invalid lifecycle');
            $old = $this->action($gameId, $id);
            $completion = isset($action['completion']) ? $this->json($action['completion']) : null;
            if ($old === null) {
                $start = $this->offset($action['start'] ?? null, $eventCount, $eventBase);
                $end = $status === 'open' ? null : $this->offset($action['end'] ?? null, $eventCount, $eventBase);
                if ($end !== null && $end < $start) throw new \LogicException('End before start');
                $root = $this->id($action['root'] ?? null); $parent = $action['parent'] ?? null;
                if ($parent !== null) {
                    $parent = $this->id($parent); $p = $this->action($gameId, $parent);
                    if ($p === null || $p['root_id'] !== $root || (int)$p['started_event_seq'] > $start
                        || ($p['ended_event_seq'] !== null && ($end === null || (int)$p['ended_event_seq'] < $end))) throw new \LogicException('Invalid parent/root interval');
                } elseif ($root !== $id) throw new \LogicException('Root action must reference itself');
                if ($root !== $id) {
                    $r = $this->action($gameId,$root);
                    if ($r === null || $r['parent_id'] !== null) throw new \LogicException('Root missing');
                }
                if (!in_array($action['initiator'] ?? null, ['host','player','system'], true)) throw new \InvalidArgumentException('Invalid initiator');
                $this->insert('battle_history_actions', ['game_id'=>$gameId,'action_id'=>$id,'root_id'=>$root,'parent_id'=>$parent,
                    'action_type'=>$this->id($action['type'] ?? null),'initiator'=>$action['initiator'],'lifecycle'=>$status,
                    'started_event_seq'=>$start,'ended_event_seq'=>$end,'completion_json'=>$completion]);
            } else {
                if ($old['lifecycle'] !== 'open' || $status === 'open') throw new \LogicException('Only open -> terminal mutation allowed');
                $end = $this->offset($action['end'] ?? null, $eventCount, $eventBase);
                $this->db->query('UPDATE battle_history_actions SET lifecycle='.$this->sql($status).',ended_event_seq='.$end
                    .',completion_json='.$this->sql($completion)." WHERE game_id=$gameId AND action_id=".$this->sql($id));
            }
        }
        foreach ($events as $i => $event) {
            $action = $this->requireAction($gameId, $event['action'] ?? null);
            $seq = $eventBase+$i+1;
            if ((int)$action['started_event_seq'] > $seq || ($action['ended_event_seq'] !== null && (int)$action['ended_event_seq'] < $seq)) throw new \LogicException('Event outside action lifecycle');
            if (($event['version'] ?? null) !== 1) throw new \InvalidArgumentException('Unsupported event version');
            $cause = $event['cause'] ?? null; // absolute earlier committed seq, or earlier offset in this batch
            if (isset($event['cause_order'])) $cause = $this->offset($event['cause_order'], $i, $eventBase);
            if ($cause !== null && (!is_int($cause) || $cause < 1 || $cause >= $seq)) throw new \InvalidArgumentException('Invalid event cause');
            if ($cause !== null && $this->db->fetchOne("SELECT event_seq FROM battle_history_events WHERE game_id=$gameId AND event_seq=$cause") === null) throw new \LogicException('Missing cause');
            foreach (['public','host','player'] as $view) if (!array_key_exists($view,$event)) throw new \InvalidArgumentException('Explicit event visibility required');
            $time = $event['at'] ?? null;
            if (!is_string($time) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D',$time)) throw new \InvalidArgumentException('UTC timestamp required');
            $this->insert('battle_history_events', ['game_id'=>$gameId,'event_seq'=>$seq,'journal_id'=>$journalId,'command_order'=>$i+1,
                'action_id'=>$action['action_id'],'event_type'=>$this->id($event['type'] ?? null),'schema_version'=>1,'cause_event_seq'=>$cause,
                'payload_json'=>$this->json($event['payload'] ?? null),'public_json'=>$this->nullableJson($event['public']),
                'host_json'=>$this->nullableJson($event['host']),'player_json'=>$this->nullableJson($event['player']),'occurred_at'=>$time]);
        }
        foreach ($data['actions'] as $mutation) {
            $a = $this->requireAction($gameId,$mutation['id']);
            foreach (['started_event_seq','ended_event_seq'] as $field) {
                if ($a[$field] === null) continue;
                $reference = $this->db->fetchOne("SELECT action_id FROM battle_history_events WHERE game_id=$gameId AND event_seq=".(int)$a[$field]);
                if ($reference === null || $reference['action_id'] !== $a['action_id']) throw new \LogicException('Lifecycle boundary must reference own event');
            }
        }
        $initial = (bool)$meta['has_initial']; $final = (bool)$meta['has_final'];
        foreach ($captures as $i => $capture) {
            $action = $this->requireAction($gameId, $capture['action'] ?? null);
            $through = $capture['through'] ?? null;
            if (!is_int($through) || $through < 0 || $through > $eventCount) throw new \InvalidArgumentException('Invalid capture event watermark');
            $watermark = $eventBase+$through;
            if ($watermark < (int)$action['started_event_seq']) throw new \LogicException('Capture precedes action');
            if ($i > 0 && $through < $captures[$i-1]['through']) throw new \LogicException('Capture order reverses events');
            foreach (['initial','final'] as $flag) if (!isset($capture[$flag]) || !is_bool($capture[$flag])) throw new \InvalidArgumentException('Explicit capture flags required');
            if ($capture['initial'] && ($initial || $stepBase+$i !== 0)) throw new \LogicException('Initial must be the first step');
            if ($capture['final'] && ($final || !$data['complete_match'] || $i !== count($captures)-1)) throw new \LogicException('Final must complete match at last step');
            foreach (['state','public','host','player'] as $view) if ($capture[$view]['finished'] !== $capture['final'] && $capture['final']) throw new \LogicException('Final state must be finished');
            $initial = $initial || $capture['initial']; $final = $final || $capture['final'];
            $state = $this->json($capture['state']);
            $step = ['game_id'=>$gameId,'step_seq'=>$stepBase+$i+1,'journal_id'=>$journalId,'capture_order'=>$i+1,
                'boundary_key'=>$this->id($capture['boundary'] ?? null,128),'step_type'=>$this->id($capture['type'] ?? null),'action_id'=>$action['action_id'],
                'last_event_seq'=>$watermark,'persistence_version'=>$afterVersion,'schema_version'=>1,'state_json'=>$state,
                'public_json'=>$this->json($capture['public']),'host_json'=>$this->json($capture['host']),'player_json'=>$this->json($capture['player']),
                'is_initial'=>(int)$capture['initial'],'is_final'=>(int)$capture['final'],'created_at'=>$this->now()];
            $step['state_hash'] = self::captureHash($step);
            $this->insert('battle_history_steps', $step);
        }
        if ($data['complete_match'] && !$final) throw new \LogicException('Completion requires final capture');
        if ($data['complete_match'] && $this->db->fetchOne("SELECT action_id FROM battle_history_actions WHERE game_id=$gameId AND lifecycle='open' LIMIT 1") !== null) throw new \LogicException('Open actions require explicit interruption/settlement');
        $status = $data['complete_match'] && !$data['partial'] ? 'completed' : 'recording';
        $complete = $data['partial'] ? 'incomplete' : $meta['completeness'];
        if ($data['complete_match'] && !$initial) $complete = 'incomplete';
        $this->db->query('UPDATE battle_history_matches SET last_event_seq='.($eventBase+$eventCount).',last_step_seq='.($stepBase+count($captures))
            .',has_initial='.(int)$initial.',has_final='.(int)$final.',recording_status='.$this->sql($status).',completeness='.$this->sql($complete)
            .',updated_at='.$this->sql($this->now())." WHERE game_id=$gameId");
        return $this->db->fetchOne("SELECT * FROM battle_history_batches WHERE game_id=$gameId AND journal_id=$journalId");
    }

    /** Server-only retry outcome. No Engine invocation or sequence allocation. */
    public function committedBatch(int $gameId, int $journalId): ?array
    {
        $this->committedRead();
        return $this->db->fetchOne("SELECT b.* FROM battle_history_batches b JOIN battle_command_journal j ON j.id=b.journal_id AND j.game_id=b.game_id WHERE b.game_id="
            .(int)$gameId.' AND b.journal_id='.(int)$journalId." AND j.status='committed'");
    }

    /** Historical projections only; caller authenticates participant -> view outside this storage layer. */
    public function events(int $gameId, string $view): array
    {
        $this->committedRead();
        $column = $this->viewColumn($view);
        return $this->db->fetchAll("SELECT event_seq,action_id,event_type,schema_version,cause_event_seq,$column AS projection_json,occurred_at FROM battle_history_events WHERE game_id="
            .(int)$gameId." AND $column IS NOT NULL ORDER BY event_seq");
    }
    public function steps(int $gameId, string $view): array
    {
        $this->committedRead();
        $column = $this->viewColumn($view);
        $rows = $this->db->fetchAll("SELECT step_seq,step_type,action_id,last_event_seq,schema_version,state_json,public_json,host_json,player_json,state_hash,is_initial,is_final FROM battle_history_steps WHERE game_id="
            .(int)$gameId.' ORDER BY step_seq');
        foreach ($rows as &$row) {
            $this->verifyCapture($row);
            $row['replay_json'] = $row[$column];
            unset($row['state_json'],$row['public_json'],$row['host_json'],$row['player_json'],$row['state_hash']);
        }
        unset($row);
        return $rows;
    }
    /** Full history unlock requires persisted final state AND committed final journal. */
    public function completedServerHistory(int $gameId): array
    {
        $this->committedRead();
        $proof = $this->db->fetchOne("SELECT m.game_id FROM battle_history_matches m JOIN games g ON g.ind=m.game_id AND g.status='game_over' JOIN battle_history_steps s ON s.game_id=m.game_id AND s.is_final=1 JOIN battle_command_journal j ON j.id=s.journal_id AND j.game_id=s.game_id WHERE m.game_id="
            .(int)$gameId." AND m.recording_status='completed' AND m.has_final=1 AND j.status='committed'");
        if ($proof === null) throw new \LogicException('Full history locked');
        $steps = $this->db->fetchAll('SELECT * FROM battle_history_steps WHERE game_id='.(int)$gameId.' ORDER BY step_seq');
        foreach ($steps as $step) $this->verifyCapture($step);
        return ['events'=>$this->db->fetchAll('SELECT * FROM battle_history_events WHERE game_id='.(int)$gameId.' ORDER BY event_seq'),
            'steps'=>$steps];
    }
    /** Finished partial preview only. Caller authenticates and allowlists before calling.
     * SELECT only: no repository/schema constructors, Engine, journal mutation or progression.
     */
    public function experimentalFinishedPreview(int $gameId): array
    {
        $this->committedRead();
        if ($gameId <= 0) throw new \InvalidArgumentException('Invalid game');
        $meta = $this->db->fetchOne("SELECT m.* FROM battle_history_matches m JOIN games g ON g.ind=m.game_id AND g.status='game_over' JOIN battle_history_steps s ON s.game_id=m.game_id AND s.is_final=1 AND s.step_seq=m.last_step_seq JOIN battle_command_journal j ON j.id=s.journal_id AND j.game_id=s.game_id AND j.status='committed' WHERE m.game_id=$gameId AND m.has_initial=1 AND m.has_final=1 AND m.snapshot_version=1");
        if ($meta === null) throw new \DomainException('confirmed_final_snapshot_required', 409);
        $steps = $this->db->fetchAll("SELECT s.* FROM battle_history_steps s JOIN battle_command_journal j ON j.id=s.journal_id AND j.game_id=s.game_id AND j.status='committed' WHERE s.game_id=$gameId ORDER BY s.step_seq");
        if (count($steps) !== (int)$meta['last_step_seq']) throw new \UnexpectedValueException('Replay step sequence incomplete');
        foreach ($steps as $i => $step) {
            if ((int)$step['step_seq'] !== $i+1) throw new \UnexpectedValueException('Replay step sequence invalid');
            $this->verifyCapture($step);
        }
        $gaps = $this->db->fetchAll("SELECT e.event_seq,e.event_type,e.payload_json FROM battle_history_events e JOIN battle_command_journal j ON j.id=e.journal_id AND j.game_id=e.game_id AND j.status='committed' WHERE e.game_id=$gameId AND e.event_type='recording_gap.started' ORDER BY e.event_seq");
        $gaps = array_map(static fn(array $row): array => ['event_seq'=>(int)$row['event_seq'], 'type'=>'recording_gap', 'detail'=>json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR)], $gaps);
        return ['metadata'=>$meta, 'steps'=>$steps, 'gaps'=>$gaps];
    }
    /** Hash exact persisted JSON strings, including every historical projection; never reserialize decoded DTOs. */
    private static function captureHash(array $row): string
    {
        return hash('sha256', ReplayState::encode([
            'format'=>'capture-sha256-v1','schema_version'=>(int)$row['schema_version'],
            'state_json'=>$row['state_json'],'public_json'=>$row['public_json'],
            'host_json'=>$row['host_json'],'player_json'=>$row['player_json'],
        ]));
    }
    private function verifyCapture(array $row): void
    {
        if (!hash_equals(self::captureHash($row), (string)$row['state_hash'])) {
            throw new \UnexpectedValueException('Replay snapshot integrity failure at step '.(int)$row['step_seq']);
        }
    }
    private function transaction(): void { if (!$this->db->inTransaction()) throw new \LogicException('Caller transaction required'); }
    private function committedRead(): void { if ($this->db->inTransaction()) throw new \LogicException('Read on a fresh committed connection, outside write transaction'); }
    private function action(int $gameId, string $id): ?array { return $this->db->fetchOne("SELECT * FROM battle_history_actions WHERE game_id=$gameId AND action_id=".$this->sql($id)); }
    private function requireAction(int $gameId, mixed $id): array { return $this->action($gameId,$this->id($id)) ?? throw new \LogicException('Missing action'); }
    private function offset(mixed $offset, int $count, int $base): int
    {
        if (!is_int($offset) || $offset < 1 || $offset > $count) throw new \InvalidArgumentException('Invalid 1-based event offset');
        return $base+$offset;
    }
    private function id(mixed $id, int $limit=64): string
    {
        if (!is_string($id) || strlen($id)>$limit || !preg_match('/^[A-Za-z0-9_.:-]+$/D',$id)) throw new \InvalidArgumentException('Invalid identifier');
        return $id;
    }
    private function json(mixed $data): string { if (!is_array($data)) throw new \InvalidArgumentException('Structured array required'); return ReplayState::encode($data); }
    private function nullableJson(mixed $data): ?string { return $data === null ? null : $this->json($data); }
    private function now(): string { return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
    private function sql(mixed $value): string { return $value === null ? 'NULL' : (is_int($value) ? (string)$value : "'".$this->db->escape((string)$value)."'"); }
    private function insert(string $table, array $values): void { $this->db->query('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_map(fn($v)=>$this->sql($v),array_values($values))).')'); }
    private function viewColumn(string $view): string
    {
        if (!in_array($view,['public','host','player'],true)) throw new \InvalidArgumentException('Invalid view');
        return $view.'_json';
    }
}
