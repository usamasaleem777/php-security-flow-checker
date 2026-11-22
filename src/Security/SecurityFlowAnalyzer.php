<?php

declare(strict_types=1);

namespace SecurityFlow\Security;

use SecurityFlow\Mathematics\FixedPointEngine;
use SecurityFlow\Models\Action;
use SecurityFlow\Models\State;
use SecurityFlow\Models\TransitionSystem;

final class SecurityFlowAnalyzer
{
    public function analyze(
        TransitionSystem $model,
        ?int $maxDepth = null,
        ?int $maxStates = null,
        ?int $maxTransitions = null,
        ?callable $observe = null,
    ): array {
        $reachability = (new FixedPointEngine())->reach($model, $maxDepth, $maxStates, $maxTransitions, $observe !== null);
        $predecessors = $reachability['predecessors'];
        $reachedStates = array_keys($predecessors);
        $stateChecker = new SecurityProperties();
        $transitionChecker = new TransitionPropertyChecker();
        $counterexampleEngine = new CounterexampleEngine();
        $uncheckedBoundary = false;
        $findings = [];
        $checks = [];
        $paths = [];

        foreach ($model->properties as $property) {
            $passedStates = [];
            $sample = null;
            $count = 0;
            $onCheck = $observe === null ? null : static function (State|Action $candidate, bool $passed) use (
                &$passedStates,
                &$sample,
                &$count,
            ): void {
                ++$count;
                $sample ??= $candidate;
                if ($passed && $candidate instanceof State) {
                    $passedStates[$candidate->id] = true;
                }
            };
            if ($property->scope === 'state') {
                $violation = $stateChecker->firstViolation($model, $reachedStates, $property->assertion, $onCheck);
            } else {
                $violation = $transitionChecker->firstViolation($model, $property, $reachability['enabled_edges'], $onCheck);
                $uncheckedBoundary = $uncheckedBoundary || $transitionChecker->hasApplicableEdge(
                    $property,
                    $reachability['depth_boundary_edges'],
                );
            }

            if ($observe !== null) {
                $checks[$property->id] = [
                    'evaluated' => $count,
                    'passed_states' => $passedStates,
                    'sample' => $violation ?? $sample,
                    'violation' => $violation,
                ];
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
                'counterexample' => $counterexampleEngine->explain(
                    $model,
                    $predecessors,
                    $violation,
                    $observe === null ? null : static function (array $actions) use (&$paths, $property): void {
                        $paths[$property->id] = $actions;
                    },
                ),
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
        if ($reachability['transition_limit_exhausted']) {
            $reasons[] = 'transition_limit';
        }
        $complete = $reasons === [];
        $status = match (true) {
            $findings !== [] => Outcome::VIOLATED,
            $complete => Outcome::VERIFIED,
            default => Outcome::INCOMPLETE,
        };

        $report = [
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
        if ($maxTransitions !== null) {
            $report['reachability']['transition_limit'] = $maxTransitions;
        }
        if ($observe !== null) {
            $observe($reachability, $checks, $paths);
        }
        return $report;
    }
}
