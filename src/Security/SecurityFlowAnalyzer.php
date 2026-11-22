<?php

declare(strict_types=1);

namespace SecurityFlow\Security;

use SecurityFlow\Mathematics\FixedPointEngine;
use SecurityFlow\Models\TransitionSystem;

final class SecurityFlowAnalyzer
{
    public function analyze(TransitionSystem $model, ?int $maxDepth = null, ?int $maxStates = null): array
    {
        $reachability = (new FixedPointEngine())->reach($model, $maxDepth, $maxStates);
        $predecessors = $reachability['predecessors'];
        $reachedStates = array_keys($predecessors);
        $stateChecker = new SecurityProperties();
        $transitionChecker = new TransitionPropertyChecker();
        $counterexampleEngine = new CounterexampleEngine();
        $uncheckedBoundary = false;
        $findings = [];

        foreach ($model->properties as $property) {
            if ($property->scope === 'state') {
                $violation = $stateChecker->firstViolation($model, $reachedStates, $property->assertion);
            } else {
                $violation = $transitionChecker->firstViolation($model, $property, $reachability['enabled_edges']);
                $uncheckedBoundary = $uncheckedBoundary || $transitionChecker->hasApplicableEdge(
                    $property,
                    $reachability['depth_boundary_edges'],
                );
            }

            if ($violation === null) {
                continue;
            }
            $findings[] = [
                'id' => $model->name . '.' . $property->id,
                'rule_id' => $property->rule,
                'property_id' => $property->id,
                'model_id' => $model->name,
                'category' => $property->category,
                'explanation' => $property->explanation,
                'assertion' => $property->assertion->expression,
                'counterexample' => $counterexampleEngine->explain($model, $predecessors, $violation),
                'evidence_type' => 'MODEL_COUNTEREXAMPLE',
                'status' => Outcome::VIOLATED,
                'remediation' => $property->remediation,
                'assumptions' => ['The supplied facts and transitions represent the workflow being checked.'],
                'limitations' => ['Evidence comes from the declared finite model.'],
                'runtime_reproduced' => false,
            ];
        }

        $reasons = [];
        if ($reachability['state_limit_exhausted']) {
            $reasons[] = 'state_limit';
        }
        if ($reachability['depth_limit_exhausted'] || $uncheckedBoundary) {
            $reasons[] = 'depth_limit';
        }
        $complete = $reasons === [];
        $status = match (true) {
            $findings !== [] => Outcome::VIOLATED,
            $complete => Outcome::VERIFIED,
            default => Outcome::INCOMPLETE,
        };

        return [
            'project' => 'PHP Security Flow Checker',
            'phase' => 2,
            'model' => $model->name,
            'status' => $status,
            'evidence_type' => $findings === [] ? 'FINITE_MODEL_ANALYSIS' : 'MODEL_COUNTEREXAMPLE',
            'runtime_reproduced' => false,
            'analysis_complete' => $complete,
            'incomplete_reasons' => $reasons,
            'states_explored' => count($reachedStates),
            'transitions_explored' => count($reachability['enabled_edges']),
            'transitions_considered' => $reachability['transitions_considered'],
            'reachability' => [
                'model_states' => count($model->states),
                'max_depth_explored' => $reachability['max_depth_explored'],
                'fixed_point_reached' => $reachability['fixed_point_reached'],
                'depth_limit' => $maxDepth,
                'state_limit' => $maxStates,
            ],
            'findings' => $findings,
        ];
    }
}
