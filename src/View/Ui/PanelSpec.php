<?php
// src/View/Ui/PanelSpec.php

declare(strict_types=1);

namespace Berserk\View\Ui;

final class PanelSpec
{
    /**
     * @param string   $title
     * @param string[] $cards        HTML-строки карточек (уже отрендеренные)
     * @param string[] $text         текстовые блоки (с HTML внутри)
     * @param ?array   $form         ['type'=>'radio'|'checkbox', 'name'=>..., 'items'=>[...], 'hidden'=>[...], 'submit'=>..., 'cancel'=>...]
     * @param array    $buttons      [['label'=>..., 'url'=>..., 'class'=>...], ...]
     * @param bool     $isMine
     * @param string   $waitText
     */
    public function __construct(
        public string $title,
        public array  $cards = [],
        public array  $text = [],
        public ?array $form = null,
        public array  $buttons = [],
        public bool   $isMine = true,
        public string $waitText = 'Ожидание выбора оппонента...',
    ) {}
}