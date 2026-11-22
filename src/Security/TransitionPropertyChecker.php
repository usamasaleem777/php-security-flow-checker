<?php

declare(strict_types=1);

namespace SecurityFlow\Security;

use SecurityFlow\Models\Action;
use SecurityFlow\Models\TransitionSystem;

final class TransitionPropertyChecker
{
    /** @param list<Action> $edges Edges in order of BFS source depth */
    public function firstViolation(
        TransitionSystem $model,
        SecurityProperty $property,
        array $edges,
        ?callable $observe = null,
    ): ?Action {
        foreach ($edges as $edge) {
            if (!$this->applies($property, $edge)) {
                continue;
            }
            $passed = $property->assertion->evaluate(
                $model->states[$edge->from]->facts,
                $model->states[$edge->to]->facts,
            );
            if ($observe !== null) {
                $observe($edge, $passed);
            }
            if (!$passed) {
                return $edge;
            }
        }
        return null;
    }

    /** @param list<Action> $edges */
    public function hasApplicableEdge(SecurityProperty $property, array $edges): bool
    {
        foreach ($edges as $edge) {
            if ($this->applies($property, $edge)) {
                return true;
            }
        }
        return false;
    }

    private function applies(SecurityProperty $property, Action $edge): bool
    {
        return ($property->action === null || $property->action === $edge->action)
            && (!$property->successOnly || $edge->succeeded === true);
    }
}
