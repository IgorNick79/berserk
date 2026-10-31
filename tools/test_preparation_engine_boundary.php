<?php
declare(strict_types=1);
require __DIR__.'/test_view_sideboard.php';
use Berserk\Core\{Engine,GameState,Command,MatchPause,GameSettings,ActionResolution};
$engine=new Engine(null,static fn():int=>1000);
foreach (['view','deck','place','draft','mode','settings','turn','side','deal'] as $stage) {
    $s=new GameState(-901,1,2);$s->status=$stage;
    $context=['next_id'=>3,'sequence'=>1,'tokens'=>[],'notifications'=>['preserve']];
    $s->battle[ActionResolution::KEY]=$context;
    $before=$s->toArray();
    $r=$engine->apply($s,'host',new Command('nonexistent_preparation_command'));
    assertTrue(!$r->success,'Unknown preparation command rejected');
    assertTrue($s->battle[ActionResolution::KEY]===$context,'No combat action resolution cleanup in '.$stage);
    assertTrue(!isset($s->battle['turn_timer']),'No preparation clock in '.$stage);
}
foreach ([0,180] as $seconds) {
    $s=new GameState(-902,1,2);$s->status='view';$s->settings->battle['turn_seconds']=$seconds;
    assertTrue($engine->apply($s,'host',new Command('request_pause',['duration_seconds'=>60]))->success,'View pause requested');
    $id=$s->battle['match_pause']['request_id'];
    foreach (['view_to_sideboard','view_to_deck','confirm_view'] as $type) {
        $before=$s->toArray();$r=$engine->apply($s,'host',new Command($type,['card_id'=>1]));
        assertTrue(!$r->success && $r->error==='match_paused' && $before===$s->toArray(),'Server blocks '.$type);
    }
    assertTrue($engine->apply($s,'player',new Command('accept_pause',['request_id'=>$id]))->success,'Opponent accepts');
    $s=GameState::fromArray($s->toArray());
    assertTrue($engine->apply($s,'host',new Command('pause_ready',['request_id'=>$id]))->success,'First ready');
    assertTrue($engine->apply($s,'player',new Command('pause_ready',['request_id'=>$id]))->success,'Second ready');
    assertTrue(!isset($s->battle['turn_timer']) && !MatchPause::blocking($s),'Resume without fake battle clock');
}
echo "Preparation Engine boundary tests passed.\n";
