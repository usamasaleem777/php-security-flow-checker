<?php

declare(strict_types=1);

namespace SecurityFlow\Mathematics;

use InvalidArgumentException;
use SecurityFlow\Models\Action;
use SecurityFlow\Models\TransitionSystem;

final class FixedPointEngine
{
    /**
     * @return array{
     *     predecessors: array<string, Action|null>,
     *     enabled_edges: list<Action>,
     *     depth_boundary_edges: list<Action>,
     *     transitions_considered: int,
     *     max_depth_explored: int,
     *     fixed_point_reached: bool,
     *     depth_limit_exhausted: bool,
     *     state_limit_exhausted: bool,
     *     transition_limit_exhausted: bool,
     *     depths: array<string, int>,
     *     observed_edges: list<array{edge: Action, enabled: bool}>
     * }
     */
    public function reach(
        TransitionSystem $model,
        ?int $maxDepth = null,
        ?int $maxStates = null,
        ?int $maxTransitions = null,
        bool $observe = false,
    ): array {
        if ($maxDepth !== null && $maxDepth < 0) {
            throw new InvalidArgumentException('Maximum depth must be nonnegative.');
        }
        if ($maxStates !== null && $maxStates < 1) {
            throw new InvalidArgumentException('Maximum states must be positive.');
        }
        if ($maxTransitions !== null && $maxTransitions < 1) {
            throw new InvalidArgumentException('Maximum transitions must be positive.');
        }

        $frontier = array_slice($model->initialStates, 0, $maxStates);
        $predecessors = array_fill_keys($frontier, null);
        $depth = 0;
        $depthLimitExhausted = false;
        $stateLimitExhausted = count($frontier) !== count($model->initialStates);
        $transitionLimitExhausted = false;
        $depths = $observe ? array_fill_keys($frontier, 0) : [];
        $observedEdges = [];
        $enabledEdges = [];
        $depthBoundaryEdges = [];
        $transitionsConsidered = 0;

        while ($frontier !== [] && !$stateLimitExhausted && !$transitionLimitExhausted) {
            $nextFrontier = [];
            foreach ($frontier as $state) {
                $facts = $model->states[$state]->facts;
                foreach ($model->outgoing[$state] as $edge) {
                    if ($transitionsConsidered === $maxTransitions) {
                        $transitionLimitExhausted = true;
                        break 2;
                    }
                    ++$transitionsConsidered;
                    $enabled = $edge->guard === null || $edge->guard->evaluate($facts);
                    if ($observe) {
                        $observedEdges[] = ['edge' => $edge, 'enabled' => $enabled];
                    }
                    if (!$enabled) {
                        continue;
                    }

                    $target = $edge->to;
                    if ($depth === $maxDepth) {
                        $depthBoundaryEdges[] = $edge;
                        if (!array_key_exists($target, $predecessors)) {
                            $depthLimitExhausted = true;
                        }
                        continue;
                    }

                    $enabledEdges[] = $edge;
                    if (array_key_exists($target, $predecessors)) {
                        continue;
                    }
                    if (count($predecessors) === $maxStates) {
                        // Finish this source layer, then stop. Every shorter trace was considered.
                        $stateLimitExhausted = true;
                        continue;
                    }
                    $predecessors[$target] = $edge;
                    $nextFrontier[] = $target;
                    if ($observe) {
                        $depths[$target] = $depth + 1;
                    }
                }
            }

            $frontier = $nextFrontier;
            if ($frontier !== []) {
                ++$depth;
            }
        }

        return [
            'predecessors' => $predecessors,
            'enabled_edges' => $enabledEdges,
            'depth_boundary_edges' => $depthBoundaryEdges,
            'transitions_considered' => $transitionsConsidered,
            'max_depth_explored' => $depth,
            'fixed_point_reached' => !$depthLimitExhausted && !$stateLimitExhausted && !$transitionLimitExhausted,
            'depth_limit_exhausted' => $depthLimitExhausted,
            'state_limit_exhausted' => $stateLimitExhausted,
            'transition_limit_exhausted' => $transitionLimitExhausted,
            'depths' => $depths,
            'observed_edges' => $observedEdges,
        ];
    }
}
