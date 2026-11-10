<?php
declare(strict_types=1);

namespace Berserk\View\Ui;

final class CardSelectionGroup
{
    /**
     * @param list<array> $items Rows with an optional instanceId for battlefield candidates.
     * @param list<int|string> $selected Instance IDs for cards, command values for other rows.
     */
    public function __construct(
        public string $id,
        public string $field,
        public array $items,
        public string $label = '',
        public int $minSelect = 1,
        public int $maxSelect = 1,
        public array $selected = [],
        public bool $checkbox = false,
    ) {
        if ($minSelect < 0 || $maxSelect < 1 || $minSelect > $maxSelect) {
            throw new \InvalidArgumentException('Invalid card selection limits');
        }
    }
}
