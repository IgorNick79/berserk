<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,TurnTimer,Engine,Command};
use Berserk\View\{Template};use Berserk\View\Screen\BattleScreen;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$n=0;function puAssert(bool $ok,string $m):void{global $n;++$n;if(!$ok)throw new RuntimeException($m);}
$s=new GameState(-91241,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];$now=time();
TurnTimer::prepare($s,'host');TurnTimer::resume($s,1,'start_phase',$now-10);
$engine=new Engine(null,static function()use(&$now):int{return $now;});$tpl=new Template(__DIR__.'/../templates/');
function puRender(GameState $s,string $viewer):array{global $tpl;return (new BattleScreen($tpl))->prepare($s,$viewer,$viewer,null,[])['data'];}
$before=$s->toArray();$idle=puRender($s,'host');
puAssert($idle['pause_mask_hidden']==='hidden'&&$idle['pause_root_attr']==='','Idle board not covered');
puAssert(str_contains($idle['info_panel_html'],'data-open-pause'),'Pause icon in existing footer');
puAssert($s->toArray()===$before,'Idle UI projection is read-only');
puAssert(str_contains($idle['info_panel_html'],'<noscript>')&&str_contains($idle['info_panel_html'],'Управление паузой требует JavaScript'),'Idle no-JS fallback');
puAssert($engine->apply($s,'host',new Command('request_pause',['duration_seconds'=>180]))->success,'Request');
$host=puRender($s,'host');$guest=puRender($s,'player');
puAssert($host['pause_field_attr']==='inert aria-hidden="true"'&&$host['pause_mask_hidden']==='','Boot board inert and masked');
puAssert(str_contains($host['info_panel_html'],'cancel_pause_request'),'Initiator can cancel');
puAssert(str_contains($host['info_panel_html'],'pause-noscript-notice'),'Requested no-JS fallback');
puAssert(str_contains($guest['info_panel_html'],'accept_pause')&&str_contains($guest['info_panel_html'],'reject_pause'),'Recipient can decide');
puAssert(!str_contains($guest['info_panel_html'],'&cmd=end_turn'),'No end-turn action during request');
$id=$s->battle['match_pause']['request_id'];puAssert($engine->apply($s,'player',new Command('accept_pause',['request_id'=>$id]))->success,'Accept');
$before=$s->toArray();$active=puRender($s,'host');$html=$active['info_panel_html'];
puAssert(str_contains($html,'pause-noscript-notice'),'Active no-JS fallback');
puAssert(str_contains($html,'Вернулся / Готов')&&str_contains($html,'data-pause-countdown'),'Ready and pause countdown');
puAssert(str_contains($html,'&cmd=resign')&&str_contains($html,'data-action="battle-log" aria-label="Лог игры" aria-disabled="true" disabled'),'Surrender accessible, log disabled');
puAssert(str_contains($html,'data-battle-fragment="panel_html" hidden'),'Selected card fragment stays hidden');
puAssert($s->toArray()===$before,'Active UI projection is read-only');
puAssert(str_contains($html,'method="post"')&&str_contains($html,'name="command_id"')&&str_contains($html,'name="expected_version"')&&str_contains($html,'data-http-resign'),'No-JS surrender uses journalled POST');
$engine->apply($s,'host',new Command('pause_ready',['request_id'=>$id]));
puAssert(str_contains(puRender($s,'host')['info_panel_html'],'Вы готовы, ожидаем соперника'),'Own readiness survives server rendering');
echo "Match pause server UI tests passed ($n checks)\n";
