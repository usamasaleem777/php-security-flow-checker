<?php

declare(strict_types=1);

use SecurityFlow\Models\TransitionSystem;
use SecurityFlow\Reporting\JsonReporter;
use SecurityFlow\Security\InvariantChecker;
use SecurityFlow\Security\Outcome;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (PHP_VERSION_ID < 80200) {
    echo '{"status":"ERROR","error":"PHP 8.2 or later is required."}', PHP_EOL;
    exit(3);
}

try {
    require_once __DIR__ . '/../src/bootstrap.php';
    $reporter = new JsonReporter();

    $arguments = $_SERVER['argv'] ?? [];
    $argumentCount = count($arguments);
    if (($argumentCount !== 2 && $argumentCount !== 4) || str_starts_with($arguments[1], '--')) {
        throw new InvalidArgumentException('Usage: php bin/stateaxiom.php MODEL.json [--max-depth N]');
    }

    $maxDepth = null;
    if ($argumentCount === 4) {
        $maxDepth = (int) $arguments[3];
        if ($arguments[2] !== '--max-depth' || $maxDepth < 0 || (string) $maxDepth !== $arguments[3]) {
            throw new InvalidArgumentException('--max-depth requires a nonnegative integer in decimal notation.');
        }
    }

    $path = realpath($arguments[1]);
    if ($path === false || !is_file($path)) {
        throw new InvalidArgumentException('Model path must refer to a readable file.');
    }

    $json = @file_get_contents($path, length: TransitionSystem::MAX_JSON_BYTES + 1);
    if ($json === false) {
        throw new InvalidArgumentException('Unable to read the model file.');
    }

    $report = (new InvariantChecker())->verify(TransitionSystem::fromJson($json), $maxDepth);
    $output = $reporter->encode($report);
    $exitCode = $report['status']->exitCode();
} catch (InvalidArgumentException $exception) {
    $output = $reporter->encode(['status' => Outcome::ERROR, 'error' => $exception->getMessage()]);
    $exitCode = Outcome::ERROR->exitCode();
} catch (Throwable) {
    $output = '{"status":"ERROR","error":"Internal verification error."}';
    $exitCode = 3;
}

echo $output, PHP_EOL;
exit($exitCode);
