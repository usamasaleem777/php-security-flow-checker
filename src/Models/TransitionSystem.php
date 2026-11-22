<?php

declare(strict_types=1);

namespace SecurityFlow\Models;

use InvalidArgumentException;
use SecurityFlow\Mathematics\BooleanLogic;
use SecurityFlow\Security\SecurityProperty;
use stdClass;

final readonly class TransitionSystem
{
    public const MAX_JSON_BYTES = 1_048_576;
    public const MAX_PROPERTIES = 32;

    /**
     * @param list<string> $initialStates
     * @param array<string, State> $states
     * @param array<string, list<Action>> $outgoing
     * @param list<SecurityProperty> $properties
     */
    private function __construct(
        public string $name,
        public array $initialStates,
        public array $states,
        public array $outgoing,
        public ?BooleanLogic $invariant,
        public array $properties,
        public array $domains,
    ) {
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('Model JSON exceeds the 1 MiB size limit.');
        }

        $decoded = ModelJson::decode($json);
        $typed = $decoded instanceof stdClass && property_exists($decoded, 'domains');
        $model = ModelJson::object(
            $decoded,
            $typed
                ? ['name', 'domains', 'initial', 'states', 'transitions', 'properties']
                : ['name', 'facts', 'initial', 'states', 'transitions', 'invariant'],
            'model',
        );
        $name = ModelJson::identifier($model['name'], 'model name');
        $domains = $typed
            ? self::domains($model['domains'])
            : array_fill_keys(self::identifiers($model['facts'], 'facts'), 'boolean');
        $types = array_map(
            static fn (array|string $domain): string => is_array($domain) ? gettype($domain[0]) : $domain,
            $domains,
        );
        $factNames = array_keys($domains);

        $initialStates = self::identifiers($model['initial'], 'initial');
        if ($initialStates === []) {
            throw new InvalidArgumentException('At least one initial state is required.');
        }

        $states = [];
        foreach (ModelJson::items($model['states'], 'states') as $index => $entry) {
            $state = ModelJson::object($entry, ['id', 'facts'], "states[$index]");
            $id = ModelJson::identifier($state['id'], "states[$index].id");
            if (isset($states[$id])) {
                throw new InvalidArgumentException("Duplicate state ID: $id.");
            }

            $facts = ModelJson::object($state['facts'], $factNames, "facts for state $id");
            foreach ($facts as $fact => $value) {
                $domain = $domains[$fact];
                if (is_array($domain) ? !in_array($value, $domain, true) : !is_bool($value)) {
                    throw new InvalidArgumentException("Value for $fact in state $id is outside its finite domain.");
                }
            }
            $states[$id] = new State($id, $facts);
        }

        foreach ($initialStates as $id) {
            if (!isset($states[$id])) {
                throw new InvalidArgumentException("Unknown initial state: $id.");
            }
        }

        $invariant = $typed ? null : new BooleanLogic($model['invariant'], $types);
        $properties = $typed ? self::properties($model['properties'], $types) : [SecurityProperty::legacy($invariant)];
        $requiredActions = [];
        foreach ($properties as $property) {
            if ($property->action !== null) {
                $requiredActions[$property->action] = ($requiredActions[$property->action] ?? false) || $property->successOnly;
            }
        }

        $outgoing = array_fill_keys(array_keys($states), []);
        $actions = [];
        foreach (ModelJson::items($model['transitions'], 'transitions') as $index => $entry) {
            $location = "transitions[$index]";
            $edge = ModelJson::object($entry, ['from', 'to', 'action'], $location, $typed ? ['guard', 'succeeded'] : []);
            $from = ModelJson::identifier($edge['from'], "$location.from");
            $to = ModelJson::identifier($edge['to'], "$location.to");
            $action = ModelJson::identifier($edge['action'], "$location.action");
            if (!isset($states[$from], $states[$to])) {
                throw new InvalidArgumentException("Unknown transition endpoint in $location.");
            }
            $succeeded = $edge['succeeded'] ?? null;
            if ((array_key_exists('succeeded', $edge) || ($requiredActions[$action] ?? false)) && !is_bool($succeeded)) {
                throw new InvalidArgumentException("A Boolean succeeded value is required in $location.");
            }
            $guard = array_key_exists('guard', $edge) ? new BooleanLogic($edge['guard'], $types) : null;
            $actions[$action] = true;
            $outgoing[$from][] = new Action($from, $to, $action, $guard, $succeeded);
        }
        if (array_diff_key($requiredActions, $actions) !== []) {
            throw new InvalidArgumentException('A property references an unknown transition action.');
        }

        return new self($name, $initialStates, $states, $outgoing, $invariant, $properties, $domains);
    }

    private static function domains(mixed $value): array
    {
        if (!$value instanceof stdClass) {
            throw new InvalidArgumentException('Expected a JSON object in domains.');
        }

        $domains = get_object_vars($value);
        foreach ($domains as $name => $domain) {
            ModelJson::identifier($name, 'domain variable name');
            if ($domain === 'boolean') {
                continue;
            }
            if (!is_array($domain) || $domain === []) {
                throw new InvalidArgumentException("Domain $name must be boolean or a nonempty list of values.");
            }
            $type = gettype($domain[0]);
            if (!in_array($type, ['boolean', 'integer', 'string'], true)) {
                throw new InvalidArgumentException("Domain $name must contain Boolean, integer, or string values.");
            }
            foreach ($domain as $item) {
                if (gettype($item) !== $type) {
                    throw new InvalidArgumentException("Domain $name must contain values of a single type.");
                }
            }
            if (count($domain) !== count(array_unique($domain, SORT_STRING))) {
                throw new InvalidArgumentException("Domain $name contains duplicate values.");
            }
        }
        return $domains;
    }

    /** @return list<SecurityProperty> */
    private static function properties(mixed $value, array $types): array
    {
        $entries = ModelJson::items($value, 'properties');
        if ($entries === [] || count($entries) > self::MAX_PROPERTIES) {
            throw new InvalidArgumentException('Models must declare between 1 and ' . self::MAX_PROPERTIES . ' security properties.');
        }

        $properties = [];
        foreach ($entries as $entry) {
            $property = SecurityProperty::fromJson($entry, $types);
            if (isset($properties[$property->id])) {
                throw new InvalidArgumentException("Duplicate property ID: $property->id.");
            }
            $properties[$property->id] = $property;
        }
        return array_values($properties);
    }

    private static function identifiers(mixed $value, string $location): array
    {
        $identifiers = [];
        foreach (ModelJson::items($value, $location) as $index => $entry) {
            $id = ModelJson::identifier($entry, "{$location}[$index]");
            if (isset($identifiers[$id])) {
                throw new InvalidArgumentException("Duplicate identifier in $location: $id.");
            }
            $identifiers[$id] = true;
        }
        return array_keys($identifiers);
    }
}
