<?php
declare(strict_types=1);
namespace Berserk\Core;
/** Persisted informational acknowledgements; effects have already resolved. */
final class TurnInstantResult
{
    public static function create(array $summary): array
    {
        return array_map(static function(array $item): array {
            if (!in_array($item['player'] ?? null, ['host','player'], true)) throw new \LogicException('Unknown turn instant result owner');
            return ['owner'=>$item['player'], 'ack_id'=>bin2hex(random_bytes(16))];
        }, $summary);
    }
    public static function current(mixed $result): ?array
    {
        if (!is_array($result) || !is_int($result['position'] ?? null) || $result['position'] < 0
            || !is_array($result['confirmations'] ?? null) || !is_array($result['summary'] ?? null)
            || !array_is_list($result['summary']) || !array_is_list($result['confirmations'])
            || count($result['summary']) !== count($result['confirmations'])) return null;
        $ids=[];
        foreach ($result['confirmations'] as $i=>$ack) {
            if (!is_array($result['summary'][$i] ?? null) || !TurnPhaseAck::valid($ack) || ($result['summary'][$i]['player'] ?? null) !== $ack['owner'] || isset($ids[$ack['ack_id']])) return null;
            $ids[$ack['ack_id']]=true;
        }
        return $result['confirmations'][$result['position']] ?? null;
    }
}
