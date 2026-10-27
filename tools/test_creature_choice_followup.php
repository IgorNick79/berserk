<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, CardInstance, Command, Engine, GameState, InstantProcessor};
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
use Berserk\View\Ui\{ActiveCardEffects, CellMarkerPicker, CreatureChoice, Panel, PanelSpec};
Autoloader::register(); Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
set_error_handler(static function(int $n, string $m, string $f, int $l): bool {
    if (error_reporting() & $n) throw new ErrorException($m, 0, $n, $f, $l); return false;
});
$uxChecks = 0;
function uxCheck(bool $ok, string $message): void { global $uxChecks; $uxChecks++; if (!$ok) throw new RuntimeException($message); }
function uxState(): GameState {
    $s = new GameState(-251026, 101, 202); $s->status = 'battle';
    $s->battle = ['active' => 'host', 'turn' => 1, 'strike' => null, 'hidden_row_revealed' => true];
    foreach ([1 => ['host',3,2],2 => ['player',4,2],3 => ['host',1,4],4 => ['player',6,4]] as $id => [$owner,$row,$col]) {
        $s->addCard(new CardInstance(instanceId:$id, ukid:'ux_'.$id, owner:$owner, zone:'field', row:$row, col:$col,
            hp:10, hpMax:10, move:2, moveMax:2, strikeWeak:1, strikeMedium:2, strikeStrong:3, revealed:true));
    }
    return $s;
}
function uxCatalog(): array { return ['ux_1'=>['name'=>'Варлок'], 'ux_2'=>['name'=>'Лунная баньши'], 'ux_3'=>['name'=>'Союзное существо'], 'ux_4'=>['name'=>'Другое существо']]; }
function uxInstant(string $trigger = 'combat', string $target = 'self', string $effect = 'strike_level'): array {
    return ['key'=>'test', 'name'=>'Плач баньши', 'trigger'=>$trigger, 'target'=>$target, 'phase'=>'value',
        'effect'=>['type'=>$effect, 'mode'=>'reduce_one', 'value'=>1]];
}
function uxStrike(): array {
    return ['kind'=>'strike','state'=>'results','attacker_id'=>1,'target_id'=>2,'defender_id'=>null,
        'attack_dice'=>6,'defend_dice'=>1,'confirmed'=>[], 'damage'=>1,
        'result'=>['attack'=>'strong','defend'=>'weak','winner'=>'attack'],
        'final'=>['attack'=>'strong','defend'=>'weak','winner'=>'attack']];
}
function uxRender(GameState $s, string $stage = '', string $viewer = 'host'): array {
    $_GET=[]; $_SESSION=[];
    return (new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,$viewer,$viewer,null,uxCatalog(),
        ['sel'=>1, 'mode'=>$stage==='action'?'action:door':'strike', 'mode_explicit'=>$stage==='action'])['data'];
}
function uxFixture(string $stage): array {
    $s=uxState();
    $s->getCard(1)->prop=['actions'=>[['key'=>'door','name'=>'Дверь измерений','type'=>'teleport_target']]];
    if ($stage==='door') $s->battle['pending_teleport_target']=['owner'=>'host','attacker_id'=>1,'target_id'=>2,
        'cells'=>[['row'=>1,'col'=>1],['row'=>2,'col'=>2],['row'=>4,'col'=>3],['row'=>6,'col'=>5]]];
    if (in_array($stage,['impact','death','vampire'],true)) {
        $s->battle['strike']=uxStrike();
        if ($stage==='impact') { $s->battle['strike']['kind']='impact'; $s->battle['strike']['action_name']='Потеря памяти'; }
        if ($stage==='vampire') $s->battle['strike']['vampire_heal']=[['ukid'=>'ux_1','heal'=>1]];
        if ($stage==='death') $s->battle['strike']['pending_choice']=['died_id'=>1,'died_ukid'=>'ux_1','candidates'=>[2,3,4]];
    }
    if ($stage==='instant') $s->getCard(3)->prop=['instants'=>[uxInstant('turn','self','heal')]];
    if ($stage==='stack') $s->battle['turn_instant_stack']=['state'=>'ordering','priority'=>'host','phase'=>'turn','stack'=>[],'participants'=>['host','player']];
    if ($stage==='memory') $s->getCard(2)->flags['memory_loss_sources']=[1=>true];
    return uxRender($s,$stage);
}
if (($argv[1]??'')==='--fixture') { echo json_encode(uxFixture($argv[2]??'action'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); exit; }

$s=uxState(); $s->battle['strike']=uxStrike();
$s->battle['strike']['state']='waiting_instant'; $s->battle['strike']['instant_phase']='combat';
$s->battle['strike']['instant_priority']='player'; $s->battle['strike']['instant_participants']=['host','player'];
$s->getCard(2)->prop=['instants'=>[uxInstant()]]; $s->getCard(4)->prop=$s->getCard(2)->prop;
$ip=new InstantProcessor($s,new Engine());
uxCheck(array_column($ip->getInstants('player','combat','combat'),'card_id')===[4], 'engaged Banshee excluded; third-party Banshee remains legal');
$before=serialize($s->toArray());
uxCheck(!$ip->playCombat('player',2,'test')->success && serialize($s->toArray())===$before, 'forged defender command refused without mutation');
$s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));
uxCheck(!(new InstantProcessor($s,new Engine()))->playCombat('player',2,'test')->success,'reload cannot bypass engagement');
$s->battle['strike']['defender_id']=4;
uxCheck(array_column((new InstantProcessor($s,new Engine()))->getInstants('player','combat','combat'),'card_id')===[2], 'only actual defender is engaged after replacement');
$s->battle['strike']['defender_id']=null; $s->getCard(2)->prop=['instants'=>[uxInstant('turn','self','heal')]];
uxCheck(in_array(2,array_column((new InstantProcessor($s,new Engine()))->getInstants('player','before','turn'),'card_id'),true),'defender turn instant available before combat');
uxCheck(!in_array(2,array_column((new InstantProcessor($s,new Engine()))->getInstants('player','after','turn'),'card_id'),true),'engaged target has no after turn action');
$s=uxState(); $s->battle['strike']=uxStrike(); $s->getCard(4)->row=5; $s->getCard(4)->col=2;
$s->getCard(2)->prop=['instants'=>[uxInstant('combat','adjacent_ally','redirect_strike')]];
uxCheck(in_array(2,array_column((new \Berserk\Core\StrikeResolver($s,new Engine()))->getMageRedirectOptions('player'),'card_id'),true),'attacked Mage retains special redirect window');

// Real before stack: using a turn instant closes the defender; Ice Guard closes
// the attacker, which cancels the impending combat via the original continuation.
foreach (['heal','close_target'] as $effect) {
    $s=uxState(); $s->getCard(2)->prop=['instants'=>[uxInstant('turn',$effect==='heal'?'self':'enemy',$effect)]];
    $e=new Engine(); $r=$e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>2]));
    uxCheck($r->success && !empty($s->battle['turn_instant_stack']), 'real before window '.$effect);
    $ip=new InstantProcessor($s,$e);
    uxCheck($ip->playTurnInstant('player',new Command('play_turn_instant',['card_id'=>2,'instant_key'=>'test']))->success,'before declaration');
    if ($effect==='close_target') uxCheck($ip->chooseTurnTarget('player',new Command('choose_instant_pick',['target_id'=>1]))->success,'Ice Guard chooses attacker');
    uxCheck($ip->passTurnInstant('player')->success,'before stack resolves');
    uxCheck($s->getCard(2)->closed,'turn instant pays closing');
    uxCheck($ip->ackTurnInstantResult('player')->success,'before result ACK');
    if ($effect==='close_target') uxCheck(empty($s->battle['strike']) && $s->getCard(1)->closed,'Ice Guard stops combat by closing attacker');
    else uxCheck(!empty($s->battle['strike']) && isset($s->battle['strike']['attack_dice'])
        && ($s->battle['strike']['defend_dice']??0)===0,'combat continues against closed defender without defense roll');
}
$html=uxFixture('action')['info_panel_html'];
uxCheck(str_contains($html,'data-creature-sides') && !str_contains($html,'❤️ 10/10 HP'),'shared ability pick has side controls and no HP');
uxCheck(str_contains($html,'creature-choice-name') && str_contains($html,'coords--enemy'),'coordinate labels use shared compact markup');
$html=uxFixture('impact')['info_panel_html'];
uxCheck(!str_contains($html,'class="strike-dice"') && !str_contains($html,'class="dice-value"'),'impact result has no dice');
$html=uxFixture('instant')['info_panel_html'];
$dom=new DOMDocument();@$dom->loadHTML($html);$x=new DOMXPath($dom);
uxCheck($x->query('//footer//section[@data-instant-control]')->length===1,'instant offer is in the persistent footer');
$html=uxFixture('stack')['info_panel_html'];
uxCheck(strpos($html,'Стек инстантов')>strpos($html,'info-panel-instants'),'actual instant stack uses shared context section');
@$dom->loadHTML($html);uxCheck((new DOMXPath($dom))->query('//*[contains(@class,"info-panel-context")]//section[contains(@class,"info-panel-instants")]')->length===1,'active instant stack is in the same scrollable context');
$html=uxFixture('death')['info_panel_html'];
uxCheck(substr_count($html,'type="radio" name="target_id"')===3 && str_contains($html,'value="choose_death_target"'),'death choice is radio with original command');
$s=uxState(); $s->getCard(2)->flags['memory_loss_sources']=[1=>true];
uxCheck(str_contains(implode(' ',ActiveCardEffects::forCard($s->getCard(2),$s)),'Потеря памяти'),'memory debuff appears in active effects star');
$html=uxFixture('memory')['field_html'];
uxCheck(str_contains($html,'Потеря памяти') && str_contains($html,'data-mini-hint'),'field star exposes memory tooltip');
$door=uxFixture('door');
uxCheck(str_contains($door['info_panel_html'],'data-cell-marker-picker') && str_contains($door['field_html'],'cmd=choose_teleport_cell'),'door uses shared field cell picker');
uxCheck(str_contains($door['info_panel_html'],'data-picker-side="own"') && str_contains($door['info_panel_html'],'data-picker-side="enemy"'),'door has side and row controls');
$s=uxState(); $s->getCard(1)->prop=['actions'=>[['key'=>'door','type'=>'teleport_target']]];
$r=(new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'door']));
uxCheck($r->success && isset($s->battle['pending_teleport_target']),'real door creates original pending');
$choice=new \Berserk\Core\Choice\TeleportTargetChoice();
uxCheck(!$choice->apply($s,new Engine(),'host',new Command('choose_teleport_cell',['cell'=>'3_2']))->success,'occupied door cell remains invalid');
$s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));
uxCheck($choice->apply($s,new Engine(),'host',new Command('choose_teleport_cell',['cell'=>'2_1']))->success,'door selection works after reload');
uxCheck($s->getCard(2)->row===2 && $s->getCard(2)->col===1 && $s->getCard(1)->closed,'door uses original move/payment/closure');
echo "Creature choices and instant engagement: $uxChecks checks passed\n";
