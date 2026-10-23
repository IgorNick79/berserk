<?php
declare(strict_types=1);

// A process-local Db adapter: no credentials, sockets, SQL server or production game writes.
namespace Berserk\Core {
    final class Db
    {
        public array $cards = [];
        public array $game = [];
        public bool $omitCard = false;
        public bool $failRead = false;
        public bool $failWrite = false;
        private int $affected = 0;
        public function escape(string $value): string { return addslashes($value); }
        public function fetchAll(string $sql): array
        {
            if ($this->failRead) throw new \RuntimeException('fixture read failure');
            if (str_contains($sql, 'FROM elements')) return [['ind' => 1, 'code' => 'forests']];
            if ($sql === 'SELECT ukid FROM cards ORDER BY ukid') return array_map(fn($c) => ['ukid' => $c['ukid']], array_values($this->cards));
            if (preg_match('/ukid IN \(([^)]+)\)/', $sql, $match)) {
                preg_match_all("/'([^']+)'/", $match[1], $ids);
                $cards = array_values(array_intersect_key($this->cards, array_flip($ids[1])));
                if ($this->omitCard) array_pop($cards);
                return $cards;
            }
            throw new \RuntimeException('Unexpected fixture SELECT: ' . $sql);
        }
        public function fetchOne(string $sql): ?array
        {
            if (str_starts_with($sql, 'SELECT game_id FROM battle_history_matches')) return null; // Legacy fixture, no enrollment.
            if (str_starts_with($sql, 'SHOW COLUMNS')) return ['Field' => 'version'];
            if (str_starts_with($sql, 'SELECT * FROM games')) return $this->game ?: null;
            throw new \RuntimeException('Unexpected fixture query');
        }
        public function query(string $sql): bool
        {
            if ($this->failWrite) throw new \RuntimeException('fixture write failure');
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
    use Berserk\Core\Prepare\{DraftDeckBuilder, LuckTestGenerator, PrepareProcessor};
    use Berserk\Lobby\RoomSetup;
    use Berserk\View\{Template};
    use Berserk\View\Screen\{ModeScreen, SettingsScreen};
    Autoloader::register(); Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

    // Capture server diagnostics in this isolated test directory; never change server configuration.
    $diagnosticFile = tempnam(__DIR__, 'lt-');
    if ($diagnosticFile === false) throw new \RuntimeException('Cannot allocate test error log');
    $previousErrorLog = ini_get('error_log');
    ini_set('error_log', $diagnosticFile);
    register_shutdown_function(static function () use ($diagnosticFile, $previousErrorLog): void {
        ini_set('error_log', $previousErrorLog);
        if (is_file($diagnosticFile)) unlink($diagnosticFile);
    });

    $checks = 0;
    function check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new \RuntimeException($message); }
    function settings(array $values = []): GameSettings { return GameSettings::fromArray(['lucktest' => $values]); }
    function card(string $id, int $price = 3, string $type = 'creature', array $prop = []): array
    {
        return ['ukid'=>$id, 'count'=>1, 'price'=>$price, 'health'=>8, 'move'=>2, 'elite'=>true,
            'single'=>true, 'type'=>$type, 'class'=>'Воин', 'strike_weak'=>1, 'strike_medium'=>3,
            'strike_strong'=>5, 'element'=>'forests', 'prop'=>$prop];
    }
    function fixture(array $cards): Db
    {
        $db = new Db();
        foreach ($cards as $c) {
            $c['element_id'] = 1; $c['prop'] = json_encode($c['prop'], JSON_THROW_ON_ERROR);
            $db->cards[$c['ukid']] = $c;
        }
        return $db;
    }
    function fresh(): GameState
    {
        $s = new GameState(123, 11, 22);
        $r = (new PrepareProcessor($s))->chooseMode('host', new Command('choose_mode', ['mode'=>'lucktest']));
        check($r->success && $s->mode==='lucktest' && $s->status==='settings', 'choose LuckTest');
        return $s;
    }
    function total(array $cards): int { return array_sum(array_column($cards,'count')); }
    function cost(array $cards): int { return array_sum(array_map(fn($c)=>$c['price']*$c['count'], $cards)); }

    $default = GameSettings::defaults();
    check($default->validateLuckTestSettings()===null, 'valid defaults');
    check($default->lucktest['balanced']===true && $default->lucktest['quick_game']===true, 'default flags');
    check(GameSettings::fromArray([])->lucktest===$default->lucktest, 'old settings defaults');
    foreach (range(6,14) as $half) check(settings(['average_cost'=>$half/2])->validateLuckTestSettings()===null,'half-step valid');
    foreach ([['card_count'=>2],['card_count'=>11],['card_count'=>3.0],['card_count'=>'3'],['card_count'=>true],
        ['average_cost'=>2.5],['average_cost'=>7.5],['average_cost'=>3.25],['average_cost'=>NAN],['average_cost'=>INF],
        ['average_cost'=>'3.5'],['average_cost'=>true],['balanced'=>'false'],['quick_game'=>0],['balanced'=>null]] as $bad) {
        check(settings($bad)->validateLuckTestSettings()!==null,'reject malformed settings');
    }
    $parsed = GameSettings::luckTestInput($default->lucktest, ['lucktest_card_count'=>'10','lucktest_average_cost'=>'3.5','lucktest_balanced'=>'0','lucktest_quick_game'=>'0']);
    $s = settings($parsed);
    check($s->validateLuckTestSettings()===null && $s->lucktest['balanced']===false && $s->lucktest['quick_game']===false,'HTTP false values');
    check(GameSettings::fromArray(json_decode(json_encode($s->toArray()),true))->lucktest===$s->lucktest,'settings JSON roundtrip');
    foreach (['junk','false',[],2] as $bad) {
        check(settings(GameSettings::luckTestInput($default->lucktest,['lucktest_balanced'=>$bad]))->validateLuckTestSettings()!==null,'no truthy boolean coercion');
    }
    $room = RoomSetup::parse('lucktest', ['lucktest_card_count'=>'3','lucktest_average_cost'=>'6.5','lucktest_balanced'=>'0','lucktest_quick_game'=>'0']);
    check($room['settings']['lucktest']===['card_count'=>3,'average_cost'=>6.5,'balanced'=>false,'quick_game'=>false],'room settings');
    check(RoomSetup::parse('lucktest',[])['settings']['lucktest']['balanced']===true,'room defaults');
    check(RoomSetup::validate($room)===$room && RoomSetup::label('lucktest')==='LuckTest','room roundtrip and label');
    try { RoomSetup::parse('lucktest',['lucktest_card_count'=>'3abc']); check(false,'bad room should throw'); } catch (\DomainException) { check(true,'bad room rejected'); }

    $normal = card('normal');
    $fly = card('fly',3,'fly');
    check(LuckTestGenerator::isSpecial($fly),'fly special');
    foreach (['shot','throw','discharge'] as $type) check(LuckTestGenerator::isSpecial(card($type,3,'creature',['actions'=>[['type'=>$type]]])),'ranged special');
    foreach (['tap','magic','execute'] as $type) check(!LuckTestGenerator::isSpecial(card($type,3,'creature',['actions'=>[['type'=>$type]]])),'other action not special');
    check(LuckTestGenerator::isBalanced([$normal],[$normal]),'neither special accepted');
    check(LuckTestGenerator::isBalanced([$fly],[card('shot',3,'creature',['actions'=>[['type'=>'shot']]]),$normal]),'both special accepted with unequal counts/types');
    check(!LuckTestGenerator::isBalanced([$normal],[$fly]) && !LuckTestGenerator::isBalanced([$fly],[$normal]),'asymmetry rejected both directions');

    $generator = new LuckTestGenerator();
    foreach ([3,10] as $count) {
        foreach ([[$normal],[$fly]] as $catalog) {
            $pair = $generator->generate($catalog,settings(['card_count'=>$count,'average_cost'=>7]));
            foreach (['host','player'] as $owner) check(total($pair[$owner])===$count && $pair[$owner][0]['single']===true,'repeated unique cards permitted; nearest unattainable cost accepted');
            check(LuckTestGenerator::isBalanced($pair['host'],$pair['player']),'single-category catalog balanced');
        }
        $pair = $generator->generate([card('three',3),card('four',4)], settings(['card_count'=>$count,'average_cost'=>3.5]));
        foreach (['host','player'] as $owner) check(abs(cost($pair[$owner])-$count*3.5)<=0.5,'nearest half-budget');
    }
    foreach (range(1,8) as $i) {
        $pair=$generator->generate([$normal,$fly,card('seven',7)],settings());
        check(LuckTestGenerator::isBalanced($pair['host'],$pair['player']),'mixed catalog symmetric');
    }
    // Deterministic stream explicitly produces an asymmetric pair when balancing is disabled.
    $draw = 0;
    $asymmetric = new LuckTestGenerator(static function(int $min,int $max) use (&$draw): int {
        if ($max===0) return 0;
        return (++$draw < 3400) ? $min : $max;
    });
    $pair=$asymmetric->generate([$normal,$fly],settings(['card_count'=>3,'balanced'=>false]));
    check(!LuckTestGenerator::isBalanced($pair['host'],$pair['player']),'disabled balancing accepts asymmetry');
    $variants=[];
    foreach (range(1,10) as $i) {
        $pair=$generator->generate([card('a'),card('b'),card('c')],settings(['card_count'=>3]));
        $variants[json_encode($pair)]=true;
    }
    check(count($variants)>1,'randomness preserved among equal-price squads');
    foreach ([[],[array_replace($normal,['prop'=>null])],[array_replace($normal,['price'=>-1])]] as $catalog) {
        try { $generator->generate($catalog,settings()); check(false,'invalid catalog accepted'); } catch (\RuntimeException) { check(true,'invalid catalog rejected'); }
    }

    $engineState=fresh();
    $engineResult=(new Engine(fixture([$normal])))->apply($engineState,'host',new Command('confirm_settings',['lucktest_quick_game'=>'0']));
    check($engineResult->success && count($engineState->cards)===60 && $engineState->status==='turn' && $engineState->battle===[], 'real Engine route enters manual Turn');
    $legacy=(new GameState(789,11,22))->toArray(); unset($legacy['settings']['lucktest']);
    check(GameState::fromArray($legacy)->settings->lucktest===$default->lucktest,'old saved game compatible');

    foreach ([3,10] as $count) {
        $state=fresh(); $state->nextInstanceId=70; $state->persistenceVersion=9; $state->persistenceJson='persisted';
        $db=fixture([card('only',7,'fly',['actions'=>[['type'=>'shot']], 'deal'=>['recruit_choice'=>['extra_cost'=>['max'=>4]]]])]);
        $result=(new PrepareProcessor($state,$db))->confirmSettings('host',new Command('confirm_settings',[
            'lucktest_card_count'=>(string)$count,'lucktest_average_cost'=>'3.5','lucktest_balanced'=>'0','lucktest_quick_game'=>'0']));
        check($result->success && count($state->cards)===60,'both squads and reserves initialized');
        check($state->status==='turn' && in_array($state->firstPlayer,['host','player'],true) && $state->battle===[],'manual Turn without pending');
        check($state->persistenceVersion===9 && $state->persistenceJson==='persisted','CAS metadata retained');
        check(array_keys($state->cards)===range(70,129),'unique monotonic instance IDs');
        foreach (['host','player'] as $owner) {
            $cards=$state->getCardsInZone($owner,CardInstance::ZONE_SQUAD);
            check(count($cards)===$count && total($state->getPlayer($owner)->deckCards)===30 && count($state->getCardsInZone($owner,CardInstance::ZONE_DECK))===30-$count,'exact squad, reserve and snapshot');
            check($state->getPlayer($owner)->resources===['gold'=>0,'silver'=>0] && $state->getPlayer($owner)->confirmed===[],'no economy or fake confirmations');
            foreach ($cards as $c) check($c->row===null && $c->col===null && $c->slot===0 && $c->single && $c->elite
                && $c->price===7 && $c->type==='fly' && $c->hp===8 && $c->hpMax===8 && $c->move===2 && $c->strikeMedium===3
                && $c->element==='forests' && $c->classes===['Воин'] && isset($c->prop['deal']), 'instance fields/prop intact; no recruitment');
        }
        $restored=GameState::fromArray(json_decode(json_encode($state->toArray()),true));
        check($restored->toArray()===$state->toArray(),'complete state roundtrip');
        $before=$restored->toArray();
        check(!(new PrepareProcessor($restored,$db))->confirmSettings('host',new Command('confirm_settings'))->success && $restored->toArray()===$before,'refresh/repeated launch cannot materialize twice');
        $restored->status='side';
        check((new PrepareProcessor($restored,$db))->chooseSide($restored->firstPlayer,new Command('choose_side',['side'=>1]))->success && $restored->status==='place','LuckTest skips standard Deal');
    }
    foreach (['empty','missing','invalid_prop','read_failure'] as $failure) {
        $state=fresh();$db=fixture([$normal]);
        if ($failure==='empty') $db->cards=[];
        if ($failure==='missing') $db->omitCard=true;
        if ($failure==='invalid_prop') $db->cards['normal']['prop']='broken-json';
        if ($failure==='read_failure') $db->failRead=true;
        $before=$state->toArray();
        $logOffset=strlen(file_get_contents($diagnosticFile));
        $result=(new PrepareProcessor($state,$db))->confirmSettings('host',new Command('confirm_settings'));
        check(!$result->success,'controlled generation failure');
        check($state->toArray()===$before,'failure leaves neither squad/settings/IDs changed');
        $diagnostic=substr(file_get_contents($diagnosticFile),$logOffset);
        check(str_contains($diagnostic,'"event":"lucktest_generation_failed"') && str_contains($diagnostic,'"game_id":123')
            && str_contains($diagnostic,'"exception":"RuntimeException"') && str_contains($diagnostic,'"file":')
            && str_contains($diagnostic,'"line":'), 'internal failure has server diagnostics');
        check($result->error==='Не удалось сформировать оба отряда LuckTest: проверьте каталог карт'
            && !str_contains($result->error,'fixture') && !str_contains($result->error,'.php'), 'player error stays generic');
    }
    $state=fresh();$db=fixture([$normal]);$db->failRead=true;
    $result=(new Engine($db))->apply($state,'host',new Command('confirm_settings'));
    $events=\Berserk\Core\GameLog::read($state->gameId);$last=end($events);
    check(!$result->success && $last['error']===$result->error && !str_contains(json_encode($last),'fixture read failure'), 'GameLog contains only public failure');
    check(str_contains(file_get_contents($diagnosticFile),'fixture read failure'), 'original exception message retained server-side');
    $state=fresh();$db=fixture([$normal]);$before=$state->toArray();
    check(!(new PrepareProcessor($state,$db))->confirmSettings('player',new Command('confirm_settings'))->success && $state->toArray()===$before,'only host may launch');
    check(!(new PrepareProcessor($state,$db))->confirmSettings('host',new Command('confirm_settings',['lucktest_card_count'=>'bad']))->success && $state->toArray()===$before,'invalid command atomic');
    $state->getPlayer('host')->deckCards=[$normal];$before=$state->toArray();
    check(!(new PrepareProcessor($state,$db))->confirmSettings('host',new Command('confirm_settings'))->success && $state->toArray()===$before,'partial legacy/inconsistent generation not duplicated');

    // Two requests read the same persisted version; CAS commits exactly one complete pair.
    $initial=fresh();$db=fixture([$normal,$fly]);
    $db->game=['ind'=>123,'host_id'=>11,'player_id'=>22,'status'=>'settings','version'=>4,'json'=>json_encode($initial->toArray())];
    $repo=new GameRepository($db);$a=$repo->findById(123);$b=$repo->findById(123);
    foreach ([$a,$b] as $s) check((new PrepareProcessor($s,$db))->confirmSettings('host',new Command('confirm_settings'))->success,'parallel candidate preparation');
    check(json_decode($db->game['json'],true)['cards']===[], 'generation alone does not persist anything');
    $repo->save($a);
    $winnerJson=$db->game['json'];$loserMemory=$b->toArray();
    try { $repo->save($b); check(false,'stale save accepted'); } catch (StaleStateException) { check(true,'concurrent second write rejected'); }
    check($db->game['json']===$winnerJson && $b->toArray()===$loserMemory && $b->persistenceVersion===4, 'CAS conflict preserves winner; no in-memory rollback');
    $saved=$repo->findById(123);
    check(count($saved->cards)===60 && $saved->persistenceVersion===5,'exactly one complete persisted pair');
    check(!(new PrepareProcessor($saved,$db))->confirmSettings('host',new Command('confirm_settings'))->success && count($saved->cards)===60,'persisted retry blocked');

    // A failed single UPDATE leaves the durable original, not a half-generated squad.
    $db=fixture([$normal]);
    $db->game=['ind'=>123,'host_id'=>11,'player_id'=>22,'status'=>'settings','version'=>4,'json'=>json_encode($initial->toArray())];
    $repo=new GameRepository($db);$candidate=$repo->findById(123);$durableBefore=$db->game;
    check((new PrepareProcessor($candidate,$db))->confirmSettings('host',new Command('confirm_settings'))->success,'candidate before write failure');
    $db->failWrite=true;
    try { $repo->save($candidate); check(false,'write failure accepted'); } catch (\RuntimeException $e) {
        check($e->getMessage()==='fixture write failure','write failure propagates to caller');
    }
    check($db->game===$durableBefore && $candidate->persistenceVersion===4 && count($candidate->cards)===60,'failed write retains durable original and transient candidate');
    $db->failWrite=false;$fresh=$repo->findById(123);
    check($fresh->cards===[] && (new PrepareProcessor($fresh,$db))->confirmSettings('host',new Command('confirm_settings'))->success,'reload after failed write permits fresh generation');
    $repo->save($fresh);
    check(count($repo->findById(123)->cards)===60,'retry commits one complete pair');

    $state=fresh();$view=(new SettingsScreen())->prepare($state,'host','host',null)['data']['actions_html'];
    check(str_contains($view,'name="lucktest_balanced" value="0"') && str_contains($view,'name="lucktest_balanced" value="1" checked'),'hidden false + checked defaults');
    check(str_contains($view,'name="lucktest_average_cost" value="5" checked'),'integer average selected by default');
    $state->settings=settings(['average_cost'=>3.5]);
    $fractional=(new SettingsScreen())->prepare($state,'host','host',null)['data']['actions_html'];
    check(str_contains($fractional,'name="lucktest_average_cost" value="3.5" checked'),'fractional average restored in UI');
    $state->settings=settings(['balanced'=>false,'quick_game'=>false]);
    $view=(new SettingsScreen())->prepare($state,'host','host',null)['data']['actions_html'];
    check(!str_contains($view,'value="1" checked'),'false UI restored');
    $state->cards=$saved->cards;
    check(str_contains((new SettingsScreen())->prepare($state,'host','host',null)['data']['actions_html'],'Продолжить подготовку'),'legacy settings can resume');
    check((new SettingsScreen())->prepare($state,'player','player',null)['data']['actions_html']==='','legacy peer cannot resume');
    $mode=(new ModeScreen(new Template(__DIR__.'/../templates')))->prepare(new GameState(2,1,2),'host','host',null);
    check(str_contains($mode['data']['content_html'],'value="lucktest"'),'LuckTest selectable');
    $roomState=new GameState(321,11,22);RoomSetup::apply($roomState,$room,fixture([$normal]));
    check(count($roomState->cards)===60 && $roomState->status==='turn' && $roomState->settings->lucktest['quick_game']===false,'room acceptance generates squads and enters Turn');
    foreach (['system'=>'deck','draft'=>'settings','solomon'=>'settings','sealed'=>'settings'] as $mode=>$stage) {
        $s=new GameState(444,1,2);$r=(new Engine())->apply($s,'host',new Command('choose_mode',['mode'=>$mode]));
        check($r->success && $s->status===$stage && $s->cards===[],'existing mode entry unchanged: '.$mode);
        check(RoomSetup::parse($mode,[])['mode']===$mode,'existing room mode: '.$mode);
    }
    echo "LuckTest: {$checks} checks passed (in-memory adapter; no live database).\n";
}
