<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;
use Berserk\Core\{GameState,CardInstance};

/** Display copy only; never computes battle outcomes or exposes raw prop/flags/modifiers. */
final class ReplayStateBuilder
{
    public function __construct(private array $catalog) {}
    public function definition(CardInstance $card): ?array { return $this->catalog[$card->ukid] ?? null; }
    public function known(CardInstance $card, string $view): bool
    {
        if ($view==='server') return true;
        if (in_array($card->zone,['deck','sideboard'],true)) return false;
        return $card->owner===$view || ($card->revealed && $card->zone!=='hand');
    }
    public function build(GameState $state, string $view): array
    {
        $out=['version'=>1,'ruleset'=>'berserk-stage4b-experimental','participants'=>['host'=>['label'=>'Host'],'player'=>['label'=>'Player']],
            'turn'=>(int)($state->battle['turn']??0),'active'=>$state->battle['active']??null,'cards'=>[],'catalog'=>[],
            'markers'=>[],'finished'=>$state->status==='game_over','winner'=>$state->winner];
        foreach ($state->cards as $card) {
            if ($view!=='server' && (in_array($card->zone,['deck','sideboard'],true) || ($card->zone==='hand' && $card->owner!==$view))) continue;
            $known=$this->known($card,$view); $ref=null;
            if ($known && isset($this->catalog[$card->ukid])) {
                $entry=$this->catalog[$card->ukid];$ref=$card->ukid.':'.$entry['revision'];$out['catalog'][$ref]=$entry;
            }
            $out['cards'][]=['instance_id'=>(string)$card->instanceId,'definition'=>$ref,'owner'=>$card->owner,'zone'=>$card->zone,
                'order'=>$card->order,'row'=>$card->row,'col'=>$card->col,'slot'=>$card->slot,
                'hp'=>$known?$card->hp:null,'hp_max'=>$known?$card->hpMax:null,'move'=>$known?$card->move:null,'move_max'=>$known?$card->moveMax:null,
                'armor'=>$known?$card->armor:null,'armor_max'=>$known?$card->armorMax:null,
                'strike'=>$known?['weak'=>$card->strikeWeak,'medium'=>$card->strikeMedium,'strong'=>$card->strikeStrong]:null,
                'coins'=>$known?$card->coins:null,'closed'=>$card->closed,'revealed'=>$card->revealed,
                'statuses'=>$known&&$card->dying?[['code'=>'dying','label'=>'dying','value'=>true]]:[], 'markers'=>[],'effects'=>[]];
        }
        ReplayState::validate($out);
        return $out;
    }
    /** Conservative certified subset. Unknown props/visual effects are NOT silently treated as supported. */
    public function simple(GameState $state): bool
    {
        if ($state->cell_markers) return false;
        foreach ($state->battle as $key=>$value) {
            if ($value && ((str_starts_with($key,'pending_') && $key!=='pending_attack_series_choice')
                || in_array($key,['turn_instant_stack','degasca_sequence','coin_continuation'],true))) return false;
        }
        foreach ($state->cards as $card) {
            if (!isset($this->catalog[$card->ukid]) || $card->markers || $card->modifiers) return false;
            foreach ($card->prop as $key=>$value) {
                if (in_array($key,['attack_series','unanswer'],true) && is_bool($value)) continue;
                if ($key==='start' && is_array($value) && ($value['type']??'')==='get_hp'
                    && !array_diff(array_keys($value),['type','hp','side'])) continue;
                return false;
            }
        }
        return true;
    }
}
