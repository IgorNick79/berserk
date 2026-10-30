<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
\Berserk\Core\Autoloader::register();\Berserk\Core\Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
echo \Berserk\View\Ui\GameMenu::render('battle',new \Berserk\Auth\Account(1,'Fixture'),'fixture');
