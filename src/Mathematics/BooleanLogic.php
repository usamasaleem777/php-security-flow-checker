<?php

declare(strict_types=1);

namespace SecurityFlow\Mathematics;

use InvalidArgumentException;
use LogicException;

final readonly class BooleanLogic
{
    public bool|string|array $expression;

    /** @var array<string, 'boolean'|'integer'|'string'> */
    private array $types;

    /** @param list<string>|array<string, 'boolean'|'integer'|'string'> $factNames */
    public function __construct(mixed $expression, array $factNames, private bool $allowPost = false)
    {
        $this->types = array_is_list($factNames) ? array_fill_keys($factNames, 'boolean') : $factNames;
        $this->requireType($this->typeOf($expression), 'boolean');
        $this->expression = $expression;
    }

    /** @param array<string, bool|int|string> $facts */
    public function evaluate(array $facts, array $post = []): bool
    {
        return $this->evaluateExpression($this->expression, $facts, $post);
    }

    private function typeOf(mixed $expression, int $depth = 0): string
    {
        if ($depth >= 128) {
            throw new InvalidArgumentException('Policy expression exceeds the nesting limit.');
        }
        if (is_bool($expression) || is_int($expression)) {
            return gettype($expression);
        }
        if (is_string($expression)) {
            return $this->factType($expression);
        }

        if (!is_array($expression) || !array_is_list($expression) || $expression === []) {
            throw new InvalidArgumentException('Expected a literal, declared variable, or operator list in policy expression.');
        }

        $operator = $expression[0];
        $arity = count($expression) - 1;
        $valid = match ($operator) {
            'not', 'pre', 'post', 'literal' => $arity === 1,
            'implies', 'equals', 'not-equals', 'lt', 'lte', 'gt', 'gte' => $arity === 2,
            'and', 'or' => $arity >= 2,
            default => false,
        };
        if (!$valid) {
            throw new InvalidArgumentException('Unknown operator or invalid operand count in policy expression.');
        }
        if ($operator === 'literal') {
            if (!is_string($expression[1])) {
                throw new InvalidArgumentException('The literal operator requires a string.');
            }
            return 'string';
        }
        if ($operator === 'pre' || $operator === 'post') {
            if (!$this->allowPost || !is_string($expression[1])) {
                throw new InvalidArgumentException('Pre and post references require a transition assertion and a variable name.');
            }
            return $this->factType($expression[1]);
        }

        $firstType = $this->typeOf($expression[1], $depth + 1);
        if ($operator === 'equals' || $operator === 'not-equals') {
            $this->requireType($this->typeOf($expression[2], $depth + 1), $firstType);
            return 'boolean';
        }

        $expectedType = in_array($operator, ['lt', 'lte', 'gt', 'gte'], true) ? 'integer' : 'boolean';
        $this->requireType($firstType, $expectedType);
        for ($index = 2; $index <= $arity; ++$index) {
            $this->requireType($this->typeOf($expression[$index], $depth + 1), $expectedType);
        }
        return 'boolean';
    }

    private function factType(string $name): string
    {
        return $this->types[$name] ?? throw new InvalidArgumentException('Unknown variable in policy expression.');
    }

    private function requireType(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            throw new InvalidArgumentException("Policy expression requires $expected operands, received $actual.");
        }
    }

    private function evaluateExpression(bool|int|string|array $expression, array $facts, array $post): bool|int|string
    {
        if (is_bool($expression) || is_int($expression)) {
            return $expression;
        }

        if (is_string($expression)) {
            return $this->readFact($expression, $facts);
        }

        return match ($expression[0]) {
            'literal' => $expression[1],
            'pre' => $this->readFact($expression[1], $facts),
            'post' => $this->readFact($expression[1], $post),
            'not' => !$this->evaluateExpression($expression[1], $facts, $post),
            'implies' => !$this->evaluateExpression($expression[1], $facts, $post)
                || $this->evaluateExpression($expression[2], $facts, $post),
            'and' => $this->evaluateOperands($expression, $facts, $post, true),
            'or' => $this->evaluateOperands($expression, $facts, $post, false),
            default => $this->compare(
                $expression[0],
                $this->evaluateExpression($expression[1], $facts, $post),
                $this->evaluateExpression($expression[2], $facts, $post),
            ),
        };
    }

    private function readFact(string $name, array $facts): bool|int|string
    {
        $value = $facts[$name] ?? null;
        if (gettype($value) !== $this->types[$name]) {
            throw new LogicException('Policy evaluation requires a correctly typed value for each referenced variable.');
        }
        return $value;
    }

    private function compare(string $operator, bool|int|string $left, bool|int|string $right): bool
    {
        return match ($operator) {
            'equals' => $left === $right,
            'not-equals' => $left !== $right,
            'lt' => $left < $right,
            'lte' => $left <= $right,
            'gt' => $left > $right,
            'gte' => $left >= $right,
        };
    }

    private function evaluateOperands(array $expression, array $facts, array $post, bool $all): bool
    {
        $count = count($expression);
        for ($index = 1; $index < $count; ++$index) {
            if ($this->evaluateExpression($expression[$index], $facts, $post) !== $all) {
                return !$all;
            }
        }
        return $all;
    }
}
