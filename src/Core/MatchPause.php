<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Match-level suspension. Never a ChoiceRegistry handler or a combat continuation. */
final class MatchPause
{
    public const MIN_SECONDS = 60;
    public const MAX_SECONDS = 600;
    public const STEP_SECONDS = 60;
    public const ACCEPTED_LIMIT = 2;
    public const RESPONSE_SECONDS = 20;
    public const COOLDOWN_SECONDS = 300;
    public const COMMANDS = ['request_pause', 'accept_pause', 'reject_pause', 'cancel_pause_request', 'pause_ready'];

    public static function isCommand(string $type): bool { return in_array($type, self::COMMANDS, true); }
    public static function blocking(GameState $s): bool
    {
        return in_array($s->battle['match_pause']['mode'] ?? 'idle', ['requested', 'active'], true);
    }
    private static function initial(): array
    {
        return ['mode'=>'idle', 'generation'=>0, 'used'=>['host'=>0,'player'=>0],
            'cooldown_until'=>['host'=>0,'player'=>0], 'last_result'=>null, 'last_resume'=>null];
    }
    public static function due(GameState $s, int $now): bool
    {
        if (!self::blocking($s)) return false;
        $p=$s->battle['match_pause'];
        return $s->status !== 'battle' || $s->winner !== null
            || $now >= ($p['mode']==='requested' ? $p['request_deadline_at'] : $p['pause_deadline_at']);
    }

    /** Caller commits this operation under the game lock and command journal. */
    public static function apply(GameState $s, string $actor, Command $cmd, int $now): Result
    {
        if (!in_array($actor,['host','player'],true)) return Result::error('pause_not_participant');
        if ($s->status!=='battle' || $s->winner!==null) return Result::error('pause_battle_not_active');
        // Deadlines are committed separately, even if the late user command is rejected.
        if (self::due($s,$now)) return Result::error('pause_deadline_due');
        if ($cmd->type==='request_pause') return self::request($s,$actor,$cmd,$now);
        $p=$s->battle['match_pause'] ?? self::initial();
        if (!self::blocking($s) || !is_string($cmd->get('request_id'))
            || !hash_equals($p['request_id'],$cmd->get('request_id'))) return Result::error('pause_stale_request');
        if ($cmd->type==='pause_ready') {
            if ($p['mode']!=='active') return Result::error('pause_not_active');
            $s->battle['match_pause']['ready'][$actor]=true;
            if (!in_array(false,$s->battle['match_pause']['ready'],true)) self::finish($s,$now,'both_ready');
        } else {
            if ($p['mode']!=='requested') return Result::error('pause_not_requested');
            if (($cmd->type==='cancel_pause_request') !== ($actor===$p['initiator'])) return Result::error('pause_wrong_actor');
            if ($cmd->type==='accept_pause') {
                $s->battle['match_pause']['mode']='active';
                $s->battle['match_pause']['accepted_at']=$now;
                $s->battle['match_pause']['pause_deadline_at']=$now+$p['duration_seconds'];
                ++$s->battle['match_pause']['used'][$p['initiator']];
                $s->battle['match_pause']['last_result']=['request_id'=>$p['request_id'],'outcome'=>'accepted','at'=>$now];
            } elseif (in_array($cmd->type,['reject_pause','cancel_pause_request'],true)) {
                self::finish($s,$now,$cmd->type==='reject_pause'?'rejected':'cancelled');
            } else return Result::error('pause_unknown_command');
        }
        $s->bumpVersion();
        return Result::ok([$cmd->type]);
    }

    private static function request(GameState $s, string $actor, Command $cmd, int $now): Result
    {
        if (self::blocking($s)) return Result::error('pause_busy');
        $duration=$cmd->get('duration_seconds');
        if ((!is_int($duration) && (!is_string($duration) || !preg_match('/^[0-9]{2,3}$/D',$duration)))
            || (int)$duration<self::MIN_SECONDS || (int)$duration>self::MAX_SECONDS
            || (int)$duration%self::STEP_SECONDS!==0) return Result::error('pause_invalid_duration');
        $p=$s->battle['match_pause'] ?? self::initial();
        if ($p['used'][$actor]>=self::ACCEPTED_LIMIT) return Result::error('pause_limit');
        if ($now<$p['cooldown_until'][$actor]) return Result::error('pause_cooldown');
        $timer=TurnTimer::snapshot($s,$now);
        if ($timer===null && TurnTimer::enabled($s)) return Result::error('pause_invalid_timer');
        if (TurnTimeoutEligibility::check($s,$now)['allowed']) return Result::error('pause_turn_expired');
        $wait=BattleWaitResolver::current($s);
        if (($wait['kind']??null)==='unknown') return Result::error('pause_unsafe_wait');
        $info=$s->battle['informational_wait'] ?? null;
        if ($info!==null && InformationalWaitManager::record($s)===null) return Result::error('pause_unsafe_informational_wait');
        // All validation precedes any mutation, including settlement.
        TurnTimer::settle($s,$now);
        $original=TurnTimer::enabled($s)?$s->battle['turn_timer']:null;
        if ($original!==null) TurnTimer::pause($s,$original['period_id'],'match_pause',$now);
        $generation=$p['generation']+1;
        $s->battle['match_pause']=[
            'mode'=>'requested','generation'=>$generation,'request_id'=>'mp:'.$s->gameId.':'.$generation,
            'initiator'=>$actor,'duration_seconds'=>(int)$duration,'requested_at'=>$now,
            'request_deadline_at'=>$now+self::RESPONSE_SECONDS,'accepted_at'=>null,'pause_deadline_at'=>null,
            'ready'=>['host'=>false,'player'=>false], 'suspended_timer'=>$original,
            'suspended_wait'=>$info===null?null:['record'=>$info,'remaining_seconds'=>max(0,$info['deadline_at']-$now)],
            'used'=>$p['used'],'cooldown_until'=>$p['cooldown_until'],
            'last_result'=>$p['last_result'],'last_resume'=>$p['last_resume'],
        ];
        $s->bumpVersion();
        return Result::ok(['request_pause']);
    }

    /** Lazy deadline processing, with the effective time fixed to the deadline. */
    public static function expire(GameState $s, int $now): Result
    {
        if (!self::due($s,$now)) return Result::error('pause_not_due');
        if ($s->status!=='battle' || $s->winner!==null) self::terminate($s,$now);
        else {
            $p=$s->battle['match_pause'];
            self::finish($s,$p['mode']==='requested'?$p['request_deadline_at']:$p['pause_deadline_at'],
                $p['mode']==='requested'?'request_timeout':'deadline');
            TurnTimer::settle($s,$now);
        }
        $s->bumpVersion();
        return Result::ok(['pause_expired']);
    }

    /** The only non-terminal exit, shared by both ready and both deadline paths. */
    private static function finish(GameState $s, int $at, string $reason): void
    {
        $p=$s->battle['match_pause'];
        $timer=$p['suspended_timer'];
        // No battle command may change the period while suspended.
        if ($timer!==null && ($s->battle['turn_timer']['period_id']??null)!==$timer['period_id']) throw new \LogicException('Pause timer period changed');
        if ($timer!==null && $timer['status']==='running') TurnTimer::resume($s,$timer['period_id'],'match_pause',$at);
        elseif ($timer!==null) $s->battle['turn_timer']=$timer;
        if ($p['suspended_wait']!==null) {
            $record=$p['suspended_wait']['record'];
            $record['deadline_at']=$at+$p['suspended_wait']['remaining_seconds'];
            $record['started_at']=$record['deadline_at']-InformationalWaitManager::LIMIT_SECONDS;
            $s->battle['informational_wait']=$record;
        }
        if ($p['mode']==='requested') {
            $p['cooldown_until'][$p['initiator']]=$at+self::COOLDOWN_SECONDS;
            $p['last_result']=['request_id'=>$p['request_id'],'outcome'=>$reason,'at'=>$at];
        } else {
            $p['last_resume']=['resume_event_id'=>$p['request_id'].':resume','request_id'=>$p['request_id'],
                'resumed_at'=>$at,'reason'=>$reason];
        }
        $p['mode']='idle';
        unset($p['suspended_timer'],$p['suspended_wait']);
        $s->battle['match_pause']=$p;
        TurnTimer::reconcileWaiting($s,$at);
    }

    public static function terminate(GameState $s, int $now): void
    {
        if (!self::blocking($s)) return;
        $p=$s->battle['match_pause'];
        $p['mode']='idle';
        $p['last_result']=['request_id'=>$p['request_id'],'outcome'=>'battle_finished','at'=>$now];
        unset($p['suspended_timer'],$p['suspended_wait']);
        $s->battle['match_pause']=$p;
        TurnTimer::settle($s,$now);
    }

    /** Public projection: no saved timer/wait records or continuation contents. */
    public static function snapshot(GameState $s, string $viewer, int $now): array
    {
        $p=$s->battle['match_pause'] ?? self::initial();
        $participant=in_array($viewer,['host','player'],true);
        $live=$participant && $s->status==='battle' && $s->winner===null && !self::due($s,$now);
        $requested=$live && $p['mode']==='requested';
        $active=$live && $p['mode']==='active';
        $commands=[];
        // Reuse actual request validation on a disposable copy; projection never mutates the match.
        $copy=clone $s;
        if ($live && $p['mode']==='idle' && self::request($copy,$viewer,new Command('request_pause',['duration_seconds'=>60]),$now)->success) $commands[]='request_pause';
        if ($requested) $commands=$p['initiator']===$viewer?['cancel_pause_request']:['accept_pause','reject_pause'];
        if ($active) $commands=['pause_ready'];
        $opponent=$participant?$s->getOpponentKey($viewer):null;
        return [
            'mode'=>$p['mode'],'server_now'=>$now,'request_id'=>$p['request_id']??null,
            'initiator'=>$p['initiator']??null,'recipient'=>isset($p['initiator'])?$s->getOpponentKey($p['initiator']):null,
            'duration_seconds'=>$p['duration_seconds']??null,'requested_at'=>$p['requested_at']??null,
            'request_deadline_at'=>$p['request_deadline_at']??null,'accepted_at'=>$p['accepted_at']??null,
            'pause_deadline_at'=>$p['pause_deadline_at']??null,
            'ready_self'=>$p['ready'][$viewer]??false,'ready_opponent'=>$p['ready'][$opponent??'']??false,
            'allowed_commands'=>$commands,'used'=>$participant?$p['used'][$viewer]:null,
            'cooldown_until'=>$participant?$p['cooldown_until'][$viewer]:null,
            'turn_timer'=>TurnTimer::snapshot($s,$now),'last_result'=>$p['last_result'],'last_resume'=>$p['last_resume'],
        ];
    }
}
