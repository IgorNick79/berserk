<?php
declare(strict_types=1);
namespace Berserk\View\Screen;

use Berserk\View\Template;
use Berserk\View\Ui\{BattleCardMarkup,Coords};

/** Saved display DTO only. No gameplay components, links, state reconstruction or scripts. */
final class ReplayScreen
{
    public function __construct(private Template $tpl) {}
    private static function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    public function render(array $preview, int $seq): string
    {
        $steps = $preview['steps']; $selected = null;
        foreach ($steps as $step) if ($step['step_seq'] === $seq) $selected = $step;
        if ($selected === null) throw new \DomainException('step_not_found', 404);
        $state = $selected['state']; $game = $preview['metadata']['game_id'];
        $labels = ['initial'=>'Начальная расстановка', 'battle_ready'=>'Стартовые эффекты', 'move'=>'Перемещение',
            'combat'=>'Удар', 'series_end'=>'Завершение серии Гидры', 'turn_end'=>'Завершение хода', 'match_terminal'=>'Завершение боя'];
        $nav = '';
        foreach (['first'=>[1,'Первый'], 'prev'=>[max(1,$seq-1),'Предыдущий'],
            'next'=>[min(count($steps),$seq+1),'Следующий'], 'last'=>[count($steps),'Последний']] as $id=>[$to,$label]) {
            $nav .= '<a id="replay-'.$id.'" href="?game='.$game.'&amp;step='.$to.'">'.$label.'</a> ';
        }
        $options = '';
        foreach ($steps as $step) $options .= '<li><a href="?game='.$game.'&amp;step='.$step['step_seq'].'">'
            .$step['step_seq'].'. '.self::e($labels[$step['step_type']] ?? $step['step_type']).'</a></li>';
        $gaps = '';
        foreach ($preview['gaps'] as $gap) {
            $before = 0; $after = null;
            foreach ($steps as $step) {
                if ($step['last_event_seq'] < $gap['event_seq']) $before = $step['step_seq'];
                elseif ($after === null) $after = $step['step_seq'];
            }
            $gaps .= '<li>recording_gap: после кадра '.$before.', до '.($after ?? 'конца записи')
                .'. '.self::e($gap['detail']['reason'] ?? 'Неподдержанное действие').'</li>';
        }
        $map = []; $zones = [];
        foreach ($state['cards'] as $card) {
            if ($card['zone'] === 'field') $map[$card['row'].'_'.$card['col']][] = $card;
            else $zones[$card['zone']][] = $card;
        }
        $field = '';
        foreach ([6,5,4,3,2,1] as $row) {
            $field .= '<div class="field-row">';
            foreach ([1,2,3,4,5] as $col) {
                $content = '<small>'.$row.':'.$col.'</small>'; $classes = '';
                foreach ($map[$row.'_'.$col] ?? [] as $card) {
                    $content .= $this->card($card,$state); $classes = ($card['owner']==='host'?' own':' enemy').($card['closed']?' closed':'');
                }
                $field .= $this->tpl->parse('includes/battle_cell.tpl',['cl'=>$classes,'content'=>$content]);
            }
            $field .= '</div>';
        }
        $zoneHtml = '';
        foreach ($zones as $zone=>$cards) {
            $zoneHtml .= '<section class="replay-zone"><h3>'.self::e($zone).'</h3><div class="replay-zone-cards">';
            foreach ($cards as $card) $zoneHtml .= '<div class="replay-zone-card'.($card['closed']?' closed':'').'">'.$this->card($card,$state).'</div>';
            $zoneHtml .= '</div></section>';
        }
        return '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Реплей #'.$game.'</title><link rel="stylesheet" href="/assets/css/global.css">'
            .'<link rel="stylesheet" href="/assets/css/replay.css"><main class="replay"><h1>Реплей боя #'.$game.'</h1>'
            .'<p>Участники: host #'.$preview['metadata']['participants']['host'].' / player #'.$preview['metadata']['participants']['player']
            .'. Матч завершён. Победитель: '.self::e(end($steps)['state']['winner'] ?? '—').'</p>'
            .'<aside class="replay-warning">Экспериментальная неполная история (partial/incomplete). Сохранены только отдельные подтверждённые состояния. '
            .'Между кадрами могли происходить незаписанные действия. Пропуски не восстанавливаются.</aside>'
            .($gaps!==''?'<aside class="replay-warning"><strong>Неполные участки записи</strong><ul>'.$gaps.'</ul></aside>':'')
            .'<nav aria-label="Кадры реплея">'.$nav.'</nav><h2 id="replay-step">Кадр '.$seq.' / '.count($steps).' — '
            .self::e($labels[$selected['step_type']] ?? $selected['step_type']).'</h2><p>Ход '.$state['turn'].', активный: '.self::e($state['active']).'</p>'
            .'<div class="replay-field">'.$field.'</div>'.$zoneHtml.'<details><summary>Все сохранённые кадры</summary><ol>'.$options.'</ol></details></main></html>';
    }

    private function card(array $card, array $state): string
    {
        $definition = $state['catalog'][$card['definition'] ?? ''] ?? [];
        $status = [$card['owner'], $card['closed']?'Закрыта':'Открыта'];
        if (!$card['revealed']) $status[] = 'Скрыта в момент записи';
        foreach (['statuses','markers','effects'] as $key) foreach ($card[$key] as $label) $status[] = $label['label'];
        $status[] = 'Ход: '.($card['move'] ?? '?').'/'.($card['move_max'] ?? '?');
        if ($card['strike'] !== null) $status[] = 'Удар: '.implode('/',$card['strike']);
        $name = self::e($definition['name'] ?? 'Неизвестная карта').' #'.self::e($card['instance_id']);
        $badges = '<span class="replay-status">'.self::e(implode(' · ',$status)).'</span>';
        return '<div data-card-id="'.self::e($card['instance_id']).'" data-closed="'.($card['closed']?'true':'false').'">'
            .BattleCardMarkup::render('', $name, ($card['hp'] ?? '?').'/'.($card['hp_max'] ?? '?'), $badges,
                $card['coins'] ? '<div class="bcoins">'.(int)$card['coins'].'</div>' : '',
                $card['armor'] ? '<div class="barmor">'.(int)$card['armor'].'</div>' : '')
            .Coords::render($card['row'],$card['col'],'host').'</div>';
    }
}
