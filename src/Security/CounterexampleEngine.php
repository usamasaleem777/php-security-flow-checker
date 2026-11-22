<?php

declare(strict_types=1);

namespace SecurityFlow\Security;

use SecurityFlow\Mathematics\GraphSearch;
use SecurityFlow\Models\Action;
use SecurityFlow\Models\State;

final class CounterexampleEngine
{
    /** @param array<string, Action|null> $predecessors */
    public function trace(array $predecessors, State $violation): array
    {
        $path = (new GraphSearch())->shortestPath($predecessors, $violation->id);

        return [
            'length' => count($path['actions']),
            'initial_state' => $path['initial_state'],
            'transitions' => array_map('get_object_vars', $path['actions']),
            'violating_state' => $violation->id,
            'violating_facts' => (object) $violation->facts,
        ];
    }
}
