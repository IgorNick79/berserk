<?php
declare(strict_types=1);
// Synthetic CLI-only compatibility probe. No application bootstrap/DB/session/network.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../src/Core/Autoloader.php';
Berserk\Core\Autoloader::register();
Berserk\Core\Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$dummy=(new ReflectionClass(Berserk\Auth\PasswordPolicy::class))->getConstant('DUMMY')[12];
$local=password_hash('SyntheticStage5EOnly',PASSWORD_DEFAULT);
$simulated=password_hash('SyntheticStage5EOnly',PASSWORD_BCRYPT,['cost'=>12]);
echo json_encode(['php_version'=>PHP_VERSION,'registration_policy_cost'=>password_get_info(Berserk\Auth\PasswordPolicy::hash('SyntheticStage5EOnly'))['options']['cost'],'dummy_cost'=>password_get_info($dummy)['options']['cost'],
 'local_default_cost'=>password_get_info($local)['options']['cost'],'explicit_cost12_simulation'=>password_get_info($simulated)['options']['cost'],
 'target_php84_default_cost'=>12,'target_dummy_cost_mismatch'=>password_get_info($dummy)['options']['cost']!==12,
 'source'=>'https://www.php.net/manual/en/function.password-hash.php','production_timing_test_performed'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
