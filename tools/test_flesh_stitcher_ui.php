<?php
require __DIR__ . '/test_flesh_stitcher.php';
use Berserk\Core\{StitchRevival,ZoneManager,EffectiveFeatures};
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
function fsRender($s,string $viewer='host'):array {$_GET=$_SESSION=[];return(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,$viewer,$viewer,null,[],['sel'=>1,'mode'=>'strike'])['data'];}
$s=fsState();fsCheck(fsStart($s)->success,'UI start');
foreach(['card','exile','cell']as$step){$s=fsReload($s);$own=fsRender($s);$other=fsRender($s,'player');$html=$own['info_panel_html'];fsCheck(str_contains($html,$s->battle['pending_revive']['choice_id']),'current token in rendered '.$step);fsCheck(!str_contains($other['info_panel_html'],'cmd=choose_revive_'),'opponent has no action '.$step);if($step!=='cell')fsCheck(str_contains($html,'type="radio"')&&str_contains($html,'name="target_id"'),'native graveyard radio '.$step);else fsCheck(str_contains($html,'coordinate.svg')&&str_contains($html,'choose_revive_cell'),'native coordinate cells');if($step==='card')fsCheck(fsPick($s,2)->success,'UI first');if($step==='exile')fsCheck(fsPick($s,3)->success,'UI second');}
fsCheck(fsCell($s)->success,'UI finish');foreach(['host','player']as$o){$html=json_encode(fsRender(fsReload($s),$o),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);fsCheck(str_contains($html,'17'),'result health visible '.$o);}
$s=fsState();$s->getCard(3)->type='fly';fsCheck(fsStart($s)->success&&fsPick($s,2)->success&&fsPick($s,3)->success&&fsCell($s)->success,'flying sacrifice legal');
$s=fsState();$s->getCard(3)->strikeWeak=0;fsCheck(fsStart($s)->success&&fsPick($s,2)->success&&fsPick($s,3)->success&&fsCell($s)->success&&$s->getCard(2)->strikeWeak===1,'zero weak uses zero X');
$s=fsReady();ZoneManager::addMarker($s,'2_1',['type'=>'bonfire']);$before=$s->getCard(1)->coins;fsCheck(!fsCell($s)->success&&$s->getCard(1)->coins===$before,'late blocking marker rejected');
foreach(['card','exile','cell']as$step){$s=fsState();fsStart($s);if($step!=='card')fsPick($s,2);if($step==='cell')fsPick($s,3);$token=$s->battle['pending_revive']['choice_id'];fsCheck(fsCmd($s,'cancel_pending',['choice_id'=>$token])->success&&!isset($s->battle['pending_revive'])&&$s->getCard(1)->coins===4&&!$s->getCard(1)->closed,'cancel '.$step);}
echo "OK: stitcher UI/stages/extra edge cases ($checks cumulative checks)\n";
