<?php

declare(strict_types=1);

use SecurityFlow\Mathematics\BooleanLogic;
use SecurityFlow\Models\TransitionSystem;
use SecurityFlow\Security\Outcome;
use SecurityFlow\Security\SecurityFlowAnalyzer;

function typedGraph(array $safeByState, array $edges, array $initial, array $properties): array
{
    $model = graph($safeByState, [], $initial);
    unset($model['invariant'], $model['facts']);
    $model['domains'] = ['safe' => 'boolean'];
    $model['transitions'] = $edges;
    $model['properties'] = $properties;
    return $model;
}

function analyzeGraph(array $model, ?int $depth = null, ?int $states = null): array
{
    $system = TransitionSystem::fromJson(json_encode($model, JSON_THROW_ON_ERROR));
    return (new SecurityFlowAnalyzer())->analyze($system, $depth, $states);
}

$fixtureCases = [
    // File prefix, category, shortest length, and the false prerequisite at the violating transition.
    ['authorization/permission', 'AUTHORIZATION_BYPASS', 1, 'has_permission', false, 4, 3],
    ['mfa-bypass/export', 'MFA_BYPASS', 2, 'mfa_verified', false, 5, 4],
    ['session-revocation/session', 'SESSION_REVOCATION_FAILURE', 3, 'session_status', 'revoked', 4, 3],
    ['authorization/revocation', 'PERMISSION_REVOCATION_FAILURE', 2, 'permission_revoked', true, 4, 3],
    ['password-reset/replay', 'ONE_TIME_OPERATION_REPLAY', 2, 'consumed', true, 3, 2],
    ['approval-workflow/approval', 'WORKFLOW_PRECONDITION_BYPASS', 1, 'stage', 'draft', 4, 3],
];
$phase2 = [];
foreach ($fixtureCases as [$file, $category, $length, $variable, $value, $vulnerableEdges, $correctedEdges]) {
    $phase2["Phase 2 detects $category and verifies the corrected model"] =
        static function () use ($file, $category, $length, $variable, $value, $vulnerableEdges, $correctedEdges): void {
            $root = __DIR__ . '/../examples/';
            [$code, $report] = runCli('analyze', $root . $file . '-vulnerable.json', '--format=json');
            expectSame(1, $code);
            expectSame('VIOLATED', $report['status']);
            expectSame(true, $report['analysis_complete']);
            expectSame(false, $report['runtime_reproduced']);
            expectSame($vulnerableEdges, $report['transitions_explored']);
            expectSame(1, count($report['findings']));
            $finding = $report['findings'][0];
            expectSame($report['model'] . '.' . $finding['property_id'], $finding['id']);
            expectSame($category, $finding['category']);
            expectSame('MODEL_COUNTEREXAMPLE', $finding['evidence_type']);
            $trace = $finding['counterexample'];
            expectSame($length, $trace['length']);
            expectSame($length, count($trace['transitions']));
            expectSame('transition', $trace['violation']['scope']);
            expectSame(true, $trace['violation']['succeeded']);
            expectSame($value, $trace['violation']['pre'][$variable]);
            if ($category === 'SESSION_REVOCATION_FAILURE') {
                expectSame(true, $trace['violation']['pre']['export_recorded']);
                expectSame('active', $trace['transitions'][1]['changes']['session_status']['before']);
                expectSame('revoked', $trace['transitions'][1]['changes']['session_status']['after']);
            }

            [$code, $corrected] = runCli('analyze', $root . $file . '-corrected.json', '--format=json');
            expectSame(0, $code);
            expectSame('VERIFIED', $corrected['status']);
            expectSame(true, $corrected['analysis_complete']);
            expectSame([], $corrected['findings']);
            expectSame($correctedEdges, $corrected['transitions_explored']);
            expectSame($vulnerableEdges, $corrected['transitions_considered']);
        };
}

return $phase2 + [
    'Phase 2 typed operators compare nested values strictly' => static function (): void {
        $types = ['level' => 'integer', 'stage' => 'string', 'safe' => 'boolean'];
        $facts = ['level' => 2, 'stage' => '01', 'safe' => true];
        $policy = new BooleanLogic([
            'and',
            ['equals', ['lt', 'level', 3], 'safe'],
            ['lte', 'level', 2],
            ['gte', 'level', 2],
            ['gt', 'level', 1],
            ['not-equals', 'stage', ['literal', '1']],
            ['equals', 'stage', ['literal', '01']],
        ], $types);
        expectSame(true, $policy->evaluate($facts));
        expectSame(false, $policy->evaluate(array_replace($facts, ['level' => 3])));

        $invalid = [
            ['equals', 'safe', 1],
            ['lt', 'stage', ['literal', '3']],
            ['or', true, ['equals', 'missing', 1]],
            ['gte', 'level', 1.0],
            ['literal', 'standalone'],
            ['post', 'safe'],
        ];
        foreach ($invalid as $expression) {
            expectInvalid(static fn () => new BooleanLogic($expression, $types));
        }
    },
    'Phase 2 checks initial states and ignores unreachable or disabled dangerous states' => static function (): void {
        $model = typedGraph(
            ['healthy' => true, 'unsafe' => false],
            [
                ['from' => 'healthy', 'to' => 'healthy', 'action' => 'cycle'],
                ['from' => 'healthy', 'to' => 'unsafe', 'action' => 'ignored', 'guard' => false],
            ],
            ['healthy', 'unsafe'],
            [['id' => 'always-safe', 'scope' => 'state', 'assert' => 'safe']],
        );
        $report = analyzeGraph($model);
        expectSame(Outcome::VIOLATED, $report['status']);
        expectSame(0, $report['findings'][0]['counterexample']['length']);
        expectSame('unsafe', $report['findings'][0]['counterexample']['initial_state']);

        $model['initial'] = ['healthy'];
        $report = analyzeGraph($model);
        expectSame(Outcome::VERIFIED, $report['status']);
        expectSame(1, $report['states_explored']);
        expectSame(1, $report['transitions_explored']);
        expectSame(2, $report['transitions_considered']);
    },
    'Phase 2 checks transition pre/post assertions and takes the shortest trace across initial states' => static function (): void {
        $model = typedGraph(
            ['far' => true, 'middle' => true, 'near' => true, 'bad' => false],
            [
                ['from' => 'far', 'to' => 'middle', 'action' => 'advance'],
                ['from' => 'middle', 'to' => 'bad', 'action' => 'edit'],
                ['from' => 'near', 'to' => 'bad', 'action' => 'edit'],
                ['from' => 'bad', 'to' => 'middle', 'action' => 'repair'],
            ],
            ['far', 'near', 'bad'],
            [
                ['id' => 'edit-preserves-safe', 'scope' => 'transition', 'action' => 'edit',
                    'assert' => ['implies', ['pre', 'safe'], ['post', 'safe']]],
                ['id' => 'always-safe', 'scope' => 'state', 'assert' => 'safe'],
            ],
        );
        $report = analyzeGraph($model);
        expectSame(Outcome::VIOLATED, $report['status']);
        expectSame(2, count($report['findings']));
        $trace = $report['findings'][0]['counterexample'];
        expectSame(1, $trace['length']);
        expectSame('near', $trace['initial_state']);
        expectSame(['before' => true, 'after' => false], $trace['transitions'][0]['changes']->safe);
        expectSame(0, $report['findings'][1]['counterexample']['length']);
    },
    'Phase 2 rejects invalid state changes, rule declarations, and guard scopes' => static function (): void {
        $fixture = __DIR__ . '/../examples/password-reset/replay-vulnerable.json';
        $model = json_decode(file_get_contents($fixture), flags: JSON_THROW_ON_ERROR);

        $invalid = [];
        $graph = clone $model;
        $graph->domains = (object) ['consumed' => 'boolean', 'uses' => [0, '1', 2]];
        $invalid[] = $graph;
        $graph = clone $model;
        $graph->states[2] = (object) ['id' => 'replayed', 'facts' => (object) ['consumed' => true, 'uses' => 3]];
        $invalid[] = $graph;
        $graph = clone $model;
        $graph->transitions[2] = (object) ['from' => 'used', 'to' => 'missing', 'action' => 'accept_once',
            'succeeded' => true, 'guard' => false];
        $invalid[] = $graph;
        $graph = clone $model;
        $graph->transitions[2] = (object) ['from' => 'used', 'to' => 'replayed', 'action' => 'accept_once'];
        $invalid[] = $graph;
        $graph = clone $model;
        $graph->transitions[2] = (object) ['from' => 'used', 'to' => 'replayed', 'action' => 'accept_once',
            'succeeded' => true, 'guard' => ['post', 'consumed']];
        $invalid[] = $graph;
        $graph = clone $model;
        $graph->properties[] = $graph->properties[0];
        $invalid[] = $graph;
        $graph = clone $model;
        $graph->properties[0] = (object) ['id' => 'missing-action', 'scope' => 'transition', 'action' => 'missing',
            'assert' => true];
        $invalid[] = $graph;
        $graph = clone $model;
        $graph->properties[0] = (object) ['id' => 'unknown-rule', 'rule' => 'unknown', 'action' => 'accept_once'];
        $invalid[] = $graph;
        foreach ($invalid as $graph) {
            expectInvalid(static fn () => analyzeGraph((array) $graph));
        }
    },
    'Phase 2 validates JSON escapes and rejects a duplicate property declaration member' => static function (): void {
        $value = "a\\b:\"}\t";
        $model = [
            'name' => 'escaped-value',
            'domains' => ['stage' => [$value, 'other']],
            'initial' => ['one'],
            'states' => [['id' => 'one', 'facts' => ['stage' => $value]]],
            'transitions' => [],
            'properties' => [['id' => 'escaped', 'scope' => 'state', 'assert' => ['equals', 'stage', ['literal', $value]]]],
        ];
        expectSame(Outcome::VERIFIED, analyzeGraph($model)['status']);
        $json = str_replace('"id":"escaped"', '"id":"first","\u0069d":"escaped"', json_encode($model, JSON_THROW_ON_ERROR));
        expectInvalid(static fn () => TransitionSystem::fromJson($json));
    },
    'Phase 2 depth limits also account for unchecked transitions between reached states' => static function (): void {
        $model = typedGraph(
            ['initial' => true],
            [['from' => 'initial', 'to' => 'initial', 'action' => 'work']],
            ['initial'],
            [['id' => 'check-edge', 'scope' => 'transition', 'assert' => false]],
        );
        $report = analyzeGraph($model, 0);
        expectSame(Outcome::INCOMPLETE, $report['status']);
        expectSame(true, $report['reachability']['fixed_point_reached']);
        expectSame(['depth_limit'], $report['incomplete_reasons']);
        expectSame(0, $report['transitions_explored']);

        $report = analyzeGraph($model, 1, 1);
        expectSame(Outcome::VIOLATED, $report['status']);
        expectSame(true, $report['analysis_complete']);
        expectSame(1, $report['findings'][0]['counterexample']['length']);
    },
    'Phase 2 state budgets preserve shortest witnesses and mark incomplete coverage' => static function (): void {
        $model = typedGraph(
            ['start' => true, 'near' => true, 'excluded' => false, 'far' => false],
            [
                ['from' => 'start', 'to' => 'near', 'action' => 'advance'],
                ['from' => 'start', 'to' => 'excluded', 'action' => 'check'],
                ['from' => 'near', 'to' => 'far', 'action' => 'check'],
            ],
            ['start'],
            [['id' => 'check-safe', 'scope' => 'transition', 'action' => 'check', 'assert' => ['post', 'safe']]],
        );
        $report = analyzeGraph($model, states: 2);
        expectSame(Outcome::VIOLATED, $report['status']);
        expectSame(false, $report['analysis_complete']);
        expectSame(['state_limit'], $report['incomplete_reasons']);
        expectSame(2, $report['states_explored']);
        expectSame(2, $report['transitions_explored']);
        expectSame(1, $report['findings'][0]['counterexample']['length']);

        $model['properties'][0] = ['id' => 'always-safe', 'scope' => 'state', 'assert' => 'safe'];
        $report = analyzeGraph($model, states: 2);
        expectSame(Outcome::INCOMPLETE, $report['status']);
        expectSame([], $report['findings']);
        expectSame(Outcome::VIOLATED, analyzeGraph($model)['status']);

        $model['initial'] = ['start', 'near'];
        $report = analyzeGraph($model, states: 1);
        expectSame(Outcome::INCOMPLETE, $report['status']);
        expectSame(1, $report['states_explored']);
        expectSame(0, $report['transitions_considered']);
    },
    'Phase 2 CLI emits deterministic JSON, verbose text, and clear errors' => static function (): void {
        $root = __DIR__ . '/../examples/mfa-bypass/';
        $file = $root . 'export-vulnerable.json';
        [$code, $json] = runCliOutput('analyze', $file, '--format=json');
        expectSame(1, $code);
        expectSame([$code, $json], runCliOutput('analyze', $file, '--format=json'));
        expectSame([$code, $json], runCliOutput('verify', $file));
        expectSame(str_replace("\r\n", "\n", file_get_contents($root . 'reports/export-vulnerable.json')), str_replace("\r\n", "\n", $json));
        [$code, $corrected] = runCliOutput('analyze', $root . 'export-corrected.json', '--format=json');
        expectSame(0, $code);
        expectSame(str_replace("\r\n", "\n", file_get_contents($root . 'reports/export-corrected.json')), str_replace("\r\n", "\n", $corrected));

        [$code, $text] = runCliOutput('analyze', $file, '--verbose');
        expectSame(1, $code);
        foreach (['MFA_BYPASS', 'Shortest trace of 2 actions', 'Failed assertion', 'Model evidence'] as $line) {
            expectSame(true, str_contains($text, $line));
        }

        [$code, $bounded] = runCli('analyze', $file, '--format=json', '--max-states', '1');
        expectSame(2, $code);
        expectSame('INCOMPLETE', $bounded['status']);
        expectSame(['state_limit'], $bounded['incomplete_reasons']);
        foreach ([[$file, '--max-states', '0'], [$file, '--max-depth', '1', '--max-depth', '2'],
            ['https://sentinel.invalid/model.json'], ['//sentinel.invalid/model.json']] as $arguments) {
            [$code, $error] = runCli('analyze', ...[...$arguments, '--format=json']);
            expectSame(3, $code);
            expectSame('ERROR', $error['status']);
            expectSame(false, $error['analysis_complete']);
        }
    },
];
