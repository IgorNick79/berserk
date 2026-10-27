<?php
declare(strict_types=1);
define('IR_FIXTURES_ONLY',true);
require __DIR__.'/test_instant_reservation.php';
use Berserk\Core\{Command,GameState,InstantProcessor,InstantReservation as IR,EffectiveFeatures,StrikeResolver};
$iaChecks=0;
function iaCheck(bool $ok,string $why):void {global$iaChecks;++$iaChecks;if(!$ok)throw new RuntimeException($why);}
function iaInstant(string $type='turn',string $target='self'):array {
    return ['key'=>'probe','trigger'=>$type,'target'=>$target,'phase'=>'setter','coins'=>1,'uses_per_turn'=>1,
        'effect'=>$type==='turn'?['type'=>'heal','value'=>1]:['type'=>'damage_cap','value'=>1]];
}
function iaState(string $type='turn',?array $instant=null):GameState {
    $s=irState();foreach($s->cards as$c)$c->prop=['save_coins'=>true];$s->getCard(3)->coins=2;
    $s->getCard(3)->prop=['instants'=>[$instant??iaInstant($type)]];
    if($type==='combat')$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,
        'state'=>'waiting_instant','instant_phase'=>'combat','combat_instant_window'=>'main','instant_priority'=>'host',
        'instant_participants'=>['host','player'],'instant_passed'=>[],'instant_stack'=>[],'instant_next_sequence'=>0,
        'attack_dice'=>3,'defend_dice'=>2,'attack_mod'=>0,'defend_mod'=>0,'result'=>['attack'=>'weak','defend'=>'weak','winner'=>'attack'],
        'confirmed'=>[]];
    else $s->battle['turn_instant_stack']=['state'=>'ordering','phase'=>'turn','participants'=>['host','player'],
        'priority'=>'host','passed'=>[],'stack'=>[],'next_sequence'=>0,'continuation'=>['type'=>'manual']];
    return$s;
}
function iaList(GameState $s,string $type):array {return(new InstantProcessor($s,irEngine()))->getInstants('host',$type==='turn'?'turn':'combat',$type);}
function iaPlay(GameState $s,string $type):\Berserk\Core\Result {return irEngine()->apply($s,'host',new Command($type==='turn'?'play_turn_instant':'combat_instant_play',['card_id'=>3,'instant_key'=>'probe']));}
function iaEntries(GameState $s,string $type):array {return$type==='turn'?($s->battle['turn_instant_stack']['stack']??[]):($s->battle['strike']['instant_stack']??[]);}
function iaChoose(GameState $s,string $type,int $target):\Berserk\Core\Result {return irEngine()->apply($s,'host',new Command($type==='turn'?'choose_instant_pick':'choose_combat_pick',['target_id'=>$target]));}
function iaMutate(GameState $s,string $case):void {
    $c=$s->getCard(3);
    switch($case){case'dead':$c->hp=0;break;case'dying':$c->dying=true;break;case'hidden':$c->revealed=false;break;
        case'grave':$c->zone='graveyard';break;case'closed':$c->closed=true;break;case'coins':$c->coins=0;break;
        case'used':$c->flags['instant_uses_this_turn']['probe']=1;break;case'in_stack':$c->flags['in_stack']=true;break;
        case'suppressed':$c->flags[EffectiveFeatures::MEMORY_LOSS]=[999=>true];break;
        case'other_pending':$s->battle['pending_revive']=['healer_id'=>3,'owner'=>'host'];break;
        case'queued_pending':$s->battle['pending_any_death']=[['source_id'=>3]];break;
        case'source_condition':$c->prop['instants'][0]['condition']=['type'=>'column','values'=>[6]];break;
    }
}

// Stale ordering states must not bypass the list's source predicates through a direct command.
foreach(['turn','combat']as$type)foreach(['valid','dead','dying','hidden','grave','closed','coins','used','in_stack','suppressed','source_condition']as$case){
    $s=iaState($type);iaMutate($s,$case);$before=$s->getCard(3)->coins;$raw=$s->getCard(3)->prop;
    $pure=$s->toArray();$list=iaList($s,$type);iaCheck($pure===$s->toArray(),"Pure $type $case listing");
    iaCheck((count($list)===1)===($case==='valid'),"Source list $type $case");$r=iaPlay($s,$type);
    iaCheck($r->success===($case==='valid'),"Source declaration $type $case: ".$r->error);
    iaCheck(count(iaEntries($s,$type))===($case==='valid'?1:0),"Atomic admission $type $case");
    iaCheck($s->getCard(3)->coins===$before-($case==='valid'?1:0),"Exactly one cost $type $case");
    iaCheck($s->getCard(3)->prop===$raw,"Raw prop unchanged $type $case");
}
foreach(['turn','combat']as$type)foreach(['other_pending','queued_pending']as$case){$s=iaState($type);iaMutate($s,$case);iaCheck(iaList($s,$type)===[],"Busy source $type $case");}

// Closure contracts: explicit tap_source takes precedence; native combat closure remains distinct.
foreach([['no_close'=>true],['tap_source'=>false],['close_source'=>false],['tap_source'=>true,'no_close'=>true],['tap_source'=>false,'close_source'=>true]]as$flags){
    $i=array_replace(iaInstant(),$flags);$s=iaState('turn',$i);$s->getCard(3)->closed=true;
    $allowed=empty($flags['tap_source']);iaCheck((bool)iaList($s,'turn')===$allowed,'Turn tap precedence '.json_encode($flags));
    iaCheck(iaPlay($s,'turn')->success===$allowed,'Turn declaration tap precedence');
    $i['trigger']='combat';$i['effect']=['type'=>'damage_cap','value'=>1];$s=iaState('combat',$i);$s->getCard(3)->closed=true;
    iaCheck(iaList($s,'combat')===[]&&!iaPlay($s,'combat')->success,'No new ordinary combat closure exception');
}
$s=iaState('turn',array_replace(iaInstant(),['uses_per_turn'=>0]));$s->getCard(3)->flags['instant_uses_this_turn']['probe']=99;
iaCheck(count(iaList($s,'turn'))===1&&iaPlay($s,'turn')->success,'Unlimited turn uses retain their meaning');
$s=iaState();$s->getCard(3)->zone='flying';iaCheck(iaPlay($s,'turn')->success,'Flying source stays legal');
$s=iaState();unset($s->battle['turn_instant_stack']);$s->getCard(3)->owner='player';
iaCheck(count((new InstantProcessor($s,irEngine()))->getInstants('player','turn','turn'))===1,'Potential passive ability does not require current initiation right');
iaCheck(!irEngine()->apply($s,'player',new Command('open_turn_instants'))->success&&empty($s->battle['turn_instant_stack']),'Potential legality never grants passive initiation right');
$s=iaState();unset($s->battle['turn_instant_stack']);$s->getCard(3)->hp=0;$s->getCard(1)->prop=['instants'=>[iaInstant()]];
$before=$s->toArray();$r=(new InstantProcessor($s,irEngine()))->declareTurnInstantFromTask('host',['card_id'=>3,'payload'=>['key'=>'probe']]);
iaCheck(!$r->success&&$s->toArray()===$before,'Stale turn-phase task cannot open another ability stack as a side effect');
$s=iaState('turn',iaInstant('turn','enemy'));$s->battle['turn_instant_stack']['priority']='player';
$r=(new InstantProcessor($s,irEngine()))->declareTurnInstantFromTask('host',['card_id'=>3,'payload'=>['key'=>'probe']]);
iaCheck(!$r->success&&empty($s->battle['pending_instant_pick']),'Task cannot open target picker without priority');

// Self uses precisely the same explicit target conditions as selected allies/enemies.
foreach(['turn','combat']as$type)foreach(['target_closed','target_not_moved']as$condition){
    $i=iaInstant($type);$i['no_close']=true;$i['effect']['condition']=$condition;
    $s=iaState($type,$i);if($condition==='target_not_moved')$s->getCard(3)->flags['moved_this_turn']=true;
    iaCheck(iaList($s,$type)===[]&&!iaPlay($s,$type)->success,"Illegal self $type $condition");
    $s=iaState('turn',$i);$s->getCard(3)->prop['instants'][0]['trigger']='turn';
    if($condition==='target_closed')$s->getCard(3)->closed=true;
    iaCheck(count(iaList($s,'turn'))===1&&iaPlay($s,'turn')->success,"Legal self turn $condition");
}

// Concrete target checks share source/cost/condition rules at the final picker boundary.
foreach(['turn','combat']as$type)foreach(['enemy','ally']as$targetKind){
    $i=iaInstant($type,$targetKind);$i['effect']['condition']='target_closed';$s=iaState($type,$i);
    $target=$targetKind==='enemy'?2:1;$s->getCard($target)->closed=true;
    iaCheck(count(iaList($s,$type))===1&&iaPlay($s,$type)->success,"Picker opens $type $targetKind");
    iaCheck($s->getCard(3)->coins===2&&empty($s->getCard(3)->flags['instant_uses_this_turn']),'Opening picker does not pay or consume use');
    iaCheck(!iaChoose($s,$type,$targetKind==='enemy'?1:2)->success,'Wrong owner rejected');
    $s->getCard($target)->closed=false;iaCheck(!iaChoose($s,$type,$target)->success,'Target condition changed before pick');
    $s->getCard($target)->closed=true;$s=irReload($s);
    iaCheck(iaChoose($s,$type,$target)->success&&$s->getCard(3)->coins===1,'Valid resumed selection pays once');
    iaCheck((int)$s->getCard(3)->flags['instant_uses_this_turn']['probe']===1,'Turn and combat consume declared use');
    iaCheck(!iaChoose($s,$type,$target)->success&&$s->getCard(3)->coins===1,'Duplicate pick cannot pay twice');
}
foreach(['turn','combat']as$type)foreach(['dead','hidden','grave','closed','coins','used','in_stack','suppressed','other_pending','source_condition']as$case){
    $s=iaState($type,iaInstant($type,'enemy'));iaCheck(iaPlay($s,$type)->success,"Initial picker $type $case");iaMutate($s,$case);
    $before=$s->getCard(3)->coins;$r=iaChoose($s,$type,2);
    iaCheck($case==='suppressed'?$r->success:!$r->success,"Picker revalidates source $type $case");
    iaCheck(iaEntries($s,$type)===[]&&$s->getCard(3)->coins===$before,"Failed/suppressed pick cannot spend or stack $type $case");
}
foreach(['turn','combat']as$type)foreach(['dead','grave']as$case){
    $s=iaState($type,iaInstant($type,'enemy'));iaCheck(iaPlay($s,$type)->success,'Live enemy picker opened');
    if($case==='dead')$s->getCard(2)->hp=0;else$s->getCard(2)->zone='graveyard';
    iaCheck(!iaChoose($s,$type,2)->success&&iaEntries($s,$type)===[],'Dead/off-field target rejected');
}

// Preserve declaration versus usefulness: later LIFO entries may create the wounds.
$i=iaInstant();$i['effect']=['type'=>'heal_turn_wounds'];$s=iaState('turn',$i);
iaCheck(iaPlay($s,'turn')->success,'Chronos-style instant legal without wounds yet');
$s->getCard(3)->closed=true;$s->getCard(3)->revealed=false;$s->getCard(3)->coins=0;
$r=irEngine()->apply($s,'host',new Command('pass_turn_instant'));
iaCheck($r->success&&isset($s->battle['turn_instant_result']),'Post-stack closure/reveal/resources do not re-run admission checks');
iaCheck($s->battle['turn_instant_result']['summary'][0]['effect_type']==='heal_turn_wounds','Declared effect stays in resolution');
$i=iaInstant('turn','enemy');$i['effect']=['type'=>'open','condition'=>'target_closed','damage'=>1];$s=iaState('turn',$i);$s->getCard(2)->closed=true;
iaCheck(iaPlay($s,'turn')->success&&iaChoose($s,'turn',2)->success,'Conditional open declared against legal closed enemy');
$s->getCard(2)->closed=false;irCmd($s,'host','pass_turn_instant');
iaCheck($s->getCard(2)->hp===9&&$s->getCard(3)->coins===1,'Admission condition not rechecked after stacking; declared damage and paid cost preserved');

// Forced-strike target selection is still before admission, including its secondary picker.
$i=iaInstant('turn','enemy_open_creature');$i['effect']=['type'=>'forced_strike_adjacent'];$s=iaState('turn',$i);
iaCheck(iaPlay($s,'turn')->success,'Forced attack initial picker');$s->battle['turn_instant_stack']['priority']='player';
iaCheck(!iaChoose($s,'turn',2)->success&&empty($s->battle['pending_forced_strike_adjacent']),'No secondary picker without current priority');
$s->battle['turn_instant_stack']['priority']='host';iaCheck(iaChoose($s,'turn',2)->success,'Forced attack secondary picker');$s->getCard(3)->hp=0;
$r=irEngine()->apply($s,'host',new Command('choose_forced_strike_adjacent',['target_id'=>1]));
iaCheck(!$r->success&&iaEntries($s,'turn')===[]&&$s->getCard(3)->coins===2,'Forced secondary picker revalidates source before spending');

// Combat die/main windows retain their phase gates at listing, declaration and late selection.
foreach(['dice','power','value','setter','wounds','redirect']as$phase){
    $i=iaInstant('combat');$i['phase']=$phase;$s=iaState('combat',$i);$s->battle['strike']['combat_instant_window']='dice';
    iaCheck((bool)iaList($s,'combat')===($phase==='dice'),'Dice window phase '.$phase);
    $s->battle['strike']['combat_instant_window']='main';iaCheck((bool)iaList($s,'combat')===!in_array($phase,['dice','wounds'],true),'Main window phase '.$phase);
}
$i=iaInstant('combat','enemy');$i['phase']='dice';$s=iaState('combat',$i);$s->battle['strike']['combat_instant_window']='dice';
iaCheck(iaPlay($s,'combat')->success,'Dice target picker');$s->battle['strike']['combat_instant_window']='main';
iaCheck(!iaChoose($s,'combat',2)->success&&$s->getCard(3)->coins===2,'Window change blocks stale picker');

// Reservation consumes the SAME getInstants predicate at registration and grant.
foreach(['paid','free']as$reason){
    $s=iaState();unset($s->battle['turn_instant_stack']);$s->getCard(3)->owner='player';$s->getCard(3)->closed=true;
    $s->getCard(3)->prop['instants'][0]['no_close']=true;$s->getCard(3)->prop['instants'][0]['effect']['condition']='target_closed';
    $origin=null;if($reason==='free'){$s->battle['pending_revive']=['owner'=>'player','healer_id'=>4];$origin=['source'=>'pending_revive','choice_id'=>'availability:1'];}
    $cmd=new Command('reserve_instant',['period_id'=>1]);$r=IR::request($s,irEngine(),'player',$cmd,$origin);
    iaCheck($r->success&&IR::snapshot($s)['request']['reason']===$reason,'Same condition permits '.$reason.' registration');
    $s->getCard(3)->closed=false;unset($s->battle['pending_revive']);$s=irReload($s);
    if($reason==='free')IR::afterCommand($s,irEngine(),new Command('choose_revive_cell'));
    else irCmd($s,'host','gain_coin',['card_id'=>1]);
    iaCheck(IR::snapshot($s)['status']==='idle'&&IR::snapshot($s)['used']===0,'Lost self condition drops '.$reason.' reserve without spend');
}
$s=iaState();unset($s->battle['turn_instant_stack']);$s->getCard(3)->owner='player';$s->getCard(3)->prop['instants'][0]['effect']['condition']='target_closed';
iaCheck(!IR::request($s,irEngine(),'player',new Command('reserve_instant',['period_id'=>1]))->success,'Illegal self cannot register reserve');
$s=iaState();unset($s->battle['turn_instant_stack']);$s->getCard(3)->owner='player';$s->getCard(3)->closed=true;
$s->getCard(3)->prop['instants'][0]['no_close']=true;$s->getCard(3)->prop['instants'][0]['effect']['condition']='target_closed';
irReserve($s,'player');irCmd($s,'host','gain_coin',['card_id'=>1]);$s->getCard(3)->closed=false;
$r=irEngine()->apply($s,'player',new Command('play_turn_instant',['card_id'=>3,'instant_key'=>'probe']));
iaCheck(!$r->success&&IR::snapshot($s)['status']==='granted'&&IR::snapshot($s)['used']===1,'Lost condition after grant rejects declaration without refund or cancelling grant');
irCmd($s,'player','decline_instant_priority',['request_id'=>IR::snapshot($s)['request']['id']]);
iaCheck(IR::snapshot($s)['status']==='idle'&&IR::snapshot($s)['used']===1,'Unusable granted priority still has safe paid decline');
foreach(['paid','free']as$reason)foreach(['dead','hidden','coins','used','in_stack','suppressed','source_condition']as$case){
    $s=iaState();unset($s->battle['turn_instant_stack']);$s->getCard(3)->owner='player';$origin=null;
    if($reason==='free'){$s->battle[IR::KEY]['used']=2;$s->battle['pending_revive']=['owner'=>'player','healer_id'=>4];$origin=['source'=>'pending_revive','choice_id'=>'availability:2'];}
    $r=IR::request($s,irEngine(),'player',new Command('reserve_instant',['period_id'=>1]),$origin);iaCheck($r->success,"Register $reason before $case");
    iaMutate($s,$case);unset($s->battle['pending_revive']);
    if($reason==='free')IR::afterCommand($s,irEngine(),new Command('choose_revive_cell'));
    else irCmd($s,'host','gain_coin',['card_id'=>1]);
    iaCheck(IR::snapshot($s)['status']==='idle'&&IR::snapshot($s)['used']===($reason==='free'?2:0),"Revalidate $reason $case at grant without spending");
}

// Documented Ice Guard condition, independent of any unavailable live card catalog.
$i=iaInstant('turn','ally');$i['effect']=['type'=>'close_target','condition'=>'target_not_moved'];$s=iaState('turn',$i);
iaCheck(iaPlay($s,'turn')->success,'Ice Guard documented effect opens picker');$s->getCard(1)->flags['moved_this_turn']=true;
iaCheck(!iaChoose($s,'turn',1)->success,'Ice Guard moved target rejected');$s->getCard(1)->flags['moved_this_turn']=false;
iaCheck(iaChoose($s,'turn',1)->success,'Ice Guard unmoved target accepted');irCmd($s,'host','pass_turn_instant');
iaCheck($s->getCard(1)->closed,'Ice Guard closes selected target');

// Special Mage route shares source eligibility but retains its own defender geometry.
$i=iaInstant('combat','adjacent_ally');$i['phase']='redirect';$i['effect']=['type'=>'redirect_strike'];
foreach(['valid','hidden','coins','used','other_pending','source_condition']as$case){
    $s=iaState('combat',$i);$s->getCard(3)->owner='player';$s->getCard(3)->row=6;$s->getCard(3)->col=5;
    $s->getCard(2)->row=4;$s->getCard(2)->col=3;$s->getCard(4)->row=4;$s->getCard(4)->col=4;
    $s->battle['strike']['state']='waiting_defender';iaMutate($s,$case);
    $options=(new StrikeResolver($s,irEngine()))->getMageRedirectOptions('player');
    iaCheck((bool)$options===($case==='valid'),'Mage shared source gate '.$case);
    if($case==='valid'){
        $s->getCard(3)->prop['instants'][0]['effect']['condition']='target_closed';
        iaCheck((new StrikeResolver($s,irEngine()))->getMageRedirectOptions('player')===[],'Mage rejects unmet target condition through shared predicate');
        $s->getCard(4)->closed=true;
        iaCheck((new StrikeResolver($s,irEngine()))->getMageRedirectOptions('player')[0]['target_ids']===[4],'Mage accepts condition without changing original defender geometry');
        $pure=$s->toArray();$ip=new InstantProcessor($s,irEngine());
        iaCheck($ip->isLegalInstantTarget($s->getCard(3),$s->getCard(4),'ally','target_closed')&&$s->toArray()===$pure,'Public target projection is pure for future UI');
    }
}
echo "Instant availability Stage 3A: $iaChecks checks passed\n";
