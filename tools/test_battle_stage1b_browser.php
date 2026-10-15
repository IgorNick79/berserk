<?php
/** Browser regression harness. Requires an explicitly isolated Stage 1B database. */
declare(strict_types=1);
$config=require __DIR__.'/../config/db.php';
if (!str_starts_with((string)($config['name']??''),'berserk_stage1b')) {
    http_response_code(403); exit('Use an isolated berserk_stage1b test database.');
}
ob_start();
require __DIR__.'/../www/index.php';
$html=ob_get_clean();
$tests='<button id="run-stage1b-checks" type="button" style="position:fixed;left:4px;bottom:4px;z-index:200">Run Stage 1B checks</button>'
    . '<pre id="stage1b-results" style="position:fixed;left:4px;bottom:35px;max-height:200px;overflow:auto;z-index:200;background:white" hidden></pre>'
    . '<script src="/qa-tests.js"></script>';
echo str_replace('</body>',$tests.'</body>',$html);
