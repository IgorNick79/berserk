<?php
// src/Core/DegascaProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

use Berserk\Core\Choice\ChoiceRegistry;

final class DegascaProcessor
{
    public const ANY_DEATH_CONTINUATION_KEY = 'pending_any_death_degasca_sequence';
    public const NOKAMI_WOUND_CONTINUATION_KEY = 'pending_nokami_wound_degasca_sequence';
    private const DEFERRED_CONTINUATIONS_KEY = 'pending_degasca_deferred_sequences';

    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    public static function hasPreBattleTrigger(CardInstance $card): bool
    {
        return false;
    }

    public static function hasStrongStrikeTrigger(CardInstance $card, string $level): bool
    {
        foreach ((array) ($card->prop['strike_effects'] ?? []) as $effect) {
            if (($effect['type'] ?? '') !== 'degasca_sequence') continue;
            if (!self::isStrongOnlyEffect($effect)) continue;
            return $level === 'strong';
        }
        return false;
    }

    public static function isDegasca(CardInstance $card): bool
    {
        return self::hasStrongStrikeTrigger($card, 'strong');
    }

    public static function isStrongOnlyEffect(array $effect): bool
    {
        $levels = $effect['levels'] ?? null;
        if (!is_array($levels)) return false;
        $levels = array_values(array_map('strval', $levels));
        return $levels === ['strong'];
    }

    public function openPreBattleSequence(CardInstance $source): bool
    {
        return false;
    }

    public function openStrongStrikeSequence(
        CardInstance $source,
        CardInstance $target,
        string $attackLevel,
        int $woundsDealt,
        bool $continueAfterComplete = false
    ): bool
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || $attackLevel !== 'strong') return false;
        if (!self::hasStrongStrikeTrigger($source, $attackLevel)) return false;
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
            'continue_after_result_ack' => $continueAfterComplete,
            'strike' => $continueAfterComplete ? $this->state->battle['strike'] : null,
        ]);
    }

    private function startSequence(CardInstance $source, string $activation, array $context): bool
    {
        $this->state->battle['pending_degasca_sequence'] = [
            'owner' => $source->owner,
            'source_id' => $source->instanceId,
            'activation' => $activation,
            'effects' => $activation === 'pre_battle' ? [] : $this->learnedEffectEntries($source),
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

                $target = $this->state->getCard((int) (($pending['context']['target_id'] ?? 0)));
                if ($this->handoffActiveExternalPending($index)) {
                    return;
                }

                $effectEntry = (array) $effects[$index];
                $effectList = (array) ($effectEntry['effects'] ?? []);
                if ($target === null && $this->effectListRequiresTarget($effectList)) {
                    $this->state->battle['pending_degasca_sequence']['index'] = $index + 1;
                    continue;
                }

                $pendingSnapshot = $this->continuationPendingSnapshot();
                (new StrikeResolver($this->state, $this->engine))->applyConfiguredStrikeEffects(
                    $source,
                    $target ?? $source,
                    (string) ($pending['context']['attack_level'] ?? 'strong'),
                    $effectList,
                    (int) ($pending['context']['wounds_dealt'] ?? 0)
                );
                if ($this->handoffChangedExternalPending($pendingSnapshot, $index + 1)) {
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

        $effects = $this->learnableEffects($card);
        if (empty($effects)) {
            $this->finishSequence($pending, null, true);
            return Result::error('У карты больше нет подходящей способности');
        }

        $this->addLearned($source, $card->ukid, $effects);
        $this->shuffleDeck($source->owner);
        $this->finishSequence($pending, null, true);
        $this->state->bumpVersion();
        return Result::ok(['degasca_learned:' . $card->ukid]);
    }

    private function pendingFor(string $playerKey, string $stage): ?array
    {
        $pending = $this->state->battle['pending_degasca_sequence'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) return null;
        if (($pending['stage'] ?? '') !== $stage) return null;
        return $pending;
    }

    private function handoffActiveExternalPending(int $nextIndex): bool
    {
        $key = $this->currentExternalPendingKey();
        if ($key !== null
            && $this->supportsContinuation($key)
            && !$this->hasContinuation($key)) {
            return $this->handoffExternalPending($key, $nextIndex);
        }
        return false;
    }

    /**
     * @param array<string,string> $before
     */
    private function handoffChangedExternalPending(array $before, int $nextIndex): bool
    {
        foreach (ChoiceRegistry::all() as $handler) {
            $key = $handler->pendingKey();
            if (!$this->supportsContinuation($key)) continue;
            if (empty($this->state->battle[$key]) || !is_array($this->state->battle[$key])) continue;
            if ($this->hasContinuation($key)) continue;
            $current = serialize($this->state->battle[$key]);
            if (($before[$key] ?? null) === $current) continue;
            if ($this->currentExternalPendingKey() !== $key) continue;
            return $this->handoffExternalPending($key, $nextIndex);
        }
        return false;
    }

    /**
     * @return array<string,string>
     */
    private function continuationPendingSnapshot(): array
    {
        $snapshot = [];
        foreach (ChoiceRegistry::all() as $handler) {
            $key = $handler->pendingKey();
            if (!$this->supportsContinuation($key)) continue;
            if (empty($this->state->battle[$key]) || !is_array($this->state->battle[$key])) continue;
            $snapshot[$key] = serialize($this->state->battle[$key]);
        }
        return $snapshot;
    }

    private function handoffExternalPending(string $key, int $nextIndex): bool
    {
        $pending = $this->state->battle['pending_degasca_sequence'] ?? null;
        if (!$pending) return false;
        $pending['index'] = $nextIndex;
        $pending['stage'] = 'effects';

        if ($key === 'pending_any_death') {
            self::appendSavedContinuation($this->state, self::ANY_DEATH_CONTINUATION_KEY, $pending);
            unset($this->state->battle['pending_degasca_sequence']);
            return true;
        }

        if ($key === 'pending_nokami_wound') {
            self::appendSavedContinuation($this->state, self::NOKAMI_WOUND_CONTINUATION_KEY, $pending);
            unset($this->state->battle['pending_degasca_sequence']);
            return true;
        }

        if ($key === 'pending_anubisar_chain' || $key === 'pending_talion_incarnation') {
            $this->state->battle[$key]['degasca_sequence'] = $pending;
            unset($this->state->battle['pending_degasca_sequence']);
            return true;
        }

        return false;
    }

    private function currentExternalPendingKey(): ?string
    {
        foreach (ChoiceRegistry::all() as $handler) {
            $key = $handler->pendingKey();
            if ($key === 'pending_degasca_sequence') continue;
            if (method_exists($handler, 'isActive')) {
                if ($handler->isActive($this->state)) return $key;
                continue;
            }
            if (!empty($this->state->battle[$key])) return $key;
        }
        return null;
    }

    private function supportsContinuation(string $key): bool
    {
        return in_array($key, [
            'pending_anubisar_chain',
            'pending_talion_incarnation',
            'pending_any_death',
            'pending_nokami_wound',
        ], true);
    }

    private function hasContinuation(string $key): bool
    {
        if ($key === 'pending_any_death') {
            return !empty($this->state->battle[self::ANY_DEATH_CONTINUATION_KEY]);
        }
        if ($key === 'pending_nokami_wound') {
            return !empty($this->state->battle[self::NOKAMI_WOUND_CONTINUATION_KEY]);
        }
        return !empty($this->state->battle[$key]['degasca_sequence']);
    }

    public function restoreAndResume(array $pending): void
    {
        if (!empty($this->state->battle['pending_degasca_sequence'])) {
            $this->deferContinuation($pending);
            return;
        }
        $this->state->battle['pending_degasca_sequence'] = $pending;
        $this->resume(true);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function drainSavedContinuations(GameState $state, string $key): array
    {
        $stored = $state->battle[$key] ?? null;
        unset($state->battle[$key]);
        return self::normalizeContinuationList($stored);
    }

    public static function appendSavedContinuation(GameState $state, string $key, array $pending): void
    {
        $queue = self::normalizeContinuationList($state->battle[$key] ?? null);
        self::appendUniqueContinuation($queue, $pending);
        $state->battle[$key] = $queue;
    }

    /**
     * @param mixed $stored
     * @return array<int,array<string,mixed>>
     */
    private static function normalizeContinuationList(mixed $stored): array
    {
        if (!is_array($stored) || empty($stored)) {
            return [];
        }
        if (isset($stored['stage']) || isset($stored['source_id'])) {
            return [$stored];
        }

        $queue = [];
        foreach ($stored as $item) {
            if (is_array($item)) {
                $queue[] = $item;
            }
        }
        return $queue;
    }

    /**
     * @param array<int,array<string,mixed>> $queue
     */
    private static function appendUniqueContinuation(array &$queue, array $pending): void
    {
        $serialized = serialize($pending);
        foreach ($queue as $existing) {
            if (serialize($existing) === $serialized) {
                return;
            }
        }
        $queue[] = $pending;
    }

    private function deferContinuation(array $pending): void
    {
        self::appendSavedContinuation($this->state, self::DEFERRED_CONTINUATIONS_KEY, $pending);
    }

    private function resumeDeferredContinuation(): bool
    {
        if (!empty($this->state->battle['pending_degasca_sequence'])) {
            return false;
        }

        $queue = self::drainSavedContinuations($this->state, self::DEFERRED_CONTINUATIONS_KEY);
        if (empty($queue)) {
            return false;
        }

        $first = array_shift($queue);
        foreach ($queue as $pending) {
            $this->deferContinuation($pending);
        }

        $this->state->battle['pending_degasca_sequence'] = $first;
        $this->resume(true);
        return true;
    }

    private function continueAfterComplete(array $pending): void
    {
        $activation = (string) ($pending['activation'] ?? '');
        if ($activation === 'pre_battle') {
            (new TurnProcessor($this->state, $this->engine))->continuePreBattleEffects();
            return;
        }

        if ($activation === 'strong_strike'
            && !empty($pending['context']['continue_after_result_ack'])
            && is_array($pending['context']['strike'] ?? null)) {
            $currentStrike = $this->state->battle['strike'] ?? null;
            if (!is_array($currentStrike) || empty($currentStrike['attacker_id'])) {
                $this->state->battle['strike'] = (array) $pending['context']['strike'];
            }
            (new StrikeResolver($this->state, $this->engine))
                ->continueAfterStrikeResultAck((array) $pending['context']['strike']);
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
            $this->continueAfterComplete($pending);
        }

        $this->resumeDeferredContinuation();
    }

    /**
     * @return array<int,array{ukid:string,effects:array}>
     */
    private function learnedEffectEntries(CardInstance $source): array
    {
        $entries = $source->flags['degasca_learned_effects'] ?? [];
        if (!is_array($entries)) return [];

        $result = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $ukid = (string) ($entry['ukid'] ?? '');
            $effects = (array) ($entry['effects'] ?? []);
            if ($ukid === '' || empty($effects)) continue;
            foreach (array_values($effects) as $effect) {
                if (!is_array($effect)) continue;
                $result[] = ['ukid' => $ukid, 'effects' => [$effect]];
            }
        }
        return $result;
    }

    /**
     * @param array<int,array> $effects
     */
    private function effectListRequiresTarget(array $effects): bool
    {
        foreach ($effects as $effect) {
            if (!is_array($effect)) return true;
            if (!empty($effect['incarnation_token_graveyard'])
                || (($effect['type'] ?? '') === 'incarnation_token_graveyard')) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * @return string[]
     */
    private function learnedUkids(CardInstance $source): array
    {
        $raw = $source->flags['degasca_learned'] ?? [];
        if (!is_array($raw)) return [];
        return array_values(array_unique(array_map('strval', $raw)));
    }

    private function addLearned(CardInstance $source, string $ukid, array $effects): void
    {
        $learned = $this->learnedUkids($source);
        if (!in_array($ukid, $learned, true)) {
            $learned[] = $ukid;
        }
        $source->flags['degasca_learned'] = $learned;

        $entries = $source->flags['degasca_learned_effects'] ?? [];
        if (!is_array($entries)) $entries = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['ukid'] ?? '') === $ukid) {
                $source->flags['degasca_learned_effects'] = $entries;
                return;
            }
        }
        $entries[] = ['ukid' => $ukid, 'effects' => array_values($effects)];
        $source->flags['degasca_learned_effects'] = $entries;
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
        if (self::isDegasca($card)) return false;
        if (in_array($card->ukid, $this->learnedUkids($source), true)) return false;
        return !empty($this->learnableEffects($card));
    }

    /**
     * @return array<int,array>
     */
    private function learnableEffects(CardInstance $card): array
    {
        $effects = [];
        foreach ((array) ($card->prop['strike_effects'] ?? []) as $effect) {
            if (!is_array($effect)) continue;
            if (!self::isStrongOnlyEffect($effect)) continue;
            if (($effect['type'] ?? '') === 'degasca_sequence') continue;
            $effects[] = $effect;
        }
        return $effects;
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
