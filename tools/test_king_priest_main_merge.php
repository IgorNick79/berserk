<?php
declare(strict_types=1);
$kmFixture = ($argv[1]??'')==='--fixture';
if ($kmFixture) ob_start();
require __DIR__ . '/test_king_priest.php';
if ($kmFixture) ob_end_clean();
use Berserk\Core\{ActionResolution, Command, EffectiveFeatures, Engine, GameState, OchiKronga, ZoneManager};
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

$mergeBefore = $checks;
function kmCatalog(): array {
    return ['ef_1'=>['name'=>'Очи Кронга'], 'ef_2'=>['name'=>'Король-жрец'],
        'ef_3'=>['name'=>'Гидра'], 'ef_4'=>['name'=>'Существо']];
}
function kmRender(GameState $s, string $viewer = 'host', ?array $catalog = null): string {
    return (new InfoPanel(new Template(__DIR__.'/../templates')))->render($s,$viewer,$viewer,$catalog??kmCatalog(),'?first&game='.$s->gameId);
}
function kmSequence(GameState $s): void {
    foreach (['strike','shot','heal'] as $type) ActionResolution::effect($s,$s->getCard(4),$type,'ordinary',
        ['hit'=>true,'hp_restored'=>$type==='heal'?1:0]);
}
if ($kmFixture) {
    $s=kpState(); $catalog=kmCatalog();
    if (($argv[2]??'')==='area_impact') {
        $s->getCard(2)->prop=['actions'=>[['key'=>'blast','name'=>'Воздействие','type'=>'impact','target'=>'all_near','value'=>2,'poison'=>1,'self_destroy'=>true]]];
        $catalog['ef_2']['name']='Болотник'; kpAction($s,'blast',2);
    } else { $s->getCard(3)->prop=['damage_reduction'=>1]; kpAction($s); }
    $_GET=[]; $_SESSION=[];
    $data=(new \Berserk\View\Screen\BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,'host','host',null,$catalog,['sel'=>2])['data'];
    echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); exit;
}

$s=kpState(); $s->getCard(3)->prop=['damage_reduction'=>1];
efCheck(kpAction($s)->success,'real memory loss for result UI');
$s=efRoundtrip($s); $html=kmRender($s);
efCheck(str_contains($html,'memory-loss-notice') && str_contains($html,'уникальные особенности отключены'),'memory loss is an explicit effect notice');
efCheck(str_contains($html,'Гидра') && str_contains($html,'Король-жрец'),'notice names target and source');
efCheck(!str_contains($html,'dice-block') && !str_contains($html,'impact-targets') && !str_contains($html,'❤️ HP'),'no dice or fake zero HP wounds for memory loss');
efCheck(str_contains($html,'cmd=confirm_strike') && str_contains($html,'Продолжить'),'notice uses the original result ACK');
efCheck(count(array_filter($s->battle[ActionResolution::KEY]['notifications'],static fn($n)=>$n['type']==='impact'))===1,'merged impact emits one native Ochi resolution event');
$r=kpCommand($s,'confirm_strike',['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]);
efCheck($r->success && str_contains(kmRender($s),'Ожидание оппонента') && !str_contains(kmRender($s),'cmd=confirm_strike'),'acknowledged viewer only waits');
efCheck(str_contains(kmRender($s,'player'),'Продолжить'),'other viewer retains independent ACK');
efCheck(!kpCommand($s,'confirm_strike',['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)])->success,'repeated host ACK is refused');
efCheck(kpCommand($s,'confirm_strike',['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)],'player')->success
    && empty($s->battle['strike']),'other player finalizes the original result');

$s=kpState(); $s->getCard(2)->prop=['actions'=>[['key'=>'blast','name'=>'Воздействие','type'=>'impact','target'=>'all_near','value'=>2]]];
efCheck(kpAction($s,'blast',2)->success,'real Bolotnik-style area impact');
$catalog=kmCatalog(); $catalog['ef_2']['name']='Болотник';
$html=kmRender($s,'host',$catalog);
efCheck(substr_count($html,'Болотник')===1 && !str_contains($html,'→'),'self impact names source once');
efCheck(substr_count($html,'Воздействие')===1,'generic impact heading is not repeated in the body');
efCheck(str_contains($html,'impact-targets') && str_contains($html,'<ul>') && str_contains($html,'Гидра'),'area results have a separate creature list');
efCheck(str_contains($html,'−2 ❤️ HP') && !str_contains($html,'dice-block'),'real area damage remains visible without dice');

$s=kpState(); $eye=$s->getCard(1); $eye->prop=[OchiKronga::PROP=>['filter'=>'forests']]; $eye->closed=true;
$s->getCard(4)->owner='host'; $s->getCard(4)->element='forests';
kmSequence($s);
efCheck(!$eye->closed && $eye->flags[OchiKronga::STATE]['uses']===1,'Ochi works before suppression');
$eye->closed=true;
efCheck(kpAction($s,'memory_loss',1)->success,'friendly Ochi receives actual memory impact');
$s=efRoundtrip($s); $eye=$s->getCard(1); $memory=$eye->flags[OchiKronga::STATE];
kmSequence($s);
efCheck($eye->closed && $eye->flags[OchiKronga::STATE]===$memory,'suppressed Ochi neither opens nor resets its usage state');
kpAck($s); (new ZoneManager($s))->toHand($s->getCard(2)); $s=efRoundtrip($s); $eye=$s->getCard(1);
efCheck(!EffectiveFeatures::suppressed($eye),'source departure releases Ochi');
kmSequence($s);
efCheck(!$eye->closed && $eye->flags[OchiKronga::STATE]['uses']===2,'restored Ochi consumes only its remaining opening');
$eye->closed=true; kmSequence($s);
efCheck($eye->closed && $eye->flags[OchiKronga::STATE]['uses']===2,'suppression/reload/release does not bypass Ochi turn cap');

echo 'King-priest/main merge: ', $checks-$mergeBefore, " new checks passed\n";
