<?php
declare(strict_types=1);
namespace Berserk\Core {
    // Read-only DB double: no credentials, network, or filesystem persistence.
    final class Db {
        public function inTransaction(): bool { return false; }
        public function fetchOne(string $sql): ?array { return str_contains($sql,'FROM battle_history_matches') ? ['last_event_seq'=>1,'recording_status'=>'recording','completeness'=>'incomplete'] : null; }
        public function fetchAll(string $sql): array {
            $p=['card_id'=>1,'from'=>[5,1],'to'=>[6,2],'_journal'=>['cards'=>['1'=>['name'=>'Дверг','owner'=>'player']]]];
            return [['event_seq'=>1,'event_type'=>'move.started','projection_json'=>json_encode($p)]];
        }
    }
}
namespace {
require __DIR__.'/../src/Core/Autoloader.php';
Berserk\Core\Autoloader::register(); Berserk\Core\Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
use Berserk\Core\BattleHistory\{JournalText,HistoryRepository};
use Berserk\View\Ui\{BattlefieldPosition,RussianNumber};
function cosmeticCheck(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
$p=['target_id'=>1,'card_id'=>1,'from'=>[5,1],'to'=>[6,2],'_journal'=>['cards'=>['1'=>['name'=>'Тич']]]];
$before=json_encode($p);
foreach(['host','player'] as $side) {
 $expected='Тич перемещается: '.BattlefieldPosition::label(5,1,$side).' → '.BattlefieldPosition::label(6,2,$side).'.';
 cosmeticCheck(JournalText::format('move.started',$p,$side)===$expected,'shared coordinate formatter '.$side);
}
cosmeticCheck(json_encode($p)===$before,'stored coordinates unchanged');
$forms=[1=>'рану',2=>'раны',3=>'раны',4=>'раны',5=>'ран',10=>'ран',11=>'ран',12=>'ран',13=>'ран',14=>'ран',15=>'ран',20=>'ран',21=>'рану',22=>'раны',23=>'раны',24=>'раны',25=>'ран',101=>'рану',111=>'ран',112=>'ран',114=>'ран',121=>'рану',122=>'раны',125=>'ран'];
foreach($forms as $n=>$form)cosmeticCheck(JournalText::format('combat.wounds',$p+['wounds'=>$n])==='Тич получает '.$n.' '.$form.'.','wounds '.$n);
cosmeticCheck(RussianNumber::form(0,'рана','раны','ран')==='ран','zero');
cosmeticCheck(RussianNumber::form(PHP_INT_MIN,'рана','раны','ран')==='ран','integer boundary');
cosmeticCheck(JournalText::format('combat.dice',$p+['attack'=>6,'defend'=>3])==='Бросок: атакующий 6, защитник 3.','compatible plain text dice');
cosmeticCheck(JournalText::format('move.started',['redacted'=>true])===null,'legacy redacted projection stays hidden');
$repo=new HistoryRepository(new Berserk\Core\Db());$host=$repo->journalPage(1,'host',0,100);$player=$repo->journalPage(1,'player',0,100);
cosmeticCheck($host['entries'][0]['text']!==$player['entries'][0]['text'],'reader supplies viewer to formatter');
cosmeticCheck($host['cursor']===$player['cursor'] && $host['cursor']===1,'cursor unchanged');
cosmeticCheck(array_keys($host['entries'][0])===['seq','kind','text'],'no new API fields');
echo json_encode(['status'=>'PASS','wound_cases'=>count($forms),'host'=>$host['entries'][0]['text'],'player'=>$player['entries'][0]['text']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
}
