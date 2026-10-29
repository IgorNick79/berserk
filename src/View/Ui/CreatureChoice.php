<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Core\{CardInstance, GameState};

/** Shared creature presentation; never changes gameplay candidates. */
final class CreatureChoice
{
    public static function item(CardInstance $card, string $name, string $viewer, bool $showHealth = false): array
    {
        return ['value' => $card->instanceId, 'label' => $name, 'side' => $card->owner === $viewer ? 'own' : 'enemy',
            'label_html' => '<span class="creature-choice-name">' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
                . ($showHealth && ($card->owner === $viewer || $card->revealed)
                    ? '<span class="creature-choice-health">❤ ' . $card->hp . '/' . $card->hpMax . ' HP</span>' : '')
                . Coords::renderResult($card->row, $card->col, $viewer, $card->owner)];
    }

    public static function decorate(PanelSpec $spec, GameState $state, string $viewer, array $catalog): void
    {
        if (!$spec->isMine) return;
        if ($spec->form === null) {
            $items = []; $remaining = []; $hidden = null;
            foreach ($spec->buttons as $button) {
                parse_str((string) parse_url($button['url'], PHP_URL_QUERY), $query);
                if (!in_array($query['cmd'] ?? '', ['choose_instant_pick', 'choose_combat_pick', 'choose_revive_target', 'choose_death_target'], true)
                    || !isset($query['target_id']) || !$state->getCard((int) $query['target_id'])) {
                    $remaining[] = $button; continue;
                }
                $card = $state->getCard((int) $query['target_id']);
                $items[] = self::item($card, (string) ($catalog[$card->ukid]['name'] ?? $card->ukid), $viewer);
                unset($query['target_id']); $hidden ??= $query;
            }
            if ($items) {
                $spec->buttons = [];
                foreach ($remaining as $button) {
                    if (str_contains($button['url'],'cmd=cancel_pending')) $cancel=$button['url'];
                    else $spec->buttons[]=$button;
                }
                $spec->form = ['type' => 'radio', 'name' => 'target_id', 'items' => $items, 'hidden' => $hidden, 'submit' => 'Выбрать'];
                if (isset($cancel)) $spec->form['cancel']=$cancel;
            }
        }
        if ($spec->form === null || !in_array($spec->form['name'] ?? '', ['target_id', 'target_ids[]', 'card_id'], true)) return;
        $showHealth = in_array($spec->form['hidden']['cmd'] ?? '', ['choose_multi_heal', 'choose_wild_healer_heal'], true)
            || (($spec->form['hidden']['cmd'] ?? '') === 'choose_instant_pick'
                && ($state->battle['pending_instant_pick']['effect']['type'] ?? '') === 'heal');
        foreach ($spec->form['items'] as &$item) {
            $value = (string) ($item['value'] ?? '');
            if (!ctype_digit($value)) continue;
            $card = $state->getCard((int) $value);
            if (!$card || !in_array($card->zone, ['field', 'flying'], true)) continue;
            $name = !$card->revealed && $card->owner !== $viewer ? '???' : (string) ($catalog[$card->ukid]['name'] ?? $card->ukid);
            $item = array_replace($item, self::item($card, $name, $viewer, $showHealth));
        }
        unset($item);
        $spec->form['side_default'] ??= $showHealth ? 'own' : 'enemy';
        $spec->form['side_toggle'] = ($spec->form['type'] ?? 'radio') === 'radio' && count(array_unique(array_column($spec->form['items'], 'side'))) > 1;
    }

    public static function controls(string $defaultSide = 'enemy'): string
    {
        return '<div class="creature-choice-sides" data-creature-sides data-default-side="' . ($defaultSide === 'own' ? 'own' : 'enemy') . '" hidden role="group" aria-label="Сторона существа">'
            . '<button type="button" data-creature-side="own" aria-pressed="false">Свои</button>'
            . '<button type="button" data-creature-side="enemy" aria-pressed="false">Чужие</button></div>';
    }
}
