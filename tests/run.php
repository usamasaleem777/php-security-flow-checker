<?php

declare(strict_types=1);

use SecurityFlow\Mathematics\BooleanLogic;
use SecurityFlow\Models\TransitionSystem;
use SecurityFlow\Security\InvariantChecker;
use SecurityFlow\Security\Outcome;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/bootstrap.php';

function expectSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function expectInvalid(callable $operation): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Expected invalid input to be rejected.');
}

function graph(array $safeByState, array $transitions, array $initial): array
{
    $states = [];
    foreach ($safeByState as $id => $safe) {
        $states[] = ['id' => $id, 'facts' => (object) ['safe' => $safe]];
    }

    return [
        'name' => 'test-model',
        'facts' => ['safe'],
        'initial' => $initial,
        'states' => $states,
        'transitions' => array_map(
            static fn (array $edge): array => ['from' => $edge[0], 'to' => $edge[1], 'action' => 'advance'],
            $transitions,
        ),
        'invariant' => 'safe',
    ];
}

function verifyGraph(array $graph, ?int $limit = null): array
{
    return (new InvariantChecker())->verify(TransitionSystem::fromJson(json_encode($graph, JSON_THROW_ON_ERROR)), $limit);
}

function runCli(string ...$arguments): array
{
    [$exitCode, $stdout] = runCliOutput(...$arguments);
    return [$exitCode, json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)];
}

function runCliOutput(string ...$arguments): array
{
    $process = proc_open(
        [PHP_BINARY, '-n', __DIR__ . '/../bin/stateaxiom.php', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        options: ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch the verification CLI.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    expectSame('', $stderr);
    return [$exitCode, $stdout];
}

$tests = [
    'Boolean truth tables and both De Morgan identities' => static function (): void {
        $expressions = [
            ['not', 'a'],
            ['and', 'a', 'b', true],
            ['or', 'a', 'b', false],
            ['implies', 'a', 'b'],
            ['not', ['and', 'a', 'b']],
            ['or', ['not', 'a'], ['not', 'b']],
            ['not', ['or', 'a', 'b']],
            ['and', ['not', 'a'], ['not', 'b']],
        ];
        $policies = array_map(static fn ($expression) => new BooleanLogic($expression, ['a', 'b']), $expressions);

        foreach ([false, true] as $a) {
            foreach ([false, true] as $b) {
                $values = array_map(static fn (BooleanLogic $policy) => $policy->evaluate(['a' => $a, 'b' => $b]), $policies);
                expectSame([!$a, $a && $b, $a || $b, !$a || $b, $values[5], $values[5], $values[7], $values[7]], $values);
            }
        }
    },
    'Rejects malformed policies, including branches skipped by short-circuiting' => static function (): void {
        foreach ([['or', true, 'unknown'], ['implies', true], ['not', true, false], ['and', true], ['xor', true, false], 1] as $expression) {
            expectInvalid(static fn () => new BooleanLogic($expression, ['safe']));
        }
    },
    'Rejects invalid graph definitions before exploration' => static function (): void {
        $base = graph(['start' => true, 'end' => false], [['start', 'end']], ['start']);
        $invalid = [
            array_replace($base, ['facts' => ['safe', 'safe']]),
            array_replace($base, ['initial' => []]),
            array_replace($base, ['initial' => ['start', 'start']]),
            array_replace($base, ['initial' => ['missing']]),
            array_replace($base, ['states' => [$base['states'][0], $base['states'][0]]]),
            array_replace($base, ['transitions' => [['from' => 'start', 'to' => 'missing', 'action' => 'advance']]]),
            graph(['start' => true, 'unreachable' => 'false'], [], ['start']),
            graph(['start' => true, '000' => false], [], ['start']),
            array_replace($base, ['states' => [['id' => 'start', 'facts' => []]]]),
            array_replace($base, ['states' => [['id' => 'start', 'facts' => (object) []]]]),
        ];
        foreach ($invalid as $model) {
            expectInvalid(static fn () => verifyGraph($model));
        }
        expectInvalid(static fn () => TransitionSystem::fromJson('{"broken":'));
        expectInvalid(static fn () => TransitionSystem::fromJson(
            '{"name":"test","facts":["safe"],"initial":["start"],' .
            '"states":[{"id":"start","facts":{"safe":false,"\u0073afe":true}}],"transitions":[],"invariant":"safe"}',
        ));
        expectInvalid(static fn () => TransitionSystem::fromJson(str_repeat(' ', TransitionSystem::MAX_JSON_BYTES + 1)));
        expectInvalid(static fn () => verifyGraph($base, -1));
    },
    'Cycles converge and unreachable violations do not affect the invariant' => static function (): void {
        $result = verifyGraph(graph(
            ['start' => true, 'loop' => true, 'unreachable' => false],
            [['start', 'loop'], ['loop', 'start'], ['loop', 'loop']],
            ['start'],
        ), 1);
        expectSame(Outcome::VERIFIED, $result['status']);
        expectSame(true, $result['reachability']['fixed_point_reached']);
        expectSame(2, $result['reachability']['states_reached']);
        expectSame(null, $result['counterexample']);
    },
    'BFS returns the shorter trace across branches and initial states' => static function (): void {
        $result = verifyGraph(graph(
            ['start' => true, 'middle' => true, 'bad' => false, 'alternate' => true],
            [['start', 'middle'], ['middle', 'bad'], ['alternate', 'bad']],
            ['start', 'alternate'],
        ));
        expectSame(Outcome::VIOLATED, $result['status']);
        expectSame(1, $result['counterexample']['length']);
        expectSame('alternate', $result['counterexample']['initial_state']);
        expectSame([['from' => 'alternate', 'to' => 'bad', 'action' => 'advance']], $result['counterexample']['transitions']);
    },
    'An initial-state violation has a zero-length trace' => static function (): void {
        $result = verifyGraph(graph(['bad' => false], [], ['bad']), 0);
        expectSame(Outcome::VIOLATED, $result['status']);
        expectSame(0, $result['counterexample']['length']);
        expectSame([], $result['counterexample']['transitions']);
        expectSame('bad', $result['counterexample']['violating_state']);
    },
    'Depth limits distinguish incomplete search from a discovered violation' => static function (): void {
        $model = graph(['start' => true, 'bad' => false, 'end' => true], [['start', 'bad'], ['bad', 'end']], ['start']);
        $incomplete = verifyGraph($model, 0);
        expectSame(Outcome::INCOMPLETE, $incomplete['status']);
        expectSame(false, $incomplete['reachability']['fixed_point_reached']);
        expectSame(1, $incomplete['reachability']['states_reached']);
        expectSame(null, $incomplete['counterexample']);

        $violation = verifyGraph($model, 1);
        expectSame(Outcome::VIOLATED, $violation['status']);
        expectSame(false, $violation['reachability']['fixed_point_reached']);
        expectSame(1, $violation['counterexample']['length']);
    },
    'All 512 three-state graphs match direct path enumeration' => static function (): void {
        $safeByState = ['start' => true, 'middle' => true, 'end' => false];
        $possibleEdges = [];
        foreach (array_keys($safeByState) as $from) {
            foreach (array_keys($safeByState) as $to) {
                $possibleEdges[] = [$from, $to];
            }
        }

        for ($mask = 0; $mask < 512; ++$mask) {
            $edges = [];
            foreach ($possibleEdges as $index => $edge) {
                if (($mask & (1 << $index)) !== 0) {
                    $edges[] = $edge;
                }
            }

            // Any shortest path from start has at most two edges in a three-state graph.
            $distances = ['start' => 0];
            foreach ($edges as [$from, $to]) {
                if ($from === 'start' && $to !== 'start') {
                    $distances[$to] = 1;
                }
            }
            foreach ($edges as [$from, $to]) {
                if (($distances[$from] ?? null) === 1 && !isset($distances[$to])) {
                    $distances[$to] = 2;
                }
            }

            $model = graph($safeByState, $edges, ['start']);
            foreach ([0, 1, 2, null] as $limit) {
                $expected = array_filter($distances, static fn (int $distance): bool => $distance <= ($limit ?? 2));
                $complete = count($expected) === count($distances);
                $result = verifyGraph($model, $limit);

                expectSame(count($expected), $result['reachability']['states_reached']);
                expectSame($complete, $result['reachability']['fixed_point_reached']);
                expectSame($expected['end'] ?? null, $result['counterexample']['length'] ?? null);
                expectSame(match (true) {
                    isset($expected['end']) => Outcome::VIOLATED,
                    $complete => Outcome::VERIFIED,
                    default => Outcome::INCOMPLETE,
                }, $result['status']);
            }
        }
    },
    'CLI detects the abstract MFA violation and emits the recorded shortest trace' => static function (): void {
        $examples = __DIR__ . '/../examples/mfa-bypass/';
        [$code, $report] = runCli($examples . 'mfa-vulnerable.json');
        expectSame(1, $code);
        expectSame('VIOLATED', $report['status']);
        expectSame(2, $report['counterexample']['length']);
        expectSame('protected_without_mfa', $report['counterexample']['violating_state']);
        expectSame(false, $report['counterexample']['violating_facts']['mfa_verified']);
        expectSame(json_decode(file_get_contents($examples . 'reports/mfa-vulnerable.json'), true, flags: JSON_THROW_ON_ERROR), $report);
    },
    'CLI verifies the corrected MFA model and matches the recorded report' => static function (): void {
        $examples = __DIR__ . '/../examples/mfa-bypass/';
        [$code, $report] = runCli($examples . 'mfa-corrected.json');
        expectSame(0, $code);
        expectSame('VERIFIED', $report['status']);
        expectSame(4, $report['reachability']['states_reached']);
        expectSame(null, $report['counterexample']);
        expectSame(json_decode(file_get_contents($examples . 'reports/mfa-corrected.json'), true, flags: JSON_THROW_ON_ERROR), $report);
    },
    'CLI emits INCOMPLETE and ERROR JSON with distinct exit codes' => static function (): void {
        $model = __DIR__ . '/../examples/mfa-bypass/mfa-corrected.json';
        [$code, $report] = runCli($model, '--max-depth', '1');
        expectSame(2, $code);
        expectSame('INCOMPLETE', $report['status']);

        foreach ([[], [__FILE__], [__FILE__ . '.missing'], [$model, '--max-depth', '-1'], [$model, '--max-depth', str_repeat('9', 25)]] as $args) {
            [$code, $report] = runCli(...$args);
            expectSame(3, $code);
            expectSame('ERROR', $report['status']);
            expectSame(true, is_string($report['error']) && $report['error'] !== '');
        }
    },
];

$tests += require __DIR__ . '/phase2.php';

$failed = 0;
foreach ($tests as $label => $test) {
    try {
        $test();
        echo "PASS $label", PHP_EOL;
    } catch (Throwable $exception) {
        ++$failed;
        fwrite(STDERR, "FAIL $label: " . $exception->getMessage() . PHP_EOL);
    }
}

$total = count($tests);
$passed = $total - $failed;
echo "$passed/$total tests passed.", PHP_EOL;
exit($failed === 0 ? 0 : 1);
