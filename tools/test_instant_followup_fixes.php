<?php
declare(strict_types=1);
define('STAGE4_FIXTURES_ONLY',true);require __DIR__.'/test_instant_stage4.php';
use Berserk\Core\{CardInstance,InstantProcessor,Command};
use Berserk\Core\Choice\WoundTransferChoice;
use Berserk\View\Screen\Battle\InstantControl;
$n=0;
function followCheck(bool $v,string $why):void{global$n;++$n;if(!$v)throw new RuntimeException($why);}
foreach(['host','player']as$active){
    $s=s4Wounds($active);[$a,$p,$h,$caster]=fIds($s);
    $extra=new CardInstance(instanceId:9,ukid:'old-wounds',owner:$p,zone:'field',row:6,col:1,hp:20,hpMax:30,flags:['damage_taken_this_strike'=>10]);$s->addCard($extra);
    $s->battle['strike']['wounds_received']=[$h=>2];s4Acknowledge($s);
    fCmd($s,$p,'combat_instant_play',['card_id'=>$caster,'instant_key'=>'raneniya']);s4PassStack($s);
    $pw=$s->battle['pending_wound_transfer'];followCheck($pw['step']==='amount'&&$pw['donor_id']===$h,'One current-battle donor skips source selection');
    followCheck($pw['donors']===[$h=>2],'Old wounds on another creature never enter current battle donors');
    irEngine()->applyDamage($s,$s->getCard(9),1,'damage_on_dice',$s->getCard($caster));
    followCheck(($s->battle['strike']['wounds_received'][9]??0)===1,'Real damage adds only newly received wounds to the current combat ledger');
    $spec=(new WoundTransferChoice())->spec($s,$p,[],'?game=1',$p);followCheck(str_contains($spec->form['items'][0]['label'],'❤'),'Wounds amounts use hearts');
    $s=s4Wounds($active);$s->addCard(clone$extra);$s->battle['strike']['wounds_received']=[$h=>2,9=>1];s4Acknowledge($s);
    fCmd($s,$p,'combat_instant_play',['card_id'=>$caster,'instant_key'=>'raneniya']);s4PassStack($s);
    followCheck($s->battle['pending_wound_transfer']['step']==='source','Two actual battle donors retain choice');
    $spec=(new WoundTransferChoice())->spec($s,$p,[],'?game=1',$p);followCheck(str_contains($spec->buttons[0]['label_html'],'❤'),'Donor buttons show hearts');
    $s=fState($active);[$a,$p,$h,$caster,$attacker]=fIds($s);
    $s->getCard($attacker)->row=3;$s->getCard($attacker)->col=3;$s->getCard($h)->row=4;$s->getCard($h)->col=3;$s->getCard($caster)->row=3;$s->getCard($caster)->col=4;
    fCmd($s,$a,'strike',['card_id'=>$attacker,'target_id'=>$h]);followCheck(!empty($s->battle['turn_instant_stack']),'Before window opens before defender admission');
    $s->getCard($caster)->closed=true;fCmd($s,$p,'pass_turn_instant');
    followCheck(($s->battle['strike']['state']??'')!=='waiting_defender','Defender closed during before window does not create an empty choice');
    $s=fState($active);fCmd($s,$p,'reserve_instant',['period_id'=>1]);
    (new InstantProcessor($s,irEngine()))->openTurnStackWindow($p,['type'=>'manual'],'turn');
    $s->battle['pending_instant_pick']=['owner'=>$p,'card_id'=>$caster,'target'=>'enemy','effect'=>[]];
    followCheck(InstantControl::project($s,$p,1000)['label']!=='Отменить резерв','Active declaration never shows reserve cancellation');
}
echo "Instant follow-up fixes: $n checks passed\n";
