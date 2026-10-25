<?php
declare(strict_types=1);
require __DIR__.'/test_effective_features.php';
use Berserk\Core\{CardInstance, Engine, ProphecyProcessor};
$before=$checks;
foreach([false,true] as $machinator) {
    $s=efState();$s->nextInstanceId=100;
    if($machinator)$s->getCard(2)->prop=['prophecy_reorder'=>true];
    else foreach($s->cards as $c)$c->prop=[];
    foreach([10,11,12] as $id)$s->addCard(new CardInstance(instanceId:$id,ukid:'secret_'.$id,owner:'host',zone:'deck',order:$id-9));
    $p=new ProphecyProcessor($s,new Engine());$peek=$p->peek('host',1);$first=$peek['cards'][0];
    $p->commit('host',$s->getCard(1),$peek,'simple','Пророчество 1',[]);
    efCheck($p->close('host')['ok'],'close explicitly ordered deck');
    $s=efRoundtrip($s);$p=new ProphecyProcessor($s,new Engine());
    efCheck($p->peek('host',1)['ukids']===['secret_11'],'next prophecy does not repeat first card, with or without extra action');
    efCheck($first->order===null,'consumed explicit deck order removed');
}
$s=efState();$s->nextInstanceId=100;$s->getCard(2)->prop=['prophecy_reorder'=>true];
foreach([10,11] as $id)$s->addCard(new CardInstance(instanceId:$id,ukid:'secret_'.$id,owner:'host',zone:'deck',order:$id-9));
$p=new ProphecyProcessor($s,new Engine());$p->commit('host',$s->getCard(1),$p->peek('host',1),'simple','Пророчество 1',[]);
efCheck($p->startReorder('host')->success,'Machinator may explicitly retain first card');
efCheck($p->close('host')['ok']&&$p->peek('host',1)['ukids']===['secret_10'],'explicit Machinator top still respected');
echo 'Prophecy deck order: ',$checks-$before," new checks passed\n";
