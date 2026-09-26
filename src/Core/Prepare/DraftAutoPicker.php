<?php
// src/Core/Prepare/DraftAutoPicker.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

final class DraftAutoPicker
{
    public function __construct(private DraftSelectionEvaluator $evaluator) {}

    /**
     * @param array<int,array{positions:int[],cards:string[]}> $validSelections
     * @param string[] $currentUkids
     * @return array{positions:int[],cards:string[]}|null
     */
    public function pick(array $validSelections, array $currentUkids): ?array
    {
        if (empty($validSelections)) return null;

        $total = 0.0;
        foreach ($validSelections as $i => $selection) {
            $weight = $this->evaluator->weight($currentUkids, $selection['cards']);
            $validSelections[$i]['weight'] = $weight;
            $total += $weight;
        }

        if ($total <= 0.0) {
            return $validSelections[random_int(0, count($validSelections) - 1)];
        }

        $roll = random_int(1, 1_000_000) / 1_000_000 * $total;
        foreach ($validSelections as $selection) {
            $roll -= $selection['weight'];
            if ($roll <= 0.0) {
                unset($selection['weight']);
                return $selection;
            }
        }

        $selection = $validSelections[array_key_last($validSelections)];
        unset($selection['weight']);
        return $selection;
    }
}
