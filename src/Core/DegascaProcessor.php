<?php
// src/Core/DegascaProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

final class DegascaProcessor
{
    private const DEGASCA_UKID = 's1_32';

    private const POOL = [
        's1_26' => 'Анубисар',
        's1_131' => 'Медуза Горгона',
        's1_149' => 'Талион',
        's1_199' => 'Тич',
    ];

    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    public static function isDegasca(CardInstance $card): bool
    {
        return $card->ukid === self::DEGASCA_UKID || !empty($card->prop['degasca']);
    }

    /**
     * @return array<string,string>
     */
    public static function sourceNames(): array
    {
        return self::POOL;
    }

    public function openPreBattleSequence(CardInstance $source): bool
    {
        if (!empty($this->state->battle['pending_degasca_sequence'])) {
            return false;
        }

        if (!$this->canActivate($source)) {
            return false;
        }

        return $this->startSequence($source, 'pre_battle', []);
    }

    public function openStrongStrikeSequence(CardInstance $source, CardInstance $target, string $attackLevel, int $woundsDealt): bool
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || $attackLevel !== 'strong') return false;
        if (!empty($strike['degasca_strong_done']) || !empty($this->state->battle['pending_degasca_sequence'])) {
            return false;
        }
        if (!$this->canActivate($source)) {
            $this->state->battle['strike']['degasca_strong_done'] = true;
            return false;
        }

        $this->state->battle['strike']['degasca_strong_done'] = true;
        return $this->startSequence($source, 'strong_strike', [
            'target_id' => $target->instanceId,
            'attack_level' => $attackLevel,
            'wounds_dealt' => $woundsDealt,
        ]);
    }

    private function startSequence(CardInstance $source, string $activation, array $context): bool
    {
        // Before battle Degasca only learns a new ability. Learned strong-strike
        // effects are resolved from an actual strong strike, not an artificial one.
        $learned = $activation === 'pre_battle' ? [] : $this->learned($source);
        $this->state->battle['pending_degasca_sequence'] = [
            'owner' => $source->owner,
            'source_id' => $source->instanceId,
            'activation' => $activation,
            'effects' => $learned,
            'index' => 0,
            'stage' => 'effects',
            'context' => $context,
        ];

        $this->resume(false);
        return !empty($this->state->battle['pending_degasca_sequence']);
    }

    public function resume(bool $continueOnComplete = false): void
    {
        while (!empty($this->state->battle['pending_degasca_sequence'])) {
            $pending = &$this->state->battle['pending_degasca_sequence'];
            $source = $this->state->getCard((int) ($pending['source_id'] ?? 0));
            if (!$this->canActivate($source)) {
                $this->finishSequence($pending, null, $continueOnComplete);
                return;
            }

            $stage = (string) ($pending['stage'] ?? 'effects');
            if ($stage === 'effects') {
                $effects = array_values((array) ($pending['effects'] ?? []));
                $index = (int) ($pending['index'] ?? 0);
                if ($index >= count($effects)) {
                    $pending['stage'] = 'deck_pick';
                    continue;
                }

                $ukid = (string) $effects[$index];
                $openedChoice = $this->applyLearnedEffect($source, $ukid, (array) ($pending['context'] ?? []));
                if ($openedChoice) {
                    return;
                }
                $this->state->battle['pending_degasca_sequence']['index'] = $index + 1;
                continue;
            }

            if ($stage === 'deck_pick') {
                $candidateIds = $this->deckCandidateIds($source);
                if (empty($candidateIds)) {
                    $this->finishSequence($pending, 'Нет новых способностей в колоде', $continueOnComplete);
                    return;
                }
                $pending['stage'] = 'choose_card';
                $pending['candidate_ids'] = $candidateIds;
                return;
            }

            return;
        }
    }

    public function chooseCard(string $playerKey, Command $cmd): Result
    {
        $pending = $this->pendingFor($playerKey, 'choose_card');
        if (!$pending) return Result::error('Нет выбора Дегаски');

        $source = $this->state->getCard((int) ($pending['source_id'] ?? 0));
        if (!$this->canActivate($source)) {
            $this->finishSequence($pending, null, true);
            return Result::error('Дегаска недоступна');
        }

        $cardId = (int) $cmd->get('card_id', 0);
        $candidateIds = array_map('intval', (array) ($pending['candidate_ids'] ?? []));
        if (!in_array($cardId, $candidateIds, true)) {
            return Result::error('Нельзя выбрать эту карту');
        }

        $card = $this->state->getCard($cardId);
        if (!$card || !$this->isDeckCandidate($source, $card)) {
            $this->finishSequence($pending, null, true);
            return Result::error('Карта больше недоступна');
        }

        $this->addLearned($source, $card->ukid);
        $this->shuffleDeck($source->owner);
        $this->recordMessage($source, 'Изучена способность: ' . self::POOL[$card->ukid]);

        $this->finishSequence($pending, null, true);
        $this->state->bumpVersion();
        return Result::ok(['degasca_learned:' . $card->ukid]);
    }

    public function chooseChain(string $playerKey, Command $cmd): Result
    {
        $pending = $this->pendingFor($playerKey, 'choose_chain');
        if (!$pending) return Result::error('Нет выбора цепей Дегаски');
        $source = $this->state->getCard((int) ($pending['source_id'] ?? 0));
        $attacked = $this->state->getCard((int) ($pending['attacked_id'] ?? 0));
        if (!$this->canActivate($source) || !$attacked) {
            $this->finishSequence($pending, null, true);
            return Result::error('Исходный удар недоступен');
        }

        $raw = $cmd->get('target_ids', []);
        if (!is_array($raw)) $raw = [$raw];
        $targetIds = array_values(array_filter(array_map('intval', $raw), static fn(int $id): bool => $id > 0));
        if (count($targetIds) !== count(array_unique($targetIds))) {
            return Result::error('Нужно выбрать разные цели');
        }

        $maxTargets = (int) ($pending['max_targets'] ?? 2);
        if (count($targetIds) !== $maxTargets) {
            return Result::error('Нужно выбрать ' . $maxTargets . ' цели');
        }

        $validIds = $this->chainTargetIds($source, $attacked, (string) ($pending['filter'] ?? 'enemy'));
        foreach ($targetIds as $targetId) {
            if (!in_array($targetId, $validIds, true)) {
                return Result::error('Недопустимая цель');
            }
        }

        $this->applyChainDamage($source, $attacked, $targetIds, (int) ($pending['value'] ?? 1));
        $this->state->battle['pending_degasca_sequence']['index'] =
            (int) ($this->state->battle['pending_degasca_sequence']['index'] ?? 0) + 1;
        $this->state->battle['pending_degasca_sequence']['stage'] = 'effects';
        unset(
            $this->state->battle['pending_degasca_sequence']['candidate_ids'],
            $this->state->battle['pending_degasca_sequence']['attacked_id'],
            $this->state->battle['pending_degasca_sequence']['value'],
            $this->state->battle['pending_degasca_sequence']['max_targets'],
            $this->state->battle['pending_degasca_sequence']['filter']
        );

        $this->resume(true);
        $this->state->bumpVersion();
        return Result::ok(['degasca_chain']);
    }

    public function chooseTalion(string $playerKey, Command $cmd): Result
    {
        $pending = $this->pendingFor($playerKey, 'choose_talion');
        if (!$pending) return Result::error('Нет выбора жетона Дегаски');
        $source = $this->state->getCard((int) ($pending['source_id'] ?? 0));
        if (!$this->canActivate($source)) {
            $this->finishSequence($pending, null, true);
            return Result::error('Дегаска недоступна');
        }

        $targetId = (int) $cmd->get('target_id', 0);
        $candidateIds = array_map('intval', (array) ($pending['candidate_ids'] ?? []));
        if (!in_array($targetId, $candidateIds, true)) {
            return Result::error('Нельзя выбрать эту карту');
        }

        $target = $this->state->getCard($targetId);
        if (!$target || !$this->isTalionCandidate($target, $source->owner)) {
            $this->finishSequence($pending, null, true);
            return Result::error('Цель больше недоступна');
        }

        $this->addIncarnationToken($target);
        $this->recordTalion($source, $target);
        $this->state->battle['pending_degasca_sequence']['index'] =
            (int) ($this->state->battle['pending_degasca_sequence']['index'] ?? 0) + 1;
        $this->state->battle['pending_degasca_sequence']['stage'] = 'effects';
        unset($this->state->battle['pending_degasca_sequence']['candidate_ids']);

        $this->resume(true);
        $this->state->bumpVersion();
        return Result::ok(['degasca_talion']);
    }

    private function pendingFor(string $playerKey, string $stage): ?array
    {
        $pending = $this->state->battle['pending_degasca_sequence'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) return null;
        if (($pending['stage'] ?? '') !== $stage) return null;
        return $pending;
    }

    private function continueAfterComplete(string $activation): void
    {
        if ($activation === 'pre_battle') {
            (new TurnProcessor($this->state, $this->engine))->continuePreBattleEffects();
        }
    }

    private function finishSequence(array $pending, ?string $message, bool $continueOnComplete): void
    {
        if ($message !== null) {
            $source = $this->state->getCard((int) ($pending['source_id'] ?? 0));
            if ($source) {
                $this->recordMessage($source, $message);
            }
        }

        unset($this->state->battle['pending_degasca_sequence']);

        if ($continueOnComplete) {
            $this->continueAfterComplete((string) ($pending['activation'] ?? ''));
        }
    }

    private function applyLearnedEffect(CardInstance $source, string $ukid, array $context): bool
    {
        $target = $this->state->getCard((int) ($context['target_id'] ?? 0));
        if (!$target) return false;

        if ($ukid === 's1_131') {
            $this->engine->applyStrikeEffects($source, $target, 'strong', [[
                'levels' => ['strong'],
                'marker' => ['type' => 'stun', 'expire' => 1, 'timing' => 'owner_turn'],
            ]]);
            $this->recordMessage($source, 'Взгляд Медузы');
            return false;
        }

        if ($ukid === 's1_26') {
            return $this->applyAnubisar($source, $target);
        }

        if ($ukid === 's1_149') {
            if ((int) ($context['wounds_dealt'] ?? 0) <= 0) return false;
            return $this->applyTalion($source);
        }

        if ($ukid === 's1_199') {
            $behind = $this->behindTarget($source, $target);
            if (!$behind) return false;
            $damage = CardStats::getStrikeValue($this->state, $behind, $target, 'weak');
            if ($damage <= 0) return false;
            $this->engine->applyDamage($this->state, $target, $damage, 'impact', $source);
            $this->state->battle['strike']['behind_weak_strike_damage'][] = [
                'source_id' => $behind->instanceId,
                'target_id' => $target->instanceId,
                'damage' => $damage,
                'degasca_source_id' => $source->instanceId,
            ];
            return false;
        }

        return false;
    }

    private function applyAnubisar(CardInstance $source, CardInstance $target): bool
    {
        $damage = 1;
        $maxTargets = 2;
        $filter = 'enemy';
        $candidateIds = $this->chainTargetIds($source, $target, $filter);
        if (empty($candidateIds)) return false;

        if (count($candidateIds) > $maxTargets) {
            $this->state->battle['pending_degasca_sequence']['stage'] = 'choose_chain';
            $this->state->battle['pending_degasca_sequence']['candidate_ids'] = $candidateIds;
            $this->state->battle['pending_degasca_sequence']['attacked_id'] = $target->instanceId;
            $this->state->battle['pending_degasca_sequence']['value'] = $damage;
            $this->state->battle['pending_degasca_sequence']['max_targets'] = $maxTargets;
            $this->state->battle['pending_degasca_sequence']['filter'] = $filter;
            return true;
        }

        $this->applyChainDamage($source, $target, array_slice($candidateIds, 0, $maxTargets), $damage);
        return false;
    }

    private function applyTalion(CardInstance $source): bool
    {
        $candidateIds = $this->talionCandidateIds($source->owner);
        if (empty($candidateIds)) return false;
        if (count($candidateIds) === 1) {
            $target = $this->state->getCard($candidateIds[0]);
            if (!$target) return false;
            $this->addIncarnationToken($target);
            $this->recordTalion($source, $target);
            return false;
        }

        $this->state->battle['pending_degasca_sequence']['stage'] = 'choose_talion';
        $this->state->battle['pending_degasca_sequence']['candidate_ids'] = $candidateIds;
        return true;
    }

    /**
     * @return string[]
     */
    private function learned(CardInstance $source): array
    {
        $raw = $source->flags['degasca_learned'] ?? [];
        if (!is_array($raw)) return [];
        $result = [];
        foreach ($raw as $ukid) {
            $ukid = (string) $ukid;
            if (isset(self::POOL[$ukid]) && !in_array($ukid, $result, true)) {
                $result[] = $ukid;
            }
        }
        return $result;
    }

    private function addLearned(CardInstance $source, string $ukid): void
    {
        if (!isset(self::POOL[$ukid])) return;
        $learned = $this->learned($source);
        if (!in_array($ukid, $learned, true)) {
            $learned[] = $ukid;
        }
        $source->flags['degasca_learned'] = $learned;
    }

    /**
     * @return int[]
     */
    private function deckCandidateIds(CardInstance $source): array
    {
        $ids = [];
        $seen = [];
        foreach ($this->state->getCardsInZone($source->owner, CardInstance::ZONE_DECK) as $card) {
            if (!$this->isDeckCandidate($source, $card)) continue;
            if (isset($seen[$card->ukid])) continue;
            $seen[$card->ukid] = true;
            $ids[] = $card->instanceId;
        }
        return $ids;
    }

    private function isDeckCandidate(CardInstance $source, CardInstance $card): bool
    {
        if ($card->owner !== $source->owner) return false;
        if ($card->zone !== CardInstance::ZONE_DECK) return false;
        if (!isset(self::POOL[$card->ukid])) return false;
        if ($card->ukid === $source->ukid) return false;
        return !in_array($card->ukid, $this->learned($source), true);
    }

    /**
     * @return int[]
     */
    private function chainTargetIds(CardInstance $source, CardInstance $target, string $filter): array
    {
        $ids = [];
        foreach ($this->state->cards as $candidate) {
            if ($candidate->instanceId === $target->instanceId) continue;
            if ($candidate->zone !== CardInstance::ZONE_FIELD) continue;
            if ($candidate->dying || $candidate->hp <= 0) continue;
            if ($candidate->type !== 'creature') continue;
            if ($filter === 'enemy' && $candidate->owner === $source->owner) continue;
            if ($filter === 'ally' && $candidate->owner !== $source->owner) continue;
            if (!ZoneManager::areAdjacentFieldCards($target, $candidate)) continue;
            $ids[] = $candidate->instanceId;
        }
        return $ids;
    }

    /**
     * @param int[] $targetIds
     */
    private function applyChainDamage(CardInstance $source, CardInstance $attacked, array $targetIds, int $damage): void
    {
        $applied = [];
        foreach ($targetIds as $targetId) {
            $target = $this->state->getCard((int) $targetId);
            if (!$target) continue;
            $before = $target->hp;
            $this->engine->applyDamage($this->state, $target, $damage, 'impact', $source);
            $applied[] = [
                'source_id' => $source->instanceId,
                'target_id' => $target->instanceId,
                'damage' => max(0, $before - $target->hp),
            ];
        }
        if (!empty($applied)) {
            $this->state->battle['strike']['chain_damage'][] = [
                'source_id' => $source->instanceId,
                'attacked_id' => $attacked->instanceId,
                'items' => $applied,
                'degasca' => true,
            ];
        }
    }

    private function behindTarget(CardInstance $source, CardInstance $target): ?CardInstance
    {
        if ($source->row === null || $source->col === null || $target->row === null || $target->col === null) {
            return null;
        }
        $dRow = ($target->row - $source->row) <=> 0;
        $dCol = ($target->col - $source->col) <=> 0;
        if ($dRow === 0 && $dCol === 0) return null;
        return (new ZoneManager($this->state))->getFieldCard($target->row + $dRow, $target->col + $dCol);
    }

    /**
     * @return int[]
     */
    private function talionCandidateIds(string $owner): array
    {
        $ids = [];
        foreach ($this->state->cards as $card) {
            if ($this->isTalionCandidate($card, $owner)) {
                $ids[] = $card->instanceId;
            }
        }
        return $ids;
    }

    private function isTalionCandidate(CardInstance $card, string $owner): bool
    {
        if ($card->owner !== $owner) return false;
        if ($card->zone !== CardInstance::ZONE_GRAVEYARD) return false;
        return $card->type === 'creature' || $card->type === 'fly';
    }

    private function addIncarnationToken(CardInstance $card): void
    {
        if (!isset($card->markers['incarnation'])) {
            $inc = $card->prop['incarnation'] ?? null;
            $threshold = 0;
            $open = false;
            if (is_array($inc)) {
                $threshold = (int) ($inc['turns'] ?? 0);
                $open = !empty($inc['open']);
            } elseif ($inc !== null) {
                $threshold = (int) $inc;
            }
            $card->markers['incarnation'] = ['value' => 0, 'threshold' => $threshold, 'open' => $open];
        }

        $card->markers['incarnation']['value'] = (int) ($card->markers['incarnation']['value'] ?? 0) + 1;
        (new IncarnationTokenProcessor($this->state, $this->engine))
            ->onTokenGranted($card, (int) $card->markers['incarnation']['value']);
    }

    private function recordTalion(CardInstance $source, CardInstance $target): void
    {
        if (empty($this->state->battle['strike'])) return;
        $this->state->battle['strike']['talion_incarnation_token'][] = [
            'source_id' => $source->instanceId,
            'target_id' => $target->instanceId,
            'value' => (int) ($target->markers['incarnation']['value'] ?? 0),
            'threshold' => (int) ($target->markers['incarnation']['threshold'] ?? 0),
            'has_incarnation' => array_key_exists('incarnation', $target->prop),
            'degasca' => true,
        ];
    }

    private function shuffleDeck(string $owner): void
    {
        $deck = $this->state->getCardsInZone($owner, CardInstance::ZONE_DECK);
        usort($deck, fn(CardInstance $a, CardInstance $b): int => ($a->order ?? PHP_INT_MAX) <=> ($b->order ?? PHP_INT_MAX)
            ?: $a->instanceId <=> $b->instanceId);
        shuffle($deck);
        $order = 1;
        foreach ($deck as $card) {
            $card->order = $order++;
        }
    }

    private function canActivate(?CardInstance $source): bool
    {
        if (!$source) return false;
        if (!self::isDegasca($source)) return false;
        if ($source->dying || $source->hp <= 0) return false;
        return $source->zone === CardInstance::ZONE_FIELD || $source->zone === CardInstance::ZONE_FLYING;
    }

    private function recordMessage(CardInstance $source, string $message): void
    {
        $this->state->battle['degasca_messages'][] = [
            'source_id' => $source->instanceId,
            'source_ukid' => $source->ukid,
            'message' => 'Дегаска: ' . $message,
        ];
    }
}
