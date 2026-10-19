<?php
declare(strict_types=1);
namespace Berserk\Core;

/** One ordered pass per definite HTTP path, never inside an Engine continuation. */
final class AutomaticBattleProgress
{
    public function __construct(private Db $db, private GameRepository $repo, private Engine $engine) {}

    public function check(GameState $state, string $requester): AutomaticCommandResult
    {
        $pause=(new AutomaticMatchPause($this->db,$this->repo,$this->engine))->check($state,$requester);
        if (!$pause->certain || MatchPause::blocking($pause->state)) return $pause;
        $state=$pause->state;
        $ack=(new InformationalAutoAck($this->db,$this->repo,$this->engine))->checkOutcome($state,$requester);
        if(!$ack->certain) return $ack;
        // P2 returns a fresh state whenever it attempted a command. Normal not-due
        // reads stay read-only; T3 always reloads under lock before execution.
        return (new AutomaticTurnEnd($this->db,$this->repo,$this->engine))->check($ack->state,$requester);
    }
}
