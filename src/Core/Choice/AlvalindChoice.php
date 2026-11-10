<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState,Engine,Command,Result,AlvalindProcessor,DamageResolver};
use Berserk\View\Ui\PanelSpec;
final class AlvalindChoice implements ChoiceHandlerInterface {
 public function pendingKey():string{return 'pending_alvalind_end';}
 public function commandTypes():array{return ['choose_alvalind_target','choose_alvalind_death'];}
 public function isActive(GameState $s):bool{return !empty($s->battle['pending_alvalind_end'])||((!empty($s->battle['alvalind_recoil'])||!empty($s->battle['alvalind_end']))&&!empty($s->battle['strike']['pending_choice']));}
 public function spec(GameState $s,string $viewer,array $info,string $base,string $role):?PanelSpec {
  $p=$s->battle['pending_alvalind_end']??null;$death=false;
  if(!$p){$p=$s->battle['strike']['pending_choice']??null;if(!$p)return null;$death=true;$p['owner']=$s->getCard($p['died_id'])->owner;}
  $source=$s->getCard($death?$p['died_id']:$p['source_id']);$title=($info[$source->ukid]['name']??$source->ukid).($death?' — предсмертная способность':' — разряд на '.$p['value']);
  if($p['owner']!==$viewer)return new PanelSpec(title:$title,isMine:false);
  parse_str((string)parse_url($base,PHP_URL_QUERY),$hidden);
  $hidden['cmd']=$death?'choose_alvalind_death':'choose_alvalind_target';
  $hidden[$death?'choice_id':'operation_id']=$death?$p['choice_id']:$p['id'];
  $items = array_map(static fn($id): array => ['value'=>$id], $death ? $p['candidates'] : $p['targets']);
  return new PanelSpec(title:$title,form:['name'=>'target_id','hidden'=>$hidden,'submit'=>'Выбрать'],
      cardSelection:\Berserk\View\Ui\CreatureChoice::confirmed($s, $viewer, $info, $items,
          $hidden['cmd'] . ':' . ($p['choice_id'] ?? $p['id']), mode:\Berserk\View\Ui\CardSelectionSpec::SIMPLE));
 }
 public function apply(GameState $s,Engine $e,string $viewer,Command $cmd):Result {
  if($cmd->type==='choose_alvalind_death'){
   if(!$this->isActive($s)||empty($s->battle['strike']['pending_choice']))return Result::error('Устаревший выбор смерти');
   return (new DamageResolver($s))->chooseDeathTarget($viewer,$cmd);
  }
  return(new AlvalindProcessor($s,$e))->choose($viewer,$cmd);
 }
}
