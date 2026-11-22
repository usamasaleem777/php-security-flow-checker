<?php

declare(strict_types=1);

use SecurityFlow\Benchmark\FiniteModels;
use SecurityFlow\Benchmark\Measurements;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/generators/FiniteModels.php';
require_once __DIR__ . '/Measurements.php';

function benchmarkInteger(string $value, int $min, int $max): int
{
    if ($value !== (string) (int) $value || (int) $value < $min || (int) $value > $max) {
        throw new InvalidArgumentException("Expected a decimal integer from $min to $max.");
    }
    return (int) $value;
}

function benchmarkOptions(array $arguments): array
{
    $values = [
        'seed' => '20261122',
        'sizes' => '10,50,100,250,500,1000,2500,5000',
        'workloads' => 'chain,partitioned',
        'repetitions' => '5',
        'memory-mib' => '128',
        'timeout-ms' => '10000',
        'output' => 'tmp/benchmark',
    ];
    $seen = [];
    foreach ($arguments as $argument) {
        if (preg_match('/\A--([a-z-]+)=(.+)\z/', $argument, $parts) !== 1 || !isset($values[$parts[1]]) || isset($seen[$parts[1]])) {
            throw new InvalidArgumentException('Use unique supported --name=value options. See --help.');
        }
        $values[$parts[1]] = $parts[2];
        $seen[$parts[1]] = true;
    }

    $sizes = array_map(static fn (string $size): int => benchmarkInteger($size, 1, 5000), explode(',', $values['sizes']));
    $workloads = explode(',', $values['workloads']);
    if (count($sizes) > 32 || count(array_unique($sizes)) !== count($sizes)
        || array_diff($workloads, FiniteModels::WORKLOADS) !== [] || count(array_unique($workloads)) !== count($workloads)
    ) {
        throw new InvalidArgumentException('Choose distinct supported workloads and up to 32 distinct sizes.');
    }
    $output = $values['output'];
    if (str_contains($output, '://') || str_starts_with(str_replace('\\', '/', $output), '//') || file_exists($output)) {
        throw new InvalidArgumentException('The output must be a new local directory.');
    }

    return [
        'seed' => benchmarkInteger($values['seed'], 0, 2147483647),
        'sizes' => $sizes,
        'workloads' => $workloads,
        'repetitions' => benchmarkInteger($values['repetitions'], 1, 25),
        'memory_mib' => benchmarkInteger($values['memory-mib'], 4, 2048),
        'timeout_ms' => benchmarkInteger($values['timeout-ms'], 1, 120000),
        'output' => $output,
    ];
}

function benchmarkSummary(array $report): string
{
    $environment = $report['environment'];
    $limits = $report['limits'];
    $lines = [
        '# Finite-state benchmark results', '',
        'PHP ' . $environment['php'] . ' on ' . $environment['os'] . ' ' . $environment['release'] . ' (' . $environment['architecture'] . ').',
        'Seed ' . $report['seed'] . ', ' . $report['repetitions'] . ' repetitions per case, ' . $limits['memory_mib'] . ' MiB PHP memory limit and '
            . $limits['timeout_ms'] . ' ms worker deadline.', '',
        '| Workload | Configured | Reached | Edge evaluations | Properties evaluated | Violations | Shortest witness | Completed | Time min / median / max (ms) | Peak allocator max (MiB) |',
        '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
    ];
    foreach ($report['cases'] as $case) {
        $samples = $case['samples'];
        $completed = array_values(array_filter($samples, static fn (array $sample): bool => $sample['completion'] === 'COMPLETE'));
        $first = $completed[0] ?? [];
        $time = $case['elapsed_ns'];
        $memory = $case['peak_allocated_bytes'];
        $lines[] = sprintf(
            '| %s | %d | %s | %s | %s | %s | %s | %d/%d | %s | %s |',
            $case['workload'],
            $case['size'],
            $first['reachable_states'] ?? '—',
            $first['transitions_evaluated'] ?? '—',
            $first['properties_evaluated'] ?? '—',
            $first['violations'] ?? '—',
            $first['shortest_counterexample'] ?? '—',
            count($completed),
            count($samples),
            $time === null ? '—' : sprintf('%.3f / %.3f / %.3f', $time['min'] / 1e6, $time['median'] / 1e6, $time['max'] / 1e6),
            $memory === null ? '—' : sprintf('%.2f', $memory['max'] / 1048576),
        );
    }
    $lines[] = '';
    $lines[] = 'Only complete measurements enter the distributions. The JSON records every worker outcome and its reason when available.';
    $lines[] = '';
    $lines[] = 'Time covers instrumented verification and witness construction. The PHP allocator peak includes the loaded model and is not an OS RSS measurement.';
    return implode("\n", $lines) . "\n";
}

function writeBenchmark(string $directory, array $report): void
{
    $files = [
        'results.json' => json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
        'summary.md' => benchmarkSummary($report),
    ];
    if (!@mkdir($directory, 0777, true)) {
        throw new RuntimeException('Could not create the new benchmark output directory.');
    }
    $created = [];
    try {
        foreach ($files as $name => $content) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            $stream = @fopen($path, 'xb');
            if ($stream === false) {
                throw new RuntimeException('Could not create a benchmark report.');
            }
            $created[] = $path;
            try {
                if (fwrite($stream, $content) !== strlen($content)) {
                    throw new RuntimeException('Could not write the complete benchmark report.');
                }
            } finally {
                fclose($stream);
            }
        }
    } catch (Throwable $error) {
        foreach ($created as $path) {
            unlink($path);
        }
        @rmdir($directory);
        throw $error;
    }
}

if (array_slice($argv, 1) === ['--help']) {
    echo 'Usage: php benchmark/run.php [--seed=20261122] [--sizes=10,50,...] [--workloads=chain,partitioned]', PHP_EOL,
        '  [--repetitions=5] [--memory-mib=128] [--timeout-ms=10000] [--output=tmp/benchmark]', PHP_EOL;
    exit(0);
}

try {
    $options = benchmarkOptions(array_slice($argv, 1));
    $report = [
        'schema_version' => 1,
        'environment' => [
            'php' => PHP_VERSION,
            'os' => php_uname('s'),
            'release' => php_uname('r'),
            'architecture' => php_uname('m'),
            'worker_ini' => 'disabled',
        ],
        'seed' => $options['seed'],
        'repetitions' => $options['repetitions'],
        'limits' => [
            'memory_mib' => $options['memory_mib'],
            'timeout_ms' => $options['timeout_ms'],
            'max_model_bytes' => 1_048_576,
        ],
        'cases' => [],
    ];
    $runner = new Measurements();
    $complete = $incomplete = $errors = 0;
    foreach ($options['sizes'] as $size) {
        foreach ($options['workloads'] as $workload) {
            $samples = $timings = $memory = [];
            for ($repetition = 1; $repetition <= $options['repetitions']; ++$repetition) {
                $sample = $runner->run($workload, $size, $options['seed'], $options['memory_mib'], $options['timeout_ms']);
                $samples[] = ['repetition' => $repetition] + $sample;
                if ($sample['completion'] === 'COMPLETE') {
                    ++$complete;
                    $timings[] = $sample['elapsed_ns'];
                    $memory[] = $sample['peak_allocated_bytes'];
                } elseif ($sample['completion'] === 'INCOMPLETE') {
                    ++$incomplete;
                } else {
                    ++$errors;
                }
            }
            $report['cases'][] = [
                'workload' => $workload,
                'size' => $size,
                'elapsed_ns' => Measurements::distribution($timings),
                'peak_allocated_bytes' => Measurements::distribution($memory),
                'samples' => $samples,
            ];
        }
    }
    $report['counts'] = compact('complete', 'incomplete', 'errors');
    writeBenchmark($options['output'], $report);
    echo "$complete complete, $incomplete incomplete, $errors errors. Results saved to ", $options['output'], PHP_EOL;
    exit($errors > 0 ? 3 : ($incomplete > 0 ? 2 : 0));
} catch (Throwable $error) {
    fwrite(STDERR, 'Benchmark error. ' . $error->getMessage() . PHP_EOL);
    exit(3);
}
