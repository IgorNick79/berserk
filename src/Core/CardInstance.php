<?php
// src/Core/CardInstance.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Одна конкретная карта в партии.
 * Отличается от справочной карты (cards) тем, что имеет состояние.
 */
final class CardInstance
{
    public const ZONE_DECK      = 'deck';
    public const ZONE_SIDEBOARD = 'sideboard';
    public const ZONE_HAND      = 'hand';
    public const ZONE_SQUAD     = 'squad';
    public const ZONE_FIELD     = 'field';
    public const ZONE_DISCARD   = 'discard';
    public const ZONE_GRAVEYARD = 'graveyard';
    public const ZONE_EXILE     = 'exile';
    public const ZONE_FLYING    = 'flying';

    public function __construct(
        public int $instanceId,
        public string $ukid,
        public string $owner,
        public string $zone = self::ZONE_DECK,
        public ?int $order = null,
        public ?int $row = null,
        public ?int $col = null,
        public int $slot = 0,
        public int $hp = 0,
        public int $hpMax = 0,
        public int $price = 0,
        public bool $elite = false,
        public string $type = 'creature',
        public bool $closed = false,
        public bool $revealed = true,
        public bool $dying = false,
        public string $element = 'neutral',
        public string $class = '',
        public int $move = 0,
        public int $moveMax = 0,
        public int $armor = 0,
        public int $armorMax = 0,
        public int $strikeWeak = 0,
        public int $strikeMedium = 0,
        public int $strikeStrong = 0,
        public int $coins = 0,
        public array $prop = [],
        public array $modifiers = [],
        public array $markers = [],
        public array $flags = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            instanceId: (int) $data['instance_id'],
            ukid:       (string) $data['ukid'],
            owner:      (string) $data['owner'],
            zone:       (string) ($data['zone'] ?? self::ZONE_DECK),
            order:      isset($data['order']) ? (int) $data['order'] : null,
            row:        isset($data['row'])   ? (int) $data['row']   : null,
            col:        isset($data['col'])   ? (int) $data['col']   : null,
            slot:         (int) ($data['slot'] ?? 0),
            hp:           (int) ($data['hp'] ?? 0),
            hpMax:        (int) ($data['hp_max'] ?? 0),
            price:        (int) ($data['price'] ?? 0),
            elite:        (bool) ($data['elite'] ?? false),
            type:         (string) ($data['type'] ?? 'creature'),
            closed:       (bool) ($data['closed'] ?? false),
            revealed:     (bool) ($data['revealed'] ?? true),
            dying:        (bool) ($data['dying'] ?? false),
            element:      (string) ($data['element'] ?? 'neutral'),
            class:        (string) ($data['class'] ?? ''),
            move:         (int) ($data['move'] ?? 0),
            moveMax:      (int) ($data['move_max'] ?? 0),
            armor:        (int) ($data['armor'] ?? 0),
            armorMax:     (int) ($data['armor_max'] ?? 0),
            strikeWeak:   (int) ($data['strike_weak'] ?? 0),
            strikeMedium: (int) ($data['strike_medium'] ?? 0),
            strikeStrong: (int) ($data['strike_strong'] ?? 0),
            coins:        (int) ($data['coins'] ?? 0),
            prop:         (array) ($data['prop'] ?? []),
            modifiers:    (array) ($data['modifiers'] ?? []),
            markers:      (array) ($data['markers'] ?? []),
            flags:        (array) ($data['flags'] ?? []),
        );
    }

    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'ukid'        => $this->ukid,
            'owner'       => $this->owner,
            'zone'        => $this->zone,
            'order'       => $this->order,
            'row'         => $this->row,
            'col'         => $this->col,
            'slot'          => $this->slot,
            'hp'            => $this->hp,
            'hp_max'        => $this->hpMax,
            'price'         => $this->price,
            'elite'         => $this->elite,
            'type'          => $this->type,
            'revealed'      => $this->revealed,
            'dying'         => $this->dying,
            'closed'        => $this->closed,
            'element'       => $this->element,
            'class'         => $this->class,
            'move'          => $this->move,
            'move_max'      => $this->moveMax,
            'armor'         => $this->armor,
            'armor_max'     => $this->armorMax,
            'strike_weak'   => $this->strikeWeak,
            'strike_medium' => $this->strikeMedium,
            'strike_strong' => $this->strikeStrong,
            'coins'         => $this->coins,
            'prop'          => $this->prop,
            'modifiers'     => $this->modifiers,
            'markers'       => $this->markers,
            'flags'         => $this->flags,
        ];
    }

    public function isOnField(): bool
    {
        return $this->zone === self::ZONE_FIELD;
    }

    public function isAlive(): bool
    {
        return $this->hp > 0 && $this->zone !== self::ZONE_GRAVEYARD;
    }

    public function effectiveMove(): int
    {
        $bonus = 0;
        foreach ($this->modifiers as $m) {
            if (($m['stat'] ?? '') === 'move') {
                $bonus += (int) ($m['value'] ?? 0);
            }
        }
        return max(0, $this->moveMax + $bonus);
    }

    public function isFlying(): bool
    {
        return $this->type === 'fly';
    }

}
