<?php
declare(strict_types=1);

// In-memory adapter loaded before the autoloader. No credentials, sockets or production DB writes.
namespace Berserk\Core {
    final class Db
    {
        public array $cards = [];
        public array $game = [];
        public bool $omitBuilderCard = false;
        private int $affected = 0;

        public function __construct()
        {
            foreach (['common', 'uncommon', 'rare', 'ultrarare'] as $rarity) {
                for ($i = 0; $i < 80; $i++) $this->add($rarity . $i, [], $rarity);
            }
        }

        public function add(string $ukid, array $prop = [], string $rarity = 'common'): void
        {
            $this->cards[$ukid] = ['ukid' => $ukid, 'ind' => count($this->cards) + 1, 'name' => $ukid,
                'prop' => json_encode($prop), 'rarity' => $rarity, 'power' => 3, 'price' => 2,
                'health' => 5, 'move' => 1, 'elite' => 0, 'single' => 0, 'type' => 'creature',
                'class' => '', 'strike_weak' => 1, 'strike_medium' => 2, 'strike_strong' => 3, 'element_id' => 1];
        }

        public function escape(string $value): string { return addslashes($value); }
        public function fetchAll(string $sql): array
        {
            if (str_contains($sql, 'FROM elements')) return [['ind' => 1, 'code' => 'forest']];
            if (str_contains($sql, 'FROM card_synergy')) return [];
            if (preg_match("/rarity = '([^']+)'/", $sql, $match)) {
                return array_values(array_filter($this->cards, fn($card) => $card['rarity'] === $match[1]));
            }
            if (preg_match('/ukid IN \(([^)]+)\)/', $sql, $match)) {
                preg_match_all("/'([^']+)'/", $match[1], $ids);
                $cards = array_values(array_intersect_key($this->cards, array_flip($ids[1])));
                if ($this->omitBuilderCard && str_contains($sql, 'SELECT ukid, price')) array_pop($cards);
                return $cards;
            }
            throw new \RuntimeException('Unexpected fixture SELECT');
        }
        public function fetchOne(string $sql): ?array
        {
            if (str_starts_with($sql, 'SHOW COLUMNS')) return ['Field' => 'version'];
            if (str_starts_with($sql, 'SELECT * FROM games')) return $this->game ?: null;
            throw new \RuntimeException('Unexpected fixture query');
        }
        public function query(string $sql): bool
        {
            if (!preg_match("/SET json = '(.*)', status = '([^']+)', version = (\d+) WHERE ind = (\d+) AND version = (\d+)$/s", $sql, $m)) {
                throw new \RuntimeException('Unexpected fixture UPDATE');
            }
            $this->affected = (int) (($this->game['version'] ?? -1) === (int) $m[5]);
            if ($this->affected) {
                $this->game['json'] = stripslashes($m[1]);
                $this->game['status'] = $m[2];
                $this->game['version'] = (int) $m[3];
            }
            return true;
        }
        public function affectedRows(): int { return $this->affected; }
    }
}

namespace {
    require_once __DIR__ . '/../src/Core/Autoloader.php';
    use Berserk\Core\{Autoloader, CardInstance, Command, Db, Engine, GameRepository, GameSettings, GameState, StaleStateException};
    use Berserk\Core\Prepare\{DraftAutoPicker, DraftCopyRules, DraftProcessor, DraftSelectionEvaluator, DraftTimer, PrepareProcessor};
    use Berserk\View\{Template};
    use Berserk\View\Screen\{DraftScreen, ModeScreen, SettingsScreen};
    Autoloader::register();
    Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

    $checks = 0;
    function check(bool $condition, string $message): void
    {
        global $checks;
        $checks++;
        if (!$condition) throw new \RuntimeException($message);
    }
    function fresh(Db $db, int $size = 6, int $boosters = 5): GameState
    {
        $state = new GameState(900, 1, 2);
        $p = new PrepareProcessor($state, $db);
        check($p->chooseMode('host', new Command('choose_mode', ['mode' => 'solomon']))->success, 'Choose Solomon');
        check($state->mode === 'draft' && $state->settings->isSolomonDraft(), 'Solomon is a Draft type');
        check($p->confirmSettings('host', new Command('confirm_settings', ['stack_size' => $size, 'boosters' => $boosters,
            'draft_timer_mode' => 'unlimited']))->success, 'Start Solomon');
        return $state;
    }
    function token(GameState $state, array $extra = []): Command
    {
        return new Command('draft_confirm', array_merge(['decision_id' => $state->draft['decision_id'], 'expected_version' => $state->version], $extra));
    }
    function synthetic(Db $db, int $host = 27, int $player = 27, int $remaining = 6): GameState
    {
        $state = fresh($db);
        $all = [];
        for ($i = 0; $i < $host + $player + $remaining; $i++) {
            $id = 'unique' . $i;
            $db->add($id);
            $all[] = $id;
        }
        $state->draft['picked'] = ['host' => array_slice($all, 0, $host), 'player' => array_slice($all, $host, $player)];
        $state->draft['grid'] = array_slice($all, $host + $player, min(6, $remaining));
        $state->draft['pool'] = array_slice($all, $host + $player + 6);
        $state->draft['recycle'] = [];
        return $state;
    }
    $db = new Db();
    check(GameSettings::fromArray([])->draftType() === 'grid', 'Old settings default to Grid');
    check(GameState::fromArray((new GameState(1, 1, 2))->toArray())->settings->draftType() === 'grid', 'Old state defaults');
    foreach ([0, 7, 9, '6x', 6.5, []] as $bad) {
        check(GameSettings::fromArray(['draft' => ['type' => 'solomon', 'stack_size' => $bad]])->validateDraftSettings() !== null, 'Reject bad stack size');
    }
    foreach ([6, 8] as $size) foreach ([5, 6, 7, 8] as $boosters) {
        $state = fresh($db, $size, $boosters);
        check(count($state->draft['grid']) === $size, 'Initial stack size');
        check(count($state->draft['grid']) + count($state->draft['pool']) === 12 * $boosters, 'No extra cards');
    }
    $legacy = new GameState(900, 1, 2);
    check((new PrepareProcessor($legacy, $db))->chooseMode('host', new Command('choose_mode', ['mode' => 'draft']))->success
        && $legacy->settings->draftType() === 'grid', 'Legacy mode command remains Grid');
    $explicit = new GameState(900, 1, 2);
    check((new Engine($db))->apply($explicit, 'host', new Command('choose_mode', ['mode' => 'draft', 'draft_type' => 'solomon']))->success
        && $explicit->settings->isSolomonDraft(), 'Explicit type command routed through Engine');

    // Exhaustive independent oracle on small instances checks the exact interval theorem.
    mt_srand(8723);
    for ($trial = 0; $trial < 700; $trial++) {
        $cards = ['a' => ['prop' => []], 'b' => ['prop' => []], 'h' => ['prop' => ['horde' => true]]];
        $picked = ['host' => [], 'player' => []];
        foreach (['host', 'player'] as $who) foreach (['a', 'b', 'h'] as $id) {
            $picked[$who] = array_merge($picked[$who], array_fill(0, mt_rand(0, $id === 'h' ? 5 : 3), $id));
        }
        $remaining = [];
        for ($i = 0, $n = mt_rand(0, 8); $i < $n; $i++) $remaining[] = ['a', 'b', 'h'][mt_rand(0, 2)];
        $minimum = mt_rand(0, 10);
        $oracle = false;
        for ($mask = 0; $mask < (1 << count($remaining)); $mask++) {
            $assignment = $picked;
            foreach ($remaining as $i => $id) $assignment[($mask & (1 << $i)) ? 'host' : 'player'][] = $id;
            $legal = true;
            foreach ($assignment as $deck) {
                if (count($deck) < $minimum) $legal = false;
                foreach (array_count_values($deck) as $id => $count) if ($count > ($id === 'h' ? 5 : 3)) $legal = false;
            }
            if ($legal) { $oracle = true; break; }
        }
        check(DraftCopyRules::canCompleteDistribution($picked, $remaining, $cards, $minimum) === $oracle, 'Exact oracle trial ' . $trial);
    }

    // Every candidate must account for the complementary allocation, not just the active player.
    $state = synthetic($db);
    $before = $state->toArray();
    $p = new PrepareProcessor($state, $db);
    foreach ([[], range(0, 5), [0, 0], [-1], [6], ['1x'], '0,1', [[0]]] as $positions) {
        check(!$p->draftConfirm('host', token($state, ['positions' => $positions]))->success, 'Reject invalid/unsafe partition');
        check($state->toArray() === $before, 'Rejected partition is fully atomic');
    }
    check(!$p->draftConfirm('player', token($state, ['positions' => [0, 1, 2]]))->success, 'Reject inactive player');
    $state = synthetic($db, 30, 24, 12);
    $old = token($state);
    check((new PrepareProcessor($state, $db))->draftConfirm('host', $old)->success, 'Empty selection accepted with enough reserve');
    check($state->draft['turn'] === 'player' && count($state->draft['picked']['player']) === 30, 'Empty confirm assigns complement and switches initiative');
    $after = $state->toArray();
    check(!(new PrepareProcessor($state, $db))->draftConfirm('host', $old)->success, 'Old confirm cannot affect next stack');
    check($state->toArray() === $after, 'Replay changes nothing');
    $badToken = token($state);
    $badToken->payload['expected_version']--;
    check(!(new PrepareProcessor($state, $db))->draftConfirm('player', $badToken)->success, 'Expected version mismatch rejected');
    check($state->toArray() === $after, 'Stale version does not mutate state');
    check(!(new PrepareProcessor($state, $db))->draftPass('player', new Command('draft_pass'))->success, 'Missing token rejected');
    $state = synthetic($db, 24, 30, 12);
    check((new PrepareProcessor($state, $db))->draftConfirm('host', token($state, ['positions' => range(0, 5)]))->success, 'All selected accepted');
    check(count($state->draft['picked']['host']) === 30, 'All cards assigned');
    check(count($state->draft['grid']) === 6, 'Next stack is not padded');
    check($state->draft['turn'] === 'player', 'All-card confirm switches initiative');

    // Alternation is shared by manual and automatic confirmations; passes retain their own rules.
    $state = synthetic($db, 30, 30, 36);
    $p = new PrepareProcessor($state, $db);
    $oldHostCommand = token($state, ['positions' => [0]]);
    $decision = $state->draft['decision_id'];
    check($p->draftConfirm('host', $oldHostCommand)->success, 'One-card confirm');
    check($state->draft['turn'] === 'player' && $state->draft['decision_id'] === $decision + 1, 'Host confirm: player decision, exactly one increment');
    check(count($state->draft['picked']['host']) === 31 && count($state->draft['picked']['player']) === 35, 'One-card partition assigned');
    $before = $state->toArray();
    check(!$p->draftConfirm('host', $oldHostCommand)->success && $state->toArray() === $before, 'Previous actor replay rejected');
    check($p->draftConfirm('player', token($state, ['positions' => [0, 2]]))->success, 'Several-card player confirm');
    check($state->draft['turn'] === 'host' && $state->draft['decision_id'] === $decision + 2, 'Host -> player -> host');
    $before = $state->toArray();
    check(!$p->draftConfirm('host', $oldHostCommand)->success && $state->toArray() === $before, 'Old token rejected even after initiative returns');
    check($p->draftConfirm('host', token($state, ['positions' => [1]]))->success && $state->draft['turn'] === 'player', 'Third stack alternates');
    $stack = $state->draft['grid'];
    $picked = $state->draft['picked'];
    $decision = $state->draft['decision_id'];
    check($p->draftPass('player', token($state))->success, 'First pass after confirm');
    check($state->draft['turn'] === 'host' && $state->draft['grid'] === $stack && $state->draft['picked'] === $picked
        && $state->draft['decision_id'] === $decision + 1, 'First pass keeps allocation and stack, changes decision once');
    check($p->draftPass('host', token($state))->success, 'Second pass after confirm');
    check($state->draft['turn'] === 'host' && $state->draft['recycle'] === $stack
        && $state->draft['consecutive_passes'] === 0 && $state->draft['decision_id'] === $decision + 2, 'Second passer retains next stack');
    $state = synthetic($db, 30, 30, 24);
    $p = new PrepareProcessor($state, $db);
    check($p->draftPass('host', token($state))->success && $state->draft['turn'] === 'player', 'Host passes to player');
    check($p->draftConfirm('player', token($state, ['positions' => [0, 1]]))->success
        && $state->draft['turn'] === 'host' && $state->draft['consecutive_passes'] === 0, 'Confirm after first pass returns to host and resets chain');

    // Existing lifecycle must settle the old actor, then start the new actor's action clock.
    $state = synthetic($db, 30, 30, 24);
    $state->settings->draft['timer_mode'] = 'default';
    DraftTimer::initialize($state, $state->settings, time() - 7);
    $p = new PrepareProcessor($state, $db);
    check($p->draftConfirm('host', token($state, ['positions' => [0]]))->success, 'Timed confirm');
    $started = $state->draft['timer']['action_started_at'];
    check($state->draft['turn'] === 'player' && $state->draft['timer']['elapsed']['host'] >= 7
        && $state->draft['timer']['elapsed']['host'] < 30 && $state->draft['timer']['elapsed']['player'] === 0, 'Only confirming actor charged');
    check(DraftTimer::deadlineAt($state) === $started + 32, 'New actor receives full action limit and grace');
    check($p->resolveDraftTimeouts($started + 1)->events === [], 'Switch does not immediately time out');
    $restored = GameState::fromArray($state->toArray());
    $snapshot = DraftTimer::snapshot($restored, 'player', $started + 2);
    check($snapshot['turn'] === 'player' && $snapshot['remaining_action'] === 30
        && $restored->draft['timer']['action_started_at'] === $started, 'Refresh/sync preserve new actor and deadline');
    check($p->resolveDraftTimeouts($started + 32)->success && $state->draft['turn'] === 'host', 'New actor timeout passes back to host');
    check($state->draft['timer']['elapsed']['player'] === 30, 'Timeout charges new actor');
    $state = synthetic($db, 30, 30, 24);
    $state->settings->draft['timer_mode'] = 'default';
    DraftTimer::initialize($state, $state->settings, time() - 7);
    $p = new PrepareProcessor($state, $db);
    $decision = $state->draft['decision_id'];
    check($p->draftPass('host', token($state))->success, 'Timed first pass');
    $hostElapsed = $state->draft['timer']['elapsed']['host'];
    check($state->draft['turn'] === 'player' && $hostElapsed >= 7 && $state->draft['timer']['elapsed']['player'] === 0
        && $state->draft['decision_id'] === $decision + 1, 'First pass settles old actor and changes decision once');
    $state->draft['timer']['action_started_at'] = time() - 4;
    check($p->draftPass('player', token($state))->success, 'Timed second pass');
    check($state->draft['turn'] === 'player' && $state->draft['timer']['elapsed']['player'] >= 4
        && $state->draft['timer']['elapsed']['host'] === $hostElapsed && $state->draft['decision_id'] === $decision + 2,
        'Second pass charges passer, retains initiative and increments decision once');
    check(DraftTimer::deadlineAt($state) === $state->draft['timer']['action_started_at'] + 32, 'Second pass starts a new action limit');
    $state = synthetic($db);
    $state->settings->draft['timer_mode'] = 'default';
    DraftTimer::initialize($state, $state->settings, time() - 5);
    check((new PrepareProcessor($state, $db))->draftConfirm('host', token($state, ['positions' => [0, 1, 2]]))->success
        && $state->status === 'view' && $state->draft === null, 'Final confirm starts no extra timed decision');

    $state = synthetic($db, 30, 30, 24);
    $state->settings->draft['timer_mode'] = 'default';
    DraftTimer::initialize($state, $state->settings, 1000);
    $state->draft['timer']['elapsed'] = ['host' => 600, 'player' => 600];
    $spy = new class($state, new DraftAutoPicker(new DraftSelectionEvaluator($db->cards))) {
        public array $actors = [];
        public function __construct(private GameState $state, private DraftAutoPicker $picker) {}
        public function pick(array $choices, array $current): ?array {
            $this->actors[] = $this->state->draft['turn'];
            return $this->picker->pick($choices, $current);
        }
    };
    check((new PrepareProcessor($state, $db, $spy))->resolveDraftTimeouts(1002)->success && $state->status === 'view', 'Auto completion with alternating actors');
    check($spy->actors === ['host', 'player', 'host', 'player'], 'Auto confirmations alternate on every stack');
    $state = fresh($db, 8);
    $state->draft['picked'] = ['host' => [], 'player' => []];
    $state->draft['grid'] = array_slice($state->draft['pool'], 0, 8);
    $state->draft['pool'] = array_slice($state->draft['pool'], 8, 4);
    // Draw boundary itself is independent of distribution checks.
    $open = new \ReflectionMethod(DraftProcessor::class, 'openStack');
    $open->setAccessible(true);
    $open->invoke(new DraftProcessor($state, $db));
    check(count($state->draft['grid']) === 4, 'Final partial stack has exactly four cards');

    $state = fresh($db, 8);
    $initial = $state->draft['grid'];
    $p = new PrepareProcessor($state, $db);
    $old = token($state);
    check($p->draftPass('host', $old)->success, 'First pass');
    check($state->draft['grid'] === $initial && $state->draft['turn'] === 'player', 'First pass preserves stack');
    check($p->draftPass('player', token($state))->success, 'Second pass');
    check($state->draft['turn'] === 'player' && $state->draft['recycle'] === $initial, 'Recycle and second passer initiative');
    for ($i = 0; $i < 160; $i++) check($p->draftPass($state->draft['turn'], token($state))->success, 'Repeated passes terminate individually');
    check(count($state->draft['grid']) + count($state->draft['pool']) + count($state->draft['recycle']) === 60, 'Recycle conserves all cards');
    $restored = GameState::fromArray($state->toArray());
    check($restored->toArray() === $state->toArray(), 'Full restore of decisions, recycle and state');

    // Duplicate identity uses positions; capacity includes both picked pools and all reserve.
    foreach ([false, true] as $horde) {
        $limit = $horde ? 5 : 3;
        $db->add('duplicate', ['horde' => $horde]);
        $state = synthetic($db, 30, 30, 6);
        $state->draft['picked']['host'] = array_merge(array_slice($state->draft['picked']['host'], 0, 30 - $limit), array_fill(0, $limit, 'duplicate'));
        $state->draft['grid'][0] = 'duplicate';
        $before = $state->toArray();
        check(!(new DraftProcessor($state, $db))->confirmStack('host', [0])->success, 'Recipient copy cap');
        check($state->toArray() === $before, 'Copy rejection atomic');
        check((new DraftProcessor($state, $db))->confirmStack('host', [1, 2, 3])->success, 'Duplicate assigned to opponent by position');
        check($state->status === 'view', 'Natural finalization');
    }
    $state = synthetic($db, 30, 0, 30);
    $db->add('stranded');
    $state->draft['pool'] = array_merge(array_fill(0, 5, 'stranded'), array_slice($state->draft['pool'], 0, 19));
    check((new DraftProcessor($state, $db))->validSelections() === [], '30 physical cards but only 28 playable: no false viability');

    // The last partition has precisely one solution due to complementary copy caps.
    $state = synthetic($db, 27, 27, 6);
    $db->add('lockedA'); $db->add('lockedB');
    $state->draft['picked']['host'] = array_merge(array_slice($state->draft['picked']['host'], 0, 24), array_fill(0, 3, 'lockedB'));
    $state->draft['picked']['player'] = array_merge(array_slice($state->draft['picked']['player'], 0, 24), array_fill(0, 3, 'lockedA'));
    $state->draft['grid'] = array_merge(array_fill(0, 3, 'lockedA'), array_fill(0, 3, 'lockedB'));
    check(count((new DraftProcessor($state, $db))->validSelections()) === 1, 'Exactly one legal allocation');
    check((new DraftProcessor($state, $db))->confirmStack('host', [0, 1, 2])->success && $state->status === 'view', 'Unique allocation reaches View');
    foreach (['host', 'player'] as $who) check(count($state->getCardsInZone($who, CardInstance::ZONE_DECK)) === 30, '30 unique instances');
    $engine = new Engine($db);
    $candidate = $state->getCardsInZone('host', CardInstance::ZONE_DECK)[0];
    check(!$engine->apply($state, 'host', new Command('view_to_sideboard', ['card_id' => $candidate->instanceId]))->success, 'Cannot sideboard below 30');
    check($engine->apply($state, 'host', new Command('confirm_view'))->success, 'Host View confirmation');
    check($engine->apply($state, 'player', new Command('confirm_view'))->success && $state->status === 'turn', 'Existing next stage');

    // Builder omissions and automatic failures must restore the whole batch, including clocks.
    $state = synthetic($db);
    $before = $state->toArray();
    $db->omitBuilderCard = true;
    check(!(new PrepareProcessor($state, $db))->draftConfirm('host', token($state, ['positions' => [0, 1, 2]]))->success, 'Missing builder card rejected');
    check($state->toArray() === $before, 'Finalization rollback');
    $db->omitBuilderCard = false;

    // Real picker + evaluator, not a replacement allocator. Full 60/72/84/96-card completions.
    foreach ([6, 8] as $size) foreach ([5, 6, 7, 8] as $boosters) {
        $state = fresh($db, $size, $boosters);
        $total = count($state->draft['grid']) + count($state->draft['pool']);
        $state->settings->draft['timer_mode'] = 'default';
        DraftTimer::initialize($state, $state->settings, 1000);
        $state->draft['timer']['elapsed'] = ['host' => 600, 'player' => 600];
        $result = (new PrepareProcessor($state, $db))->resolveDraftTimeouts(1002);
        check($result->success && $state->status === 'view' && $state->draft === null, 'Guaranteed auto completion');
        check(count($state->cards) === $total, 'Auto completion loses no cards');
        foreach (['host', 'player'] as $who) {
            $cards = $state->getCardsInZone($who, CardInstance::ZONE_DECK);
            check(count($cards) >= 30, 'Auto minimum');
            foreach (array_count_values(array_map(fn($card) => $card->ukid, $cards)) as $id => $count) {
                check($count <= DraftCopyRules::deckLimit($db->cards[$id]), 'Auto copy cap');
            }
        }
        if ($size === 8 && $boosters % 2 === 1) {
            $historySizes = array_map(fn($event) => (int) substr($event, strlen('auto_picked:')), array_filter($result->events, fn($event) => str_starts_with($event, 'auto_picked:')));
            check(count($historySizes) === (int) ceil($total / $size), 'Short final stack consumed in finite picks');
        }
    }
    $state = fresh($db);
    $state->settings->draft['timer_mode'] = 'default';
    DraftTimer::initialize($state, $state->settings, 1000);
    $initial = $state->draft['grid'];
    $p = new PrepareProcessor($state, $db);
    check($p->resolveDraftTimeouts(1031)->events === [], 'Transport grace preserved');
    check($p->resolveDraftTimeouts(1032)->success, 'Action timeout');
    check($state->draft['turn'] === 'player' && $state->draft['grid'] === $initial && $state->draft['timer']['elapsed']['host'] === 30, 'Timeout passes, not picks');
    check($state->draft['picked'] === ['host' => [], 'player' => []], 'Timeout does not allocate');
    check($p->resolveDraftTimeouts(1062)->success && $state->draft['turn'] === 'player', 'Second timeout keeps initiative');
    check(count($state->draft['recycle']) === 6, 'Timeout double pass recycle');
    $state = fresh($db);
    $state->settings->draft['timer_mode'] = 'default';
    DraftTimer::initialize($state, $state->settings, time() - 32);
    $old = token($state, ['positions' => [0, 1, 2]]);
    $result = (new PrepareProcessor($state, $db))->draftConfirm('host', $old);
    check($result->success && in_array('manual_draft_action_expired', $result->events, true), 'Timeout wins late confirm');
    check($state->draft['picked'] === ['host' => [], 'player' => []], 'Late confirm not applied');
    $state = fresh($db);
    $state->settings->draft['timer_mode'] = 'default';
    DraftTimer::initialize($state, $state->settings, 1000);
    $state->draft['timer']['elapsed']['host'] = 600;
    check((new PrepareProcessor($state, $db))->resolveDraftTimeouts(1002)->success, 'One total exhausted');
    check($state->status === 'draft' && $state->draft['turn'] === 'player' && $state->draft['timer']['elapsed']['player'] === 0, 'Other budget remains available');
    $state = GameState::fromArray($state->toArray());
    check($state->draft['timer']['action_started_at'] === 1000, 'Reconnect preserves server clock');
    check((new PrepareProcessor($state, $db))->resolveDraftTimeouts(10000)->success && $state->status === 'view', 'Offline catch-up completes');

    $state = synthetic($db);
    $state->settings->draft['timer_mode'] = 'default';
    DraftTimer::initialize($state, $state->settings, 1000);
    $state->draft['timer']['elapsed'] = ['host' => 600, 'player' => 600];
    $db->omitBuilderCard = true;
    $before = $state->toArray();
    check(!(new PrepareProcessor($state, $db))->resolveDraftTimeouts(1002)->success, 'Auto builder failure');
    check($state->toArray() === $before, 'Auto failure rolls back clock, picks, instances');
    $db->omitBuilderCard = false;
    $state = synthetic($db, 30, 0, 30);
    $state->draft['grid'] = array_fill(0, 6, 'stranded');
    $state->settings->draft['timer_mode'] = 'default';
    DraftTimer::initialize($state, $state->settings, 1000);
    $state->draft['timer']['elapsed'] = ['host' => 600, 'player' => 600];
    $before = $state->toArray();
    check(!(new PrepareProcessor($state, $db))->resolveDraftTimeouts(1002)->success, 'Impossible auto completion is explicit');
    check($state->toArray() === $before, 'Impossible auto completion has no partial result');

    $state = fresh($db);
    $state->settings->draft['timer_mode'] = 'custom';
    $state->settings->draft['timer_total'] = 1800;
    $state->settings->draft['timer_action'] = 10;
    DraftTimer::initialize($state, $state->settings, 1000);
    $state->draft['timer']['elapsed']['host'] = 1800;
    check((new PrepareProcessor($state, $db))->resolveDraftTimeouts(20000)->success && $state->status === 'view', 'Maximum offline catch-up is bounded and completes');

    // Two snapshots exercise the real repository CAS for confirm/timeout and pass/timeout.
    foreach (['confirm', 'pass'] as $action) {
        $state = fresh($db);
        $state->settings->draft['timer_mode'] = 'default';
        DraftTimer::initialize($state, $state->settings, time() - 31);
        $db->game = ['ind' => 900, 'host_id' => 1, 'player_id' => 2, 'status' => 'draft', 'version' => 1, 'json' => json_encode($state->toArray())];
        $repo = new GameRepository($db);
        $manual = $repo->findById(900); $timeout = $repo->findById(900);
        $p = new PrepareProcessor($manual, $db);
        $cmd = token($manual, ['positions' => [0, 1, 2]]);
        check(($action === 'confirm' ? $p->draftConfirm('host', $cmd) : $p->draftPass('host', $cmd))->success, 'Concurrent manual prepared');
        check((new PrepareProcessor($timeout, $db))->resolveDraftTimeouts(time() + 2)->success, 'Concurrent timeout prepared');
        foreach ([[$timeout, $manual], [$manual, $timeout]] as [$winner, $loser]) {
            $db->game['version'] = 1;
            $winner->persistenceVersion = 1;
            $loser->persistenceVersion = 1;
            $repo->save($winner);
            $conflict = false;
            try { $repo->save($loser); } catch (StaleStateException) { $conflict = true; }
            check($conflict, 'CAS permits exactly one concurrent commit in either order');
            $reloaded = $repo->findById(900);
            check(!(new PrepareProcessor($reloaded, $db))->draftPass('host', $cmd)->success, 'Stale request after reload rejected');
        }
    }

    $tpl = new Template(__DIR__ . '/../templates');
    $state = new GameState(900, 1, 2);
    $html = (new ModeScreen($tpl))->prepare($state, 'host', 'host', null)['data']['content_html'];
    check(substr_count($html, 'type="radio"') === 4 && str_contains($html, 'value="draft" checked'), 'Mode radio defaults');
    $state = fresh($db, 8);
    $settings = (new SettingsScreen())->prepare($state, 'host', 'host', null)['data']['actions_html'];
    check(str_contains($settings, 'name="stack_size"') && !str_contains($settings, 'name="draft_grid_mode"'), 'Solomon settings isolated');
    $view = (new DraftScreen($tpl))->prepare($state, 'host', 'host', null, $db->cards)['data'];
    check(substr_count($view['grid_html'], 'name="positions[]"') === 8, 'Multiple checkboxes');
    check(substr_count($view['actions_html'], '<button') === 2 && !str_contains($view['actions_html'], 'finish_draft'), 'Only pass and confirm');
    check(str_contains($view['draft_script_html'], 'draft_timer.js'), 'Unlimited Solomon still synchronizes');
    check(str_contains($view['actions_html'], 'expected_version') && str_contains($view['actions_html'], 'decision_id'), 'UI carries tokens');
    $preserved = fresh($db, 8);
    $p = new PrepareProcessor($preserved, $db);
    check($p->draftPass('host', token($preserved))->success && $p->draftPass('player', token($preserved))->success, 'Auto reservoir includes recycled stack');
    $choices = (new DraftProcessor($preserved, $db))->validSelections();
    $actor = $preserved->draft['turn'];
    check((new Engine($db))->apply($preserved, $actor, token($preserved, ['positions' => $choices[0]['positions']]))->success, 'Confirm routed through Engine');
    $alreadyPicked = $preserved->draft['picked'];
    $preserved->settings->draft['timer_mode'] = 'default';
    DraftTimer::initialize($preserved, $preserved->settings, 1000);
    $preserved->draft['timer']['elapsed'] = ['host' => 600, 'player' => 600];
    check((new PrepareProcessor($preserved, $db))->resolveDraftTimeouts(1002)->success && $preserved->status === 'view', 'Auto completes with existing allocations and recycle');
    foreach ($alreadyPicked as $owner => $ids) {
        $final = array_count_values(array_map(fn($card) => $card->ukid, $preserved->getCardsInZone($owner, CardInstance::ZONE_DECK)));
        foreach (array_count_values($ids) as $id => $count) check(($final[$id] ?? 0) >= $count, 'Confirmed ownership is immutable');
    }
    check(count($preserved->cards) === 60, 'Recycle and existing picks conserve all cards at View');
    if (in_array('--render-mode', $argv, true)) {
        $mode = (new ModeScreen($tpl))->prepare(new GameState(900, 1, 2), 'host', 'host', null)['data'];
        echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="stylesheet" href="/assets/css/global.css"><body><main class="screen">' . $mode['content_html'] . '</main></body></html>';
        exit;
    }
    if (in_array('--render', $argv, true) || in_array('--render-player', $argv, true)) {
        $who = in_array('--render-player', $argv, true) ? 'player' : 'host';
        $view = (new DraftScreen($tpl))->prepare($state, $who, $who, null, $db->cards)['data'];
        echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="stylesheet" href="/assets/css/global.css"><body><main class="screen">'
            . $tpl->parse('screen/draft.tpl', $view) . '</main></body></html>';
        exit;
    }
    echo "Solomon Draft: {$checks} checks passed (in-memory DB adapter; no live database).\n";
}
