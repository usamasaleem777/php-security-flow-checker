<?php

declare(strict_types=1);

use SecurityFlow\Benchmark\FiniteModels;
use SecurityFlow\Benchmark\Measurements;
use SecurityFlow\Models\TransitionSystem;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../benchmark/generators/FiniteModels.php';
require_once __DIR__ . '/../benchmark/Measurements.php';

function benchCheck(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException($reason);
    }
}

$tests = [
    'Generator is reproducible and the largest models fit the schema' => static function (): void {
        foreach (FiniteModels::WORKLOADS as $workload) {
            $first = FiniteModels::generate($workload, 5000, 20261122);
            $second = FiniteModels::generate($workload, 5000, 20261122);
            $different = FiniteModels::generate($workload, 5000, 17);
            benchCheck($first === $second && $first !== $different, 'Seed did not control the graph.');
            $model = TransitionSystem::fromJson(json_encode($first, JSON_THROW_ON_ERROR));
            benchCheck(count($model->states) === 5000, 'Configured graph size changed.');
        }
    },
    'Independent chain and partition ground truth matches actual worker metrics' => static function (): void {
        $runner = new Measurements();
        $full = $runner->run('chain', 50, 20261122, 128, 10000);
        $partial = $runner->run('partitioned', 50, 20261122, 128, 10000);
        benchCheck($full['completion'] === 'COMPLETE' && $partial['completion'] === 'COMPLETE', 'Worker failed.');
        benchCheck($full['reachable_states'] === 50 && $partial['reachable_states'] === 37, 'Unexpected reachable sizes.');
        benchCheck($full['model_status'] === 'VIOLATED' && $full['violations'] === 2 && $full['shortest_counterexample'] === 49, 'Chain violated the wrong property or length.');
        benchCheck($partial['model_status'] === 'VERIFIED' && $partial['violations'] === 0, 'Partition should verify.');
        foreach ([$full, $partial] as $sample) {
            benchCheck($sample['elapsed_ns'] > 0 && $sample['peak_allocated_bytes'] >= $sample['peak_used_bytes'], 'Invalid timer or allocator observation.');
            benchCheck($sample['properties_evaluated'] > 0 && $sample['transitions_evaluated'] > 0, 'Missing actual evaluation counts.');
        }
    },
    'Worker deadline terminates a timed-out child' => static function (): void {
        $timeout = Measurements::process([PHP_BINARY, '-n', '-r', 'usleep(500000);'], 20);
        benchCheck($timeout['timed_out'] === true && $timeout['exit_code'] === null, 'Deadline was not honored.');
    },
    'Memory exhaustion is incomplete and carries no invented measurements' => static function (): void {
        $limited = (new Measurements())->run('chain', 5000, 20261122, 4, 10000);
        benchCheck($limited === ['completion' => 'INCOMPLETE', 'reason' => 'php_memory_limit'], 'Memory-limited worker was not classified correctly.');
    },
    'Median handles odd and even samples' => static function (): void {
        benchCheck(Measurements::distribution([6, 1, 4]) === ['min' => 1, 'median' => 4, 'max' => 6], 'Odd median incorrect.');
        benchCheck(Measurements::distribution([6, 1, 4, 2]) === ['min' => 1, 'median' => 3, 'max' => 6], 'Even median incorrect.');
        benchCheck(Measurements::distribution([]) === null, 'Missing samples were given a distribution.');
    },
    'CLI writes valid JSON and Markdown including every sample' => static function (): void {
        $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stateaxiom-report-' . bin2hex(random_bytes(12));
        try {
            $run = Measurements::process([
                PHP_BINARY, '-n', __DIR__ . '/../benchmark/run.php', '--sizes=10', '--repetitions=2', '--output=' . $output,
            ], 15000);
            benchCheck($run['exit_code'] === 0, 'Benchmark CLI failed.');
            $json = json_decode((string) file_get_contents($output . DIRECTORY_SEPARATOR . 'results.json'), true, flags: JSON_THROW_ON_ERROR);
            $markdown = (string) file_get_contents($output . DIRECTORY_SEPARATOR . 'summary.md');
            benchCheck($json['counts'] === ['complete' => 4, 'incomplete' => 0, 'errors' => 0], 'Unexpected report totals.');
            benchCheck(count($json['cases']) === 2 && count($json['cases'][0]['samples']) === 2, 'A worker sample was omitted.');
            benchCheck(str_contains($markdown, '| partitioned | 10 | 7 |'), 'Markdown lacks an actual result.');
        } finally {
            foreach (['results.json', 'summary.md'] as $file) {
                $path = $output . DIRECTORY_SEPARATOR . $file;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($output)) {
                rmdir($output);
            }
        }
    },
    'Archived distributions and ground truth agree with all recorded samples' => static function (): void {
        $file = __DIR__ . '/../benchmark/results/windows-php-8.2/results.json';
        $archive = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        benchCheck($archive['counts'] === ['complete' => 80, 'incomplete' => 0, 'errors' => 0], 'Archive totals changed.');
        benchCheck(count($archive['cases']) === 16, 'Archive has the wrong case count.');
        $observedCount = 0;
        foreach ($archive['cases'] as $case) {
            $samples = $case['samples'];
            benchCheck(count($samples) === $archive['repetitions'], 'Archive case is missing a repetition.');
            $fingerprints = array_unique(array_column($samples, 'model_sha256'));
            benchCheck(count($fingerprints) === 1, 'Repeated case changed its input.');
            $full = $case['workload'] === 'chain';
            $expected = $full ? $case['size'] : intdiv(3 * $case['size'], 4);
            foreach ($samples as $sample) {
                benchCheck($sample['completion'] === 'COMPLETE', 'Unexpected incomplete sample in the archive.');
                benchCheck($sample['configured_states'] === $case['size'] && $sample['reachable_states'] === $expected, 'Archived reachability differs from generator ground truth.');
                benchCheck($sample['violations'] === ($full ? 2 : 0) && $sample['shortest_counterexample'] === ($full ? $case['size'] - 1 : null), 'Archived witness count or length is incorrect.');
                ++$observedCount;
            }
            benchCheck($case['elapsed_ns'] == Measurements::distribution(array_column($samples, 'elapsed_ns')), 'Archived time distribution is incorrect.');
            benchCheck($case['peak_allocated_bytes'] == Measurements::distribution(array_column($samples, 'peak_allocated_bytes')), 'Archived memory distribution is incorrect.');
        }
        benchCheck($observedCount === $archive['counts']['complete'], 'The archive summary omitted measurements.');
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
echo "$passed/$total benchmark validation groups passed.", PHP_EOL;
exit((int) ($passed !== $total));
