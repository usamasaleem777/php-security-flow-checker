<?php

declare(strict_types=1);

namespace SecurityFlow\Security;

use LogicException;
use SecurityFlow\Mathematics\BooleanLogic;
use SecurityFlow\Models\State;
use SecurityFlow\Models\TransitionSystem;

final class SecurityProperties
{
    /** @param iterable<string> $statesInBfsOrder */
    public function firstViolation(
        TransitionSystem $model,
        iterable $statesInBfsOrder,
        ?BooleanLogic $assertion = null,
    ): ?State {
        $assertion ??= $model->invariant ?? throw new LogicException('A state assertion is required.');
        foreach ($statesInBfsOrder as $id) {
            $state = $model->states[$id];
            if (!$assertion->evaluate($state->facts)) {
                return $state;
            }
        }
        return null;
    }
}
