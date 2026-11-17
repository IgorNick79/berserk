<?php
declare(strict_types=1);

// Reuse real action entry points and the shared selection fixtures.
ob_start();
require __DIR__ . '/test_card_selection_stage3.php';
ob_end_clean();

foreach (['enemy' => 4, 'ally' => 2] as $side => $id) {
    foreach (['dying', 'hp', 'zone', 'owner', 'missing'] as $change) {
        $state = fixture('life_gift');
        $card = $state->getCard($id);
        switch ($change) {
            case 'dying': $card->dying = true; break;
            case 'hp': $card->hp = 0; break;
            case 'zone': $card->zone = 'graveyard'; break;
            case 'owner': $card->owner = $side === 'enemy' ? 'host' : 'player'; break;
            case 'missing': unset($state->cards[$id]); break;
        }
        $before = $state->toArray();
        $result = $engine->apply($state, 'host', new \Berserk\Core\Command('choose_life_gift',
            ['amount' => 1, 'enemy_id' => 4, 'ally_id' => 2]));
        check(!$result->success, 'Reject stale ' . $side . ': ' . $change);
        check($state->toArray() === $before, 'Rejected command preserves full state');
        $html = $render($state)['info_panel_html'];
        check(!str_contains($html, 'data-card-instance="' . $id . '"'), 'Stale card absent from groups');
        check(str_contains($html, 'cmd=cancel_pending'), 'Cancellation remains available');
        $result = $engine->apply($state, 'host', new \Berserk\Core\Command('cancel_pending'));
        check($result->success && empty($state->battle['pending_life_gift']), 'Cancel stale selection');
        check($state->getCard(1)->coins === 3 && !$state->getCard(1)->closed, 'Cancel is free');
    }
}
$state = fixture('life_gift');
$result = $engine->apply($state, 'host', new \Berserk\Core\Command('choose_life_gift',
    ['amount' => 1, 'enemy_id' => 4, 'ally_id' => 2]));
check($result->success, 'Accept valid living targets');
check($state->getCard(1)->coins === 2 && $state->getCard(1)->closed, 'Charge valid action once');
check($state->getCard(4)->hp === 7 && $state->getCard(2)->hp === 9, 'Damage and heal valid targets');
echo "Life gift stale target regression passed.\n";
