<?php
declare(strict_types=1);
namespace Berserk\Auth;

/** Decorates existing renderer output at the HTTP boundary; no game semantics. */
final class GameTransport
{
    public static int $game = 0;
    public static int $version = 0;
    public static string $stage = '';
    public static string $csrf = '';

    public static function html(string $html): string
    {
        if (self::$game <= 0) return $html;
        // Existing renderers still receive host/player. Role flags are never shipped as authority.
        $html = preg_replace('/([?&]|&amp;)(?:first|second)(?:=[^&"\s<>]*)?(?:&amp;|&)/', '$1', $html);
        $html = preg_replace('/<input\b[^>]*\bname=["\'](?:first|second)["\'][^>]*>/i','',$html);
        $html = preg_replace('/(?:&amp;|&)prepare_token=[^&"\s<>]*/','',$html);
        $html = preg_replace('/<input\b[^>]*\bname=["\']prepare_token["\'][^>]*>/i','',$html);
        $token = htmlspecialchars(self::$csrf,ENT_QUOTES,'UTF-8');
        return preg_replace_callback('/<form\b([^>]*)>(.*?)<\/form>/is',static function(array $m) use($token): string {
            if (!preg_match('/name=["\']cmd["\']/', $m[2])) return $m[0];
            // Existing battle-sync intercepts GET forms and owns command_id/CAS recovery.
            // Leave those forms intact; raw GET/POST commands outside its endpoint are denied.
            if (in_array(self::$stage,['battle','game_over'],true) && preg_match('/\bmethod=["\']get["\']/i',$m[1])) return $m[0];
            $attrs = preg_replace('/\bmethod=["\']get["\']/i','method="post"',$m[1]);
            $hidden = '<input type="hidden" name="csrf" value="'.$token.'">';
            if (!str_contains($m[2],'name="prepare_version"')) $hidden .= '<input type="hidden" name="prepare_version" value="'.self::$version.'">';
            return '<form'.$attrs.'>'.$hidden.$m[2].'</form>';
        },$html);
    }

    public static function payload(array $payload): array
    {
        foreach ($payload as $key=>$value) {
            if (is_array($value)) $payload[$key] = self::payload($value);
            elseif (is_string($value) && str_ends_with((string)$key,'_html')) $payload[$key] = self::html($value);
        }
        return $payload;
    }
}
