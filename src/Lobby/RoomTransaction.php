<?php
declare(strict_types=1);
namespace Berserk\Lobby;

/** Scoped capability: only this owner begins/ends the room/game transaction. */
final class RoomTransaction
{
    private static ?\WeakMap $owners = null;
    private bool $active = true;
    private ?int $roomId = null;
    private ?int $gameId = null;

    private function __construct(private \mysqli $db) {}
    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('Transaction cannot escape'); }
    public function __unserialize(array $data): void { throw new \LogicException('Transaction cannot be restored'); }

    public static function run(\mysqli $db, callable $operation): void
    {
        self::$owners ??= new \WeakMap();
        if (isset(self::$owners[$db])) throw new \LogicException('Nested room transaction');
        $context = new self($db);
        self::$owners[$db] = $context;
        try {
            $db->begin_transaction();
            $operation($context);
            $context->assertActive();
            if ($context->roomId !== null) {
                $stmt = $db->prepare("SELECT r.id FROM lobby_rooms r JOIN games g ON g.ind=r.game_id WHERE r.id=? AND g.ind=? AND r.state='started' AND r.candidate_account_id IS NULL AND r.candidate_request_id IS NULL AND r.creator_account_id=g.host_id AND r.accepted_account_id=g.player_id");
                try {
                    $stmt->bind_param('ii', $context->roomId, $context->gameId);
                    $stmt->execute();
                    if (!$stmt->get_result()->fetch_assoc()) throw new \LogicException('Match must be linked before commit');
                } finally { $stmt->close(); }
            }
            // Invalidate BEFORE commit: a lost acknowledgement never permits reuse/retry.
            $context->active = false;
            $db->commit();
        } catch (\Throwable $e) {
            $context->active = false;
            try { $db->rollback(); } catch (\Throwable) {}
            throw $e;
        } finally {
            $context->active = false;
            unset(self::$owners[$db]);
        }
    }

    public function recordMatch(int $id): void
    {
        $this->assertActive();
        if ($this->roomId === null || $this->gameId !== null || $id <= 0) throw new \LogicException('Invalid match lifecycle');
        $this->gameId = $id;
    }

    public function assertActive(): void
    {
        if (!$this->active || (self::$owners[$this->db] ?? null) !== $this) throw new \LogicException('Active room transaction required');
    }

    /** Verify locked server state even on MySQL versions that ignore CHECK. */
    public function claimMatch(int $roomId, int $creator, int $accepted): \mysqli
    {
        $this->assertActive();
        if ($this->roomId !== null) throw new \LogicException('Match already claimed');
        $stmt = $this->db->prepare('SELECT * FROM lobby_rooms WHERE id=? FOR UPDATE');
        try {
            $stmt->bind_param('i', $roomId);
            $stmt->execute();
            $room = $stmt->get_result()->fetch_assoc();
        } finally { $stmt->close(); }
        if (!$room || $room['state'] !== 'awaiting_confirmation'
            || (int)$room['creator_account_id'] !== $creator || (int)$room['candidate_account_id'] !== $accepted
            || $creator <= 0 || $accepted <= 0 || $creator === $accepted
            || $room['accepted_account_id'] !== null || $room['game_id'] !== null
            || !is_string($room['candidate_request_id']) || !preg_match('/\A[0-9a-f]{64}\z/D', $room['candidate_request_id'])) {
            throw new \LogicException('Invalid pending room');
        }
        $this->roomId = $roomId;
        return $this->db;
    }
}
