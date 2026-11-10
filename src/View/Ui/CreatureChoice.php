<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Core\{CardInstance, GameState};

/** Shared creature presentation; never changes gameplay candidates. */
final class CreatureChoice
{
    public static function item(CardInstance $card, string $name, string $viewer, bool $showHealth = false, bool $showCoords = true): array
    {
        return ['value' => $card->instanceId, 'label' => $name, 'side' => $card->owner === $viewer ? 'own' : 'enemy',
            'label_html' => '<span class="creature-choice-name">' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
                . ($showHealth && ($card->owner === $viewer || $card->revealed)
                    ? '<span class="creature-choice-health">❤️ ' . $card->hp . '/' . $card->hpMax . ' HP</span>' : '')
                . ($showCoords ? Coords::renderResult($card->row, $card->col, $viewer, $card->owner) : '')];
    }

    /** @param list<int> $ids */
    public static function candidates(GameState $state, array $ids, string $viewer, array $catalog, string $mode): array
    {
        $cards = $names = $counts = [];
        foreach ($ids as $id) {
            $card = $state->getCard($id);
            if (!$card || isset($cards[$id])) continue;
            $cards[$id] = $card;
            $name = $card->owner === $viewer || $card->revealed
                ? (string) ($catalog[$card->ukid]['name'] ?? $card->ukid)
                : '???';
            $names[$id] = $name;
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        $items = [];
        foreach ($cards as $id => $card) {
            $name = $names[$id];
            $position = $card->zone === 'field' ? BattlefieldPosition::display($card->row, $card->col, $viewer) : null;
            $item = self::item($card, $name, $viewer, $mode !== CardSelectionSpec::SIMPLE, $position !== null && $counts[$name] > 1);
            $item['instanceId'] = $id;
            $item['row'] = $card->zone === 'flying' ? 'fly' : (string) ($position['row'] ?? '');
            if ($item['row'] === 'fly' && $counts[$name] > 1 && $card->slot > 0) {
                $item['label_html'] .= '<span class="coords coords--' . $item['side'] . '">Слот ' . $card->slot . '</span>';
            }
            if ($card->zone === 'graveyard' && $counts[$name] > 1 && ($card->owner === $viewer || $card->revealed)) {
                $item['label_html'] .= '<span> #' . $id . '</span>';
            }
            $items[] = $item;
        }
        usort($items, static fn(array $a, array $b): int =>
            strcmp(self::sortName($a['label']), self::sortName($b['label'])) ?: $a['instanceId'] <=> $b['instanceId']);
        return $items;
    }

    /**
     * Opt in only for confirmed forms whose declared rows carry instance IDs.
     * Keeps the wire values, defaults and original submission order.
     */
    public static function confirmed(
        GameState $state, string $viewer, array $catalog, array $rows, string $decision,
        int $min = 1, int $max = 1, string $mode = CardSelectionSpec::DEFAULT_MODE, bool $field = true
    ): CardSelectionSpec {
        $byId = [];
        foreach (array_values($rows) as $order => $row) {
            $byId[(int) ($row['instanceId'] ?? $row['value'])] = $row + ['submitOrder' => $order];
        }
        $items = self::candidates($state, array_keys($byId), $viewer, $catalog, $mode);
        $selected = [];
        foreach ($items as &$item) {
            $legacy = $byId[$item['instanceId']];
            $item['value'] = $legacy['value'];
            $item['submitOrder'] = $legacy['submitOrder'];
            $item['disabled'] = !empty($legacy['disabled']);
            if (!empty($legacy['checked']) && !$item['disabled']) $selected[] = $item['instanceId'];
            if (isset($legacy['suffix'])) {
                $item['label_html'] .= '<span>' . htmlspecialchars($legacy['suffix'], ENT_QUOTES) . '</span>';
            }
        }
        unset($item);

        return new CardSelectionSpec(
            context: implode(':', [$state->gameId, $viewer, $decision]),
            candidates: $items, mode: $mode, minSelect: $min, maxSelect: $max, selected: $selected,
            battlefieldOwner: $field ? $viewer : null,
        );
    }

    /** A confirmed target step with the existing standalone cancel link if no candidates remain. */
    public static function targetPanel(
        GameState $state, string $viewer, array $catalog, array $rows, string $decision,
        string $title, array $hidden, string $cancel, string $mode = CardSelectionSpec::SIMPLE, bool $field = true
    ): PanelSpec {
        return new PanelSpec(
            title: $title,
            form: $rows ? ['name'=>'target_id', 'hidden'=>$hidden, 'submit'=>'Выбрать', 'cancel'=>$cancel] : null,
            buttons: $rows ? [] : [['label'=>'Отмена', 'url'=>$cancel, 'class'=>'skip']],
            cardSelection: $rows ? self::confirmed($state, $viewer, $catalog, $rows, $decision, mode:$mode, field:$field) : null,
        );
    }

    /** Immediate links retain their caller-supplied URL and order. */
    public static function immediateButtons(GameState $state, string $viewer, array $catalog, array $links): array
    {
        $items = array_column(self::candidates($state, array_column($links, 'instanceId'), $viewer, $catalog,
            CardSelectionSpec::DEFAULT_MODE), null, 'instanceId');
        $buttons = [];
        foreach ($links as $link) {
            $item = $items[$link['instanceId']] ?? null;
            if (!$item) continue;
            $prefix = htmlspecialchars((string) ($link['prefix'] ?? ''), ENT_QUOTES);
            $suffix = htmlspecialchars((string) ($link['suffix'] ?? ''), ENT_QUOTES);
            $buttons[] = $link + ['label' => $item['label'], 'label_html' => $prefix . $item['label_html'] . $suffix];
        }
        return $buttons;
    }

    private static function sortName(string $name): string
    {
        return str_replace('ё', 'е', mb_strtolower($name, 'UTF-8'));
    }

    public static function decorate(PanelSpec $spec, GameState $state, string $viewer, array $catalog): void
    {
        if (!$spec->isMine || $spec->cardSelection !== null) return;
        if ($spec->form === null || !in_array($spec->form['name'] ?? '', ['target_id', 'target_ids[]', 'card_id'], true)) return;
        $showHealth = in_array($spec->form['hidden']['cmd'] ?? '', ['choose_multi_heal', 'choose_wild_healer_heal', 'wt_target'], true)
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
        $spec->form['side_toggle'] = !empty($spec->form['side_toggle']) || ($spec->form['type'] ?? 'radio') === 'radio' && count(array_unique(array_column($spec->form['items'], 'side'))) > 1;
    }

    public static function controls(string $defaultSide = 'enemy'): string
    {
        return '<div class="creature-choice-sides" data-creature-sides data-default-side="' . ($defaultSide === 'own' ? 'own' : 'enemy') . '" hidden role="group" aria-label="Сторона существа">'
            . '<button type="button" data-creature-side="own" aria-pressed="false">Свои</button>'
            . '<button type="button" data-creature-side="enemy" aria-pressed="false">Чужие</button></div>';
    }
}
