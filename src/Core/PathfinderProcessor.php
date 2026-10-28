<?php
declare(strict_types=1);
namespace Berserk\Core;
final class PathfinderProcessor {
 public static function active(GameState $s):?array {$p=$s->battle['pathfinder']??null;return $p&&$p['stage']!=='complete'?$p:null;}
 public static function begin(GameState $s):bool {
  if(isset($s->battle['pathfinder']))return self::active($s)!==null;
  $first=$s->getFirstPlayerKey();$queue=[];
  foreach([$first,$s->getOpponentKey($first)] as $owner){
   $ids=[];foreach($s->cards as $c)if($c->owner===$owner&&$c->zone==='field'&&!empty($c->effectiveProp()['pathfinder_rearrange']))$ids[]=$c->instanceId;
   if($ids)$queue[]=['owner'=>$owner,'source_id'=>$ids[0]];
  }
  if(!$queue)return false;
  $s->battle['pathfinder']=['queue'=>$queue,'index'=>0,'stage'=>'choice','revealed_to'=>[]];self::activate($s);return true;
 }
 private static function activate(GameState $s):void {
  $p=&$s->battle['pathfinder'];$entry=$p['queue'][$p['index']];$p['owner']=$entry['owner'];
  $c=$s->getCard($entry['source_id']);$c->revealed=true;$c->flags['pathfinder_revealed']=true;
  $p['revealed_to'][$entry['owner']]=true;
 }
 public static function finish(GameState $s):bool {
  $p=&$s->battle['pathfinder'];$s->getPlayer($p['owner'])->confirm('place');$p['index']++;
  if($p['index']>=count($p['queue'])){$p['stage']='complete';return true;}
  $p['stage']='choice';self::activate($s);return false;
 }
 public static function canEdit(GameState $s,string $owner):bool {
  $p=self::active($s);return !$p||($p['owner']===$owner&&$p['stage']==='rearranging');
 }
}
