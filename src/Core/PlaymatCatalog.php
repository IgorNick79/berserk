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
    /** Separate assignment policy so a profile preference can replace random selection later. */
    public static function assign(PlayerState $player): void {
        if ($player->userId > 0 && $player->playmatId === null) {
            $ids = array_keys(self::ITEMS);
            $player->playmatId = $ids[random_int(0, count($ids) - 1)];
        }
    }
    public static function snapshot(GameState $state, string $viewer): array {
        return ['version' => $state->persistenceVersion ?? 0,
            'own' => $state->getPlayer($viewer)->playmatId,
            'own_url' => self::url($state->getPlayer($viewer)->playmatId),
            'opponent_url' => self::url($state->getPlayer($state->getOpponentKey($viewer))->playmatId)];
    }
    /** All persisted non-playmat data: cosmetic saves never masquerade as a gameplay snapshot. */
    public static function renderToken(GameState $state): string {
        $data = $state->toArray();
        foreach ($data['players'] as &$player) unset($player['playmat_id']);
        unset($player);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
