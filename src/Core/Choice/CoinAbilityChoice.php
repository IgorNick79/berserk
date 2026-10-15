<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{CoinAbilities, Command, Engine, GameState, Result};
use Berserk\View\Ui\PanelSpec;
final class CoinAbilityChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_coin_ability'; }
    public function commandTypes(): array { return ['choose_coin_ability']; }
    public function spec(GameState $state, string $playerKey, array $cardsInfo, string $baseUrl, string $role): ?PanelSpec
    {
        $p = $state->battle[$this->pendingKey()] ?? null;
        if (!$p) return null;
        $card = $state->getCard((int) $p['card_id']);
        $name = $card ? ($cardsInfo[$card->ukid]['name'] ?? 'Карта') : 'Карта';
        $title = $name . ' — ' . match ($p['kind']) {
            'gain' => 'получить монетку?', 'block' => 'блокировать удар за монетку?', default => 'разряд при ударе',
        };
        if ($p['owner'] !== $playerKey) return new PanelSpec(title: $title, isMine: false);
        $baseUrl .= '&choice_id=' . rawurlencode($p['id']);
        $buttons = [];
        if ($p['kind'] === 'discharge') {
            foreach ($p['candidates'] as $id) {
                $target = $state->getCard((int) $id);
                if (!$target) continue;
                $buttons[] = ['label' => ($cardsInfo[$target->ukid]['name'] ?? 'Цель') . " ({$target->row},{$target->col})",
                    'url' => $baseUrl . '&cmd=choose_coin_ability&target_id=' . $id];
            }
        } else {
            $buttons = [
                ['label' => $p['kind'] === 'gain' ? 'Получить монетку' : 'Блокировать атаку',
                    'url' => $baseUrl . '&cmd=choose_coin_ability&choice=yes'],
                ['label' => $p['kind'] === 'gain' ? 'Не получать монетку' : 'Отмена',
                    'url' => $baseUrl . '&cmd=choose_coin_ability&choice=no', 'class' => 'skip'],
            ];
        }
        return new PanelSpec(title: $title, buttons: $buttons);
    }
    public function apply(GameState $state, Engine $engine, string $playerKey, Command $cmd): Result
    {
        return (new CoinAbilities($state, $engine))->choose($playerKey, $cmd);
    }
}
