<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState, Engine, Command, Result, FlyingPreparation, BattleHelper};
use Berserk\View\Ui\PanelSpec;
final class FlyingPreparationChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_flying_preparation'; }
    public function commandTypes(): array { return ['flying_preparation_ack']; }
    public function spec(GameState $state, string $playerKey, array $cardsInfo, string $baseUrl, string $role): ?PanelSpec
    {
        $p = $state->battle[$this->pendingKey()] ?? null;
        if (!$p) return null;
        $url = $baseUrl . '&cmd=flying_preparation_ack&operation_id=' . rawurlencode($p['id']);
        return new PanelSpec(title: 'Подготовка к атаке летающих', text: [
            'У вашего существа нет доступных целей для обычной ближней атаки.',
            'Вы можете закрыть его на текущий ход, чтобы на следующем своём ходу получить возможность атаковать любое летающее существо противника ближней атакой, если условия атаки сохранятся',
        ], buttons: [
            ['label'=>'Подтвердить', 'url'=>$url . '&confirm=1'],
            ['label'=>'Отмена', 'url'=>$url . '&confirm=0'],
        ], isMine: $p['owner'] === $playerKey);
    }
    public function apply(GameState $state, Engine $engine, string $playerKey, Command $cmd): Result
    {
        $p = $state->battle[$this->pendingKey()] ?? null;
        if (!$p || $p['owner'] !== $playerKey || $cmd->get('operation_id', '') !== $p['id']) return Result::error('Подтверждение устарело');
        if ((int)$cmd->get('confirm', 0) === 1) {
            $c = $state->getCard($p['source_id']);
            if (!$c || !FlyingPreparation::canPrepare($state, $c)
                || BattleHelper::getAttackTargets($state, $c, 'strike', $playerKey) === []) return Result::error('Подготовка больше недоступна');
            $c->closed = true;
            $c->flags[FlyingPreparation::FLAG] = 'waiting';
        }
        unset($state->battle[$this->pendingKey()]);
        $state->bumpVersion();
        return Result::ok(['flying_preparation_ack']);
    }
}
