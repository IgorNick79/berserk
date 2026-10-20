<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;
use Berserk\Core\Db;

/** Access + bounded forward cursor. Reads one saved projection, never server payload. */
final class LiveJournal
{
    public function __construct(private Db $db,private HistoryRuntime $runtime) {}
    public function read(int $gameId,array $session,int $after=0,int $limit=100): array
    {
        if (!$this->runtime->journals($gameId)) throw new \DomainException('journal_unavailable',404);
        $game=$this->db->fetchOne('SELECT host_id,player_id FROM games WHERE ind='.$gameId);
        if ($game===null) throw new \DomainException('journal_unavailable',404);
        $side=$this->runtime->journalSide($gameId,(int)$game['host_id'],(int)$game['player_id'],$session);
        if ($side===null) throw new \DomainException('journal_access_denied',403);
        return (new HistoryRepository($this->db))->journalPage($gameId,$side,$after,$limit);
    }
}
