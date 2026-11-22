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
        ?callable $observe = null,
    ): ?State {
        $assertion ??= $model->invariant ?? throw new LogicException('A state assertion is required.');
        foreach ($statesInBfsOrder as $id) {
            $state = $model->states[$id];
            $passed = $assertion->evaluate($state->facts);
            if ($observe !== null) {
                $observe($state, $passed);
            }
            if (!$passed) {
                return $state;
            }
        }
        return null;
    }
}
