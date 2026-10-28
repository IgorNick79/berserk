<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,ZoneManager};
use Berserk\View\{Template};
use Berserk\View\Screen\BattleScreen;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$s=new GameState(-1,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1];
$screen=new BattleScreen(new Template(__DIR__.'/../templates'));
$method=new ReflectionMethod($screen,'buildCellMarkersOverlay');$method->setAccessible(true);
$render=fn($count)=>$method->invoke($screen,$s,'2_2',$count);
$n=0;function check($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
check($render(0)==='','empty has no marker');
ZoneManager::addMarker($s,'2_2',['type'=>'tremor','id'=>'a','owner'=>'host']);
$empty=$render(0);$occupied=$render(1);
check(str_contains($empty,'cell-marker-island'),'empty island');check(str_contains($occupied,'cell-bottom-strip'),'occupied footer');
check(str_contains($empty,'tremor-owner-host'),'blue owner');check(!str_contains($empty,'tabindex'),'decoration not focusable');
ZoneManager::addMarker($s,'2_2',['type'=>'tremor','id'=>'b','owner'=>'host']);ZoneManager::addMarker($s,'2_2',['type'=>'tremor','id'=>'c','owner'=>'player']);
$multi=$render(1);check(substr_count($multi,'data-tremor-id=')===3,'all copies rendered');check(str_contains($multi,'tremor-owner-player'),'red owner');
$s=GameState::fromArray($s->toArray());check($method->invoke($screen,$s,'2_2',1)===$multi,'reload stable');
$s->cell_markers['3_3']=$s->cell_markers['2_2'];unset($s->cell_markers['2_2']);
check($method->invoke($screen,$s,'2_2',0)==='','old cell removed after state update');check(substr_count($method->invoke($screen,$s,'3_3',0),'data-tremor-id=')===3,'new cell rendered');
$s->cell_markers=[];check($method->invoke($screen,$s,'3_3',0)==='','removed marker disappears');
$s->battle['pending_tremor']=['owner'=>'host','id'=>'a','mode'=>'move','choice_id'=>'move-1','from'=>'1_1'];
$t=\Berserk\View\Ui\CellMarkerPicker::targets($s,'host','?game=1');check(count($t)===4,'corner targets');check(isset($t['1_1']),'stay target');check(str_contains($t['2_2']['url'],'choice_id=move-1'),'choice token');check(\Berserk\View\Ui\CellMarkerPicker::targets($s,'player','?game=1')===[],'opponent cannot select');
check(\Berserk\View\Ui\CellMarkerPicker::identity($s,'host')!=='','picker sync identity');
if(($argv[1]??'')==='--fixture'){echo json_encode(compact('empty','occupied','multi'));exit;}
echo "PASS $n checks; FAIL 0\n";
