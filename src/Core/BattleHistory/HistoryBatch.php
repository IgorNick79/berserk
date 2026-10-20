<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;

/** Owns serialized copies at construction: subsequent caller mutations cannot alter captures. */
final class HistoryBatch
{
    private string $json;

    public function __construct(array $actions, array $events, array $captures, bool $completeMatch = false, bool $partial = false)
    {
        foreach ([$actions,$events,$captures] as $list) if (!array_is_list($list)) throw new \InvalidArgumentException('Batch lists required');
        foreach ($captures as $capture) {
            foreach (['state','public','host','player'] as $projection) {
                if (!isset($capture[$projection]) || !is_array($capture[$projection])) throw new \InvalidArgumentException('Explicit snapshot projections required');
                ReplayState::validate($capture[$projection]);
            }
        }
        $this->json = ReplayState::encode(['actions'=>$actions,'events'=>$events,'captures'=>$captures,'complete_match'=>$completeMatch,'partial'=>$partial]);
    }
    public function data(): array { return json_decode($this->json, true, 512, JSON_THROW_ON_ERROR); }
    public function hash(): string { return hash('sha256', $this->json); }
}
