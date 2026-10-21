<?php
// Copy to auth.local.php manually. No fallback to gameplay db.php.
return [
    'db' => ['host'=>'127.0.0.1','port'=>3306,'name'=>'berserk','user'=>'auth_runtime','pass'=>''],
    // Separate least-privilege lobby connection, configured after the Stage 5B migration.
    // 'lobby_db' => ['host'=>'127.0.0.1','port'=>3306,'name'=>'berserk','user'=>'lobby_runtime','pass'=>''],
    // Generate privately: bin2hex(random_bytes(32)); never commit the real value.
    'limiter_secret' => '',
    'allow_loopback_http' => false,
];
