<?php

declare(strict_types=1);

namespace SecurityFlow\Models;

use InvalidArgumentException;
use JsonException;
use SecurityFlow\Mathematics\BooleanLogic;
use stdClass;

final readonly class TransitionSystem
{
    public const MAX_JSON_BYTES = 1_048_576;

    /**
     * @param list<string> $initialStates
     * @param array<string, State> $states
     * @param array<string, list<Action>> $outgoing
     */
    private function __construct(
        public string $name,
        public array $initialStates,
        public array $states,
        public array $outgoing,
        public BooleanLogic $invariant,
    ) {
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('Model JSON exceeds the 1 MiB size limit.');
        }

        try {
            $decoded = json_decode($json, depth: 128, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid model JSON: ' . $exception->getMessage(), previous: $exception);
        }

        $model = self::object($decoded, ['name', 'facts', 'initial', 'states', 'transitions', 'invariant'], 'model');
        $name = self::identifier($model['name'], 'model name');
        $factNames = self::identifiers($model['facts'], 'facts');
        $initialStates = self::identifiers($model['initial'], 'initial');
        if ($initialStates === []) {
            throw new InvalidArgumentException('At least one initial state is required.');
        }

        $states = [];
        foreach (self::items($model['states'], 'states') as $index => $entry) {
            $state = self::object($entry, ['id', 'facts'], "states[$index]");
            $id = self::identifier($state['id'], "states[$index].id");
            if (isset($states[$id])) {
                throw new InvalidArgumentException("Duplicate state ID: $id.");
            }

            $facts = self::object($state['facts'], $factNames, "facts for state $id");
            foreach ($facts as $fact => $value) {
                if (!is_bool($value)) {
                    throw new InvalidArgumentException("Fact $fact in state $id must be Boolean.");
                }
            }
            $states[$id] = new State($id, $facts);
        }

        foreach ($initialStates as $id) {
            if (!isset($states[$id])) {
                throw new InvalidArgumentException("Unknown initial state: $id.");
            }
        }

        $outgoing = array_fill_keys(array_keys($states), []);
        foreach (self::items($model['transitions'], 'transitions') as $index => $entry) {
            $edge = self::object($entry, ['from', 'to', 'action'], "transitions[$index]");
            $from = self::identifier($edge['from'], "transitions[$index].from");
            $to = self::identifier($edge['to'], "transitions[$index].to");
            $action = self::identifier($edge['action'], "transitions[$index].action");
            if (!isset($states[$from], $states[$to])) {
                throw new InvalidArgumentException("Unknown transition endpoint in transitions[$index].");
            }
            $outgoing[$from][] = new Action($from, $to, $action);
        }

        return new self($name, $initialStates, $states, $outgoing, new BooleanLogic($model['invariant'], $factNames));
    }

    private static function object(mixed $value, array $fields, string $location): array
    {
        if (!$value instanceof stdClass) {
            throw new InvalidArgumentException("Expected a JSON object in $location.");
        }

        $properties = get_object_vars($value);
        if (count($properties) !== count($fields) || array_diff($fields, array_keys($properties)) !== []) {
            throw new InvalidArgumentException("Unexpected or missing fields in $location.");
        }
        return $properties;
    }

    private static function items(mixed $value, string $location): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException("Expected a JSON list in $location.");
        }
        return $value;
    }

    private static function identifiers(mixed $value, string $location): array
    {
        $identifiers = [];
        foreach (self::items($value, $location) as $index => $entry) {
            $id = self::identifier($entry, "{$location}[$index]");
            if (isset($identifiers[$id])) {
                throw new InvalidArgumentException("Duplicate identifier in $location: $id.");
            }
            $identifiers[$id] = true;
        }
        return array_keys($identifiers);
    }

    private static function identifier(mixed $value, string $location): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid identifier in $location.");
        }
        return $value;
    }
}
