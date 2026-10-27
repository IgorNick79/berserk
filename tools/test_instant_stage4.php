<?php
declare(strict_types=1);
if(!defined('FREE_INSTANT_FIXTURES_ONLY'))define('FREE_INSTANT_FIXTURES_ONLY',true);require_once __DIR__.'/test_free_instant_reservation.php';
use Berserk\Core\{GameState,CardInstance,InstantProcessor,InformationalWaitManager as IW,InstantReservation as IR,Command};
use Berserk\View\Screen\Battle\{InfoPanel,InstantControl};
use Berserk\View\Template;
$stage4Checks=0;
function s4Check(bool $ok,string $why):void{global$stage4Checks;++$stage4Checks;if(!$ok)throw new RuntimeException($why);}
function s4Wounds(string $active='host'):GameState{
    $s=fState($active);[$a,$p,$h,$caster]=fIds($s);foreach($s->cards as$c)$c->prop=['save_coins'=>true];
    $s->getCard($caster)->prop['instants']=[['key'=>'raneniya','name'=>'Перераспределение ран','phase'=>'wounds','effect'=>['type'=>'redistribute_wounds'],'trigger'=>'combat']];
    $s->getCard($h)->hp=26;$s->getCard($h)->flags['damage_taken_this_strike']=4;fResult($s);return$s;
}
function s4Acknowledge(GameState $s):void{[$a,$p]=fIds($s);$id=IW::token($s);fCmd($s,$a,'confirm_strike',['wait_id'=>$id]);fCmd($s,$p,'confirm_strike',['wait_id'=>$id]);}
function s4PassStack(GameState $s):void{for($i=0;$i<4&&($s->battle['strike']['state']??'')==='waiting_instant'&&empty($s->battle['strike']['instant_resolution'])&&empty($s->battle['pending_wound_transfer']);$i++)fCmd($s,$s->battle['strike']['instant_priority'],'combat_instant_pass');}
function s4Render(GameState $s,string $actor,int $inspect=0):string{
    if(($s->battle['turn_timer']['status']??'')==='running')$s->battle['turn_timer']['anchor_at']=time();
    $info=[];foreach($s->cards as$c)$info[$c->ukid]=['name'=>$c->ukid,'prop'=>$c->prop];
    return(new InfoPanel(new Template(__DIR__.'/../templates')))->render($s,$actor,$actor,$info,'?game='.$s->gameId,'<div data-panel-card-id="4">Просмотр карты</div>','','',$inspect);
}
if(defined('STAGE4_FIXTURES_ONLY'))return;
foreach(['host','player']as$active){
    $s=s4Wounds($active);[$a,$p,$h,$caster]=fIds($s);$ip=new InstantProcessor($s,irEngine());
    foreach(['dice','main']as$window){$s->battle['strike']['combat_instant_window']=$window;s4Check($ip->getInstants($p,'combat','combat')===[],'Hermit never listed in '.$window);}
    unset($s->battle['strike']['combat_instant_window']);$hp=$s->getCard($h)->hp;$old=IW::token($s);s4Acknowledge($s);
    s4Check(($s->battle['strike']['combat_instant_window']??null)==='wounds'&&$s->getCard($h)->hp===$hp,'Post-damage window opens without repeating damage');
    s4Check(InstantControl::project($s,$p,1000)['enabled']&&!InstantControl::project($s,$a,1000)['enabled'],'Only actual wounds priority enables common button');
    fCmd($s,$p,'confirm_strike',['wait_id'=>$old],false);
    fCmd($s,$p,'combat_instant_play',['card_id'=>$caster,'instant_key'=>'raneniya']);
    fCmd($s,$p,'combat_instant_pass');
    s4Check(empty($s->battle['pending_wound_transfer'])&&count($s->battle['strike']['instant_stack'])===1,'A declaration requires two consecutive native passes');
    fCmd($s,$a,'combat_instant_pass');
    s4Check(($s->battle['pending_wound_transfer']['source_id']??0)===$caster,'Existing wounds effect opens native transfer choices');
    fCmd($s,$p,'wt_source',['donor_id'=>$h]);fCmd($s,$p,'wt_amount',['amount'=>2]);fCmd($s,$p,'wt_target',['target_id'=>$caster]);
    fCmd($s,$p,'wt_target_amount',['amount'=>2]);
    s4Check($s->getCard($h)->hp===28&&$s->getCard($caster)->hp===28,'Actual wounds transfer conserves HP; it is not ordinary healing');
    s4Check(empty($s->battle['strike'])&&empty($s->battle['pending_wound_transfer']),'Battle continues and settles after one wounds resolution '.json_encode($s->battle['strike']??null));
    fCmd($s,$p,'combat_instant_play',['card_id'=>$caster,'instant_key'=>'raneniya'],false);
    s4Check($s->getCard($h)->hp===28,'Repeated declaration cannot apply wounds again');
    foreach(['none','lethal','closed','dead','hidden','suppressed','used','coins']as$case){
        $s=s4Wounds($active);[$a,$p,$h,$caster]=fIds($s);$c=$s->getCard($caster);
        if($case==='none'){$s->getCard($h)->hp=30;unset($s->getCard($h)->flags['damage_taken_this_strike']);}
        elseif($case==='lethal'){$s->getCard($h)->hp=0;$s->getCard($h)->dying=true;}
        elseif($case==='closed')$c->closed=true;elseif($case==='dead')$c->hp=0;elseif($case==='hidden')$c->revealed=false;
        elseif($case==='suppressed')$c->flags[\Berserk\Core\EffectiveFeatures::MEMORY_LOSS]=[999=>true];
        elseif($case==='used')$c->flags['instant_uses_this_turn']['raneniya']=1;
        else $c->prop['instants'][0]['coins']=10;
        s4Acknowledge($s);s4Check(($s->battle['strike']['combat_instant_window']??null)!=='wounds','No empty/ineligible wounds window: '.$case);
        if($case==='lethal')s4Check($s->getCard($h)->zone===CardInstance::ZONE_GRAVEYARD,'Lethal donor reaches graveyard before any offer');
    }
    $s=s4Wounds($active);[$a,$p,$h,$caster]=fIds($s);$second=clone$s->getCard($caster);$second->instanceId=6;$second->col=1;$s->addCard($second);s4Acknowledge($s);
    fCmd($s,$p,'combat_instant_play',['card_id'=>$caster,'instant_key'=>'raneniya']);fCmd($s,$p,'combat_instant_play',['card_id'=>6,'instant_key'=>'raneniya']);s4PassStack($s);
    s4Check($s->battle['pending_wound_transfer']['source_id']===6,'Wounds LIFO resolves last declaration first');fCmd($s,$p,'cancel_pending');
    s4Check($s->battle['pending_wound_transfer']['source_id']===$caster,'Wounds LIFO resumes older entry exactly once');fCmd($s,$p,'cancel_pending');
    s4Check(empty($s->battle['strike'])&&$s->getCard($h)->hp===26,'Passing transfer does not damage or heal again');
    // Full normal attack, with a closed victim: every normal strike roll deals a nonlethal wound.
    $s=fState($active);[$a,$p,$h,$caster,$attacker]=fIds($s);foreach($s->cards as$c)$c->prop=[];
    $s->getCard($caster)->prop=s4Wounds($active)->getCard($caster)->prop;
    $s->getCard($attacker)->row=3;$s->getCard($attacker)->col=3;$s->getCard($h)->row=4;$s->getCard($h)->col=3;$s->getCard($h)->closed=true;
    fCmd($s,$a,'strike',['card_id'=>$attacker,'target_id'=>$h]);
    if(($s->battle['strike']['state']??'')==='waiting_defender')fCmd($s,$p,'choose_defender',['defender_id'=>0]);
    s4Check(($s->battle['strike']['state']??'')==='results'&&$s->getCard($h)->hp<30,'Full strike does not open premature Hermit main window');
    $hp=$s->getCard($h)->hp;s4Acknowledge($s);s4Check(($s->battle['strike']['combat_instant_window']??'')==='wounds','Full strike offers Hermit after real damage');
    s4PassStack($s);s4Check(empty($s->battle['strike'])&&$s->getCard($h)->hp===$hp,'Empty wounds passes finish full combat without repeating damage');
    // A forced child of the native free turn stack owns its own late wounds window.
    $s=fState($active);[$a,$p,$h,$caster,$attacker]=fIds($s);fRevive($s);fReserve($s);
    fCmd($s,$p,'choose_revive_target',['target_id'=>5]);fCmd($s,$p,'choose_revive_cell',['row'=>3,'col'=>4]);
    $s->getCard($attacker)->row=3;$s->getCard($attacker)->col=3;$s->getCard($h)->row=4;$s->getCard($h)->col=3;$s->getCard($h)->closed=true;
    $s->getCard($caster)->prop['instants']=[['key'=>'force','trigger'=>'turn','target'=>'enemy_open_creature','no_close'=>true,'effect'=>['type'=>'forced_strike_adjacent']]];
    $hermit=clone s4Wounds($active)->getCard($caster);$hermit->instanceId=6;$hermit->row=6;$hermit->col=1;$hermit->prop['instants'][0]['key']='wounds';$s->addCard($hermit);
    fCmd($s,$p,'play_turn_instant',['card_id'=>$caster,'instant_key'=>'force']);fCmd($s,$p,'choose_instant_pick',['target_id'=>$attacker]);fCmd($s,$p,'choose_forced_strike_adjacent',['target_id'=>$h]);
    fCmd($s,$p,'pass_turn_instant');if(!empty($s->battle['turn_instant_stack']))fCmd($s,$s->battle['turn_instant_stack']['priority'],'pass_turn_instant');
    $trace=fDrain($s,1000,6);s4Check(in_array('combat:wounds',$trace,true)&&IR::snapshot($s)['status']==='idle'&&IR::snapshot($s)['used']===0,'Nested Hermit window settles child and free parent once without paid budget');
    // Normal initiative, paid/free projection, cancellation, inspection and no duplicate launchers.
    $s=fState($active);[$a,$p,$h,$caster]=fIds($s);$s->getCard($active==='host'?3:4)->prop['instants']=$s->getCard($caster)->prop['instants'];
    foreach([$a,$p]as$viewer){$html=s4Render($s,$viewer,4);$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
        s4Check($xp->query('//*[@data-instant-control]')->length===1 && $xp->query('//footer//*[@data-instant-control]')->length===1,'One instant component in fixed footer, including inspect');
        s4Check($xp->query('//div[contains(@class,"info-panel-content")]//a[contains(@href,"open_turn_instants") and not(ancestor::*[@data-instant-control])]')->length===0,'No old scroll-area launcher');
    }
    s4Check(InstantControl::project($s,$a,1000)['label']==='Сыграть','Active initiative');$ui=InstantControl::project($s,$p,1000);
    s4Check($ui['label']==='Резерв'&&$ui['cost']==='−1'&&$ui['quota']==='2/2','Paid mode comes from server reservation projection');
    $s->battle[IR::KEY]['used']=2;s4Check(!InstantControl::project($s,$p,1000)['visible'],'Exhausted paid quota hides unavailable offer');
    fRevive($s);$ui=InstantControl::project($s,$p,1000);s4Check($ui['cost']==='Бесплатно'&&$ui['enabled'],'Mandatory free mode survives exhausted paid quota');
    fReserve($s);s4Check(InstantControl::project($s,$p,1000)['label']==='Отменить резерв','Waiting request remains cancellable');
    $s->getCard($caster)->hp=0;s4Check(InstantControl::project($s,$p,1000)['enabled'],'Loss of final ability cannot hide cancellation');
    $s=fState($active);foreach($s->cards as$c)$c->prop=[];s4Check(!InstantControl::project($s,$p,1000)['visible'],'No prop-only offer');
    $s=fState($active);(new InstantProcessor($s,irEngine()))->openTurnStackWindow($p,['type'=>'manual'],'turn');foreach($s->cards as$c)$c->prop=[];
    $ui=InstantControl::project($s,$p,1000);s4Check($ui['visible']&&!$ui['enabled']&&$ui['pass']==='pass_turn_instant','No remaining ability still preserves mandatory pass');
    s4Check(substr_count(s4Render($s,$p),'cmd=pass_turn_instant')===1,'Pass has one footer entry, no duplicated old button');
    $s->battle['pending_instant_pick']=['owner'=>$p,'card_id'=>$caster,'target'=>'enemy','instant'=>[]];
    $ui=InstantControl::project($s,$p,1000);s4Check(!$ui['enabled']&&$ui['pass']===null,'An unfinished declaration must finish its own choice before another instant or pass');
    $s=s4Wounds($active);s4Acknowledge($s);$html=s4Render($s,$p);
    s4Check(str_contains($html,'>Инстант</summary>')&&!str_contains($html,'Бесплатно')&&!str_contains($html,'cmd=reserve_instant'),'Combat common button has no reservation or price');
    s4Check(!InstantControl::project($s,$a,1000)['options'],'Opposing viewer never receives own ability options');
    $s=fState($active);fCmd($s,$p,'reserve_instant',['period_id'=>1]);fResult($s);
    $s->getCard($caster)->prop['instants']=[['key'=>'dice','trigger'=>'combat','phase'=>'dice','target'=>'self','effect'=>['type'=>'damage_on_dice','value'=>1,'damage'=>2]]];
    (new InstantProcessor($s,irEngine()))->openWindow('combat',$p,null,'dice');
    $ui=InstantControl::project($s,$p,1000);s4Check($ui['label']==='Инстант'&&$ui['enabled']&&$ui['pass']==='combat_instant_pass'&&$ui['cancel']!==null,'Pre-existing paid reserve cannot replace combat choice or mandatory pass');
    $html=s4Render($s,$p);s4Check(substr_count($html,'cmd=combat_instant_pass')===1&&substr_count($html,'cmd=cancel_instant_reservation')===1,'Combat keeps one pass and cancellation of earlier request');
    $s->battle['strike']['state']='waiting_defender';unset($s->battle['strike']['instant_phase'],$s->battle['strike']['combat_instant_window']);
    $s->getCard($caster)->prop['instants']=[['key'=>'magic_trick','trigger'=>'combat','phase'=>'redirect','target'=>'adjacent_ally','effect'=>['type'=>'redirect_strike']]];
    $s->addCard(new CardInstance(instanceId:7,ukid:'mage-ally',owner:$p,zone:'field',row:3,col:4,hp:30,hpMax:30));
    $ui=InstantControl::project($s,$p,1000);s4Check($ui['label']==='Инстант'&&$ui['enabled']&&$ui['cancel']!==null,'Earlier reserve cannot hide special Mage redirect');
}
echo "Instant Stage 4: $stage4Checks checks passed; $freeChecks native command assertions\n";
