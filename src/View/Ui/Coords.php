<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
final class Coords
{
    public static function rowLabel(int $row): string { return [1=>'I',2=>'II',3=>'III'][$row]??''; }
    /** Escaped text plus only recognized coordinate tokens, never arbitrary event HTML. */
    public static function text(string $text): string
    {
        $out='';$offset=0;
        preg_match_all('/(Свой|Вражеский) \[([1-3]):([1-5])\]/u',$text,$matches,PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i=>$match) {
            $out.=htmlspecialchars(substr($text,$offset,$match[1]-$offset),ENT_QUOTES,'UTF-8');
            $side=$matches[1][$i][0]==='Свой'?'own':'enemy';
            $label='['.self::rowLabel((int)$matches[2][$i][0]).':'.$matches[3][$i][0].']';
            $out.='<span class="coords coords--'.$side.'"><img class="coords-icon" src="/assets/images/battle/coordinate.svg" width="12" height="12" alt=""><span>'.$label.'</span></span>';
            $offset=$match[1]+strlen($match[0]);
        }
        return $out.htmlspecialchars(substr($text,$offset),ENT_QUOTES,'UTF-8');
    }
    public static function renderResult(mixed $row,mixed $col,string $viewerKey,?string $owner=null): string
    {
        return is_int($row)&&is_int($col)?self::render($row,$col,$viewerKey,$owner):'';
    }
    public static function render(?int $row,?int $col,string $viewerKey,?string $owner=null): string
    {
        $pos=BattlefieldPosition::display($row,$col,$viewerKey);
        if ($pos===null) return '';
        // For a creature use its actual owner, never its current half of the field.
        $side=in_array($owner,['host','player'],true)?($owner===$viewerKey?'own':'enemy'):$pos['side'];
        $label='['.self::rowLabel($pos['row']).':'.$pos['col'].']';
        return '<span class="coords coords--'.$side.'" aria-label="'.($side==='own'?'Своя':'Вражеская').' позиция '.$label.'">'
            .'<img class="coords-icon" src="/assets/images/battle/coordinate.svg" width="12" height="12" alt="">'
            .'<span>'.$label.'</span></span>';
    }
}
