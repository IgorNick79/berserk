<?php
declare(strict_types=1);
define('STAGE4_FIXTURES_ONLY',true);require __DIR__.'/test_instant_stage4.php';
use Berserk\Core\Choice\DiceChoiceChoice;
use Berserk\View\Ui\Panel;
use Berserk\View\Screen\Battle\InstantControl;
$checks=0;
function polishCheck(bool $ok,string $message):void{global$checks;++$checks;if(!$ok)throw new RuntimeException($message);}
foreach(['host','player']as$active){
    $s=fState($active);[$a,$p,$h,$caster,$attacker]=fIds($s);fResult($s);
    $s->battle['strike']['attack_dice']=2;$s->battle['strike']['defend_dice']=5;
    foreach([$a,$p]as$actor){
        $s->battle['pending_dice_choice']=['owner'=>$actor,'card_id'=>$caster];
        $spec=(new DiceChoiceChoice())->spec($s,$actor,[],'?game=1',$actor);
        polishCheck(str_contains($spec->buttons[0]['label_html'],'dice_'.($actor===$a?2:5).'.svg'),'Own die follows acting side');
        polishCheck(str_contains($spec->buttons[2]['label_html'],'dice_'.($actor===$a?5:2).'.svg'),'Enemy die follows opposing side');
        polishCheck($spec->buttons[0]['class']==='catcher-dice catcher-own'&&$spec->buttons[2]['class']==='catcher-dice catcher-enemy'&&$spec->buttons[4]['class']==='catcher-reroll','Semantic colors for Catcher');
    }
    $s=fState($active);$html=s4Render($s,$p);$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);
    polishCheck($xp->query('//h3//span[contains(@class,"instant-control-quota")]')->length===1,'Quota in heading');
    polishCheck(str_contains($html,'reserve-paid')&&!str_contains($html,'−1'),'Paid reserve is a plain yellow button');
    fRevive($s);$html=s4Render($s,$p);polishCheck(str_contains($html,'reserve-free')&&!str_contains($html,'Бесплатно'),'Free reserve is a plain green button');
    $s=fState($active);(new \Berserk\Core\InstantProcessor($s,irEngine()))->openTurnStackWindow($p,['type'=>'manual'],'turn');
    $html=s4Render($s,$p);polishCheck(str_contains($html,'>Инстант</summary>')&&str_contains($html,'data-instant-pass'),'Every open stack has Instant and Pass');
    polishCheck(!str_contains($html,'('.$s->getCard($caster)->row.','.$s->getCard($caster)->col.')'),'Modern instant coordinates');
    polishCheck(str_contains($html,'>Сражение</h3>')&&str_contains($html,'/battle/combat.png'),'Battle heading and icon');
}
echo "Instant UI polish: $checks checks passed\n";
