<?php

declare(strict_types=1);

namespace SecurityFlow\Security;

use SecurityFlow\Mathematics\FixedPointEngine;
use SecurityFlow\Models\TransitionSystem;

final class InvariantChecker
{
    public function verify(TransitionSystem $model, ?int $maxDepth = null): array
    {
        $reachability = (new FixedPointEngine())->reach($model, $maxDepth);
        $predecessors = $reachability['predecessors'];
        $violation = (new SecurityProperties())->firstViolation($model, array_keys($predecessors));

        $outcome = match (true) {
            $violation !== null => Outcome::VIOLATED,
            $reachability['fixed_point_reached'] => Outcome::VERIFIED,
            default => Outcome::INCOMPLETE,
        };

        return [
            'status' => $outcome,
            'model' => $model->name,
            'invariant' => $model->invariant->expression,
            'reachability' => [
                'model_states' => count($model->states),
                'states_reached' => count($predecessors),
                'max_depth_explored' => $reachability['max_depth_explored'],
                'depth_limit' => $maxDepth,
                'fixed_point_reached' => $reachability['fixed_point_reached'],
            ],
            'counterexample' => $violation === null ? null : (new CounterexampleEngine())->trace($predecessors, $violation),
        ];
    }
}
