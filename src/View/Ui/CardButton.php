<?php
// src/View/Ui/CardButton.php

declare(strict_types=1);

namespace Berserk\View\Ui;

use Berserk\Core\CardInstance;
use Berserk\View\Template;

final class CardButton
{
    /**
     * @param array{name?:string} $cardInfo
     * @param array{class?:string,show_coordinates?:bool} $options
     */
    public static function battle(
        Template $tpl,
        CardInstance $card,
        array $cardInfo,
        string $link,
        string $viewerKey,
        array $options = []
    ): string {
        $label = (string) ($cardInfo['name'] ?? $card->ukid);
        $showCoordinates = $options['show_coordinates'] ?? self::isBattlefieldCard($card);

        $labelHtml = htmlspecialchars($label, ENT_QUOTES);
        if ($showCoordinates && self::isBattlefieldCard($card)) {
            $coords = Coords::render($card->row, $card->col, $viewerKey, $card->owner);
            if ($coords !== '') $labelHtml .= ' ' . $coords;
        }

        return (string) $tpl->parse('includes/battle/defender_button.tpl', [
            'name'  => $labelHtml,
            'link'  => htmlspecialchars($link, ENT_QUOTES),
            'class' => htmlspecialchars((string) ($options['class'] ?? ''), ENT_QUOTES),
        ]);
    }

    private static function isBattlefieldCard(CardInstance $card): bool
    {
        return $card->zone === CardInstance::ZONE_FIELD
            || $card->zone === CardInstance::ZONE_FLYING;
    }
}
