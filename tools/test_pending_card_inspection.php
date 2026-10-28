<?php
declare(strict_types=1);
ob_start();require __DIR__.'/test_combat_panel_ui.php';ob_end_clean();
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
function inspectionFixture(string $stage,string $viewer,int $inspect=0):array {
    global $cardsInfo;
    $catalog=$cardsInfo;foreach($catalog as &$info)$info+=['price'=>1,'health'=>3,'move'=>1,'strike'=>['weak'=>1,'medium'=>2,'strong'=>3],'elite'=>false];unset($info);
    $s=battleSnapshotState();$s->getCard(2)->revealed=$stage!=='hidden';
    if($stage==='pending')$s->battle['pending_ui_test']=['owner'=>$viewer];
    if($stage==='prophecy')$s->battle['pending_prophecy']=['owner'=>$viewer,'source_id'=>1,'source_ukid'=>'own','shown_ukids'=>['own'],'title'=>'Пророчество 1','actions'=>[['label'=>'Продолжить','cmd'=>'close_prophecy']]];
    if($stage==='instant')$s->battle['turn_instant_stack']=['state'=>'ordering','priority'=>$viewer,'phase'=>'turn','stack'=>[],'participants'=>['host','player']];
    if($stage==='combat')$s->battle['strike']=['kind'=>'cell_marker','state'=>'results','attacker_id'=>1,'target_id'=>2,'cell_marker'=>['row'=>3,'col'=>1],'confirmed'=>[]];
    $s->getCard(1)->owner=$viewer;$s->battle['active']=$viewer;
    if($stage==='action')$s->getCard(1)->prop=['actions'=>[['type'=>'impact','effect'=>'prophecy_wounds','key'=>'test','name'=>'Пророчество 1']]];
    $before=serialize($s->toArray());
    $d=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,$viewer,$viewer,null,$catalog,
        ['sel'=>1,'inspect'=>$inspect,'mode'=>$stage==='action'?'action:test':'strike'])['data'];
    battleSnapshotAssert($before===serialize($s->toArray()),'Inspection never changes game state');return $d;
}
if(($argv[1]??'')==='--fixture'){echo json_encode(inspectionFixture($argv[2]??'action',$argv[3]??'host',(int)($argv[4]??0)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
foreach(['host','player'] as $viewer)foreach(['action','pending','prophecy','combat','instant'] as $stage){
    $d=inspectionFixture($stage,$viewer,2);
    battleSnapshotAssert($d['ui']['sel']===1&&$d['ui']['inspect']===2,'Original source and inspected card distinct');
    battleSnapshotAssert(str_contains($d['panel_html'],'data-panel-card-id="2"'),'Requested card rendered');
    battleSnapshotAssert(!str_contains($d['panel_html'],'cmd=')&&!str_contains($d['panel_html'],'mode='),'Inspected card cannot execute abilities');
    foreach(['field_html','fly_zones_html'] as $key){
        $dom=new DOMDocument();@$dom->loadHTML($d[$key]);$x=new DOMXPath($dom);
        foreach($x->query('//a[@class="card-link"]') as $link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$q);
            battleSnapshotAssert(isset($q['inspect'])&&$q['sel']==1&&!isset($q['cmd']),'Field and flying links inspect without executing pending');}
    }
    if($stage==='action'){
        battleSnapshotAssert(str_contains($d['info_panel_html'],'Пророчество 1: выберите цель'),'Target list in information context');
        battleSnapshotAssert(!str_contains($d['panel_html'],'Пророчество 1: выберите цель'),'Target list separate from large card');
        battleSnapshotAssert($d['ui']['mode']==='action:test','Inspection preserves action mode');
    }
}
$d=inspectionFixture('hidden','host',2);
battleSnapshotAssert(!isset($d['ui']['inspect'])&&!str_contains($d['panel_html'],'data-panel-card-id="2"'),'Hidden opponent cannot be inspected');
echo "Pending inspection: both viewers, action/prophecy/combat/instant, source preservation, read-only cards and hidden data passed\n";
