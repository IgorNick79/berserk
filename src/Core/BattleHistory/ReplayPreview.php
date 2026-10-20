<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;

use Berserk\Core\Db;

/** Separate experimental access policy; does not unlock completedServerHistory. */
final class ReplayPreview
{
    public function __construct(private Db $db, private HistoryRuntime $runtime) {}

    public function read(int $gameId, int $principal): array
    {
        if ($principal <= 0) throw new \DomainException('authentication_required', 401);
        if (!$this->runtime->previews($gameId)) throw new \DomainException('replay_not_available', 404);
        $game = $this->db->fetchOne('SELECT ind,host_id,player_id,status FROM games WHERE ind='.$gameId);
        if ($game === null) throw new \DomainException('replay_not_available', 404);
        if (!in_array($principal, [(int)$game['host_id'], (int)$game['player_id']], true) && !$this->runtime->admin($principal)) {
            throw new \DomainException('forbidden', 403);
        }
        if ($game['status'] !== 'game_over') throw new \DomainException('replay_requires_game_over', 409);
        $history = (new HistoryRepository($this->db))->experimentalFinishedPreview($gameId);
        $steps = [];
        foreach ($history['steps'] as $row) {
            $state = json_decode($row['state_json'], true, 512, JSON_THROW_ON_ERROR);
            ReplayState::validate($state);
            $steps[] = ['step_seq'=>(int)$row['step_seq'], 'step_type'=>$row['step_type'],
                'action_id'=>$row['action_id'], 'last_event_seq'=>(int)$row['last_event_seq'],
                'is_initial'=>(bool)$row['is_initial'], 'is_final'=>(bool)$row['is_final'], 'state'=>$state];
        }
        return ['metadata'=>['game_id'=>$gameId, 'status'=>'game_over',
            'participants'=>['host'=>(int)$game['host_id'], 'player'=>(int)$game['player_id']],
            'experimental'=>true, 'completeness'=>$history['metadata']['completeness'],
            'recording_status'=>$history['metadata']['recording_status'], 'step_count'=>count($steps)],
            'gaps'=>$history['gaps'], 'steps'=>$steps];
    }
}
