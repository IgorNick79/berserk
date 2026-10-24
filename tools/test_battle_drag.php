<?php
declare(strict_types=1);
// Reuse the existing in-memory fixture; no DB or game commands are executed.
ob_start();
require __DIR__ . '/test_battle_snapshot_renderer.php';
ob_end_clean();
use Berserk\Core\CardInstance;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Screen\BattleSnapshotRenderer;
use Berserk\View\Template;

$state = battleSnapshotState();
$state->getCard(5)->row = 3;
$state->getCard(5)->col = 3;
$state->getCard(1)->ukid = 's1_1';
$state->getCard(1)->modifiers[] = ['stat'=>'ova','value'=>1];
$state->getCard(2)->ukid = 's1_2';
$state->addCard(battleSnapshotCard(6, 's1_3', 'player', CardInstance::ZONE_FLYING, null, null, true, 1));
$cardsInfo = ['s1_1'=>['name'=>'Лесной страж'], 's1_2'=>['name'=>'SECRET-ENEMY'],
    's1_3'=>['name'=>'Летающий противник'], 'flying'=>['name'=>'Свой летун'], 'ally'=>['name'=>'Союзник']];
$tpl = new Template(__DIR__ . '/../templates');
$before = serialize($state->toArray());
$render = fn(array $ui) => (new BattleScreen($tpl))->prepare($state, 'host', 'host', null, $cardsInfo, $ui)['data'];
$snapshot = fn(array $ui) => (new BattleSnapshotRenderer($tpl))->render($state, 'host', 'host', null, $cardsInfo, $ui);
$cases = ['selected'=>$render(['sel'=>1]), 'unselected'=>$render(['sel'=>0]),
    'special'=>$render(['sel'=>1,'mode'=>'action:aimed_shot']), 'fly'=>$render(['sel'=>3])];
battleSnapshotAssert(str_contains($cases['selected']['field_html'], 'data-drag-enabled="1"'), 'Ordinary turn enables drag');
battleSnapshotAssert(str_contains($cases['special']['field_html'], 'data-drag-enabled="0"'), 'Special action disables drag');
battleSnapshotAssert(str_contains($cases['selected']['field_html'], 'cmd=move') && str_contains($cases['selected']['field_html'], 'cmd=strike'), 'Use existing ordinary command links');
battleSnapshotAssert(str_contains($cases['selected']['field_html'], 'data-drag-owner="enemy" data-drag-source="0"'), 'Enemy never becomes a source');
battleSnapshotAssert(!str_contains($cases['selected']['field_html'], 'SECRET-ENEMY'), 'Hidden name not exposed');
battleSnapshotAssert(!str_contains($cases['fly']['fly_zones_html'], 'cmd=move'), 'Flying zones offer no ordinary movement links');
$state->battle['pending_drag_test'] = ['owner'=>'host'];
$cases['pending'] = $render(['sel'=>1]);
battleSnapshotAssert(str_contains($cases['pending']['field_html'], 'data-drag-enabled="0"'), 'Unknown mandatory pending fails closed');
unset($state->battle['pending_drag_test']);
$state->battle['strike'] = ['kind'=>'strike','state'=>'waiting_defender','attacker_id'=>1,'target_id'=>2,'defenders'=>[]];
$cases['combat'] = $render(['sel'=>1]);
battleSnapshotAssert(str_contains($cases['combat']['field_html'], 'data-drag-enabled="0"'), 'Active combat disables drag');
$state->battle['strike'] = null;
$state->battle['match_pause'] = ['mode'=>'requested','request_deadline_at'=>time()+100,'initiator'=>'host',
    'used'=>['host'=>0,'player'=>0],'cooldown_until'=>['host'=>0,'player'=>0],'last_result'=>null,'last_resume'=>null];
$cases['pause'] = $render(['sel'=>1]);
battleSnapshotAssert(str_contains($cases['pause']['field_html'], 'data-drag-enabled="0"'), 'Pause disables drag');
unset($state->battle['match_pause']);
$state->battle['turn_timer'] = ['period_id'=>1,'owner'=>'host','status'=>'expired','remaining_seconds'=>0,'anchor_at'=>null,'pause_reason'=>null];
$cases['expired'] = $render(['sel'=>1]);
battleSnapshotAssert(str_contains($cases['expired']['field_html'], 'data-drag-enabled="0"'), 'Expired turn disables drag');
unset($state->battle['turn_timer']);
$state->battle['active'] = 'player';
$cases['inactive'] = $render(['sel'=>1]);
battleSnapshotAssert(str_contains($cases['inactive']['field_html'], 'data-drag-enabled="0"'), 'Inactive player cannot drag');
$state->battle['active'] = 'host';
$state->getCard(1)->closed = true;
$cases['closed'] = $render(['sel'=>1]);
battleSnapshotAssert(str_contains($cases['closed']['field_html'], 'data-drag-card="1" data-drag-owner="own" data-drag-source="0"'), 'Closed source disabled');
$state->getCard(1)->closed = false;
battleSnapshotAssert($before === serialize($state->toArray()), 'Rendering does not mutate state');
if (in_array('--fixture', $argv, true)) {
    $snapshots = [];
    foreach ([0,1,2,3,5] as $id) $snapshots[(string)$id] = $snapshot(['sel'=>$id]);
    echo json_encode(['cases'=>$cases,'snapshots'=>$snapshots], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
} else echo "Drag PHP: availability, special mode, pending, inactive player, closed cards, hidden data, flying links and state immutability passed.\n";
