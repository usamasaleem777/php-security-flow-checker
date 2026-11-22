<?php

declare(strict_types=1);

use SecurityFlow\Benchmark\FiniteModels;
use SecurityFlow\Models\TransitionSystem;
use SecurityFlow\Security\SecurityFlowAnalyzer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/generators/FiniteModels.php';

try {
    if ($argc !== 5 || preg_match('/\A[0-9]+\z/', $argv[2]) !== 1 || preg_match('/\A[0-9]+\z/', $argv[3]) !== 1) {
        throw new InvalidArgumentException('Expected workload, size, seed and memory limit.');
    }
    if (ini_get('memory_limit') !== $argv[4] . 'M') {
        throw new RuntimeException('PHP did not apply the requested worker memory limit.');
    }
    $generated = FiniteModels::generate($argv[1], (int) $argv[2], (int) $argv[3]);
    $json = json_encode($generated, JSON_THROW_ON_ERROR);
    $fingerprint = hash('sha256', $json);
    $configuredTransitions = count($generated['transitions']);
    $configuredProperties = count($generated['properties']);
    $bytes = strlen($json);
    $model = TransitionSystem::fromJson($json);
    unset($generated, $json);

    $propertiesEvaluated = $assertionEvaluations = 0;
    memory_reset_peak_usage();
    $started = hrtime(true);
    $report = (new SecurityFlowAnalyzer())->analyze($model, observe: static function (array $trace, array $checks) use (
        &$propertiesEvaluated,
        &$assertionEvaluations,
    ): void {
        foreach ($checks as $check) {
            $propertiesEvaluated += (int) ($check['evaluated'] > 0);
            $assertionEvaluations += $check['evaluated'];
        }
    });
    $elapsed = hrtime(true) - $started;
    $peak = memory_get_peak_usage(true);
    $used = memory_get_peak_usage(false);
    $lengths = array_column(array_column($report['findings'], 'counterexample'), 'length');
    echo json_encode([
        'completion' => $report['analysis_complete'] ? 'COMPLETE' : 'INCOMPLETE',
        'model_status' => $report['status']->value,
        'model_sha256' => $fingerprint,
        'model_bytes' => $bytes,
        'configured_states' => count($model->states),
        'configured_transitions' => $configuredTransitions,
        'reachable_states' => $report['states_explored'],
        'transitions_evaluated' => $report['transitions_considered'],
        'enabled_transitions_explored' => $report['transitions_explored'],
        'properties_configured' => $configuredProperties,
        'properties_evaluated' => $propertiesEvaluated,
        'assertion_evaluations' => $assertionEvaluations,
        'violations' => count($report['findings']),
        'shortest_counterexample' => $lengths === [] ? null : min($lengths),
        'elapsed_ns' => $elapsed,
        'peak_allocated_bytes' => $peak,
        'peak_used_bytes' => $used,
    ], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['completion' => 'ERROR', 'reason' => $error->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(1);
}
