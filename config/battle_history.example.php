<?php
// Copy to battle_history.local.php on the server. Never commit the local file.
// Account matches use the existing AuthSession and lobby membership checks.
// The prototype ?first/?second is NOT login.
return [
    'recording_enabled' => false,
    'record_game_ids' => [], // explicit integer IDs, before final confirm_place; never '*'
    'preview_enabled' => false,
    'preview_game_ids' => [], // explicit completed test matches only
    'admin_user_ids' => [], // explicit trusted authenticated user IDs
    // Frozen definitions keyed by ukid; ReplayState v1 catalog entries, including revision.
    // This catalog certifies snapshots only. The live text journal freezes names from cards.
    'catalog' => [],
    'journal_enabled' => false,
    'journal_game_ids' => [],
    // Existing lobby room IDs, selected BEFORE acceptance. Only their newly created games enroll.
    // Requires recording_enabled + journal_enabled. Never enables replay preview.
    'control_room_ids' => [],
    // Server-provisioned session binding token (64 random hex) => exact game/side/expiry.
    // Legacy invitation mode only; account-owned control matches do not need invitations.
    'journal_test_bindings' => [], // legacy lab harness only; ignored when invitation access enabled
    'journal_access_enabled' => false,
    'journal_access_directory' => '', // existing private directory OUTSIDE application/web roots
    'journal_access_local_http' => false, // isolated loopback lab ONLY; production requires HTTPS
];
