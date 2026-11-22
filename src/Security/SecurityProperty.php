<?php

declare(strict_types=1);

namespace SecurityFlow\Security;

use InvalidArgumentException;
use SecurityFlow\Mathematics\BooleanLogic;
use SecurityFlow\Models\ModelJson;
use stdClass;

final readonly class SecurityProperty
{
    private function __construct(
        public string $id,
        public string $rule,
        public string $category,
        public string $scope,
        public ?string $action,
        public bool $successOnly,
        public BooleanLogic $assertion,
        public string $explanation,
        public string $remediation,
    ) {
    }

    public static function legacy(BooleanLogic $assertion): self
    {
        return self::custom('invariant', 'state', null, $assertion);
    }

    public static function fromJson(mixed $entry, array $types): self
    {
        if (!$entry instanceof stdClass) {
            throw new InvalidArgumentException('Expected a JSON object for each security property.');
        }
        $id = ModelJson::identifier($entry->id ?? null, 'property ID');

        if (property_exists($entry, 'rule')) {
            $rule = ModelJson::identifier($entry->rule, 'rule ID');
            $action = ModelJson::identifier($entry->action ?? null, 'property action');
            $definition = RuleCatalog::compile($entry, $rule);

            return new self(
                $id,
                $rule,
                $definition['category'],
                'transition',
                $action,
                true,
                new BooleanLogic($definition['assertion'], $types),
                $definition['explanation'],
                $definition['remediation'],
            );
        }

        $scope = $entry->scope ?? null;
        if ($scope !== 'state' && $scope !== 'transition') {
            throw new InvalidArgumentException('Property scope must be state or transition.');
        }
        $isTransition = $scope === 'transition';
        $property = ModelJson::object($entry, ['id', 'scope', 'assert'], 'property', $isTransition ? ['action'] : []);
        $action = array_key_exists('action', $property) ? ModelJson::identifier($property['action'], 'property action') : null;

        return self::custom($id, $scope, $action, new BooleanLogic($property['assert'], $types, $isTransition));
    }

    private static function custom(string $id, string $scope, ?string $action, BooleanLogic $assertion): self
    {
        return new self(
            $id,
            "$scope-invariant",
            strtoupper($scope) . '_INVARIANT',
            $scope,
            $action,
            false,
            $assertion,
            "The declared $scope assertion evaluated to false.",
            'Review the enabled transition and enforce the declared security assertion.',
        );
    }
}
