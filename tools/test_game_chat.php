<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/GameChat.php';
use Berserk\Core\GameChat;
$n=0;
function check(bool $ok,string $label):void {global $n;++$n;if(!$ok)throw new RuntimeException($label);}
function invalid(mixed $value,string $error):void{try{GameChat::clean($value);throw new RuntimeException('Accepted invalid text');}catch(InvalidArgumentException $e){check($e->getMessage()===$error,$error);}}
check(GameChat::clean('  <b>Привет</b> 😀 & <script>alert(1)</script>  ')==='Привет 😀 & alert(1)','HTML stripped; Unicode and plain text preserved');
check(GameChat::clean("a\nb\t😀")==="a\nb\t😀",'Line breaks preserved');
check(GameChat::clean("a\0b")==='ab','Control characters stripped');
check(GameChat::clean(str_repeat('😀',1000))===str_repeat('😀',1000),'1000 Unicode characters');
foreach(['','   ','<img src=x>',"\u{200B}","\u{00A0}"] as $value) invalid($value,'empty_message');
invalid(str_repeat('😀',1001),'message_too_long'); invalid(['text'],'invalid_message'); invalid("\xFF",'invalid_message');
foreach(['0','1','9223372036854775807'] as $id)check(GameChat::cursor($id)===$id,'String cursor '.$id);
foreach(['-1','1e2','01','0 OR 1',[],str_repeat('9',20)] as $id){try{GameChat::cursor($id);throw new RuntimeException('Accepted bad cursor');}catch(InvalidArgumentException $e){check(true,'Rejected cursor');}}
echo "Game chat unit: $n checks passed\n";
