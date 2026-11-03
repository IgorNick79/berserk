<?php
declare(strict_types=1);

// In-memory adapter loaded before the autoloader. No credentials, sockets or production DB writes.
namespace Berserk\Core {
    final class Db
    {
        public array $cards = [];
        public array $game = [];
        public bool $omitBuilderCard = false;
        private int $affected = 0;

        public function __construct()
        {
            foreach (['common', 'uncommon', 'rare', 'ultrarare'] as $rarity) {
                for ($i = 0; $i < 80; $i++) $this->add($rarity . $i, [], $rarity);
            }
        }

        public function add(string $ukid, array $prop = [], string $rarity = 'common'): void
        {
            $this->cards[$ukid] = ['ukid' => $ukid, 'ind' => count($this->cards) + 1, 'name' => $ukid,
                'prop' => json_encode($prop), 'rarity' => $rarity, 'power' => 3, 'price' => 2,
                'health' => 5, 'move' => 1, 'elite' => 0, 'single' => 0, 'type' => 'creature',
                'class' => '', 'strike_weak' => 1, 'strike_medium' => 2, 'strike_strong' => 3, 'element_id' => 1];
        }

        public function escape(string $value): string { return addslashes($value); }
        public function fetchAll(string $sql): array
        {
            if (str_contains($sql, 'FROM elements')) return [['ind' => 1, 'code' => 'forest']];
            if (str_contains($sql, 'FROM card_synergy')) return [];
            if (preg_match("/rarity = '([^']+)'/", $sql, $match)) {
                return array_values(array_filter($this->cards, fn($card) => $card['rarity'] === $match[1]));
            }
            if (preg_match('/ukid IN \(([^)]+)\)/', $sql, $match)) {
                preg_match_all("/'([^']+)'/", $match[1], $ids);
                $cards = array_values(array_intersect_key($this->cards, array_flip($ids[1])));
                if ($this->omitBuilderCard && str_contains($sql, 'SELECT ukid, price')) array_pop($cards);
                return $cards;
            }
            throw new \RuntimeException('Unexpected fixture SELECT');
        }
        public function fetchOne(string $sql): ?array
        {
            if (str_starts_with($sql, 'SHOW COLUMNS')) return ['Field' => 'version'];
            if (str_starts_with($sql, 'SELECT * FROM games')) return $this->game ?: null;
            throw new \RuntimeException('Unexpected fixture query');
        }
        public function query(string $sql): bool
        {
            if (!preg_match("/SET json = '(.*)', status = '([^']+)', version = (\d+) WHERE ind = (\d+) AND version = (\d+)$/s", $sql, $m)) {
                throw new \RuntimeException('Unexpected fixture UPDATE');
            }
            $this->affected = (int) (($this->game['version'] ?? -1) === (int) $m[5]);
            if ($this->affected) {
                $this->game['json'] = stripslashes($m[1]);
                $this->game['status'] = $m[2];
                $this->game['version'] = (int) $m[3];
            }
            return true;
        }
        public function affectedRows(): int { return $this->affected; }
    }
}

namespace {
    require_once __DIR__ . '/../src/Core/Autoloader.php';
    use Berserk\Core\{Autoloader, CardInstance, Command, Db, Engine, GameRepository, GameSettings, GameState, StaleStateException};
    use Berserk\Core\Prepare\{DraftAutoPicker, DraftCopyRules, DraftProcessor, DraftSelectionEvaluator, DraftTimer, PrepareProcessor};
    use Berserk\View\{Template};
    use Berserk\View\Screen\{DraftScreen, ModeScreen, SettingsScreen};
    Autoloader::register();
    Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');


    $checks=0;
    function check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new \RuntimeException($message); }
    function freshG(Db $db, string $mode='normal', int $count=2, int $boosters=5, string $auto='manual'): GameState {
        $s=new GameState(901,1,2); $p=new PrepareProcessor($s,$db);
        check($p->chooseMode('host',new Command('choose_mode',['mode'=>'glimpse']))->success,'choose');
        $result=$p->confirmSettings('host',new Command('confirm_settings',['boosters'=>$boosters,'draft_burn_mode'=>$mode,'draft_burn_count'=>$count,'draft_timer_mode'=>'unlimited','draft_pick_mode'=>$auto]));
        check($result->success,'start: '.($result->error??'')); return $s;
    }
    function totalG(GameState $s): int { return count($s->draft['pool'])+count($s->draft['grid'])+count($s->draft['recycle'])+count($s->draft['picked']['host'])+count($s->draft['picked']['player']); }
    $db=new Db();
    foreach ([['normal',2,[2,2,2,2]],['progressive',2,[2,2,1,1,0,0]],['progressive',3,[3,3,1,1]]] as [$mode,$count,$sequence]) {
        $s=freshG($db,$mode,$count); $p=new DraftProcessor($s,$db);
        foreach ($sequence as $index=>$burn) {
            check($p->glimpseBurnCount()===$burn,'burn sequence');
            check($s->draft['turn']===($index%2?'player':'host'),'turn');
            $result=$p->confirmGlimpse($s->draft['turn'],0,$burn ? range(1,$burn):[]);
            check($result->success,'pick'); check(totalG($s)===60,'conservation');
        }
        check($s->draft['round_first_player']==='player' && $s->draft['turn']==='player','alternate first');
        check($s->draft['round_picks']===0,'new packet');
    }
    $s=freshG($db);$p=new DraftProcessor($s,$db);$before=$s->draft;
    check(!$p->confirmGlimpse('host',0,[0,1])->success && $before===$s->draft,'duplicate atomic');
    check(!$p->confirmGlimpse('player',0,[1,2])->success,'wrong owner');
    check(!$p->confirmGlimpse('host',99,[1,2])->success,'invalid position');
    check(!$p->pass('host')->success,'no grid pass');
    $allocated=array_merge($s->draft['pool'],array_slice($s->draft['grid'],2));
    $s->draft['picked']=['host'=>array_slice($allocated,0,29),'player'=>array_slice($allocated,29)];
    $s->draft['grid']=array_slice($s->draft['grid'],0,2); $s->draft['pool']=[];$s->draft['recycle']=[];
    check($p->glimpseBurnCount()===1,'short burn');
    check($p->confirmGlimpse('host',0,[1])->success,'short packet recycle');
    check(count($s->draft['grid'])===1 && $p->glimpseBurnCount()===0,'one card packet');
    $s=freshG($db);$p=new PrepareProcessor($s,$db);
    $cmd=new Command('draft_confirm',['pick'=>0,'positions'=>[1,2],'decision_id'=>$s->draft['decision_id'],'expected_version'=>$s->version]);
    check($p->draftConfirm('host',$cmd)->success,'manual wrapper');
    $snapshot=$s->toArray();check(!$p->draftConfirm('host',$cmd)->success && $snapshot===$s->toArray(),'replay rejected');
    $tpl=new Template(__DIR__.'/../templates');$screen=new DraftScreen($tpl);
    $s->draft['grid']=['SECRET_GRID'];$s->draft['pool']=['SECRET_POOL'];$s->draft['recycle']=['SECRET_BURN'];$s->draft['picked']['player']=['SECRET_PICK'];
    $s->draft['history']=[['player'=>'host','cards'=>['OWN_CARD'],'packet'=>2],['player'=>'player','cards'=>['SECRET_HISTORY'],'packet'=>2]];
    $view=$screen->prepare($s,'host','host',null,[])['data'];
    check(str_contains($view['history_html'],'OWN_CARD') && str_contains($view['history_html'],'Пакет 2'),'own history grouped by packet');
    check(!str_contains(json_encode($view),'SECRET_HISTORY'),'opponent history hidden');
    check(str_contains($view['panel_summary_html'],'pregame-pool-stats') && $view['panel_html']==='','shared statistics without own card list');
    $html=json_encode($screen->prepare($s,'host','host',null,[]));
    foreach (['SECRET_GRID','SECRET_POOL','SECRET_BURN','SECRET_PICK'] as $secret) check(!str_contains($html,$secret),'hidden '.$secret);
    foreach ([5,15] as $n) { $s=freshG($db,'normal',2,$n);check(totalG($s)===$n*12,'pool size'); }
    foreach ([['normal',2],['progressive',2],['progressive',3]] as [$mode,$count]) {
        for ($run=0; $run<10; $run++) {
            $s=freshG($db,$mode,$count,5,'random');
            check($s->status==='view','five booster auto completes');
            foreach (['host','player'] as $owner) check(array_sum(array_column($s->getPlayer($owner)->deckCards,'count'))===30,'five booster exact 30/30');
        }
    }
    $s=freshG($db);$p=new DraftProcessor($s,$db);
    $allocated=array_merge($s->draft['grid'],$s->draft['pool']);
    $s->draft['picked']=['host'=>array_slice($allocated,0,30),'player'=>array_slice($allocated,30,28)];
    $s->draft['grid']=array_slice($allocated,58);$s->draft['pool']=[];$s->draft['recycle']=[];
    $s->draft['turn']='player';$s->draft['round_first_player']='player';
    check($p->confirmGlimpse('player',0,[1])->success,'tail pick and burn');
    check($s->draft['turn']==='player','tail overrides packet first player');
    check(totalG($s)===60,'tail conserves cards');
    $command=new Command('draft_confirm',['pick'=>0,'positions'=>[],'decision_id'=>$s->draft['decision_id'],'expected_version'=>$s->version]);
    check((new PrepareProcessor($s,$db))->draftConfirm('player',$command)->success && $s->status==='view','manual tail reaches 30/30');


    $setup=\Berserk\Lobby\RoomSetup::parse('glimpse',['boosters'=>'15','draft_burn_mode'=>'progressive','draft_burn_count'=>'3']);
    check($setup['settings']['draft']['type']==='glimpse' && $setup['settings']['draft']['burn_count']===3,'room settings');
    $settings=GameSettings::fromArray($setup['settings']);check($settings->validateDraftSettings()===null,'settings roundtrip');
    foreach (['4','16'] as $n) {
        try { \Berserk\Lobby\RoomSetup::parse('glimpse',['boosters'=>$n]);check(false,'invalid booster accepted'); }
        catch (\DomainException) { check(true,'invalid booster rejected'); }
    }
    $state=freshG($db);$state->settings->draft['timer_mode']='default';DraftTimer::initialize($state,$state->settings,1000);
    $p=new PrepareProcessor($state,$db);$old=$state->draft['decision_id'];
    $result=$p->resolveDraftTimeouts(1032);check($result->success && $state->draft['decision_id']>$old,'timeout automatic action');
    check(totalG($state)===60,'timeout conservation');
    DraftTimer::suspend($state,1033);$deadline=DraftTimer::deadlineAt($state);DraftTimer::resume($state,1043);
    check(DraftTimer::deadlineAt($state)===$deadline+10,'pause clock preserved');
    $state=freshG($db);$allocated=array_merge($state->draft['grid'],$state->draft['pool']);
    $state->draft['grid']=[];$state->draft['pool']=[];$state->draft['recycle']=[];
    $state->draft['picked']=['host'=>array_slice($allocated,0,30),'player'=>array_slice($allocated,30)];
    $p=new DraftProcessor($state,$db);check(!$p->finish('player')->success,'finish wrong turn');
    check($p->finish('host')->success && $state->status==='view','finish at 30');
    check(array_sum(array_column($state->getPlayer('host')->deckCards,'count'))===30,'deck built exactly');
    echo "OK $checks checks\n";
}
