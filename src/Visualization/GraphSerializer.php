<?php

declare(strict_types=1);

namespace SecurityFlow\Visualization;

use SecurityFlow\Models\Action;
use SecurityFlow\Models\State;
use SecurityFlow\Models\TransitionSystem;

final class GraphSerializer
{
    public const MAX_NODES = 48;
    public const MAX_EDGES = 96;

    public function index(TransitionSystem $model, array $reach, array $checks, array $paths): array
    {
        $edges = [];
        foreach ($this->edges($model) as $id => $edge) {
            $edges[spl_object_id($edge)] = $id;
        }
        $explored = [];
        foreach ($reach['enabled_edges'] as $edge) {
            $explored[$edges[spl_object_id($edge)]] = 'explored';
        }
        foreach ($reach['observed_edges'] as ['edge' => $edge, 'enabled' => $enabled]) {
            $explored[$edges[spl_object_id($edge)]] ??= $enabled ? 'boundary' : 'disabled';
        }

        $stateProperties = 0;
        $passes = [];
        $violations = [];
        foreach ($model->properties as $property) {
            $check = $checks[$property->id];
            if ($property->scope === 'state') {
                ++$stateProperties;
                foreach ($check['passed_states'] as $id => $_) {
                    $passes[$id] = ($passes[$id] ?? 0) + 1;
                }
            }
            $violation = $check['violation'];
            if ($violation instanceof State) {
                $violations[$violation->id] = 'violating';
            } elseif ($violation instanceof Action) {
                $explored[$edges[spl_object_id($violation)]] = 'violating';
            }
        }
        $verified = array_fill_keys(array_keys(array_filter($passes, static fn (int $count): bool => $count === $stateProperties)), 'verified');
        foreach ($paths as $id => $actions) {
            $paths[$id] = array_map(static fn (Action $edge): string => $edges[spl_object_id($edge)], $actions);
        }

        return [
            'depths' => $reach['depths'],
            'states' => $violations + $verified,
            'edges' => $explored,
            'paths' => $paths,
            'closed' => $reach['fixed_point_reached'],
        ];
    }

    public function view(
        TransitionSystem $model,
        ?array $index = null,
        ?string $focus = null,
        ?string $property = null,
        ?string $start = null,
    ): array {
        $edges = iterator_to_array($this->edges($model));
        $path = $index['paths'][$property ?? array_key_first($index['paths'] ?? [])] ?? [];
        $depths = $index['depths'] ?? [];
        $prioritized = $focus === null ? [] : [$focus];
        $preferredEdges = [];
        if ($focus !== null) {
            foreach ($edges as $id => $edge) {
                if ($edge->from === $focus || $edge->to === $focus) {
                    $prioritized[] = $edge->from === $focus ? $edge->to : $edge->from;
                    $preferredEdges[] = $id;
                }
            }
        }
        if ($start !== null) {
            $prioritized[] = $start;
        }
        foreach ($path as $id) {
            $prioritized[] = $edges[$id]->from;
            $prioritized[] = $edges[$id]->to;
        }
        $nodeIds = array_slice(array_unique([
            ...$prioritized,
            ...$model->initialStates,
            ...array_keys($depths),
            ...array_keys($model->states),
        ]), 0, self::MAX_NODES);
        $visible = array_fill_keys($nodeIds, true);
        $initial = array_fill_keys($model->initialStates, true);
        $nodes = [];
        foreach ($model->states as $id => $state) {
            if (isset($visible[$id])) {
                $nodes[] = [
                    'id' => $id,
                    'facts' => (object) $state->facts,
                    'initial' => isset($initial[$id]),
                    'depth' => $depths[$id] ?? null,
                    'status' => $index['states'][$id] ?? match (true) {
                        isset($depths[$id]) => 'reachable',
                        $index['closed'] ?? false => 'unreachable',
                        default => 'unassessed',
                    },
                ];
            }
        }
        $visibleEdges = [];
        foreach (array_unique([...$preferredEdges, ...$path, ...array_keys($index['edges'] ?? []), ...array_keys($edges)]) as $id) {
            $edge = $edges[$id];
            if (isset($visible[$edge->from], $visible[$edge->to])) {
                $visibleEdges[] = $edge->describe() + [
                    'id' => $id,
                    'status' => $index['edges'][$id] ?? 'unassessed',
                    'guard' => $edge->guard?->expression,
                    'succeeded' => $edge->succeeded,
                ];
                if (count($visibleEdges) === self::MAX_EDGES) {
                    break;
                }
            }
        }
        $shown = array_fill_keys(array_column($visibleEdges, 'id'), true);

        return [
            'nodes' => $nodes,
            'edges' => $visibleEdges,
            'paths' => (object) array_map(
                static fn (array $ids): array => array_map(static fn (string $id): ?string => isset($shown[$id]) ? $id : null, $ids),
                $index['paths'] ?? [],
            ),
            'state_count' => count($model->states),
            'transition_count' => count($edges),
            'reached_count' => $index === null ? null : count($depths),
            'truncated' => count($nodes) < count($model->states) || count($visibleEdges) < count($edges),
            'focus' => $focus,
        ];
    }

    /** @return iterable<string, Action> */
    private function edges(TransitionSystem $model): iterable
    {
        $index = 0;
        foreach ($model->outgoing as $edges) {
            foreach ($edges as $edge) {
                yield 'e' . $index++ => $edge;
            }
        }
    }
}
