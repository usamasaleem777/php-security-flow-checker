<?php

declare(strict_types=1);

namespace SecurityFlow\Security;

use InvalidArgumentException;
use SecurityFlow\Models\ModelJson;
use stdClass;

final class RuleCatalog
{
    private const RULES = [
        'authorization-bypass' => [
            'AUTHORIZATION_BYPASS',
            ['authenticated', 'has_permission'],
            'The modeled operation succeeded without both authentication and permission.',
            'Enforce authentication and check the required permission at the point of operation.',
        ],
        'mfa-bypass' => [
            'MFA_BYPASS',
            ['authenticated', 'mfa_verified'],
            'The modeled operation succeeded without both authentication and verified MFA.',
            'Require verified MFA for the current authenticated session before allowing the operation.',
        ],
        'session-revocation' => [
            'SESSION_REVOCATION_FAILURE',
            ['session_status'],
            'The modeled operation succeeded with a session whose status was other than active.',
            'Require an active session and enforce session invalidation at the point of operation.',
        ],
        'permission-revocation' => [
            'PERMISSION_REVOCATION_FAILURE',
            ['permission_revoked'],
            'The modeled operation succeeded after its required permission had been revoked.',
            'Check the current permission at the point of operation and invalidate stale authorization decisions.',
        ],
        'workflow-precondition' => [
            'WORKFLOW_PRECONDITION_BYPASS',
            [],
            'The modeled operation succeeded while its configured prerequisite was false.',
            'Enforce the required workflow prerequisite before allowing the operation.',
        ],
        'one-time-operation-replay' => [
            'ONE_TIME_OPERATION_REPLAY',
            ['consumed'],
            'The modeled one-time operation succeeded after the resource had already been consumed.',
            'Require an unused resource and record consumption atomically with acceptance.',
        ],
    ];

    /** @return array{category: string, assertion: mixed, explanation: string, remediation: string} */
    public static function compile(stdClass $entry, string $rule): array
    {
        $definition = self::RULES[$rule] ?? throw new InvalidArgumentException('Unknown security rule.');
        [$category, $roles, $explanation, $remediation] = $definition;
        $isWorkflow = $rule === 'workflow-precondition';
        $configuration = ModelJson::object(
            $entry,
            ['id', 'rule', 'action', $isWorkflow ? 'requires' : 'bindings'],
            'rule property',
        );
        $bindings = [];
        if (!$isWorkflow) {
            $values = ModelJson::object($configuration['bindings'], $roles, 'rule bindings');
            foreach ($values as $role => $variable) {
                $bindings[$role] = ModelJson::identifier($variable, 'rule binding');
            }
        }

        $assertion = match ($rule) {
            'authorization-bypass' => ['and', $bindings['authenticated'], $bindings['has_permission']],
            'mfa-bypass' => ['and', $bindings['authenticated'], $bindings['mfa_verified']],
            'session-revocation' => ['equals', $bindings['session_status'], ['literal', 'active']],
            'permission-revocation' => ['not', $bindings['permission_revoked']],
            'workflow-precondition' => $configuration['requires'],
            'one-time-operation-replay' => ['not', $bindings['consumed']],
        };

        return [
            'category' => $category,
            'assertion' => $assertion,
            'explanation' => $explanation,
            'remediation' => $remediation,
        ];
    }
}
