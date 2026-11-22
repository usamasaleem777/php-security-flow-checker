<?php

declare(strict_types=1);

namespace SecurityFlow\Mathematics;

use LogicException;
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
        while (true) {
            if (!array_key_exists($target, $predecessors)) {
                throw new LogicException('The path references an undiscovered state.');
            }
            $edge = $predecessors[$target];
            if ($edge === null) {
                break;
            }
            if (!$edge instanceof Action || $edge->to !== $target || count($actions) >= count($predecessors)) {
                throw new LogicException('The predecessor chain is invalid or cyclic.');
            }
            $actions[] = $edge;
            $target = $edge->from;
        }

        return ['initial_state' => $target, 'actions' => array_reverse($actions)];
    }
}
