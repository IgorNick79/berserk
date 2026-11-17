<?php
declare(strict_types=1);

namespace Berserk\View\Ui;

final class CardSelectionSpec
{
    public const DEFAULT_MODE = 'default';
    public const SIMPLE = 'simple';
    public const EXTENDED = 'extended';
    public const CONFIRM = 'confirm';

    /**
     * @param list<array{instanceId:int,value:int|string,label:string,label_html:string,side:string,row:string}> $candidates
     * @param list<int> $selected Instance IDs, independent of command values.
     */
    public function __construct(
        public string $context,
        public array $candidates,
        public string $mode = self::DEFAULT_MODE,
        public int $minSelect = 1,
        public int $maxSelect = 1,
        public array $selected = [],
        public string $confirmation = self::CONFIRM,
    ) {}
}
