<?php
declare(strict_types=1);
use Berserk\Core\{CardInstance,GameState};

function flyingUnitFixture(int $game,int $host,int $player,string $bot='host'):GameState
{
    $s=new GameState($game,$host,$player);$s->status='battle';$s->players['host']->side=1;$s->players['player']->side=2;
    $other=$s->getOpponentKey($bot);$s->battle=['active'=>$bot,'turn'=>2,'strike'=>null,'hidden_row_revealed'=>true];
    $s->addCard(new CardInstance(1,'air-source',$bot,'flying',slot:1,hp:12,hpMax:12,type:'fly',strikeWeak:3,strikeMedium:4,strikeStrong:5));
    $s->addCard(new CardInstance(2,'ground-target',$other,'field',row:$bot==='host'?5:2,col:3,hp:20,hpMax:20,closed:true,strikeWeak:1,strikeMedium:1,strikeStrong:1));
    $s->addCard(new CardInstance(3,'own-ground',$bot,'field',row:$bot==='host'?2:5,col:1,hp:10,hpMax:10));
    $s->addCard(new CardInstance(4,'air-target',$other,'flying',slot:1,hp:20,hpMax:20,type:'fly',closed:true,strikeWeak:1,strikeMedium:1,strikeStrong:1));
    return $s;
}
