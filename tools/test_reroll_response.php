<?php
declare(strict_types=1);
define('C2_FIXTURES_ONLY', true);
require __DIR__.'/test_single_roll_c2.php';
use Berserk\Core\{GameState, SingleRoll, InstantProcessor, Engine, Dice, InformationalWaitManager as IW};

foreach (['strike','tap','magic','uchr','shot','throw','discharge'] as $type) {
    foreach ([true,false] as $playMary) {
        $s=c1state($type,false);
        $s->getCard(3)->prop=$props['s1_181'];
        $s->getCard(4)->prop=$props['s1_174'];
        $_SESSION=['debug_roll'=>'4,1,1,6']; Dice::init();
        if ($type==='strike') {
            $s->battle['strike']=['kind'=>'strike','attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'owner'=>'host',
                'attack_dice'=>4,'defend_dice'=>3,'attack_mod'=>0,'defend_mod'=>0,'state'=>'prepared'];
            (new InstantProcessor($s,new Engine()))->openWindow('combat','host',null,'dice');
            // The primary dice above are already rolled.
            $_SESSION=['debug_roll'=>'1,1,6']; Dice::init();
        } else {
            (new SingleRoll($s,new Engine()))->begin($s->getCard(1),$s->getCard(2),$type,
                ['type'=>$type,'range'=>6,'strike'=>['weak'=>1,'medium'=>2,'strong'=>3]],$type==='uchr'?'uchr':'damage');
        }
        $key=$type==='strike'?'strike':'single_roll';
        c2cmd($s,'host','combat_instant_play',['card_id'=>3,'instant_key'=>'udacha']);
        for($i=0;$i<4 && empty($s->battle['pending_dice_choice']);$i++)
            c2cmd($s,$s->battle[$key]['instant_priority'],'combat_instant_pass');
        c2cmd($s,'host','choose_dice_choice',['choice'=>'reroll:any']);
        c1check($s->battle[$key]['attack_dice']===1,'reroll creates one '.$type);
        $remaining=Dice::remaining();
        $token=IW::token($s);
        c2cmd($s,'host','confirm_strike',['wait_id'=>$token]);
        c1check($s->battle[$key]['state']==='results','one ACK still waits');
        $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));
        c2cmd($s,'player','confirm_strike',['wait_id'=>IW::token($s)]);
        c1check($s->battle[$key]['state']==='waiting_instant','new decision after reroll '.$type);
        c1check($s->battle[$key]['instant_priority']==='player','closed Luck auto-passes');
        c1check($s->getCard(2)->hp===19,'no damage before response');
        c1check($s->getCard(3)->closed,'Luck stays spent');
        c1check($s->battle[$key]['instant_stack']===[],'resolved Luck not replayed');
        if($playMary) {
            c2cmd($s,'player','combat_instant_play',['card_id'=>4,'instant_key'=>'black_mark']);
            for($i=0;$i<4 && ($s->battle[$key]['state']??'')==='waiting_instant';$i++)
                c2cmd($s,$s->battle[$key]['instant_priority'],'combat_instant_pass');
            c1check($s->getCard(1)->hp===17,'fresh Mary uses rerolled one');
            $token=IW::token($s);
            c2cmd($s,'host','confirm_strike',['wait_id'=>$token]);
            c2cmd($s,'player','confirm_strike',['wait_id'=>IW::token($s)]);
        } else c2cmd($s,'player','combat_instant_pass');
        c1check(Dice::remaining()===$remaining,'response never rerolls original attack');
        c1check($s->getCard(1)->coins===2,'response never repays resources');
        if($type!=='strike') c1check(empty($s->battle['single_roll']),'original tail continues once');
    }
}
echo "Reroll response checks: $checks passed\n";

