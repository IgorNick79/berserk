<?php
declare(strict_types=1);
namespace Berserk\Core;

final class PlaymatCatalog
{
    public const DEFAULT_URL = '/assets/images/battle/playmat_default.webp';
    public const ITEMS = [
        'mat-001' => '/assets/images/playmat/001.webp',
        'mat-002' => '/assets/images/playmat/002.webp',
        'mat-003' => '/assets/images/playmat/003.webp',
        'mat-004' => '/assets/images/playmat/004.webp',
        'mat-005' => '/assets/images/playmat/005.webp',
        'mat-006' => '/assets/images/playmat/006.webp',
        'mat-007' => '/assets/images/playmat/007.webp',
        'mat-008' => '/assets/images/playmat/008.webp',
        'mat-009' => '/assets/images/playmat/009.webp',
        'mat-010' => '/assets/images/playmat/010.webp',
        'mat-011' => '/assets/images/playmat/011.webp',
        'mat-012' => '/assets/images/playmat/012.webp',
        'mat-013' => '/assets/images/playmat/013.webp',
        'mat-014' => '/assets/images/playmat/014.webp',
        'mat-015' => '/assets/images/playmat/015.webp',
        'mat-016' => '/assets/images/playmat/016.webp',
        'mat-017' => '/assets/images/playmat/017.webp',
        'mat-018' => '/assets/images/playmat/018.webp',
    ];
    public static function url(?string $id): string { return self::ITEMS[$id ?? ''] ?? self::DEFAULT_URL; }
    public static function playerId(PlayerState $player): ?string {
        $preferences=\Berserk\Auth\AccountUiPreferences::forPlayer($player);
        return $preferences['playmat_id'] ?? null;
    }
    public static function playerUrl(PlayerState $player): string { return self::url(self::playerId($player)); }
    /** Gameplay render token; account preferences are not serialized in GameState. */
    public static function renderToken(GameState $state): string {
        $data = $state->toArray();
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
