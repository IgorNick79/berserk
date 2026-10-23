<?php
declare(strict_types=1);
// Retired prototype entry point. Current journals use the ordinary authenticated account session.
http_response_code(410);
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');
echo 'Для просмотра журнала войдите в аккаунт участника партии.';
