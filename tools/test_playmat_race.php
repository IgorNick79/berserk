<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,StaleStateException};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$config=require __DIR__.'/../config/db.php';if($config['name']!=='berserk_playmat_test'||is_dir(__DIR__.'/../.git'))throw new RuntimeException('Disposable DB required');
$db=new Db($config);$meta=$db->fetchOne('SELECT @@datadir AS dir');if(!str_contains(str_replace('\\','/',$meta['dir']),'/Berserk/playmat-tests/data/'))throw new RuntimeException('Wrong test datadir');$repo=new GameRepository($db);$game=(int)$argv[1];$actor=$argv[2];
for($i=0;$i<25;$i++){
 if($actor==='action'){
  for($retry=0;;$retry++){
   $s=$repo->findById($game);$s->battle['race_actions']=($s->battle['race_actions']??0)+1;$s->bumpVersion();
   try{$repo->save($s);break;}catch(StaleStateException $e){if($retry>100)throw $e;usleep(500);}
  }
 }else $repo->setPlaymat($game,$actor==='host'?1:2,$actor==='host'?($i%2===0?'mat-017':'mat-001'):($i%2===0?'mat-016':'mat-002'));
 usleep(random_int(100,4000));
}
echo "race $actor complete\n";
