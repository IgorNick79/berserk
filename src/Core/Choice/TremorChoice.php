<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState, Engine, Command, Result, Tremor};
use Berserk\View\Ui\PanelSpec;

final class TremorChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return Tremor::PENDING; }
    public function commandTypes(): array { return ['choose_tremor_cell']; }
    public function spec(GameState $state, string $playerKey, array $cardsInfo, string $baseUrl, string $role): ?PanelSpec
    {
        $p = $state->battle[Tremor::PENDING] ?? null;
        if (!$p) return null;
        $moving = ($p['mode'] ?? '') === 'move';
        $from = $moving ? array_map('intval', explode('_', $p['from'])) : [null,null];
        $title = $moving ? 'Дрожь ' . $p['id'] . ' — ' . \Berserk\View\Ui\BattlefieldPosition::label($from[0],$from[1],$playerKey) : 'Дрожь — выберите клетку';
        if ($p['owner'] !== $playerKey) return new PanelSpec(title:$title, isMine:false, waitText:'Ожидание выбора оппонентом');
        $buttons = []; $stay = null;
        [$oldRow, $oldCol] = $moving ? array_map('intval', explode('_', $p['from'])) : [0,0];
        for ($r=1; $r<=6; $r++) for ($c=1; $c<=5; $c++) {
            if ($moving && max(abs($r-$oldRow),abs($c-$oldCol)) > 1) continue;
            $button = ['label'=>\Berserk\View\Ui\BattlefieldPosition::label($r,$c,$playerKey),
                'url'=>$baseUrl . '&cmd=choose_tremor_cell&marker_id=' . rawurlencode($p['id'])
                    . ($moving ? '&choice_id=' . rawurlencode($p['choice_id']) : '') . "&row=$r&col=$c"];
            if ($moving && $r===$oldRow && $c===$oldCol) {
                $button['label']='Оставить на месте';$button['class']='tremor-stay';$stay=$button;
            } else {
                if($moving){$pos=\Berserk\View\Ui\BattlefieldPosition::display($r,$c,$playerKey);$button['label']=\Berserk\View\Ui\Coords::rowLabel($pos['row']).':'.$pos['col'];$button['class']='tremor-move-'.$pos['side'];}
                $buttons[]=$button;
            }
        }
        if ($stay !== null) $buttons[]=$stay;
        return new PanelSpec(title:$title, buttons:$buttons);
    }
    public function apply(GameState $state, Engine $engine, string $playerKey, Command $cmd): Result
    { return Tremor::choose($state, $engine, $playerKey, $cmd); }
}
