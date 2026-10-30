<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,Engine,Command,Result};
use Berserk\View\Ui\GameMenu;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$n=0;function verify(bool $ok):void {global $n;$n++;if(!$ok)throw new RuntimeException('Check '.$n);}
foreach(['lobby','preparation','battle','finished'] as $screen){verify(GameMenu::visible([],$screen));verify(GameMenu::visible(['allow'=>[$screen]],$screen));verify(!GameMenu::visible(['deny'=>[$screen]],$screen));verify(!GameMenu::visible(['allow'=>[]],$screen));}
foreach([['allow'=>[],'deny'=>[]],['allow'=>['other']],['deny'=>null],['allow'=>'battle'],['screens'=>['all']]] as $bad){try{GameMenu::visible($bad,'battle');throw new RuntimeException('Accepted invalid config');}catch(InvalidArgumentException){$n++;}}
$config=json_decode(file_get_contents(__DIR__.'/../config/game-menu.json'),true,512,JSON_THROW_ON_ERROR);GameMenu::validate($config);
verify(!GameMenu::visible(['deny'=>['battle']],'battle') && GameMenu::visible(['allow'=>['battle']],'battle'));
$group=['id'=>'probe','items'=>[['id'=>'lobby','label'=>'Probe','type'=>'link','allow'=>['battle']]],'deny'=>['battle']];
$html=GameMenu::render('battle',null,'',null,'','',['groups'=>[$group]]);verify(!str_contains($html,'data-menu-group="probe"'));
unset($group['deny']);$html=GameMenu::render('battle',null,'',null,'','',['groups'=>[$group]]);verify(str_contains($html,'data-menu-group="probe"'));
$group['items'][0]['deny']=['battle'];unset($group['items'][0]['allow']);$html=GameMenu::render('battle',null,'',null,'','',['groups'=>[$group]]);verify(!str_contains($html,'data-menu-group="probe"'));
foreach(['lobby','preparation','battle','finished'] as $context){$html=GameMenu::render($context,new Berserk\Auth\Account(1,'Fixture'),'token');verify(substr_count($html,'data-unified-menu ')===1);verify(str_contains($html,'unified-menu-account'));}
$legacy=(new GameState(10,1,2))->toArray();$legacy['players']['host']['sound_enabled']=false;$legacy['players']['host']['playmat_id']='mat-001';$s=GameState::fromArray($legacy);$s->status='battle';$s->battle=['active'=>'host','turn'=>1];
foreach($s->toArray()['players'] as $player)verify(!array_key_exists('sound_enabled',$player)&&!array_key_exists('playmat_id',$player));
$before=$s->toArray();verify(!(new Engine())->apply($s,'host',new Command('set_sound_enabled',['sound_enabled'=>true]))->success);verify($before===$s->toArray());
$recorder=new Berserk\Core\BattleHistory\Recorder(10,1,[],[]);$recorder->commandStarted($s,'host',new Command('set_sound_enabled'));$recorder->commandFinished($s,Result::ok());$batch=$recorder->batch($s);verify($before===$s->toArray());verify(count($batch->data()['events'])===0);
echo "Menu filters, old saves, rejected command and historical Recorder: $n checks passed\n";
