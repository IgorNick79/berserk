<?php
// Copy to battle_history.local.php on the server. Never commit the local file.
// Require a trusted login bridge setting the integer session principal
// berserk_authenticated_user_id. The prototype ?first/?second is NOT login.
return [
    'recording_enabled' => false,
    'record_game_ids' => [], // explicit integer IDs, before final confirm_place; never '*'
    'preview_enabled' => false,
    'preview_game_ids' => [], // explicit completed test matches only
    'admin_user_ids' => [], // explicit trusted authenticated user IDs
    // Frozen definitions keyed by ukid; ReplayState v1 catalog entries, including revision.
    // Missing/unsupported definitions cause recording_gap, not invented outcomes.
    'catalog' => [],
    'journal_enabled' => false,
    'journal_game_ids' => [],
    // Server-provisioned session binding token (64 random hex) => exact game/side/expiry.
    // Legacy lab provisioning only; production uses the one-time invitation form.
    'journal_test_bindings' => [], // legacy lab harness only; ignored when invitation access enabled
    'journal_access_enabled' => false,
    'journal_access_directory' => '', // existing private directory OUTSIDE application/web roots
    'journal_access_local_http' => false, // isolated loopback lab ONLY; production requires HTTPS
];
