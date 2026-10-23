<?php
// Optional Replay Preview configuration only. Journal needs no configuration.
// Copy to battle_history.local.php only for Replay Preview. Never commit the local file.
// Account matches use the existing AuthSession and lobby membership checks.
// The prototype ?first/?second is NOT login.
return [
    'preview_enabled' => false,
    'preview_game_ids' => [], // explicit completed test matches only
    'admin_user_ids' => [], // explicit trusted authenticated user IDs
    // Frozen definitions keyed by ukid; ReplayState v1 catalog entries, including revision.
    // This catalog certifies snapshots only. The live text journal freezes names from cards.
    'catalog' => [],
];
