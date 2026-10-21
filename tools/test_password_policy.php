<?php
declare(strict_types=1);
namespace Berserk\Auth {
 function password_verify(string $password,string $hash):bool {
  $GLOBALS['bcrypt_calls'][]=(int)(password_get_info($hash)['options']['cost']??0);
  return \password_verify($password,$hash);
 }
}
namespace {
 require __DIR__.'/../src/Core/Autoloader.php';
 Berserk\Core\Autoloader::register();Berserk\Core\Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
 use Berserk\Auth\PasswordPolicy;
 $rows=[];
 function check(string $name,bool $ok):void{global $rows;$rows[]=['test'=>$name,'pass'=>$ok];if(!$ok)throw new RuntimeException($name);}
 $password='SyntheticPasswordOnly123';$hash=PasswordPolicy::hash($password);
 check('registration explicitly bcrypt12',password_get_info($hash)['algoName']==='bcrypt'&&password_get_info($hash)['options']['cost']===12);
 foreach([4,10,11,12,13] as $cost){
  $stored=password_hash($password,PASSWORD_BCRYPT,['cost'=>$cost]);
  foreach([$password,'WrongSyntheticPassword'] as $candidate){
   $GLOBALS['bcrypt_calls']=[];$ok=PasswordPolicy::verify($candidate,$stored);$calls=$GLOBALS['bcrypt_calls'];
   check('existing cost'.$cost.' correct result '.($candidate===$password?'valid':'wrong'),$ok===($candidate===$password));
   check('bcrypt work normalized/no downgrade cost'.$cost.' '.($candidate===$password?'valid':'wrong'),array_sum(array_map(fn($c)=>2**$c,$calls))===2**max($cost,12));
  }
 }
 foreach([null,'','invalid hash'] as $stored){$GLOBALS['bcrypt_calls']=[];check('unknown or corrupt hash rejected '.var_export($stored,true),!PasswordPolicy::verify($password,$stored));check('unknown/corrupt gets cost12 work '.var_export($stored,true),$GLOBALS['bcrypt_calls']===[12]);}
 check('matching public dummy plaintext still cannot authenticate unknown',!PasswordPolicy::verify('PublicSyntheticBcryptPaddingOnly',null));
 echo json_encode(['php_version'=>PHP_VERSION,'checks'=>count($rows),'results'=>$rows,'note'=>'Structural bcrypt work budget, not a proof of wall-clock constant timing; higher existing costs retained. No DB/session/network.'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
}
