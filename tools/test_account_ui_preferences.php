<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\Autoloader;
use Berserk\Auth\AccountUiPreferences;
use Berserk\View\Ui\PanelTheme;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
if(getenv('THEME_TEST_MYSQL_PORT')===false){echo "SKIP: set THEME_TEST_MYSQL_PORT to an isolated test MySQL.\n";exit;}
$port=(int)getenv('THEME_TEST_MYSQL_PORT');
if($port<1024||$port>65535)throw new RuntimeException('Use an isolated test port');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$admin=new mysqli('127.0.0.1','root','',null,$port);
$database='berserk_ui_theme_'.bin2hex(random_bytes(5));
if(!preg_match('/\Aberserk_ui_theme_[a-f0-9]{10}\z/',$database))throw new RuntimeException('Unsafe test schema');
$admin->query('CREATE DATABASE '.$database);
try{
 $admin->select_db($database);
 $admin->query('CREATE TABLE accounts(id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
 $admin->query('INSERT INTO accounts VALUES(1),(2)');
 $admin->query(file_get_contents(__DIR__.'/migrations/20261024_account_ui_preferences.sql'));
 $config=['host'=>'127.0.0.1','port'=>$port,'user'=>'root','pass'=>'','name'=>$database];
 $check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
 $store=new AccountUiPreferences($config);
 $check($store->theme(1)==='grimoire','New account default');
 foreach(array_keys(PanelTheme::ITEMS) as $theme){$store->saveTheme(1,$theme);$check((new AccountUiPreferences($config))->theme(1)===$theme,'Theme persists across connections');}
 $check($store->theme(2)==='grimoire','Other account remains unchanged');
 $store->saveTheme(2,'stone');$check($store->theme(1)==='parchment','Independent account save');
 foreach(['bad',"stone' OR 1=1",['stone'],null] as $invalid){try{$store->saveTheme(1,$invalid);throw new RuntimeException('Invalid value accepted');}catch(InvalidArgumentException){}}
 try{$store->saveTheme(999,'stone');throw new RuntimeException('Unknown account accepted');}catch(mysqli_sql_exception){}
 $check($store->theme(1)==='parchment'&&$store->theme(2)==='stone','Failed save did not overwrite preferences');
 $check($admin->query('SHOW TABLES')->num_rows===2,'No game state, clocks or runtime schema created');
 echo "PASS MySQL: migration, new-account default, four themes, reconnect persistence, account isolation, validation, FK and no gameplay tables.\n";
}finally{$admin->query('DROP DATABASE '.$database);$admin->close();}
