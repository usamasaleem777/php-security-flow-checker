<?php

declare(strict_types=1);

namespace SecurityFlow\Security;

use SecurityFlow\Models\State;
use SecurityFlow\Models\TransitionSystem;

final class SecurityProperties
{
    /** @param iterable<string> $statesInBfsOrder */
    public function firstViolation(TransitionSystem $model, iterable $statesInBfsOrder): ?State
    {
        foreach ($statesInBfsOrder as $id) {
            $state = $model->states[$id];
            if (!$model->invariant->evaluate($state->facts)) {
                return $state;
            }
        }
        return null;
    }
}
