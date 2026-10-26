<?php
declare(strict_types=1);
namespace Berserk\Core;

use Berserk\View\Ui\{PanelSpec, BattlefieldPosition, Coords};

/** Three stages of the native revive pending; no resources move until the final choice. */
final class StitchRevival
{
    public const USED = 'stitch_revive_used';
    public function __construct(private GameState $state, private Engine $engine) {}

    public static function isAction(array $action): bool
    {
        return ($action['type'] ?? '') === 'revive' && !empty($action['stitch']);
    }

    public static function isPending(GameState $state): bool
    {
        return self::isAction($state->battle['pending_revive']['action'] ?? []);
    }

    public static function legalHealTarget(CardInstance $source, CardInstance $target, array $action): bool
    {
        if (empty($action['heal_gain_coin'])) return true;
        return $target->owner === $source->owner && $target->element === 'dark'
            && in_array($target->type, ['creature', 'fly'], true)
            && EffectiveFeatures::onBattlefield($target) && !$target->dying
            && $target->hp > 0 && $target->hp < $target->hpMax;
    }

    private static function sourceReady(GameState $state, CardInstance $source, array $action): bool
    {
        if ($state->status !== 'battle' || $state->winner !== null
            || ($state->battle['active'] ?? null) !== $source->owner
            || $source->zone !== CardInstance::ZONE_FIELD || $source->row === null || $source->col === null
            || $source->dying || $source->hp <= 0 || CardStats::isDisabled($source)
            || EffectiveFeatures::suppressed($source) || !empty($source->flags[self::USED])
            || $source->coins < (int) ($action['coins'] ?? 3)) return false;
        foreach ($source->effectiveProp()['actions'] ?? [] as $current) {
            if (self::isAction($current) && $current === $action) return true;
        }
        return false;
    }

    public static function cells(GameState $state, CardInstance $source): array
    {
        if ($source->zone !== CardInstance::ZONE_FIELD || $source->row === null || $source->col === null) return [];
        $cells = []; $zone = new ZoneManager($state);
        for ($r = max(1, $source->row - 1); $r <= min(6, $source->row + 1); ++$r) {
            for ($c = max(1, $source->col - 1); $c <= min(5, $source->col + 1); ++$c) {
                if (($r === $source->row && $c === $source->col) || $zone->isFieldOccupied($r, $c)
                    || ZoneManager::hasBlockingMarker($state, "{$r}_{$c}")) continue;
                $cells[] = "{$r}_{$c}";
            }
        }
        return $cells;
    }

    public static function candidates(GameState $state, string $owner, bool $revive, int $exclude = 0): array
    {
        $ids = [];
        foreach ($state->cards as $card) {
            if ($card->owner !== $owner || $card->zone !== CardInstance::ZONE_GRAVEYARD
                || $card->dying || $card->instanceId === $exclude
                || !in_array($card->type, $revive ? ['creature'] : ['creature', 'fly'], true)
                || ($revive && $card->hpMax <= 0)) continue;
            $ids[] = $card->instanceId;
        }
        return $ids;
    }

    public static function available(GameState $state, CardInstance $source, array $action): bool
    {
        return self::sourceReady($state, $source, $action) && self::cells($state, $source)
            && count(self::candidates($state, $source->owner, false)) >= 2
            && self::candidates($state, $source->owner, true);
    }

    public function start(CardInstance $source, array $action): Result
    {
        if (!self::available($this->state, $source, $action)
            || Choice\ChoiceRegistry::current($this->state) !== null) return Result::error('Сшивание недоступно');
        $this->state->battle['pending_revive'] = [
            'healer_id' => $source->instanceId, 'action' => $action, 'cost' => 0,
            'step' => 'card', 'chosen_id' => null, 'exile_id' => null,
            'choice_id' => bin2hex(random_bytes(16)),
        ];
        $this->state->bumpVersion();
        return Result::ok(['stitch_revive_started']);
    }

    public function choose(string $owner, Command $cmd): Result
    {
        $p = $this->state->battle['pending_revive'] ?? null;
        $source = $this->state->getCard((int) ($p['healer_id'] ?? 0));
        if (!$p || !self::isPending($this->state) || !$source || $source->owner !== $owner
            || !hash_equals($p['choice_id'], (string) $cmd->get('choice_id', '')))
            return Result::error('Выбор сшивания устарел');
        if ($cmd->type === 'cancel_pending') return $this->finishWithoutPayment('stitch_revive_cancelled');
        if (!self::available($this->state, $source, $p['action'])) return $this->finishWithoutPayment('stitch_revive_unavailable');
        $revive = $this->state->getCard((int) $p['chosen_id']);
        if ($p['step'] !== 'card' && !in_array($p['chosen_id'], self::candidates($this->state, $owner, true), true))
            return $this->finishWithoutPayment('stitch_revive_target_lost');
        if ($cmd->type === 'choose_revive_target' && in_array($p['step'], ['card', 'exile'], true)) {
            $id = (int) $cmd->get('target_id', 0);
            $ids = self::candidates($this->state, $owner, $p['step'] === 'card', (int) $p['chosen_id']);
            if (!in_array($id, $ids, true)) return Result::error('Неверное существо с кладбища');
            if ($p['step'] === 'card') { $p['chosen_id'] = $id; $p['step'] = 'exile'; }
            else { $p['exile_id'] = $id; $p['step'] = 'cell'; }
            // A token per stage rejects a delayed duplicate of the preceding selection.
            $p['choice_id'] = bin2hex(random_bytes(16));
            $this->state->battle['pending_revive'] = $p;
            $this->state->bumpVersion();
            return Result::ok(['stitch_revive_choose_' . $p['step']]);
        }
        if ($cmd->type !== 'choose_revive_cell' || $p['step'] !== 'cell') return Result::error('Неверный этап сшивания');
        if (!in_array($p['exile_id'], self::candidates($this->state, $owner, false, $revive->instanceId), true))
            return $this->finishWithoutPayment('stitch_revive_exile_lost');
        $r = (int) $cmd->get('row', 0); $c = (int) $cmd->get('col', 0);
        if (!in_array("{$r}_{$c}", self::cells($this->state, $source), true)) return Result::error('Клетка возрождения недоступна');
        $exile = $this->state->getCard($p['exile_id']);
        $x = $exile->strikeWeak; $y = $exile->hpMax;
        // All validation is complete. The normal repository command transaction saves this as one operation.
        $source->coins -= (int) ($p['action']['coins'] ?? 3);
        $source->closed = true; $source->flags[self::USED] = true;
        $this->engine->syncCoinBonus($source);
        $zone = new ZoneManager($this->state);
        $zone->toExile($exile);
        $revive->strikeWeak += $x; $revive->strikeMedium += $x; $revive->strikeStrong += $x;
        $revive->hpMax += $y; $revive->hp = $revive->hpMax;
        $revive->move = $revive->moveMax; $revive->armor = 0; $revive->armorMax = 0;
        $revive->coins = 0; $revive->closed = true; $revive->dying = false; $revive->revealed = true;
        $revive->markers = []; $revive->modifiers = [];
        $revive->flags = MemoryLoss::persistentFlags($revive);
        $revive->flags['stitched_unique_loss'] = true;
        $zone->toField($revive, $r, $c);
        unset($this->state->battle['pending_revive']);
        $this->engine->refreshArmor($this->state);
        $this->engine->checkGameOver($this->state);
        ActionResolution::performed($this->state, $source, $p['action']);
        $this->state->bumpVersion();
        return Result::ok(["stitched:{$source->instanceId}:{$revive->instanceId}:{$exile->instanceId}"]);
    }

    private function finishWithoutPayment(string $event): Result
    {
        unset($this->state->battle['pending_revive']);
        $this->state->bumpVersion();
        return Result::ok([$event]);
    }

    public static function spec(GameState $state, string $viewer, array $catalog, string $baseUrl): PanelSpec
    {
        $p = $state->battle['pending_revive']; $source = $state->getCard($p['healer_id']);
        if (!$source || $source->owner !== $viewer) return new PanelSpec(title: 'Сшивание плоти', isMine: false);
        $buttons = []; $suffix = '&choice_id=' . rawurlencode($p['choice_id']);
        if (self::available($state, $source, $p['action'])) {
            if ($p['step'] === 'cell') {
                $reviveIds = self::candidates($state, $viewer, true);
                $exileIds = self::candidates($state, $viewer, false, (int) $p['chosen_id']);
                if (in_array($p['chosen_id'], $reviveIds, true) && in_array($p['exile_id'], $exileIds, true)) {
                    foreach (self::cells($state, $source) as $cell) {
                        [$r, $c] = array_map('intval', explode('_', $cell));
                        $buttons[] = ['label' => BattlefieldPosition::label($r, $c, $viewer),
                            'label_html' => Coords::render($r, $c, $viewer),
                            'url' => "{$baseUrl}&cmd=choose_revive_cell&row={$r}&col={$c}{$suffix}"];
                    }
                }
            } elseif ($p['step'] === 'card' || in_array($p['chosen_id'], self::candidates($state, $viewer, true), true)) {
                foreach (self::candidates($state, $viewer, $p['step'] === 'card', (int) $p['chosen_id']) as $id) {
                    $card = $state->getCard($id);
                    $buttons[] = ['label' => (string) ($catalog[$card->ukid]['name'] ?? $card->ukid),
                        'url' => "{$baseUrl}&cmd=choose_revive_target&target_id={$id}{$suffix}"];
                }
            }
        }
        $buttons[] = ['label' => 'Отмена', 'url' => $baseUrl . '&cmd=cancel_pending' . $suffix, 'class' => 'skip'];
        $title = match ($p['step']) {
            'card' => 'Сшивание — выберите нелетающее существо для возрождения',
            'exile' => 'Сшивание — выберите другое существо для изгнания',
            default => 'Сшивание — выберите соседнюю клетку возрождения',
        };
        return new PanelSpec(title: $title, buttons: $buttons);
    }
}
