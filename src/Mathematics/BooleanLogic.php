<?php

declare(strict_types=1);

namespace SecurityFlow\Mathematics;

use InvalidArgumentException;
use LogicException;

final readonly class BooleanLogic
{
    public bool|string|array $expression;

    /** @param list<string> $factNames */
    public function __construct(mixed $expression, array $factNames)
    {
        $this->validate($expression, array_fill_keys($factNames, true));
        $this->expression = $expression;
    }

    /** @param array<string, bool> $facts */
    public function evaluate(array $facts): bool
    {
        return $this->evaluateExpression($this->expression, $facts);
    }

    private function validate(mixed $expression, array $knownFacts): void
    {
        if (is_bool($expression)) {
            return;
        }

        if (is_string($expression) && isset($knownFacts[$expression])) {
            return;
        }

        if (!is_array($expression) || !array_is_list($expression) || $expression === []) {
            throw new InvalidArgumentException('Expected a Boolean literal, declared fact, or operator list in invariant.');
        }

        $count = count($expression);
        $valid = match ($expression[0]) {
            'not' => $count === 2,
            'implies' => $count === 3,
            'and', 'or' => $count >= 3,
            default => false,
        };
        if (!$valid) {
            throw new InvalidArgumentException('Unknown operator or invalid operand count in invariant.');
        }

        for ($index = 1; $index < $count; ++$index) {
            $this->validate($expression[$index], $knownFacts);
        }
    }

    private function evaluateExpression(bool|string|array $expression, array $facts): bool
    {
        if (is_bool($expression)) {
            return $expression;
        }

        if (is_string($expression)) {
            if (!isset($facts[$expression]) || !is_bool($facts[$expression])) {
                throw new LogicException('Policy evaluation requires a Boolean value for each referenced fact.');
            }
            return $facts[$expression];
        }

        return match ($expression[0]) {
            'not' => !$this->evaluateExpression($expression[1], $facts),
            'implies' => !$this->evaluateExpression($expression[1], $facts)
                || $this->evaluateExpression($expression[2], $facts),
            'and' => $this->evaluateOperands($expression, $facts, true),
            'or' => $this->evaluateOperands($expression, $facts, false),
        };
    }

    private function evaluateOperands(array $expression, array $facts, bool $all): bool
    {
        $count = count($expression);
        for ($index = 1; $index < $count; ++$index) {
            if ($this->evaluateExpression($expression[$index], $facts) !== $all) {
                return !$all;
            }
        }
        return $all;
    }
}
