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
        $s=$this->db->prepare('INSERT INTO account_ui_preferences(account_id,panel_theme) VALUES(?,?) ON DUPLICATE KEY UPDATE panel_theme=VALUES(panel_theme)');
        try {$s->bind_param('is',$accountId,$theme);$s->execute();} finally {$s->close();}
        return $theme;
    }
}
