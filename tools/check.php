<?php
require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

use Berserk\Core\Db;
use Berserk\Core\GameRepository;
use Berserk\Core\CardInstance;

$config = require __DIR__ . '/../config/db.php';

// Подключение к БД
$db = new Db($config);
echo "DB connected\n";

// Репозиторий
$repo = new GameRepository($db);

// Создаём новую партию
$state = $repo->create(1, 2);
echo "Created game_id = {$state->gameId}\n";

// Добавляем трёх Кочевников
foreach ([1, 2, 3] as $i) {
    $id = $state->nextInstanceId();
    $state->addCard(new CardInstance($id, 's1_1', 'host', CardInstance::ZONE_SQUAD, null, null, null, 8));
}

$state->bumpVersion();
$repo->save($state);

// Перечитываем из БД
$loaded = $repo->findById($state->gameId);
echo "Loaded version: {$loaded->version}\n";
echo "Cards in host squad: " . count($loaded->getCardsInZone('host', 'squad')) . "\n";
echo "Next instance id: {$loaded->nextInstanceId}\n";

use Berserk\Core\View;

// Проекция для хоста (user_id=1)
$viewHost = (new View($loaded))->forPlayer(1);
echo "\n=== View for host ===\n";
echo "You: {$viewHost['you']}\n";
echo "Cards visible: " . count($viewHost['cards']) . "\n";
echo json_encode($viewHost['cards'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// Проекция для второго игрока (user_id=2)
$viewPlayer = (new View($loaded))->forPlayer(2);
echo "\n=== View for player ===\n";
echo "You: {$viewPlayer['you']}\n";
echo "Cards visible: " . count($viewPlayer['cards']) . "\n";

use Berserk\Core\DeckView;

$deckView = new DeckView($db);

echo "\n=== Starters ===\n";
$starters = $deckView->listStarters();
foreach ($starters as $s) {
    echo "  {$s['ind']}: {$s['name']}\n";
}

echo "\n=== Deck 1 ===\n";
$deck = $deckView->forDeck(1);
echo "Name: {$deck['name']}\n";
echo "Total cards: {$deck['total']}\n";
echo "Elite: {$deck['elite']}, Ordinary: {$deck['ordinary']}\n";
echo "Elements: " . json_encode($deck['elements'], JSON_UNESCAPED_UNICODE) . "\n";
echo "First 3 cards:\n";
foreach (array_slice($deck['cards'], 0, 3) as $c) {
    echo "  {$c['count']}x {$c['name']} ({$c['ukid']}) price={$c['price']} elite=" . ($c['elite'] ? 'yes' : 'no') . "\n";
}