<?php
declare(strict_types=1);
// All writes inherit exact disposable source/datadir/HTTP/InnoDB guards.
require __DIR__.'/test_match_pause_http.php';
$n=0;
function sp(bool $ok,string $message):void {global $n;++$n;tsAssert($ok,$message);}
foreach(['idle','requested','active'] as $mode){
 $s=mhFixture(time());
 if($mode!=='idle'){[$s]=mhLocked($s,'host','request_pause',time(),'settings-request-'.$mode);}
 if($mode==='active'){[$s]=mhLocked($s,'player','accept_pause',time(),'settings-accept');}
 $before=$s;$battle=$s->battle;$id='settings-'.bin2hex(random_bytes(8));
 $a=mhPost($s,'host','set_sound_enabled',['sound_enabled'=>'0','player_key'=>'player'],$id);
 sp(($a['command_status']??'')==='committed','Committed '.$mode);
 sp($a['player_settings']===['sound_enabled'=>false],'Own response '.$mode);
 $s=$repo->findById($s->gameId);
 sp(!$s->players['host']->soundEnabled && $s->players['player']->soundEnabled,'Sender only '.$mode);
 sp($s->battle===$battle,'No battle mutation '.$mode);
 $replay=mhPost($before,'host','set_sound_enabled',['sound_enabled'=>'0','player_key'=>'player'],$id);
 sp($replay['command_status']==='committed' && $repo->findById($s->gameId)->persistenceVersion===$s->persistenceVersion,'Replay once '.$mode);
 $mismatch=mhPost($before,'host','set_sound_enabled',['sound_enabled'=>'1','player_key'=>'player'],$id);
 sp(($mismatch['error']??'')==='command_id_conflict' && !$repo->findById($s->gameId)->players['host']->soundEnabled,'Changed payload cannot reuse ID '.$mode);
 $conflict=mhPost($before,'host','set_sound_enabled',['sound_enabled'=>'1']);
 sp($conflict['command_status']==='conflict' && !$repo->findById($s->gameId)->players['host']->soundEnabled,'CAS '.$mode);
 $status=tsRequest('first&game='.$s->gameId.'&ajax=battle_command_status&command_id='.$id);
 sp($status['player_settings']===['sound_enabled'=>false],'Recovery projection '.$mode);
 $poll=tsRequest('second&game='.$s->gameId.'&ajax=battle_sync&since_version='.$s->persistenceVersion);
 sp($poll['type']==='no_change' && $poll['player_settings']===['sound_enabled'=>true],'Independent no_change '.$mode);
 $snapshot=tsRequest('first&game='.$s->gameId.'&ajax=battle_sync&force_snapshot=1');
 sp($snapshot['player_settings']===['sound_enabled'=>false],'Snapshot '.$mode);
 $html=file_get_contents($url.'?first&game='.$s->gameId);
 preg_match('~id="battle-player-settings">(.*?)</script>~s',$html,$m);
 sp(json_decode($m[1]??'',true)===['sound_enabled'=>false],'Refresh boot '.$mode);
 $a=mhPost($s,'host','set_sound_enabled',['sound_enabled'=>'1']);
 sp($a['player_settings']===['sound_enabled'=>true],'Enable '.$mode);
 sp($repo->findById($s->gameId)->battle===$battle,'Enable pure '.$mode);
}
// Preference and journal recovery are pure; subsequent ordinary sync processes deadlines.
$s=mhFixture(time()-100);[$s]=mhLocked($s,'host','request_pause',time()-90,'settings-expired-request');
$battle=$s->battle;$id='settings-expired';$a=mhPost($s,'host','set_sound_enabled',['sound_enabled'=>'0'],$id);
sp($a['match_pause']['mode']==='requested','POST cannot expire pause');
sp($repo->findById($s->gameId)->battle===$battle,'Expired battle unchanged');
$a=tsRequest('first&game='.$s->gameId.'&ajax=battle_command_status&command_id='.$id);
sp($a['match_pause']['mode']==='requested','Status cannot expire pause');
$a=tsRequest('first&game='.$s->gameId.'&ajax=battle_sync&force_snapshot=1&settings_sync_id='.$id);
sp($a['match_pause']['mode']==='idle','Historical settings_sync_id cannot suppress deadline');
$a=tsRequest('first&game='.$s->gameId.'&ajax=battle_sync');
sp($a['match_pause']['mode']==='idle','Ordinary poll preserves completed expiry');
// A real commit succeeds, its acknowledgement and first recovery read are lost.
$s=mhFixture(time()-100);[$s]=mhLocked($s,'host','request_pause',time()-90,'settings-lost-request');
$battle=$s->battle;$id='settings-lost-commit';$pipes=[];
$process=proc_open([PHP_BINARY,__DIR__.'/test_match_pause_fault.php',(string)$s->gameId,'sound-commit-unknown',$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process))throw new RuntimeException('Cannot start fault child');fclose($pipes[0]);
$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
sp(proc_close($process)===0,'Lost commit child '.$err);
$reply=json_decode($out,true,512,JSON_THROW_ON_ERROR);
sp($reply['command_status']==='unknown' && !$reply['terminal'],'Lost commit is not falsely confirmed');
sp($repo->findById($s->gameId)->battle===$battle && !$repo->findById($s->gameId)->players['host']->soundEnabled,'Atomic preference commit has no automatic progress');
$a=tsRequest('first&game='.$s->gameId.'&ajax=battle_command_status&command_id='.$id);
sp($a['command_status']==='committed' && $a['player_settings']===['sound_enabled'=>false] && $a['match_pause']['mode']==='requested','Original ID recovers authoritative preference without progress');
$processingId='settings-processing';$fresh=$repo->findById($s->gameId);
$journal->registerProcessing($s->gameId,'host',$processingId,'set_sound_enabled',['sound_enabled'=>'1'],$fresh->persistenceVersion);
$a=mhPost($fresh,'host','set_sound_enabled',['sound_enabled'=>'1'],$processingId);
sp($a['command_status']==='processing' && !$a['terminal'] && !$repo->findById($s->gameId)->players['host']->soundEnabled,'Processing does not execute again');
echo "Match player settings HTTP/InnoDB: $n checks passed\n";
