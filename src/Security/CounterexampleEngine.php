<?php

declare(strict_types=1);

namespace SecurityFlow\Security;

use SecurityFlow\Mathematics\GraphSearch;
use SecurityFlow\Models\Action;
use SecurityFlow\Models\State;
use SecurityFlow\Models\TransitionSystem;
use WeakMap;

final class CounterexampleEngine
{
    /** @var WeakMap<Action, array> */
    private WeakMap $steps;

    public function __construct()
    {
        $this->steps = new WeakMap();
    }

    /** @param array<string, Action|null> $predecessors */
    public function trace(array $predecessors, State $violation): array
    {
        $path = (new GraphSearch())->shortestPath($predecessors, $violation->id);

        return [
            'length' => count($path['actions']),
            'initial_state' => $path['initial_state'],
            'transitions' => array_map(static fn (Action $action): array => $action->describe(), $path['actions']),
            'violating_state' => $violation->id,
            'violating_facts' => (object) $violation->facts,
        ];
    }

    /** @param array<string, Action|null> $predecessors */
    public function explain(
        TransitionSystem $model,
        array $predecessors,
        State|Action $violation,
        ?callable $observePath = null,
    ): array {
        $path = (new GraphSearch())->shortestPath(
            $predecessors,
            $violation instanceof Action ? $violation->from : $violation->id,
        );
        $actions = $path['actions'];

        if ($violation instanceof Action) {
            $actions[] = $violation;
            $failedAt = [
                'scope' => 'transition',
                'transition' => $violation->describe(),
                'succeeded' => $violation->succeeded,
                'pre' => (object) $model->states[$violation->from]->facts,
                'post' => (object) $model->states[$violation->to]->facts,
            ];
        } else {
            $failedAt = ['scope' => 'state', 'state' => $violation->id, 'facts' => (object) $violation->facts];
        }

        if ($observePath !== null) {
            $observePath($actions);
        }
        $steps = [];
        foreach ($actions as $action) {
            $steps[] = $this->steps[$action] ??= $this->describeStep($model, $action);
        }

        return [
            'length' => count($steps),
            'initial_state' => $path['initial_state'],
            'transitions' => $steps,
            'violation' => $failedAt,
        ];
    }

    private function describeStep(TransitionSystem $model, Action $action): array
    {
        $changes = [];
        $before = $model->states[$action->from]->facts;
        $after = $model->states[$action->to]->facts;
        foreach ($before as $variable => $value) {
            if ($value !== $after[$variable]) {
                $changes[$variable] = ['before' => $value, 'after' => $after[$variable]];
            }
        }
        return $action->describe() + ['succeeded' => $action->succeeded, 'changes' => (object) $changes];
    }
}
