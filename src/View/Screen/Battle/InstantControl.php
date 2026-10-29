<?php
declare(strict_types=1);
namespace Berserk\View\Screen\Battle;

use Berserk\Core\{GameState,Engine,InstantProcessor,InstantReservation,MatchPause,Choice\ChoiceRegistry,StrikeResolver};

/** Viewer-only projection; commands still revalidate every right under the game lock. */
final class InstantControl
{
    public static function project(GameState $s,string $actor,int $now): array
    {
        $r=InstantReservation::controls($s,$actor,$now);
        $stack=$s->battle['turn_instant_stack']??[];$strike=$s->battle['strike']??[];
        $combat=!empty($strike)||in_array($stack['phase']??'', ['before','after'],true)
            ||!empty($stack['continuation']['combat_origin_strike']);
        $out=['visible'=>false,'label'=>$combat?'Инстант':'Сыграть','enabled'=>false,'command'=>null,'payload'=>[],
            'options'=>[],'pass'=>null,'decline'=>null,'cancel'=>null,'combat'=>$combat,'phase'=>$strike['combat_instant_window']??$stack['phase']??null,
            'stack_open'=>!empty($stack)||($strike['state']??'')==='waiting_instant','cost'=>null,'quota'=>null];
        if($s->status!=='battle'||$s->winner!==null||MatchPause::blocking($s))return$out;
        $wait=\Berserk\Core\BattleWaitResolver::current($s);
        $mageOptions=in_array($wait['source']??'', ['strike.waiting_defender','strike.waiting_redirect'],true)
            ?(new StrikeResolver($s,new Engine()))->getMageRedirectOptions($actor):[];
        $nativeWindow=(($stack['state']??'')==='ordering'&&($wait['source']??null)==='turn_instant_stack')
            ||(($strike['state']??'')==='waiting_instant'&&($wait['source']??null)==='strike.waiting_instant'&&empty($strike['instant_resolution']))||!empty($mageOptions);
        if($r['can_cancel']&&!$combat&&empty($s->battle['turn_instant_result'])&&!$nativeWindow&&!$out['stack_open']&&empty($s->battle['pending_instant_pick'])&&empty($s->battle['pending_combat_pick'])&&empty($s->battle['pending_forced_strike_adjacent']))return array_replace($out,['visible'=>true,'enabled'=>true,'label'=>'Отменить резерв',
            'command'=>'cancel_instant_reservation','payload'=>['request_id'=>$r['request_id']],'quota'=>$r['remaining'].'/'.$r['limit']]);
        if($r['can_cancel'])$out['cancel']=['command'=>'cancel_instant_reservation','payload'=>['request_id'=>$r['request_id']]];
        $ip=new InstantProcessor($s,new Engine());$potential=[];$command=null;$priority=false;
        if(($stack['state']??'')==='ordering'){
            if(($wait['source']??null)!=='turn_instant_stack')return$out;
            $potential=$ip->getInstants($actor,$stack['phase'],'turn');$priority=($stack['priority']??null)===$actor;
            $command='play_turn_instant';if($priority)$out['pass']='pass_turn_instant';
        }elseif(($strike['state']??'')==='waiting_instant'){
            if(($wait['source']??null)!=='strike.waiting_instant'||!empty($strike['instant_resolution']))return$out;
            $potential=$ip->getInstants($actor,$strike['instant_phase']??'combat',($strike['instant_phase']??'combat')==='combat'?'combat':'turn');
            $priority=($strike['instant_priority']??null)===$actor;$command='combat_instant_play';
            if($priority)$out['pass']='combat_instant_pass';
        }elseif($combat){
            if(in_array($wait['source']??'', ['strike.waiting_defender','strike.waiting_redirect'],true)){
                $out['phase']='redirect';
                foreach($mageOptions as$option){
                    foreach($option['target_ids']as$id)$out['options'][]=['card_id'=>$option['card_id'],'label'=>$option['label']??'Перенаправить',
                        'command'=>'choose_defender_option','payload'=>['choice'=>'mage:'.$option['card_id'].':'.$id],'target_id'=>$id];
                }
                $out['enabled']=$out['visible']=!empty($out['options']);
            }
            return$out;
        }elseif(($s->battle['active']??null)===$actor){
            if ((\Berserk\Core\TurnTimer::snapshot($s,$now)['status']??null)==='expired') return$out;
            if(ChoiceRegistry::current($s)===null&&InstantReservation::safeBoundary($s)&&!InstantReservation::blocks($s,'open_turn_instants')
                &&($available=$ip->getInstants($actor,'turn','turn'))){
                $options=[];foreach($available as$inst)$options[]=['card_id'=>$inst['card_id'],'label'=>$inst['label'],'command'=>'open_turn_instants','payload'=>['card_id'=>$inst['card_id'],'instant_key'=>$inst['payload']['key']??'']];
                return array_replace($out,['visible'=>true,'enabled'=>true,'label'=>'Инстант','options'=>$options,'pass'=>'local']);
            }
            return$out;
        }else{
            return array_replace($out,['visible'=>$r['can_reserve'],'enabled'=>$r['can_reserve'],'label'=>'Резерв',
                'command'=>'reserve_instant','payload'=>array_filter(['period_id'=>$r['period_id'],'origin_choice_id'=>$r['origin_choice_id']],static fn($v)=>$v!==null),
                'cost'=>$r['mode']==='free'?'Бесплатно':'−1','quota'=>$r['remaining'].'/'.$r['limit']]);
        }
        if($priority){
            foreach($potential as$inst)$out['options'][]=['card_id'=>$inst['card_id'],'label'=>$inst['label'],
                'command'=>$command,'payload'=>['card_id'=>$inst['card_id'],'instant_key'=>$inst['payload']['key']??'']];
        }
        if($r['can_decline'])$out['decline']=['command'=>'decline_instant_priority','payload'=>['request_id'=>$r['request_id']]];
        $out['visible']=!empty($potential)||$out['pass']!==null||$out['decline']!==null||$out['cancel']!==null;
        $out['label']='Инстант';
        $out['enabled']=$priority&&!empty($out['options']);
        return$out;
    }
}
