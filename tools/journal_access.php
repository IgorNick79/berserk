<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDERR, "Legacy journal invitations are retired. Use the existing participant account login.\n");
exit(1);
