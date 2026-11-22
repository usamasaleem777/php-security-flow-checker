<?php

declare(strict_types=1);

use SecurityFlow\Models\TransitionSystem;
use SecurityFlow\Reporting\JsonReporter;
use SecurityFlow\Reporting\TextReporter;
use SecurityFlow\Security\InvariantChecker;
use SecurityFlow\Security\Outcome;
use SecurityFlow\Security\SecurityFlowAnalyzer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (PHP_VERSION_ID < 80200) {
    echo '{"status":"ERROR","error":"PHP 8.2 or later is required."}', PHP_EOL;
    exit(3);
}

/** Parse the canonical decimal integers used for exploration limits. */
function explorationLimit(string $option, ?string $value): int
{
    $limit = (int) $value;
    $minimum = $option === '--max-states' ? 1 : 0;
    if ($limit < $minimum || (string) $limit !== $value) {
        throw new InvalidArgumentException("$option requires a decimal integer of at least $minimum.");
    }
    return $limit;
}

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
$command = in_array($arguments[0] ?? null, ['verify', 'analyze'], true) ? array_shift($arguments) : 'verify';
$format = match (true) {
    in_array('--format=json', $arguments, true) => 'json',
    in_array('--format=text', $arguments, true) || $command === 'analyze' => 'text',
    default => 'json',
};

try {
    require_once __DIR__ . '/../src/bootstrap.php';

    $modelFile = array_shift($arguments);
    if ($modelFile === null || $modelFile === '' || str_starts_with($modelFile, '--')) {
        throw new InvalidArgumentException(
            'Usage: php bin/stateaxiom.php [verify|analyze] MODEL.json [--max-depth N] [--max-states N] ' .
            '[--format=json|--format=text] [--verbose]',
        );
    }

    $maxDepth = null;
    $maxStates = null;
    $verbose = false;
    $seen = [];
    for ($index = 0, $count = count($arguments); $index < $count; ++$index) {
        $option = $arguments[$index];
        $name = explode('=', $option, 2)[0];
        if (isset($seen[$name])) {
            throw new InvalidArgumentException('Options may only be specified once.');
        }
        $seen[$name] = true;

        switch ($option) {
            case '--max-depth':
                $maxDepth = explorationLimit($option, $arguments[++$index] ?? null);
                break;
            case '--max-states':
                $maxStates = explorationLimit($option, $arguments[++$index] ?? null);
                break;
            case '--verbose':
                $verbose = true;
                break;
            case '--format=json':
            case '--format=text':
                break;
            default:
                throw new InvalidArgumentException('Unknown CLI option.');
        }
    }

    if (str_contains($modelFile, '://') || str_starts_with(str_replace('\\', '/', $modelFile), '//')) {
        throw new InvalidArgumentException('Model path must use the local filesystem.');
    }
    $path = realpath($modelFile);
    if ($path === false || !is_file($path)) {
        throw new InvalidArgumentException('Model path must refer to a readable file.');
    }

    $json = @file_get_contents($path, length: TransitionSystem::MAX_JSON_BYTES + 1);
    if ($json === false) {
        throw new InvalidArgumentException('Unable to read the model file.');
    }

    $model = TransitionSystem::fromJson($json);
    $report = $command === 'verify' && $model->invariant !== null && $maxStates === null && $format === 'json'
        ? (new InvariantChecker())->verify($model, $maxDepth)
        : (new SecurityFlowAnalyzer())->analyze($model, $maxDepth, $maxStates);
} catch (InvalidArgumentException $exception) {
    $report = ['status' => Outcome::ERROR, 'error' => $exception->getMessage()];
} catch (Throwable) {
    $report = ['status' => Outcome::ERROR, 'error' => 'Internal verification error.'];
}

if ($command === 'analyze' && $report['status'] === Outcome::ERROR) {
    $report += [
        'project' => 'PHP Security Flow Checker',
        'phase' => 2,
        'analysis_complete' => false,
        'runtime_reproduced' => false,
        'findings' => [],
    ];
}

try {
    if ($format === 'text') {
        foreach ((new TextReporter())->lines($report, $verbose ?? false) as $line) {
            echo $line, PHP_EOL;
        }
    } else {
        (new JsonReporter())->write($report);
    }
    exit($report['status']->exitCode());
} catch (Throwable) {
    echo '{"status":"ERROR","error":"Internal reporting error."}', PHP_EOL;
    exit(3);
}
