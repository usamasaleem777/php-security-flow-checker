<?php

declare(strict_types=1);

namespace SecurityFlow\Mathematics;

use SecurityFlow\Models\Action;

final class GraphSearch
{
    /**
     * @param array<string, Action|null> $predecessors BFS predecessor edges indexed by state ID
     * @return array{initial_state: string, actions: list<Action>}
     */
    public function shortestPath(array $predecessors, string $target): array
    {
        $actions = [];
        while ($predecessors[$target] !== null) {
            $edge = $predecessors[$target];
            $actions[] = $edge;
            $target = $edge->from;
        }

        return ['initial_state' => $target, 'actions' => array_reverse($actions)];
    }
}
