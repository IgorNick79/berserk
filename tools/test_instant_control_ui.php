<?php
declare(strict_types=1);
define('STAGE4_FIXTURES_ONLY',true);require __DIR__.'/test_instant_stage4.php';
use Berserk\Core\{InstantProcessor,InstantReservation as IR};
function uiDom(string $html):DOMXPath {$d=new DOMDocument();@$d->loadHTML('<?xml encoding="UTF-8">'.$html);return new DOMXPath($d);}
foreach(['host','player'] as $active){
    $s=fState($active);[$a,$p,,$caster]=fIds($s);
    $s->getCard($active==='host'?3:4)->prop['instants']=$s->getCard($caster)->prop['instants'];
    foreach([$a,$p] as $viewer){
        $s->battle['turn_timer']['anchor_at']=time();$before=$s->toArray();$html=s4Render($s,$viewer);$x=uiDom($html);
        s4Check($before===$s->toArray(),'Rendering never changes game state');
        s4Check($x->query('//footer//*[@data-instant-control]')->length===0,'No footer instant row');
        s4Check($x->query('//div[@class="instant-control-layer"]/details[@data-instant-control]')->length===1,'Separate overlay layer');
        s4Check($x->query('//details[@data-instant-control and @open]')->length===0,'Ordinary availability never auto-opens');
        s4Check($x->query('//*[@data-instant-pass or @data-instant-local-pass]')->length===0,'No fake pass outside stack');
        s4Check(!str_contains($html,'Мгновенное действие'),'Old heading removed');
        s4Check($viewer===$a?str_contains($html,'>Сыграть</summary>'):str_contains($html,'−1'),'Active play/passive cost');
    }
    fCmd($s,$p,'reserve_instant',['period_id'=>1]);$x=uiDom(s4Render($s,$p));
    s4Check($x->query('//details[@data-instant-reserved="1" and not(@open)]')->length===1,'Reserve marked without auto-open');
    s4Check($x->query('//a[contains(@href,"cancel_instant_reservation")]')->length===1,'Cancel uses native command');
    $s=fState($active);$s->battle[IR::KEY]['used']=2;
    s4Check(!str_contains(s4Render($s,$p),'data-instant-control'),'Exhausted quota hides icon');
    fRevive($s);$html=s4Render($s,$p);
    s4Check(str_contains($html,'Бесплатно')&&!str_contains($html,'data-instant-required="1"'),'Free reserve does not steal pending choice');
    $s=s4Wounds($active);s4Acknowledge($s);$html=s4Render($s,$p);$x=uiDom($html);
    s4Check($x->query('//details[@data-instant-required="1" and @open]')->length===1,'Mandatory priority auto-opens');
    s4Check($x->query('//*[@data-instant-pass]')->length===1,'Exactly one real pass');
    $key=$x->query('//*[@data-instant-key]')->item(0)->getAttribute('data-instant-key');
    s4Check($key===uiDom(s4Render($s,$p))->query('//*[@data-instant-key]')->item(0)->getAttribute('data-instant-key'),'Polling identity stable');
    fCmd($s,$p,'combat_instant_play',['card_id'=>$caster,'instant_key'=>'raneniya']);
    s4Check($key!==uiDom(s4Render($s,$p))->query('//*[@data-instant-key]')->item(0)->getAttribute('data-instant-key'),'Stack mutation changes identity');
    $s->battle['pending_combat_pick']=['owner'=>$p,'card_id'=>$caster,'instant'=>[],'target'=>'ally'];
    $html=s4Render($s,$p);
    s4Check(!str_contains($html,'data-instant-required="1"')&&!str_contains($html,'data-instant-pass'),'Pending choice keeps control');
    $s=fState($active);$s->winner=$a;
    s4Check(!str_contains(s4Render($s,$p),'data-instant-control'),'Finished match hides icon');
}
echo "Floating instant UI: $stage4Checks checks passed\n";
