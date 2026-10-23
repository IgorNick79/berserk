<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;

use Berserk\Core\{CardInstance,Db,DbQueryException};

/** Transaction-local display lookup for NEW events only. Never used by the journal reader. */
final class JournalCardNames
{
    private array $names=[];
    public function __construct(private Db $db) {}
    public function name(CardInstance $card): ?string
    {
        if (array_key_exists($card->ukid,$this->names)) return $this->names[$card->ukid];
        try {
            $rows=$this->db->fetchAll("SELECT name FROM cards WHERE ukid='".$this->db->escape($card->ukid)."' LIMIT 2");
            $name=count($rows)===1?($rows[0]['name']??null):null;
        } catch (DbQueryException) {
            // Missing display metadata must not alter the battle. Storage/commit failures still roll back normally.
            $name=null;
        }
        return $this->names[$card->ukid]=is_string($name)?$name:null;
    }
}
