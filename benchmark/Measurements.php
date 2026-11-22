<?php

declare(strict_types=1);

namespace SecurityFlow\Benchmark;

use RuntimeException;

final class Measurements
{
    public function run(string $workload, int $size, int $seed, int $memoryMiB, int $timeoutMs): array
    {
        $result = self::process([
            PHP_BINARY, '-n', '-d', "memory_limit={$memoryMiB}M", '-d', 'display_errors=stderr',
            __DIR__ . '/worker.php', $workload, (string) $size, (string) $seed, (string) $memoryMiB,
        ], $timeoutMs);
        if ($result['timed_out']) {
            return ['completion' => 'INCOMPLETE', 'reason' => 'wall_timeout'];
        }
        $output = json_decode($result['output'], true);
        if (is_array($output) && (
            ($output['completion'] ?? null) === 'ERROR'
            || ($result['exit_code'] === 0 && in_array($output['completion'] ?? null, ['COMPLETE', 'INCOMPLETE'], true))
        )) {
            return $output;
        }
        if (stripos($result['diagnostics'], 'Allowed memory size') !== false) {
            return ['completion' => 'INCOMPLETE', 'reason' => 'php_memory_limit'];
        }
        return ['completion' => 'ERROR', 'reason' => 'worker_failed', 'exit_code' => $result['exit_code']];
    }

    /** @param list<string> $command Fixed local executable with separate arguments. */
    public static function process(array $command, int $timeoutMs): array
    {
        $store = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stateaxiom-bench-' . bin2hex(random_bytes(12));
        if (!mkdir($store, 0700)) {
            throw new RuntimeException('Could not create an isolated benchmark directory.');
        }
        $stdout = $store . DIRECTORY_SEPARATOR . 'out';
        $stderr = $store . DIRECTORY_SEPARATOR . 'err';
        $process = null;
        try {
            $process = proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'wb'], 2 => ['file', $stderr, 'wb']],
                $pipes,
                options: ['bypass_shell' => true],
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start the PHP benchmark worker.');
            }
            fclose($pipes[0]);
            $deadline = hrtime(true) + $timeoutMs * 1_000_000;
            do {
                $state = proc_get_status($process);
                if (!$state['running']) {
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    proc_terminate($process, 9);
                    return ['timed_out' => true, 'exit_code' => null, 'output' => '', 'diagnostics' => ''];
                }
                usleep(1_000);
            } while (true);

            return [
                'timed_out' => false,
                'exit_code' => $state['exitcode'],
                'output' => (string) file_get_contents($stdout, length: 65_537),
                'diagnostics' => (string) file_get_contents($stderr, length: 65_537),
            ];
        } finally {
            if (is_resource($process)) {
                proc_close($process);
            }
            foreach ([$stdout, $stderr] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($store);
        }
    }

    /** @param list<int> $values */
    public static function distribution(array $values): ?array
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        return [
            'min' => $values[0],
            'median' => $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2,
            'max' => $values[$count - 1],
        ];
    }
}
