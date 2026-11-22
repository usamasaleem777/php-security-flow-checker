<?php

declare(strict_types=1);

namespace SecurityFlow\Mathematics;

use InvalidArgumentException;
use SecurityFlow\Models\Action;
use SecurityFlow\Models\TransitionSystem;

final class FixedPointEngine
{
    /** @return array{predecessors: array<string, Action|null>, max_depth_explored: int, fixed_point_reached: bool} */
    public function reach(TransitionSystem $model, ?int $maxDepth = null): array
    {
        if ($maxDepth !== null && $maxDepth < 0) {
            throw new InvalidArgumentException('Maximum depth must be nonnegative.');
        }

        $frontier = $model->initialStates;
        $predecessors = array_fill_keys($frontier, null);
        $depth = 0;
        $fixedPointReached = true;

        while ($frontier !== []) {
            $nextFrontier = [];
            foreach ($frontier as $state) {
                // The frontier contains only the states added in the latest fixed-point iteration.
                foreach ($model->outgoing[$state] as $edge) {
                    $target = $edge->to;
                    if (array_key_exists($target, $predecessors)) {
                        continue;
                    }
                    if ($depth === $maxDepth) {
                        $fixedPointReached = false;
                        continue;
                    }
                    $predecessors[$target] = $edge;
                    $nextFrontier[] = $target;
                }
            }

            $frontier = $nextFrontier;
            if ($frontier !== []) {
                ++$depth;
            }
        }

        return [
            'predecessors' => $predecessors,
            'max_depth_explored' => $depth,
            'fixed_point_reached' => $fixedPointReached,
        ];
    }
}
