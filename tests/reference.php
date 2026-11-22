<?php

declare(strict_types=1);

use Random\Engine\Mt19937;
use Random\Randomizer;
use SecurityFlow\Mathematics\BooleanLogic;
use SecurityFlow\Mathematics\GraphSearch;
use SecurityFlow\Models\Action;
use SecurityFlow\Models\TransitionSystem;
use SecurityFlow\Security\Outcome;
use SecurityFlow\Security\SecurityFlowAnalyzer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/bootstrap.php';

const REFERENCE_SEED = 20261122;
const REFERENCE_GRAPHS = 160;

function referenceEqual(mixed $expected, mixed $actual, string $context): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($context . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function generatedGraphs(): Generator
{
    $random = new Randomizer(new Mt19937(REFERENCE_SEED));
    for ($sample = 0; $sample < REFERENCE_GRAPHS; ++$sample) {
        $size = 1 + $sample % 7;
        $density = [1, 3, 6, 9][intdiv($sample, 7) % 4];
        $states = [];
        for ($i = 0; $i < $size; ++$i) {
            $states[] = ['id' => "s$i", 'facts' => [
                'safe' => $random->getInt(1, 5) > 1,
                'ready' => $random->getInt(0, 1) === 1,
                'allowed' => $random->getInt(1, 4) > 1,
            ]];
        }
        $ids = array_column($states, 'id');
        $transitions = [];
        foreach ($ids as $from) {
            foreach ($ids as $to) {
                if ($random->getInt(1, 10) <= $density) {
                    $guard = [true, false, 'ready', ['not', 'ready']][$random->getInt(0, 3)];
                    $action = 'edge' . count($transitions);
                    $transitions[] = compact('from', 'to', 'action', 'guard');
                }
            }
        }
        $initial = array_slice($random->shuffleArray($ids), 0, $random->getInt(1, $size));
        $properties = array_slice([
            ['id' => 'safe-state', 'scope' => 'state', 'assert' => 'safe'],
            ['id' => 'preservation', 'scope' => 'transition', 'assert' => ['implies', 'ready', ['post', 'allowed']]],
            ['id' => 'allowed-state', 'scope' => 'state', 'assert' => 'allowed'],
        ], 0, 1 + intdiv($sample, 7) % 3);
        yield [
            'name' => "sample-$sample",
            'initial' => $initial,
            'states' => $states,
            'domains' => ['safe' => 'boolean', 'ready' => 'boolean', 'allowed' => 'boolean'],
            'transitions' => $transitions,
            'properties' => $properties,
        ];
    }
}

function referenceEnabled(array $edge, array $facts): bool
{
    return match ($edge['guard']) {
        true => true,
        false => false,
        'ready' => $facts[$edge['from']]['ready'],
        ['not', 'ready'] => !$facts[$edge['from']]['ready'],
    };
}

/** Compute a set fixed point by full relation scans and distances by synchronous relaxation. */
function referenceResult(array $sample): array
{
    $facts = array_column($sample['states'], 'facts', 'id');
    $edges = array_values(array_filter(
        $sample['transitions'],
        static fn (array $edge): bool => referenceEnabled($edge, $facts),
    ));
    $initial = array_fill_keys($sample['initial'], true);
    $reached = [];
    do {
        $previous = $reached;
        $reached = $initial + $previous;
        foreach ($edges as $edge) {
            if (isset($previous[$edge['from']])) {
                $reached[$edge['to']] = true;
            }
        }
    } while (count($reached) !== count($previous));

    $distances = array_fill_keys($sample['initial'], 0);
    for ($round = 1; $round < count($facts); ++$round) {
        $old = $distances;
        foreach ($edges as $edge) {
            if (isset($old[$edge['from']])) {
                $to = $edge['to'];
                $distances[$to] = min($distances[$to] ?? PHP_INT_MAX, $old[$edge['from']] + 1);
            }
        }
    }
    ksort($distances);
    referenceEqual(count($reached), count($distances), $sample['name'] . ' oracle agreement');

    $minimum = [];
    foreach ($sample['properties'] as $property) {
        $id = $property['id'];
        if ($property['scope'] === 'state') {
            foreach ($distances as $state => $distance) {
                if (!$facts[$state][$property['assert']]) {
                    $minimum[$id] = min($minimum[$id] ?? PHP_INT_MAX, $distance);
                }
            }
        } else {
            foreach ($edges as $edge) {
                $from = $edge['from'];
                if (isset($distances[$from]) && $facts[$from]['ready'] && !$facts[$edge['to']]['allowed']) {
                    $minimum[$id] = min($minimum[$id] ?? PHP_INT_MAX, $distances[$from] + 1);
                }
            }
        }
    }
    ksort($minimum);
    return [$distances, $minimum];
}

function validateReferenceWitness(array $sample, array $finding): int
{
    $facts = array_column($sample['states'], 'facts', 'id');
    $trace = $finding['counterexample'];
    $state = $trace['initial_state'];
    $context = $sample['name'] . ' ' . $finding['property_id'];
    referenceEqual(true, in_array($state, $sample['initial'], true), "$context initial");
    $last = null;
    foreach ($trace['transitions'] as $step) {
        referenceEqual($state, $step['from'], "$context contiguous witness");
        $last = null;
        foreach ($sample['transitions'] as $edge) {
            if ($edge['from'] === $state && $edge['to'] === $step['to'] && $edge['action'] === $step['action']
                && referenceEnabled($edge, $facts)
            ) {
                $last = $edge;
                break;
            }
        }
        referenceEqual(true, $last !== null, "$context enabled edge");
        $changes = [];
        foreach ($facts[$state] as $key => $value) {
            $after = $facts[$step['to']][$key];
            if ($after !== $value) {
                $changes[$key] = ['before' => $value, 'after' => $after];
            }
        }
        referenceEqual($changes, (array) $step['changes'], "$context fact changes");
        $state = $step['to'];
    }
    $observed = $trace['violation'];
    if ($finding['property_id'] === 'preservation') {
        referenceEqual('transition', $observed['scope'], "$context kind");
        referenceEqual(true, $last !== null && $facts[$last['from']]['ready'] && !$facts[$state]['allowed'], "$context predicate");
        referenceEqual(array_intersect_key($last, $observed['transition']), $observed['transition'], "$context recorded edge");
        referenceEqual($facts[$last['from']], (array) $observed['pre'], "$context source facts");
        referenceEqual($facts[$state], (array) $observed['post'], "$context destination facts");
    } else {
        referenceEqual('state', $observed['scope'], "$context kind");
        referenceEqual($state, $observed['state'], "$context recorded state");
        referenceEqual($facts[$state], (array) $observed['facts'], "$context state facts");
        referenceEqual(false, $facts[$state][$finding['property_id'] === 'safe-state' ? 'safe' : 'allowed'], "$context predicate");
    }
    referenceEqual(count($trace['transitions']), $trace['length'], "$context path length");
    return $trace['length'];
}

function compareToReference(array $sample, array $limits = []): array
{
    [$distances, $minimum] = referenceResult($sample);
    $model = TransitionSystem::fromJson(json_encode($sample, JSON_THROW_ON_ERROR));
    $observed = [];
    $report = (new SecurityFlowAnalyzer())->analyze($model, ...($limits + [
        'observe' => static function (array $trace) use (&$observed): void {
            $observed = $trace['depths'];
        },
    ]));
    ksort($observed);
    foreach ($observed as $id => $depth) {
        referenceEqual($distances[$id] ?? null, $depth, $sample['name'] . ' discovered depth');
    }
    $witnesses = [];
    foreach ($report['findings'] as $finding) {
        $length = validateReferenceWitness($sample, $finding);
        $id = $finding['property_id'];
        referenceEqual($minimum[$id] ?? null, $length, $sample['name'] . ' minimum length');
        $witnesses[$id] = $length;
    }
    ksort($witnesses);
    referenceEqual(count($observed), $report['states_explored'], $sample['name'] . ' reported count');
    if (isset($limits['maxDepth']) && $observed !== []) {
        referenceEqual(true, max($observed) <= $limits['maxDepth'], $sample['name'] . ' depth budget');
    }
    if (isset($limits['maxStates'])) {
        referenceEqual(true, count($observed) <= $limits['maxStates'], $sample['name'] . ' state budget');
    }
    if (isset($limits['maxTransitions'])) {
        referenceEqual(true, $report['transitions_considered'] <= $limits['maxTransitions'], $sample['name'] . ' edge budget');
    }
    if ($limits === []) {
        referenceEqual(true, $report['analysis_complete'], $sample['name'] . ' unbounded completion');
    }
    if ($report['analysis_complete']) {
        referenceEqual($distances, $observed, $sample['name'] . ' full distances');
        referenceEqual($minimum, $witnesses, $sample['name'] . ' complete findings');
    }
    $status = $witnesses !== [] ? Outcome::VIOLATED : ($report['analysis_complete'] ? Outcome::VERIFIED : Outcome::INCOMPLETE);
    referenceEqual($status, $report['status'], $sample['name'] . ' status');
    return [count($observed), $witnesses, $status];
}

function renamedGraph(array $sample): array
{
    $rename = static fn (string $id): string => 'renamed-' . $id;
    $sample['initial'] = array_map($rename, $sample['initial']);
    foreach ($sample['states'] as &$state) {
        $state['id'] = $rename($state['id']);
    }
    unset($state);
    foreach ($sample['transitions'] as &$edge) {
        $edge['from'] = $rename($edge['from']);
        $edge['to'] = $rename($edge['to']);
    }
    return $sample;
}

$tests = [
    'Independent closure and shortest distances over randomized guarded graphs' => static function (): void {
        foreach (generatedGraphs() as $sample) {
            compareToReference($sample);
        }
    },
    'State renaming, action ordering, unreachable states and duplicate edges' => static function (): void {
        foreach (generatedGraphs() as $sample) {
            $baseline = compareToReference($sample);
            $ordered = $sample;
            $ordered['transitions'] = array_reverse($sample['transitions']);
            $ordered['initial'] = array_reverse($sample['initial']);
            $unreachable = $sample;
            $unreachable['states'][] = ['id' => 'isolated', 'facts' => ['safe' => false, 'ready' => true, 'allowed' => false]];
            $unreachable['transitions'][] = ['from' => 'isolated', 'to' => 'isolated', 'action' => 'isolated-loop', 'guard' => true];
            $duplicate = $sample;
            if ($sample['transitions'] !== []) {
                $duplicate['transitions'][] = $sample['transitions'][0];
            }
            foreach ([renamedGraph($sample), $ordered, $unreachable, $duplicate] as $variant) {
                referenceEqual($baseline, compareToReference($variant), $sample['name'] . ' metamorphic outcome');
            }
        }
    },
    'Reduced state, depth and transition budgets retain only genuine shortest witnesses' => static function (): void {
        foreach (generatedGraphs() as $sample) {
            foreach ([
                ['maxStates' => 1],
                ['maxDepth' => 0],
                ['maxTransitions' => 1],
                ['maxStates' => 3, 'maxDepth' => 1, 'maxTransitions' => 4],
            ] as $limits) {
                compareToReference($sample, $limits);
            }
        }
    },
    'Diamond ties and zero-action initial-state violations' => static function (): void {
        $sample = generatedGraphs()->current();
        $sample['properties'] = [['id' => 'safe-state', 'scope' => 'state', 'assert' => 'safe']];
        $sample['initial'] = ['s0', 's1'];
        $sample['states'] = array_map(static fn (string $id): array => [
            'id' => $id, 'facts' => ['safe' => !in_array($id, ['s4', 's5'], true), 'ready' => true, 'allowed' => false],
        ], ['s0', 's1', 's2', 's3', 's4', 's5']);
        $sample['transitions'] = array_map(
            static fn (array $edge): array => ['from' => $edge[0], 'to' => $edge[1], 'action' => $edge[0] . '-' . $edge[1], 'guard' => true],
            [['s0', 's2'], ['s1', 's3'], ['s2', 's4'], ['s3', 's4'], ['s4', 's0'], ['s5', 's5']],
        );
        referenceEqual([5, ['safe-state' => 2], Outcome::VIOLATED], compareToReference($sample), 'diamond');
        $sample['initial'][] = 's4';
        referenceEqual([5, ['safe-state' => 0], Outcome::VIOLATED], compareToReference($sample), 'initial failure');
    },
    'Boolean truth tables, De Morgan, typed comparisons and malformed syntax' => static function (): void {
        foreach ([false, true] as $p) {
            foreach ([false, true] as $q) {
                $facts = ['p' => $p, 'q' => $q];
                $expected = [
                    ['and', $p && $q], ['or', $p || $q], ['implies', !$p || $q],
                    ['equals', $p === $q], ['not-equals', $p !== $q],
                ];
                foreach ($expected as [$operator, $value]) {
                    referenceEqual($value, (new BooleanLogic([$operator, 'p', 'q'], ['p', 'q']))->evaluate($facts), $operator);
                }
                referenceEqual(!$p, (new BooleanLogic(['not', 'p'], ['p']))->evaluate($facts), 'not');
                foreach ([['and', 'or'], ['or', 'and']] as [$from, $to]) {
                    $negated = (new BooleanLogic(['not', [$from, 'p', 'q']], ['p', 'q']))->evaluate($facts);
                    $distributed = (new BooleanLogic([$to, ['not', 'p'], ['not', 'q']], ['p', 'q']))->evaluate($facts);
                    referenceEqual($negated, $distributed, 'De Morgan');
                }
            }
        }
        foreach ([-1, 0, 1] as $left) {
            foreach ([-1, 0, 1] as $right) {
                foreach (['lt' => $left < $right, 'lte' => $left <= $right, 'gt' => $left > $right, 'gte' => $left >= $right] as $op => $expected) {
                    $policy = new BooleanLogic([$op, ['pre', 'level'], ['post', 'level']], ['level' => 'integer'], true);
                    referenceEqual($expected, $policy->evaluate(['level' => $left], ['level' => $right]), $op);
                }
            }
        }
        foreach ([['xor', true, false], ['and', true], ['equals', true, 1], ['lt', true, false], ['post', 'p'], ['not', 'unknown']] as $expr) {
            try {
                new BooleanLogic($expr, ['p']);
            } catch (InvalidArgumentException) {
                continue;
            }
            throw new RuntimeException('Malformed policy was accepted.');
        }
    },
    'Path reconstruction rejects missing states, broken links and cycles' => static function (): void {
        $search = new GraphSearch();
        $invalid = [
            [],
            ['b' => new Action('absent', 'b', 'advance')],
            ['a' => null, 'b' => new Action('a', 'c', 'advance')],
            ['b' => new Action('b', 'b', 'loop')],
        ];
        foreach ($invalid as $parents) {
            try {
                $search->shortestPath($parents, 'b');
            } catch (LogicException) {
                continue;
            }
            throw new RuntimeException('Invalid predecessor chain was accepted.');
        }
    },
];

$passed = 0;
foreach ($tests as $label => $test) {
    try {
        $test();
        ++$passed;
        echo "PASS $label", PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL $label" . PHP_EOL . $error->getMessage() . PHP_EOL);
    }
}
$total = count($tests);
echo "$passed/$total reference groups passed (seed " . REFERENCE_SEED . ', ' . REFERENCE_GRAPHS . ' base graphs).', PHP_EOL;
exit((int) ($passed !== $total));
