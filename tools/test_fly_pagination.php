<?php
declare(strict_types=1);
require __DIR__ . '/test_battle_snapshot_renderer.php';

use Berserk\Core\CardInstance;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

function flyPaginationFixture(int $own, int $opp, string $viewer = 'host'): array {
    global $cardsInfo;
    $state = battleSnapshotState();
    foreach ($state->cards as $card) {
        if ($card->zone === CardInstance::ZONE_FLYING) $card->zone = CardInstance::ZONE_GRAVEYARD;
    }
    $opponent = $state->getOpponentKey($viewer);
    foreach ([$viewer => $own, $opponent => $opp] as $owner => $count) {
        for ($i = 1; $i <= $count; $i++) {
            $id = ($owner === 'host' ? 100 : 200) + $i;
            // Reverse insertion order and sparse slot numbers test existing ksort ordering.
            $state->addCard(battleSnapshotCard($id, 'flying', $owner, CardInstance::ZONE_FLYING,
                null, null, true, ($count - $i + 1) * 2));
        }
    }
    $before = serialize($state->toArray());
    $data = (new BattleScreen(new Template(__DIR__ . '/../templates')))
        ->prepare($state, $viewer, $viewer, null, $cardsInfo, ['sel'=>0])['data'];
    battleSnapshotAssert($before === serialize($state->toArray()), 'View leaves game state untouched');
    return $data;
}

if (in_array('--fixture', $argv ?? [], true)) {
    $data = flyPaginationFixture((int) ($argv[2] ?? 4), (int) ($argv[3] ?? 6));
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
foreach (['host', 'player'] as $viewer) {
    for ($count = 0; $count <= 6; $count++) {
        $data = flyPaginationFixture($count, 6 - $count, $viewer);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $data['fly_zones_html']);
        $x = new DOMXPath($dom);
        foreach (['own'=>$count, 'opp'=>6-$count] as $side=>$n) {
            $zone = '//*[@data-fly-side="'.$side.'"]';
            battleSnapshotAssert($x->query($zone.'/*[@class="fly-slot"]')->length === 3, 'Exactly three slots per side');
            battleSnapshotAssert($x->query($zone.'//*[@data-fly-go]')->length === ($n > 3 ? 2 : 0), 'Two bullets only for four or more');
            battleSnapshotAssert($x->query($zone.'//*[@class="battle-card"]')->length === $n, 'All flying cards remain accessible');
            battleSnapshotAssert($x->query($zone.'//*[@data-fly-page="1" and @hidden]')->length === 3, 'Second page starts hidden');
            $links = [];
            foreach ([0, 1] as $page) foreach ($x->query($zone.'//*[@data-fly-page="'.$page.'"]//a[contains(@class,"card-link")]') as $link) $links[] = $link;
            foreach ($links as $i=>$link) {
                $owner = $side === 'own' ? $viewer : ($viewer === 'host' ? 'player' : 'host');
                $id = ($owner === 'host' ? 100 : 200) + $n - $i;
                battleSnapshotAssert(str_contains($link->getAttribute('href'), 'sel='.$id), 'Sorted slot order and selection links preserved');
            }
        }
    }
}
echo "Flying pagination: 0-6 cards, both viewers, slots, bullets, ordering and state passed.\n";
