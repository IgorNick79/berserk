<?php
declare(strict_types=1);
namespace Berserk\Auth;
use Berserk\View\Ui\PanelTheme;

/** Account preferences live outside the match: saving never advances a game or clock. */
final class AccountUiPreferences
{
    private \mysqli $db;
    public function __construct(array $config)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = new \mysqli($config['host'],$config['user'],$config['pass'],$config['name'],(int)($config['port']??3306));
        $this->db->set_charset('utf8mb4');
    }
    private static ?self $presentation = null;
    private array $cache = [];
    public static function bindPresentation(self $store): void { self::$presentation=$store; }
    public static function forPlayer(\Berserk\Core\PlayerState $player): ?array
    {
        if (self::$presentation===null || $player->userId<=0) return null;
        return self::$presentation->cached($player->userId);
    }
    private function cached(int $id): array { return $this->cache[$id]??= $this->snapshot($id); }
    /** Initializes each account once; concurrent first reads retain the winning mat. */
    public function snapshot(int $accountId): array
    {
        if ($accountId <= 0) throw new \InvalidArgumentException('invalid_account');
        $ids=array_keys(\Berserk\Core\PlaymatCatalog::ITEMS);
        $mat=$ids[random_int(0,count($ids)-1)];
        $s=$this->db->prepare('INSERT IGNORE INTO account_ui_preferences(account_id,playmat_id) VALUES(?,?)');
        try {$s->bind_param('is',$accountId,$mat);$s->execute();} finally {$s->close();}
        $s=$this->db->prepare('UPDATE account_ui_preferences SET playmat_id=?,revision=revision+1 WHERE account_id=? AND playmat_id IS NULL');
        try {$s->bind_param('si',$mat,$accountId);$s->execute();} finally {$s->close();}
        $s=$this->db->prepare('SELECT panel_theme,playmat_id,sound_enabled,revision FROM account_ui_preferences WHERE account_id=?');
        try {$s->bind_param('i',$accountId);$s->execute();$r=$s->get_result()->fetch_assoc();} finally {$s->close();}
        if (!$r || !PanelTheme::valid($r['panel_theme']) || !isset(\Berserk\Core\PlaymatCatalog::ITEMS[$r['playmat_id']])) throw new \RuntimeException('invalid_preferences');
        return ['theme'=>$r['panel_theme'],'playmat_id'=>$r['playmat_id'],'sound_enabled'=>(bool)$r['sound_enabled'],'revision'=>(int)$r['revision']];
    }
    public function save(int $accountId, string $key, mixed $value): array
    {
        $columns=['sound_enabled'=>'sound_enabled','playmat_id'=>'playmat_id','theme'=>'panel_theme'];
        if (!isset($columns[$key]) || ($key==='theme' && !PanelTheme::valid($value))
            || ($key==='playmat_id' && (!is_string($value) || !isset(\Berserk\Core\PlaymatCatalog::ITEMS[$value])))
            || ($key==='sound_enabled' && !in_array($value,['0','1'],true))) throw new \InvalidArgumentException('invalid_preference');
        $this->snapshot($accountId);
        $s=$this->db->prepare('UPDATE account_ui_preferences SET '.$columns[$key].'=?,revision=revision+1 WHERE account_id=?');
        try {$s->bind_param('si',$value,$accountId);$s->execute();} finally {$s->close();}
        unset($this->cache[$accountId]);
        return $this->snapshot($accountId);
    }
    public function theme(int $accountId): string
    {
        if ($accountId <= 0) throw new \InvalidArgumentException('invalid_account');
        $s=$this->db->prepare('SELECT panel_theme FROM account_ui_preferences WHERE account_id=?');
        try {
            $s->bind_param('i',$accountId);$s->execute();$row=$s->get_result()->fetch_assoc();
            return PanelTheme::valid($row['panel_theme']??null)?$row['panel_theme']:PanelTheme::DEFAULT;
        } finally {$s->close();}
    }
    public function saveTheme(int $accountId, mixed $theme): string
    {
        if ($accountId<=0 || !PanelTheme::valid($theme)) throw new \InvalidArgumentException('invalid_theme');
        return $this->save($accountId,'theme',$theme)['theme'];
    }
}
