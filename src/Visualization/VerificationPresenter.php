<?php

declare(strict_types=1);

namespace SecurityFlow\Visualization;

use SecurityFlow\Models\Action;
use SecurityFlow\Models\State;
use SecurityFlow\Models\TransitionSystem;
use SecurityFlow\Security\SecurityProperty;

final class VerificationPresenter
{
    public function describe(TransitionSystem $model): array
    {
        $actions = [];
        foreach ($model->outgoing as $edges) {
            foreach ($edges as $edge) {
                $actions[$edge->action] = ($actions[$edge->action] ?? 0) + 1;
            }
        }
        $properties = array_map(static fn (SecurityProperty $property): array => [
            'id' => $property->id,
            'scope' => $property->scope,
            'category' => $property->category,
            'action' => $property->action,
            'success_only' => $property->successOnly,
            'assertion' => $property->assertion->expression,
        ], $model->properties);

        return [
            'name' => $model->name,
            'states' => count($model->states),
            'transitions' => array_sum($actions),
            'initial' => $model->initialStates,
            'domains' => (object) $model->domains,
            'actions' => (object) $actions,
            'properties' => $properties,
        ];
    }

    public function mathematics(TransitionSystem $model, array $reach, array $checks): array
    {
        $layers = [];
        foreach ($reach['depths'] as $depth) {
            $layers[$depth] = ($layers[$depth] ?? 0) + 1;
        }
        $iterations = [];
        $total = 0;
        foreach ($layers as $depth => $added) {
            $total += $added;
            $iterations[] = ['depth' => $depth, 'new_states' => $added, 'reached' => $total];
        }

        $properties = [];
        foreach ($model->properties as $property) {
            $check = $checks[$property->id];
            $sample = $check['sample'];
            $example = match (true) {
                $sample instanceof State => ['state' => $sample->id] + $property->assertion->explain($sample->facts),
                $sample instanceof Action => ['transition' => $sample->describe()] + $property->assertion->explain(
                    $model->states[$sample->from]->facts,
                    $model->states[$sample->to]->facts,
                ),
                default => null,
            };
            $properties[] = [
                'id' => $property->id,
                'scope' => $property->scope,
                'assertion' => $property->assertion->expression,
                'evaluated' => $check['evaluated'],
                'violated' => $check['violation'] !== null,
                'induction' => $property->scope === 'state' ? $this->induction($model, $property, $reach) : null,
                'example' => $example,
            ];
        }

        return [
            'initial_states' => $model->initialStates,
            'iterations' => $iterations,
            'fixed_point_reached' => $reach['fixed_point_reached'],
            'properties' => $properties,
        ];
    }

    private function induction(TransitionSystem $model, SecurityProperty $property, array $reach): array
    {
        $base = ['checked' => 0, 'failed' => 0, 'total' => count($model->initialStates)];
        $preservation = ['checked' => 0, 'failed' => 0];
        $values = [];
        foreach ($model->initialStates as $id) {
            if (isset($reach['depths'][$id])) {
                ++$base['checked'];
                $values[$id] = $property->assertion->evaluate($model->states[$id]->facts);
                $base['failed'] += (int) !$values[$id];
            }
        }
        foreach ($reach['enabled_edges'] as $edge) {
            $pre = $values[$edge->from] ??= $property->assertion->evaluate($model->states[$edge->from]->facts);
            if ($pre) {
                ++$preservation['checked'];
                $post = $values[$edge->to] ??= $property->assertion->evaluate($model->states[$edge->to]->facts);
                $preservation['failed'] += (int) !$post;
            }
        }
        return ['base' => $base, 'preservation' => $preservation];
    }
}
