<?php
declare(strict_types=1);
namespace Berserk\Core {
    // Test-only catalog adapter: no connection, credentials or writes.
    final class Db {
        public function fetchAll(string $sql): array {
            if (str_contains($sql, 'FROM decks')) return [['ind'=>1,'name'=>'Системная тестовая колода','image'=>'']];
            if (str_contains($sql, 'FROM cards')) return [['ukid'=>'system-visible','name'=>'Системная карта','price'=>2,'health'=>6,'move'=>1,'elite'=>0,'single'=>0,'strike_weak'=>1,'strike_medium'=>2,'strike_strong'=>3,'prop'=>'{}','type'=>'creature','class'=>'','descr'=>'<p>system description</p>','rarity'=>'rare','element_code'=>'dark','element_name'=>'Тьма']];
            throw new \RuntimeException('Unexpected fixture query');
        }
        public function fetchOne(string $sql): ?array { return ['ind'=>1,'name'=>'Системная тестовая колода','cards'=>json_encode([['ukid'=>'system-visible','count'=>30]])]; }
        public function escape(string $value): string {return addslashes($value);}
    }
}
namespace {
    require_once __DIR__ . '/../src/Core/Autoloader.php';
    use Berserk\Core\{Autoloader, CardInstance, Db, DeckView, GameState, GameSettings};
    use Berserk\Core\Prepare\DraftTimer;
    use Berserk\View\Template;
    use Berserk\View\Ui\PreGameLayout;
    use Berserk\View\Screen\{ModeScreen, SettingsScreen, DeckScreen, ViewScreen, DraftScreen, TurnScreen, SideScreen, DealScreen, PlaceScreen};
    Autoloader::register(); Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
    $checks = 0;
    function integrationAssert(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new \RuntimeException($message); }
    function integrationCard(GameState $state, string $owner, string $zone, string $ukid, array &$catalog, ?int $row = null): CardInstance {
        $id=$state->nextInstanceId();
        $card=new CardInstance(instanceId:$id, ukid:$ukid, owner:$owner, zone:$zone, row:$row, col:$row===null?null:1, hp:1, hpMax:99, price:2, element:'dark', moveMax:99, strikeWeak:99);
        $state->cards[$id]=$card;
        $catalog[$ukid]=['name'=>$ukid, 'health'=>5, 'move'=>2,'price'=>2,'strike'=>['weak'=>1,'medium'=>2,'strong'=>3], 'element'=>'Тьма','element_code'=>'dark','element_label'=>'Тьма','rarity'=>'rare','descr'=>str_repeat('<p>Описание карты. Длинный текст для проверки независимой прокрутки.</p>',90)];
        return $card;
    }
    /** Execute the actual production page-assembly block without bootstrapping DB/commands. */
    function integrationPage(Template $tpl, GameState $state, string $role, string $screenName, array $screenData, string $scriptsHtml = ''): string {
        $source = file_get_contents(__DIR__ . '/../www/index.php');
        $marker = '$isPreGame = \Berserk\View\Ui\PreGameLayout::supports($screenName);';
        $start = strpos($source, $marker);
        if ($start === false) throw new \RuntimeException('Production rendering block not found');
        $render = str_replace('__DIR__', var_export(dirname(__DIR__) . '/www', true), substr($source, $start));
        ob_start();
        try { eval($render); return (string) ob_get_contents(); }
        finally { ob_end_clean(); }
    }
    function integrationFixture(string $stage, string $role, string $variant = '', ?array $request = null): array {
        global $tpl;
        $_GET=[]; $catalog=[]; $state=new GameState(970001,1,2); $state->status=$stage; $state->mode='draft';
        $state->getPlayer($role)->deckId=0; $state->getPlayer($role)->resources=['gold'=>30,'silver'=>30];
        $state->firstPlayer='host'; $state->getPlayer('host')->dice=6; $state->getPlayer('player')->dice=1;
        $opponent=$role==='host'?'player':'host';
        integrationCard($state,$opponent,'deck','opponent-hidden',$catalog);
        $selected=null;
        if ($stage==='draft') {
            $solomon=str_contains($variant,'solomon');
            $state->settings->draft['type']=$solomon?'solomon':'grid';
            if (str_contains($variant,'discrete')) $state->settings->draft['grid_mode']='discrete';
            $state->settings->draft['timer_mode']=str_contains($variant,'unlimited')?'unlimited':'default';
            $state->draft=['turn'=>str_contains($variant,'player-active')?'player':'host','grid'=>['grid-visible-0','grid-visible-1','grid-visible-2','grid-visible-3','grid-visible-4','grid-visible-5','grid-visible-6','grid-visible-7'], 'pool'=>['future-hidden'], 'picked'=>['host'=>['host-picked'],'player'=>['player-picked']], 'history'=>[['player'=>'host','cards'=>['host-picked']],['player'=>'player','cards'=>['player-picked']]], 'recycle'=>[], 'decision_id'=>1];
            if (!$solomon) $state->draft['grid'][]='grid-visible-8';
            foreach(array_merge($state->draft['grid'],['future-hidden','host-picked','player-picked']) as $ukid) $catalog[$ukid]=['name'=>$ukid,'health'=>5,'move'=>2,'strike'=>['weak'=>1,'medium'=>2,'strong'=>3],'rarity'=>'common','element_code'=>'dark','element_label'=>'Тьма','descr'=>str_repeat('<p>Описание карты Draft.</p>',100)];
            $state->version=7;
            DraftTimer::initialize($state,$state->settings,time());
            // initialize starts a new history; these allocations model an already running draft.
            $state->draft['history'] = str_contains($variant, 'empty-history') ? [] : [['player'=>'host','cards'=>['host-picked']],['player'=>'player','cards'=>['player-picked']]];
            if (str_contains($variant,'partial')) $state->draft['grid']=array_merge(array_fill(0,6,null),array_slice($state->draft['grid'],6));
            if (str_contains($variant,'finish')) $state->draft['picked']=['host'=>array_fill(0,30,'host-picked'),'player'=>array_fill(0,30,'player-picked')];
            $_GET=['card'=>'0','card_ukid'=>'grid-visible-0','card_version'=>str_contains($variant,'stale')?'6':'7'];
            if (str_contains($variant,'wrong-identity')) $_GET['card_ukid']='future-hidden';
            if ($request !== null) $_GET = $request;
            $result=(new DraftScreen($tpl))->prepare($state,$role,$role,null,$catalog);
        } elseif ($stage==='view') {
            if ($variant==='system') {
                $state->getPlayer($role)->deckId=1;
                $_GET=['card'=>'system-visible'];
            } elseif ($variant!=='empty') {
                for($i=0;$i<32;$i++) {
                    $entry=integrationCard($state,$role,'deck',$role.'-deck-'.$i,$catalog);
                    if ($variant === 'filters') {
                        $entry->elite = $i % 4 < 2;
                        $entry->element = $i % 2 === 0 ? 'plains' : 'dark';
                        $catalog[$entry->ukid]['elite'] = $entry->elite;
                        $catalog[$entry->ukid]['element_code'] = $entry->element;
                        $catalog[$entry->ukid]['element'] = $catalog[$entry->ukid]['element_label'] = $entry->element === 'plains' ? 'Степи' : 'Тьма';
                    }
                    $selected ??= $entry;
                }
                $side=integrationCard($state,$role,'sideboard',$role.'-sideboard',$catalog);
                if ($variant === 'filters') {
                    $side->elite = true; $side->element = 'forests';
                    $catalog[$side->ukid]['elite'] = true;
                    $catalog[$side->ukid]['element_code'] = 'forests';
                    $catalog[$side->ukid]['element'] = $catalog[$side->ukid]['element_label'] = 'Леса';
                }

                $_GET=['card'=> $variant==='sideboard'?'sideboard:'.$side->instanceId:'deck:'.$selected->instanceId];
                if($variant==='denied') $_GET=['card'=>'deck:1'];
            }
            if($variant==='waiting') $state->getPlayer($role)->confirmed['view']=true;
            if ($request !== null) $_GET = $request;
            $result=(new ViewScreen(new DeckView(new Db()),$tpl))->prepare($state,$role,$role,null,$catalog);
        } elseif ($stage==='deal') {
            $selected=integrationCard($state,$role,'hand',$role.'-hand',$catalog);
            integrationCard($state,$role,'squad',$role.'-squad',$catalog);
            for($i=0;$i<28;$i++) integrationCard($state,$role,'hand',$role.'-hand-extra-'.$i,$catalog);
            if ($variant === 'mixed') {
                integrationCard($state,$role,'hand',$role.'-squad',$catalog);
                foreach ($state->getCardsInZone($role, 'squad') as $card) $card->type = 'fly';
                $selected->elite = true; $selected->element = 'plains';
                $catalog[$selected->ukid]['elite'] = true;
                $catalog[$selected->ukid]['element_code'] = 'plains';
                $catalog[$selected->ukid]['element'] = $catalog[$selected->ukid]['element_label'] = 'Степи';
            }
            if ($variant === 'flying-hand') $selected->type = 'fly';
            if ($variant === 'penalty') {
                $other = integrationCard($state,$role,'squad',$role.'-forest-squad',$catalog);
                $other->element = 'forests'; $other->type = 'fly';
                $catalog[$other->ukid]['element_code'] = 'forests';
                $catalog[$other->ukid]['element'] = $catalog[$other->ukid]['element_label'] = 'Леса';
            }
            if ($variant === 'empty-squad') foreach ($state->getCardsInZone($role,'squad') as $card) $card->zone = 'hand';
            if ($variant === 'exhausted') $state->getPlayer($role)->reshuffles = 3;
            if ($variant === 'pending') $state->battle['pending_deal_variable_recruit'] = [
                'owner' => $role, 'card_id' => $selected->instanceId, 'min_x' => 0, 'max_x' => 4, 'resource' => 'silver', 'health' => 2, 'attack_per_x' => 1];
            if($variant==='waiting') $state->getPlayer($role)->confirmed['deal']=true;
            $_GET=['card'=>$variant==='denied'?'hand:opponent-hidden':'hand:'.$selected->ukid];
            if ($variant === 'selected') $_GET['card'] = 'squad:' . $role . '-squad';
            if ($request !== null) $_GET = $request;
            $result=(new DealScreen($tpl))->prepare($state,$role,$role,null,$catalog);
        } elseif ($stage==='place') {
            $selected=integrationCard($state,$role,'squad',$role.'-unplaced',$catalog);
            $placed=integrationCard($state,$role,'field',$role.'-placed',$catalog,$role==='host'?1:6);
            if ($variant === 'many') for ($i=0;$i<14;$i++) integrationCard($state,$role,'squad',$role.'-extra-'.$i,$catalog);
            if ($variant === 'complete') { $selected->zone = 'field'; $selected->row = $role==='host'?3:4; $selected->col = 2; }
            if($variant==='waiting') $state->getPlayer($role)->confirmed['place']=true;
            $_GET=['card'=>$variant==='denied'?'1':(string)($variant==='placed'?$placed->instanceId:$selected->instanceId)];
            if (in_array($variant,['list','many','complete'],true)) $_GET['card'] = '0';
            if ($request !== null) $_GET = $request;
            $result=(new PlaceScreen($tpl))->prepare($state,$role,$role,null,$catalog);
        } elseif (in_array($stage, ['turn', 'side'], true)) {
            if ($variant === 'system') {
                $state->getPlayer($role)->deckId = 1;
            } else {
                for ($i = 0; $i < 32; $i++) integrationCard($state, $role, 'deck', $role . '-deck-' . $i, $catalog);
                integrationCard($state, $role, 'sideboard', $role . '-sideboard', $catalog);
            }
            if ($variant === 'waiting') $state->getPlayer($role)->confirmed['turn'] = true;
            if ($variant === 'chooser') $state->firstPlayer = $role;
            if ($request !== null) $_GET = $request;
            $deckData = (new ViewScreen(new DeckView(new Db()), $tpl))->readOnlyDeck($state, $role, $role, null, $catalog);
            $screen = $stage === 'turn' ? new TurnScreen() : new SideScreen();
            $result = $screen->prepare($state, $role, $role, null, $deckData);
        } else {
            if($variant==='solomon') $state->settings->draft['type']='solomon';
            if($variant==='sealed') $state->mode='sealed';
            if($variant==='system') $state->mode='system';
            if($variant==='waiting') $state->getPlayer($role)->confirmed['turn']=true;
            $screen=match($stage) {'mode'=>new ModeScreen($tpl),'settings'=>new SettingsScreen(),'deck'=>new DeckScreen(new DeckView(new Db()),$tpl),'turn'=>new TurnScreen(),'side'=>new SideScreen()};
            $result=$screen->prepare($state,$role,$role,null);
        }
        if ($stage === 'draft' && str_contains($variant, 'solomon')) {
            integrationAssert(str_contains($result['data']['panel_title'], '#1 · '), 'Existing decision number in title');
            integrationAssert(str_starts_with($result['data']['panel_phase'], 'Соломон драфт · Пул: '), 'Solomon phase includes pool');
            integrationAssert(str_contains($result['data']['panel_actions_html'], 'id="solomon-decision"') === ($state->draft['turn'] === $role), 'Panel controls restricted to active player');
        }
        $shell=(new PreGameLayout($tpl))->renderScreen($result['screen'],$result['data']);
        $dom=new \DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">'.$shell); $xp=new \DOMXPath($dom);
        integrationAssert($xp->query('//aside[contains(@class,"pregame-info-panel")]')->length===1,'One panel '.$stage);
        integrationAssert($xp->query('//main//h1')->length===0,'No duplicate stage heading '.$stage);
        $solomonPanel = $stage === 'draft' && $state->settings->isSolomonDraft();
        integrationAssert($xp->query('//aside//form')->length === (($stage === 'draft' && $state->draft['turn'] === $role) || ($stage === 'side' && $state->firstPlayer === $role) || ($stage === 'deal' && $variant === 'pending') ? 1 : 0), 'Draft decisions in footer only');
        integrationAssert($xp->query('//aside//*[contains(@class,"pregame-panel-content")]//a[contains(@href,"cmd=")][not(ancestor::*[@data-deal-actions])][not(ancestor::*[@data-view-card-actions])][not(ancestor::*[contains(@class,"pregame-place-actions")])][not(ancestor::*[contains(@class,"card-choice")])]')->length===0,'No command links in information');
        if ($stage === 'place') {
            integrationAssert($xp->query('//main//*[contains(@class,"place-squad")]|//main//*[contains(@class,"prepare-bottom-bar")]')->length === 0, 'Placement list and footer leave Main');
            integrationAssert($xp->query('//main//*[contains(@class,"pregame-place-row-number")]')->length === 3, 'Three viewer-relative row labels');
            integrationAssert($xp->query('//main//*[contains(@class,"pregame-place-columns")]/span')->length === 10, 'Column labels above and below');
            integrationAssert($xp->query('//main//*[@data-place-row and @data-place-col]')->length === 15, 'Placement keeps 15 system-coordinate cells');
            integrationAssert($xp->query('//main//a[contains(@href,"cmd=unplace_card")]|//main//a[contains(@href,"cmd=confirm_place")]')->length === 0, 'Placement actions in InfoPanel');
            integrationAssert($xp->query('//aside//*[@data-place-close]')->length === ($result['data']['place_view'] === 'card' ? 1 : 0), 'Close switches card details back to placement list');
        }
        if ($stage === 'deal') {
            integrationAssert($xp->query('//main//*[@data-deal-pager]')->length === 1, 'Deal uses the shared card pager');
            integrationAssert($xp->query('//main//*[contains(@class,"prepare-bottom-bar")]|//main//h2')->length === 0, 'Deal has no bottom bar or separate squad section');
            integrationAssert($xp->query('//main//a[contains(@href,"cmd=")][not(@data-deal-quick)]')->length === 0, 'Only explicit quick-recruit controls can issue commands in Main');
            integrationAssert($xp->query('//footer//*[@data-deal-selected]')->length === 1, 'Selected count in footer');
            integrationAssert($xp->query('//main//*[@data-deal-quick]')->length === (in_array($variant, ['waiting','pending'], true) ? 0 : 29 + ($variant === 'mixed' || $variant === 'empty-squad' ? 1 : 0)), 'Quick recruitment only for available hand cards');

            integrationAssert($xp->query('//aside//button[contains(@class,"pregame-pool-stat")]')->length === (in_array($variant, ['mixed','flying-hand','penalty'], true) ? 9 : 8), 'Flying budget appears only when flying cards are available');
            integrationAssert($xp->query('//footer//a[contains(@href,"cmd=confirm_deal")]')->length === (in_array($variant, ['waiting','pending'], true) ? 0 : 1), 'Deal confirmation in footer');
        }
        if (in_array($stage, ['turn', 'side'], true)) {
            integrationAssert($xp->query('//main//*[@data-view-pager]')->length === 1, 'Shared deck pager on ' . $stage);
            integrationAssert($xp->query('//main//a[contains(@href,"cmd=")]')->length === 0, 'Read-only deck has no commands on ' . $stage);
            integrationAssert($xp->query('//main//*[contains(@class,"dice-block")]|//main//*[@name="side"]')->length === 0, 'Decision UI only in InfoPanel');
            integrationAssert($xp->query('//aside//button[contains(@class,"pregame-pool-stat")]')->length === 8, 'Deck statistics on ' . $stage);
            if ($stage === 'turn') {
                integrationAssert($xp->query('//aside//*[contains(@class,"dice-column")]')->length === 2, 'Both dice in InfoPanel');
                integrationAssert($xp->query('//footer//a[contains(@href,"cmd=confirm_turn")]')->length === ($variant === 'waiting' ? 0 : 1), 'Continue in Turn footer');
            } else {
                integrationAssert($xp->query('//aside//input[@name="side"]')->length === ($state->firstPlayer === $role ? 2 : 0), 'Only chooser has side options');
                integrationAssert($xp->query('//footer//button[@form="pregame-side-choice"]')->length === ($state->firstPlayer === $role ? 1 : 0), 'Side continue in footer');
            }
        }
        if ($stage === 'view') {
            integrationAssert($xp->query('//main//*[contains(@class,"prepare-bottom-bar")]')->length === 0, 'View has no Main bottom bar');
            integrationAssert($xp->query('//main//*[@data-view-pagination]')->length === 1, 'View page navigation');
            integrationAssert($xp->query('//main//a[contains(@href,"cmd=confirm_view")]')->length === 0, 'View confirm is not in Main');
            integrationAssert($xp->query('//main//a[contains(@href,"cmd=view_to_")]')->length === 0, 'View card actions are not in Main');
            integrationAssert($xp->query('//aside//*[@data-view-card-actions]//a')->length === (in_array($variant,['empty','waiting','denied','system'],true) ? 0 : 1), 'View moves belong to card actions');
            if ($variant !== 'empty') {
                integrationAssert($xp->query('//aside//button[contains(@class,"pregame-pool-stat")]')->length === 8, 'View currency and element hints');
                integrationAssert($xp->query('//footer//a[contains(@href,"cmd=confirm_view")]')->length === ($variant === 'waiting' ? 0 : 1), 'View confirmation in footer');
            }
        }
        if ($solomonPanel) {
            integrationAssert($xp->query('//main//*[@class="draft-info"]|//main//*[@class="prepare-bottom-bar"]')->length === 0, 'No obsolete Main summary');
            integrationAssert($xp->query('//aside//button[contains(@class,"pregame-pool-stat")]')->length === 8, 'Six elements and two currencies');
            integrationAssert($xp->query('//footer//*[@data-solomon-history]')->length === 1, 'History icon in footer');
            integrationAssert($xp->query('//footer//*[@data-solomon-panel-action]')->length === 0, 'Small actions are not in footer');
            integrationAssert($xp->query('//footer//*[@data-solomon-selected]')->length === ($state->draft['turn'] === $role ? 1 : 0), 'Footer selection count only for active player');
        }
        if ($stage === 'draft' && !$solomonPanel) {
            $active = $state->draft['turn'] === $role;
            integrationAssert($xp->query('//main//*[@class="pregame-grid-row-number"]')->length === 3, 'Grid row coordinates');
            integrationAssert($xp->query('//main//*[@class="pregame-grid-columns"]/span')->length === 6, 'Grid column coordinates above and below');
            integrationAssert($xp->query('//main//*[@data-command="finish_draft"]')->length === 0, 'Finish is outside the mat');
            integrationAssert($xp->query('//aside//a[@data-grid-finish and contains(@onclick,"window.confirm")]')->length === ($active && str_contains($variant,'finish') ? 1 : 0), 'Finish uses native confirmation directly');
            integrationAssert($xp->query('//aside//*[@class="pregame-grid-finish"]')->length === ($active && str_contains($variant,'finish') ? 1 : 0), 'Wide finish appears only when allowed');
            integrationAssert($xp->query('//main//*[@data-grid-position]')->length === 9, 'Grid keeps nine cells');
            integrationAssert($xp->query('//main//a[contains(@href,"cmd=")]')->length === 0, 'Grid controls do not submit commands');
            integrationAssert($xp->query('//main//*[@data-grid-pick and @data-axis]')->length === ($active ? 6 : 0), 'Only active player selects rows/columns');
            integrationAssert($xp->query('//footer//button[@data-grid-confirm and @disabled]')->length === ($active ? 1 : 0), 'Grid confirmation starts disabled');
            integrationAssert($xp->query('//footer//*[@data-grid-history]')->length === 1, 'Grid history icon in footer');
            integrationAssert($xp->query('//aside//button[contains(@class,"pregame-pool-stat")]')->length === 8, 'Grid statistics use currency and element hints');
            integrationAssert($xp->query('//main//*[@class="draft-info"]|//main//*[@class="prepare-bottom-bar"]')->length === 0, 'Grid summary moved to panel');
            integrationAssert(str_contains($result['data']['panel_phase'], 'Grid Draft · Пул:'), 'Grid subtitle in panel');
            integrationAssert(!str_contains($result['data']['history_html'], '<details'), 'Grid history is panel content');
            integrationAssert(str_contains($result['data']['draft_script_html'],'draft_timer.js'), 'Grid polling works even without a timer limit');
        }
        integrationAssert(!str_contains($shell,'opponent-hidden') && !str_contains($shell,'future-hidden'),'No hidden cards in HTML '.$stage);
        integrationAssert(!str_contains($shell,'battle-sync.js'),'No battle JS');
        if($stage==='draft') {
            integrationAssert($xp->query('//header//*[@data-draft-timer]')->length===1 || str_contains($variant,'unlimited'),'Timer in header');
            integrationAssert($xp->query('//*[@data-draft-timer]')->length<=1,'One timer');
            integrationAssert(str_contains($result['data']['draft_script_html'],'draft_timer.js') || str_contains($variant,'unlimited'),'Polling preserved');
            if(str_contains($variant,'stale') || str_contains($variant,'wrong-identity')) integrationAssert($result['data']['panel_html']==='','Stale preview rejected');
            if(str_contains($variant,'solomon') && $role !== $state->draft['turn']) integrationAssert(!str_contains($shell,'id="solomon-decision"') && str_contains($shell,'data-solomon-preview'),'Waiting preview without form');
        }
        if(in_array($variant,['denied','empty'],true)) integrationAssert($stage === 'place' ? str_contains($result['data']['panel_html'],'pregame-place-list') : ($result['data']['panel_html']??'') === '', 'Denied/empty selection returns safe content');
        if($selected && ($stage !== 'place' || $result['data']['place_view'] === 'card') && !in_array($variant,['denied','empty','pending','selected'],true) && ($request === null || ($result['data']['panel_html'] ?? '') !== '')) {
            integrationAssert(str_contains($result['data']['panel_html'],'aria-label="Здоровье: 5"'),'Panel uses static catalog HP');
            integrationAssert(!str_contains($result['data']['panel_html'],'aria-label="Здоровье: 99"'),'No runtime battle stats');
        }
        if(in_array($stage,['view','deal','place'],true) && $variant==='waiting') integrationAssert($xp->query('//main//a[contains(@href,"cmd=")]')->length===0,'No actions after confirmation');
        if($stage==='view' && $variant==='system') integrationAssert(str_contains($result['data']['panel_html'],'system description'),'System catalog fallback');
        $page = integrationPage($tpl, $state, $role, $result['screen'], $result['data']);
        $expectedKey = hash('sha256', hash_file('sha256', __DIR__ . '/../www/assets/css/global.css')
            . hash_file('sha256', __DIR__ . '/../www/assets/css/pregame.css'));
        $pageDom = new \DOMDocument(); @$pageDom->loadHTML('<?xml encoding="UTF-8">' . $page);
        $pageXp = new \DOMXPath($pageDom);
        $links = $pageXp->query('//link[contains(@href,"/assets/css/pregame.css")]');
        integrationAssert($links->length === 1, 'One PreGame stylesheet in actual page HTML');
        $href = $links->item(0)->getAttribute('href');
        integrationAssert($href === '/assets/css/pregame.css?v=' . $expectedKey, 'Actual CSS version matches resource contents');
        integrationAssert(!preg_match('/[{}]|%7b|%7d|rkey/i', $href), 'No raw/encoded/unresolved CSS placeholders');
        integrationAssert($page === integrationPage($tpl, $state, $role, $result['screen'], $result['data']), 'Stable page HTML across renders');
        return ['html'=>$page, 'stage'=>$stage,'role'=>$role,'variant'=>$variant];
    }
    $tpl=new Template(__DIR__.'/../templates'); $fixtures=[];
    if (in_array('--request', $argv, true)) {
        echo integrationFixture($argv[2], $argv[3], $argv[4], json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR))['html'];
        exit;
    }
    foreach(['mode','settings','deck','view','turn','side','deal','place','draft'] as $stage) foreach(['host','player'] as $role) $fixtures[$stage.'-'.$role]=integrationFixture($stage,$role);
    foreach(['solomon','sealed','system'] as $variant) foreach(['host','player'] as $role) $fixtures['settings-'.$variant.'-'.$role]=integrationFixture('settings',$role,$variant);
    foreach(['sideboard','waiting','empty','denied','system','filters'] as $variant) $fixtures['view-'.$variant]=integrationFixture('view','host',$variant);
    foreach(['waiting','denied'] as $variant) foreach(['deal','place'] as $stage) $fixtures[$stage.'-'.$variant]=integrationFixture($stage,'host',$variant);
    foreach (['mixed','selected','empty-squad','exhausted','pending','penalty','flying-hand'] as $variant) $fixtures['deal-'.$variant]=integrationFixture('deal','host',$variant);
    $fixtures['place-placed']=integrationFixture('place','host','placed');
    foreach(['list','many','complete'] as $variant) foreach(['host','player'] as $role) $fixtures['place-'.$variant.'-'.$role]=integrationFixture('place',$role,$variant);
    foreach(['solomon','solomon-unlimited','solomon-empty-history','stale','wrong-identity'] as $variant) foreach(['host','player'] as $role) $fixtures['draft-'.$variant.'-'.$role]=integrationFixture('draft',$role,$variant);
    foreach (['grid-unlimited','grid-discrete','grid-partial','grid-finish','grid-empty-history'] as $variant) $fixtures['draft-'.$variant.'-host']=integrationFixture('draft','host',$variant);
    $fixtures['draft-grid-unlimited-player']=integrationFixture('draft','player','grid-unlimited');
    foreach (['host','player'] as $role) $fixtures['draft-grid-player-active-'.$role]=integrationFixture('draft',$role,'grid-player-active');
    $fixtures['draft-solomon-player-active']=integrationFixture('draft','player','solomon-player-active');
    $fixtures['draft-solomon-host-waiting']=integrationFixture('draft','host','solomon-player-active');
    foreach (['turn','side'] as $stage) $fixtures[$stage.'-system']=integrationFixture($stage,'host','system');
    $fixtures['turn-waiting']=integrationFixture('turn','host','waiting');
    $fixtures['side-player-chooser']=integrationFixture('side','player','chooser');
    // Negative integration: production assembly must not add PreGame CSS to any non-PreGame screen.
    foreach (['battle', 'game_over', 'debug', 'wait', 'deck'] as $screen) {
        $state = new GameState(970002, 1, 2);
        $page = integrationPage($tpl, $state, 'host', $screen, [], '<script src="/assets/js/battle-sync.js?v={{rkey}}"></script>');
        integrationAssert(!str_contains($page, 'pregame.css') && !str_contains($page, 'pregame-page'), 'No PreGame CSS on ' . $screen);
        if ($screen === 'battle') {
            integrationAssert(str_contains($page, 'class="battle-page"'), 'Battle page class preserved');
            integrationAssert((bool) preg_match('~/assets/css/global\.css\?v=[0-9]+~', $page), 'Battle time-based rkey fallback preserved');
            integrationAssert((bool) preg_match('~/assets/js/battle-sync\.js\?v=[0-9]+~', $page), 'Battle script version preserved');
        }
    }
    if(in_array('--fixtures',$argv,true)) echo json_encode($fixtures,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    else echo 'PreGame screen integration: '.$checks.' checks passed across '.count($fixtures)." fixtures.\n";
}
