<?php
// src/View/Ui/TaskCard.php

declare(strict_types=1);

namespace Berserk\View\Ui;

/**
 * Карточка-задача для очереди в фазе хода.
 * Используется в TurnPhaseProcessor / InfoPanel::renderTaskList.
 */
final class TaskCard
{
    /**
     * @param array{
     *   label?: string,
     *   card_ukid?: string,
     *   row?: int|null,
     *   col?: int|null,
     *   hint?: string,
     *   is_instant?: bool,
     * } $task
     */
    public static function render(
        array $task,
        string $url,
        array $cardsInfo = [],
        bool $withCoords = true
    ): string {
        $name = null;
        if (!empty($task['card_ukid'])) {
            $name = $cardsInfo[$task['card_ukid']]['name'] ?? $task['card_ukid'];
        }

        $main = $name ?? ($task['label'] ?? '');

        $coords = '';
        if ($withCoords && isset($task['row']) && $task['row'] !== null) {
            $coords = ' (' . (int) $task['row'] . ',' . (int) $task['col'] . ')';
        }

        $hint = '';
        if (!empty($task['hint']) && $name !== null) {
            $hint = $task['hint'];
        }

        $class = 'task-card';
        if (!empty($task['is_instant'])) {
            $class .= ' task-card--instant';
        }

        $html = '<a class="' . $class . '" href="' . htmlspecialchars($url, ENT_QUOTES) . '">';
        $html .= '<div class="task-card__name">'
            . htmlspecialchars($main, ENT_QUOTES) . $coords . '</div>';
        if ($hint !== '') {
            $html .= '<div class="task-card__hint">'
                . htmlspecialchars($hint, ENT_QUOTES) . '</div>';
        }
        $html .= '</a>';

        return $html;
    }
}