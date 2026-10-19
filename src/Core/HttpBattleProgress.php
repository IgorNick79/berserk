<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Request-scoped certainty gate. Construct once per HTTP request, never persist it. */
final class HttpBattleProgress
{
    private bool $stopped = false;
    private bool $combatAttempted = false;

    public function __construct(private Db $db, private GameRepository $repo, private Engine $engine) {}

    public function check(GameState $state, string $requester, bool $allowCombat = true): AutomaticCommandResult
    {
        if ($this->stopped) return new AutomaticCommandResult($state, false, 'request_stopped');
        try {
            $pause = (new AutomaticMatchPause($this->db, $this->repo, $this->engine))->check($state, $requester);
            if (!$pause->certain) {
                $this->stopped = true;
                return $pause;
            }
            if (MatchPause::blocking($pause->state) || !$allowCombat || $this->combatAttempted) return $pause;
            $this->combatAttempted = true;
            $result = (new AutomaticBattleProgress($this->db, $this->repo, $this->engine))->check($pause->state, $requester);
            if (!$result->certain) $this->stopped = true;
            return $result;
        } catch (\Throwable $e) {
            $this->stopped = true;
            throw $e;
        }
    }
}
