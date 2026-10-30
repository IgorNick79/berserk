<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Auth\Account;

/** Config selects presentation only; callers retain all authorization checks. */
final class GameMenu
{
    public static function visible(array $node, string $screen): bool
    {
        $known=['lobby','preparation','battle','finished'];
        if (!in_array($screen,$known,true)) throw new \InvalidArgumentException('Unknown menu context');
        if (array_diff(array_keys($node),['id','items','position','label','type','requires','allow','deny']) || (array_key_exists('allow',$node) && array_key_exists('deny',$node))) throw new \InvalidArgumentException('Invalid menu filter');
        foreach (['allow','deny'] as $key) if (array_key_exists($key,$node)) {
            if (!is_array($node[$key]) || !array_is_list($node[$key])) throw new \InvalidArgumentException('Invalid menu filter');
            foreach ($node[$key] as $name) if (!is_string($name) || !in_array($name,$known,true)) throw new \InvalidArgumentException('Unknown menu context');
        }
        return (!isset($node['allow']) || in_array($screen,$node['allow'],true)) && (!isset($node['deny']) || !in_array($screen,$node['deny'],true));
    }
    public static function validate(array $config): void
    {
        if (array_keys($config)!==['groups'] || !is_array($config['groups']) || !array_is_list($config['groups'])) throw new \InvalidArgumentException('Invalid menu configuration');
        $ids=[];
        foreach ($config['groups'] as $group) {
            if (!is_array($group) || array_diff(array_keys($group),['id','items','position','allow','deny']) || !is_string($group['id']??null) || !is_array($group['items']??null) || !array_is_list($group['items']) || (array_key_exists('position',$group) && $group['position']!=='bottom')) throw new \InvalidArgumentException('Invalid menu group');
            self::visible($group,'lobby');
            foreach ($group['items'] as $item) {
                if (!is_array($item) || array_diff(array_keys($item),['id','label','type','requires','allow','deny']) || !is_string($item['id']??null) || !is_string($item['label']??null) || $item['label']==='' || !in_array($item['type']??null,['link','toggle','select','action'],true) || (array_key_exists('requires',$item) && !in_array($item['requires'],['authenticated','guest','preparation_debug','battle_debug'],true)) || isset($ids[$item['id']])) throw new \InvalidArgumentException('Invalid menu item');
                $ids[$item['id']]=true;self::visible($item,'lobby');
            }
        }
    }
    private static function debug(?\Berserk\Core\GameState $state, string $key, string $csrf): string
    {
        if ($state === null || $state->status !== 'settings' || !\Berserk\Auth\DebugAccess::forPlayer($state,$key) || $state->status === 'game_over') return '';
        if ($state->status !== 'settings' || $state->cards !== []) return '<details><summary>Debug</summary><p>Настройка бросков закрыта после запуска подготовки.</p></details>';
        if ($key !== 'host') return '<details><summary>Debug</summary><p>URL-броски ' . (($state->lucktestDebug['url_roll_enabled'] ?? false) ? 'разрешены' : 'выключены') . '. Разрешение задаёт создатель игры.</p></details>';
        return '<details><summary>Debug</summary><form method="post"><input type="hidden" name="game" value="'.$state->gameId.'">'
            .'<input type="hidden" name="cmd" value="set_lucktest_debug"><input type="hidden" name="csrf" value="'.htmlspecialchars($csrf,ENT_QUOTES).'">'
            .'<input type="hidden" name="prepare_version" value="'.(int)$state->persistenceVersion.'">'
            .LuckTestDebugFields::toggle(($state->lucktestDebug['url_roll_enabled'] ?? false) === true)
            .'<button type="submit">Сохранить Debug</button></form></details>';
    }

    public static function render(string $screen, ?Account $account, string $csrf, ?\Berserk\Core\GameState $state=null, string $key='', string $battleDebug='', ?array $configuration=null): string
    {
        $e=static fn(string $v): string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $config=$configuration??json_decode(file_get_contents(dirname(__DIR__,3).'/config/game-menu.json'),true,512,JSON_THROW_ON_ERROR);
        self::validate($config);self::visible([],$screen);
        $prep=$screen==='preparation'?self::debug($state,$key,$csrf):'';
        $permissions=['authenticated'=>$account!==null,'guest'=>$account===null,'preparation_debug'=>$prep!=='','battle_debug'=>$battleDebug!==''];
        $boot=['authenticated'=>$account!==null,'game'=>$state?->gameId,'playmats'=>\Berserk\Core\PlaymatCatalog::ITEMS,'themes'=>PanelTheme::ITEMS];
        $html='<link rel="stylesheet" href="/assets/css/game-menu.css?v=1"><aside class="unified-menu" data-unified-menu data-config="'.$e(json_encode($boot,JSON_THROW_ON_ERROR)).'">'
            .'<button class="unified-menu-toggle" type="button" aria-expanded="false" aria-controls="unified-menu-panel" aria-label="Меню игры"><span class="unified-menu-icon" aria-hidden="true"><i></i><i></i><i></i></span></button>'
            .'<nav id="unified-menu-panel" class="unified-menu-panel" aria-label="Меню игры" inert>';
        foreach ($config['groups'] as $group) {
            if (!self::visible($group,$screen)) continue;
            $items='';
            foreach ($group['items'] as $item) {
                if (!self::visible($item,$screen)) continue;
                if (array_key_exists('requires',$item) && !($permissions[$item['requires']]??false)) continue;
                $id=$item['id'];$label=$e($item['label']);
                if ($id==='debug_preparation') {$items.=str_replace('<summary>Debug</summary>','<summary>'.$label.'</summary>',$prep);continue;}
                if ($id==='debug' && $battleDebug==='') continue;
                if ($id==='debug') {$items.='<button type="button" data-menu-debug>'.$label.'</button>';continue;}
                if ($id==='logout') {$items.='<form method="post" action="/auth.php?page=logout"><input type="hidden" name="csrf" value="'.$e($csrf).'"><button type="submit">'.$label.'</button></form>';continue;}
                if ($item['type']==='link') {
                    $urls=['lobby'=>'/lobby.php','profile'=>'/auth.php?page=account','login'=>'/auth.php?page=login','register'=>'/auth.php?page=register'];
                    $items.='<a href="'.$e($urls[$id]).'">'.$label.'</a>';continue;
                }
                $items.='<button type="button" data-preference="'.$e($id).'"'.($item['type']==='toggle'?' aria-pressed="false"':'').' disabled>'.$label.'<span data-preference-value="'.$e($id).'"></span></button>';
            }
            if ($items==='') continue;
            $bottom=($group['position']??'')==='bottom';
            $html.='<section class="unified-menu-group'.($bottom?' unified-menu-account':'').'" data-menu-group="'.$e($group['id']).'">'
                .($bottom&&$account?'<strong>♙ '.$e($account->login).'</strong>':'').$items.'</section>';
        }
        return $html.'<p data-menu-notice role="status"></p></nav></aside><script src="/assets/js/room-sound.js?v=1" defer></script><script src="/assets/js/unified-menu.js?v=1" defer></script>';
    }
}
